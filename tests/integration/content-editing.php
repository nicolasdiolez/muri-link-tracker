<?php
/**
 * Real WordPress / InnoDB smoke tests. Run on a disposable installation only:
 *   wp eval-file tests/integration/content-editing.php
 * Creates its own posts/URLs and removes them on completion. No HTTP calls.
 */

use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Models\Enums\LinkType;
use MuriLinkTracker\Scanner\BlockParser;
use MuriLinkTracker\Scanner\ContentParser;
use MuriLinkTracker\Scanner\LinkClassifier;
use MuriLinkTracker\Scanner\LinkEditingService;
use MuriLinkTracker\Scanner\LinkExtractor;
use MuriLinkTracker\Scanner\LinkHtmlEditor;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this test through WP-CLI on a disposable site.' );
}

if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
	throw new RuntimeException( 'Integration fixtures are restricted to a local disposable WordPress installation.' );
}

global $wpdb;
$previous_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
if ( ! $admins ) {
	throw new RuntimeException( 'An administrator is required for the integration test.' );
}
wp_set_current_user( (int) $admins[0] );
$links = new LinksRepository( $wpdb );
$instances = new InstancesRepository( $wpdb );
$html = new LinkHtmlEditor( $wpdb );
$editor = new LinkEditingService( $wpdb, $links, $instances, $html );
$parser = new ContentParser();
$extractor = new LinkExtractor( $parser, new BlockParser( $parser ), new LinkClassifier() );
$suffix = wp_generate_uuid4();
$old_url = 'https://content-editing.invalid/old-' . $suffix;
$new_url = 'https://content-editing.invalid/new-' . $suffix;
$cpt_url = 'https://content-editing.invalid/cpt-' . $suffix;
$readonly_url = 'https://content-editing.invalid/readonly-' . $suffix;
$post_ids = array();
$failure_trigger = 'mltr_edit_failure_' . str_replace( '-', '', $suffix );
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};
$sync = static function ( int $post_id ) use ( $links, $instances, $extractor ): void {
	$data = array();
	foreach ( $extractor->extract_from_post( get_post( $post_id ), get_option( 'mltr_settings', array() ) ) as $group ) {
		$id = $links->insert_or_get( $group['url'], $group['url_hash'], LinkType::External === $group['type'], $group['is_affiliate'], $group['affiliate_network'] );
		foreach ( $group['instances'] as $instance ) {
			$result = $instance['scan_result'];
			$data[] = array_merge( $instance['rel_flags'], array(
				'link_id' => $id, 'post_id' => $post_id, 'source_type' => $result->source_type,
				'anchor_text' => $result->anchor_text, 'link_position' => $result->link_position ?? 0, 'block_name' => $result->block_name ?? '',
			) );
		}
	}
	$instances->sync_for_post( $post_id, $data );
};
$create = static function ( array $data ) use ( &$post_ids ): int {
	$id = wp_insert_post( wp_slash( array_merge( array( 'post_title' => 'MLTR integration fixture', 'post_status' => 'publish' ), $data ) ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$post_ids[] = $id;
	return $id;
};
try {
	$original_content = '<!-- wp:paragraph -->' . "\n" . '<p>été <a href="' . $old_url . '" rel="sponsored noopener"><img src="/sample.jpg" alt="é"><strong>bold</strong></a></p>' . "\n<!-- /wp:paragraph -->";
	$original_excerpt = '<a href="' . $old_url . '" rel="external">Excerpt</a>';
	$a = $create( array( 'post_content' => $original_content, 'post_excerpt' => $original_excerpt ) );
	$b = $create( array( 'post_content' => '<p><a href="' . $new_url . '" rel="nofollow">Existing destination</a></p>' ) );
	$sync( $a );
	$sync( $b );
	$before = get_post( $a );
	$source = $links->find_by_hash( hash( 'sha256', $old_url ) );
	$target = $links->find_by_hash( hash( 'sha256', $new_url ) );
	$result = $editor->edit( $source, $new_url, null );
	$after = get_post( $a );
	$assert( $target->id === $result['link']->id, 'A -> existing B must reuse B ID.' );
	$assert( null === $links->find( $source->id ), 'Source A must no longer exist after merging.' );
	$assert( str_replace( $old_url, $new_url, $original_content ) === $after->post_content, 'URL replacement must preserve Gutenberg, image, attributes and exact unrelated bytes.' );
	$assert( str_replace( $old_url, $new_url, $original_excerpt ) === $after->post_excerpt, 'Excerpt must be updated.' );
	$assert( $before->post_modified === $after->post_modified && $before->post_modified_gmt === $after->post_modified_gmt, 'Publication modification dates must stay unchanged.' );
	$assert( 3 === count( $instances->find_by_link( $target->id ) ), 'Merged inventory must retain all three occurrences.' );
	$revision = wp_get_post_revision( $result['revisions'][0]['revisionId'] );
	$assert( $revision && $revision->post_content === $original_content && $revision->post_excerpt === $original_excerpt, 'A native revision must contain exact previous content and excerpt.' );
	$assert( wp_revisions_enabled( get_post( $a ) ), 'Recovery revision must be accessible in WordPress.' );
	$restored = wp_restore_post_revision( $revision->ID );
	$assert( $restored === $a && get_post( $a )->post_content === $original_content && get_post( $a )->post_excerpt === $original_excerpt, 'Native WordPress restore must undo both fields.' );
	$sync( $a );

	$source = $links->find_by_hash( hash( 'sha256', $old_url ) );
	$rel_result = $editor->edit( $source, null, 'ugc' );
	$after = get_post( $a );
	$assert( str_contains( $after->post_content, 'rel="noopener ugc"' ) && str_contains( $after->post_excerpt, 'rel="external ugc"' ), 'Rel edit must preserve each source\'s unexposed tokens.' );
	$flags = $instances->find_by_link( $source->id );
	$assert( count( $flags ) === 2 && $flags[0]->rel_ugc && ! $flags[0]->rel_sponsored && $flags[1]->rel_ugc && ! $flags[1]->rel_sponsored, 'Inventory flags must reflect actual rel edits.' );
	$assert( ! $rel_result['link']->is_affiliate, 'Removing the only sponsored hint must refresh aggregate affiliate classification.' );
	// Force a genuine SQL failure after the post and its recovery revision have
	// been written; rollback must restore all three parts of the operation.
	$before_failure = get_post( $a );
	$revision_count = count( wp_get_post_revisions( $a ) );
	$trigger_created = $wpdb->query( $wpdb->prepare(
		"CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW BEGIN IF NEW.post_id = %d THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mltr integration instance failure'; END IF; END",
		$failure_trigger, $wpdb->prefix . 'mltr_instances', $a
	) );
	$assert( false !== $trigger_created, 'The disposable DB must allow a trigger for the rollback fault injection.' );
	$previous_suppress = $wpdb->suppress_errors( true );
	$failed = false;
	try {
		$editor->edit( $rel_result['link'], null, 'nofollow' );
	} catch ( RuntimeException $error ) {
		$failed = true;
	} finally {
		$wpdb->suppress_errors( $previous_suppress );
		$wpdb->query( $wpdb->prepare( 'DROP TRIGGER IF EXISTS %i', $failure_trigger ) );
	}
	$after_failure = get_post( $a );
	$assert( $failed, 'The injected SQL failure must propagate instead of returning success.' );
	$assert( $after_failure->post_content === $before_failure->post_content && $after_failure->post_excerpt === $before_failure->post_excerpt, 'SQL failure must roll back both content fields.' );
	$assert( count( wp_get_post_revisions( $a ) ) === $revision_count, 'SQL failure must roll back the uncommitted recovery revision.' );
	$flags = $instances->find_by_link( $source->id );
	$assert( count( $flags ) === 2 && $flags[0]->rel_ugc && ! $flags[0]->rel_nofollow && $flags[1]->rel_ugc, 'SQL failure must retain the previous complete inventory.' );

	$removed = $editor->edit( $rel_result['link'], null, null, true );
	$after = get_post( $a );
	$assert( str_contains( $after->post_content, '<img src="/sample.jpg" alt="é"><strong>bold</strong>' ) && ! str_contains( $after->post_content, '<a ' ), 'Unlink must preserve image and formatting.' );
	$assert( 'Excerpt' === $after->post_excerpt && null === $links->find( $source->id ), 'Unlink must remove excerpt wrapper and inventory.' );
	$assert( ! empty( $removed['revisions'][0]['revisionId'] ), 'Unlink needs a restore revision too.' );

	register_post_type( 'mltr_no_revisions', array( 'public' => true, 'supports' => array( 'title', 'editor' ) ) );
	$c = $create( array( 'post_type' => 'mltr_no_revisions', 'post_content' => '<a href="' . $cpt_url . '">CPT</a>' ) );
	$sync( $c );
	$source = $links->find_by_hash( hash( 'sha256', $cpt_url ) );
	$blocked = false;
	try {
		$editor->edit( $source, null, null, true );
	} catch ( RuntimeException $error ) {
		$blocked = str_contains( $error->getMessage(), 'revisions' );
	}
	$assert( $blocked && str_contains( get_post( $c )->post_content, $cpt_url ) && null !== $links->find( $source->id ), 'CPT without revision support must fail before changing anything.' );

	$r = $create( array( 'post_content' => '<!-- wp:button {"url":"' . $readonly_url . '"} --><div class="wp-block-button"><a class="wp-block-button__link" href="' . $readonly_url . '">Button</a></div><!-- /wp:button -->' ) );
	$sync( $r );
	$source = $links->find_by_hash( hash( 'sha256', $readonly_url ) );
	$previous = get_post( $r )->post_content;
	$blocked = false;
	try {
		$editor->edit( $source, null, null, true );
	} catch ( RuntimeException $error ) {
		$blocked = str_contains( $error->getMessage(), 'block attribute' );
	}
	$assert( $blocked && get_post( $r )->post_content === $previous && null !== $links->find( $source->id ), 'Mixed HTML and block-attribute links must never be partially edited.' );

	WP_CLI::success( "Content editing integration: {$checks} assertions passed." );
} finally {
	$wpdb->query( $wpdb->prepare( 'DROP TRIGGER IF EXISTS %i', $failure_trigger ) );
	foreach ( $post_ids as $post_id ) {
		$instances->delete_by_post( $post_id );
		wp_delete_post( $post_id, true );
	}
	foreach ( array( $old_url, $new_url, $cpt_url, $readonly_url ) as $url ) {
		$link = $links->find_by_hash( hash( 'sha256', $url ) );
		if ( $link ) {
			$links->delete( $link->id );
		}
	}
	unregister_post_type( 'mltr_no_revisions' );
	wp_set_current_user( $previous_user );
}
