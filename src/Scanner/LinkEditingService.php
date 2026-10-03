<?php
/**
 * Atomic, permission-aware content edits with native recovery revisions.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Scanner;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Models\Enums\LinkType;
use MuriLinkTracker\Models\Link;
use MuriLinkTracker\Queue\ScanStore;

/** Coordinates guarded edits across content, revisions and inventory. */
class LinkEditingService {
	/**
	 * Post link extractor.
	 *
	 * @var LinkExtractor
	 */
	private LinkExtractor $extractor;
	/**
	 * URL and affiliate classifier.
	 *
	 * @var LinkClassifier
	 */
	private LinkClassifier $classifier;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param \wpdb               $wpdb WordPress database connection.
	 * @param LinksRepository     $links Link inventory repository.
	 * @param InstancesRepository $instances Link occurrence repository.
	 * @param LinkHtmlEditor      $html Surgical HTML editing service.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly LinksRepository $links,
		private readonly InstancesRepository $instances,
		private readonly LinkHtmlEditor $html,
	) {
		$this->classifier = new LinkClassifier();
		$parser           = new ContentParser();
		$this->extractor  = new LinkExtractor( $parser, new BlockParser( $parser ), $this->classifier );
	}

	/**
	 * Preflight every source before the first write. All content, revisions and
	 * inventory changes commit together; no endpoint can report partial success.
	 *
	 * @param Link        $expected Link snapshot submitted for editing.
	 * @param string|null $url Requested link URL.
	 * @param string|null $rel Requested managed rel flags, or null to retain them.
	 * @param bool        $unlink Whether to remove anchor wrappers.
	 * @return array{link:Link|null,updated_posts:int,revisions:array}
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 * @throws \Throwable When the guarded operation fails; the original error is propagated.
	 */
	public function edit( Link $expected, ?string $url, ?string $rel, bool $unlink = false ): array {
		return ( new ScanStore( $this->wpdb ) )->exclusive(
			function () use ( $expected, $url, $rel, $unlink ): array {
				$wpdb = $this->wpdb;
				$this->require_transactional_tables();
				if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
						throw new \RuntimeException( 'Could not start the content transaction.' );
				}
				$changed_posts = array();
				$revision_ids  = array();
				try {
					// Lock and re-read: another request may have changed this URL while
					// the REST controller was waiting for the plugin mutation lock.
					$row = $this->wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->wpdb->prefix . 'mltr_links', $expected->id ) );
					if ( null === $row || $row->url !== $expected->url ) {
						throw new \RuntimeException( 'The link changed. Refresh the list before editing it.' );
					}
					$link      = Link::from_db_row( $row );
					$instances = $this->instances->find_by_link( $link->id );
					$sources   = array();
					foreach ( $instances as $instance ) {
						if ( ! in_array( $instance->source_type, array( 'post_content', 'post_excerpt' ), true ) ) {
							throw new \RuntimeException( 'This link also appears in a block attribute or custom field. Edit those sources in WordPress, then rescan before using a global action.' );
						}
						if ( ! \current_user_can( 'edit_post', $instance->post_id ) ) {
								throw new \RuntimeException( 'You cannot edit every source post for this link.', 403 );
						}
						$sources[ $instance->post_id ][ $instance->source_type ] = true;
					}
					$settings = \get_option( 'mltr_settings', array() );
					$plans    = array();
					ksort( $sources );
					foreach ( $sources as $post_id => $fields ) {
						$row = $this->wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d FOR UPDATE', $this->wpdb->posts, $post_id ) );
						if ( null === $row || 'publish' !== $row->post_status ) {
							throw new \RuntimeException( 'A source post was removed or unpublished. Rescan before editing this link.' );
						}
						// wpdb returns scalar MySQL columns as strings; WP_Post's raw
						// constructor does not perform get_post()'s normal sanitization.
						$row->ID = (int) $row->ID;
						$post    = new \WP_Post( $row );
						if ( ! \wp_revisions_enabled( $post ) ) {
							throw new \RuntimeException( 'Enable WordPress revisions for every source post type before using a global edit, so the previous content can be restored.' );
						}

						if ( function_exists( 'wp_check_post_lock' ) && \wp_check_post_lock( $post_id ) ) {
							throw new \RuntimeException( 'A source post is currently being edited in WordPress. Retry after the editor has finished.' );
						}
						$fresh = $this->extractor->extract_from_post( $post, $settings );
						foreach ( $fresh[ $link->url_hash ]['instances'] ?? array() as $current ) {
							$source = $current['scan_result']->source_type;
							if ( ! in_array( $source, array( 'post_content', 'post_excerpt' ), true ) ) {
								throw new \RuntimeException( 'This URL now appears in a read-only source. Rescan and edit that source in WordPress.' );
							}
							$fields[ $source ] = true;
						}
						$changes = array();
						foreach ( array_keys( $fields ) as $field ) {
							$content = (string) $post->$field;
							if ( 0 === $this->html->count_matches( $content, $link->url ) ) {
								throw new \RuntimeException( 'The content no longer matches the inventory. Rescan before editing this link.' );
							}
							$updated = $unlink
								? $this->html->unlink_in_html( $content, $link->url )
								: $this->html->replace_link_in_html( $content, $link->url, $url, $rel );
							if ( $updated !== $content ) {
								$changes[ $field ] = $updated;
							}
						}
						$plans[] = array(
							'post'   => $post,
							'fields' => $changes,
						);
					}

					$target_id  = $link->id;
					$target_url = $url ?? $link->url;
					if ( ! $unlink && $target_url !== $link->url ) {
						$existing = $this->links->find_by_hash( hash( 'sha256', $target_url ) );
						if ( null !== $existing ) {
							$target_id = $existing->id;
						} else {
							$affiliate = $this->classifier->detect_affiliate( $target_url );
							if ( ! $this->links->update_url( $link->id, $target_url, LinkType::External === $this->classifier->classify_type( $target_url ), $affiliate['is_affiliate'], $affiliate['network'] ) ) {
								throw new \RuntimeException( 'Could not update the tracked URL.' );
							}
						}
					}
					$revisions = array();
					foreach ( $plans as $plan ) {
						$post = $plan['post'];
						if ( ! $plan['fields'] ) {
							continue;
						}
						// Include this post in cache invalidation even if a hook throws
						// after inserting the revision but before returning its ID.
						$changed_posts[] = $post->ID;
						$revision_id     = $this->html->update_post_fields_silently( $post, $plan['fields'] );
						$revision_ids[]  = $revision_id;
						$revisions[]     = array(
							'postId'     => $post->ID,
							'revisionId' => $revision_id,
						);
						$updated_post    = clone $post;
						foreach ( $plan['fields'] as $field => $value ) {
							$updated_post->$field = $value;
						}
						$this->sync_post( $updated_post, $settings );
					}
					if ( $unlink ) {
						if ( ! $this->links->delete( $link->id, false ) ) {
							throw new \RuntimeException( 'Could not remove the tracked URL.' );
						}
					} else {
						if ( $target_id !== $link->id && ! $this->links->merge_into( $link->id, $target_id ) ) {
								throw new \RuntimeException( 'Could not merge the tracked URLs.' );
						}
						$affiliate = $this->classifier->detect_affiliate( $target_url );
						$this->links->refresh_classification( $target_id, LinkType::External === $this->classifier->classify_type( $target_url ), $affiliate['is_affiliate'], $affiliate['network'] );
					}
					$result = $unlink ? null : $this->links->find( $target_id );
					if ( ! $unlink && null === $result ) {
						throw new \RuntimeException( 'The updated link could not be loaded.' );
					}
					if ( false === $this->wpdb->query( 'COMMIT' ) ) {
						throw new \RuntimeException( 'Could not commit the content changes.' );
					}
					return array(
						'link'          => $result,
						'updated_posts' => count( $changed_posts ),
						'revisions'     => $revisions,
					);
				} catch ( \Throwable $error ) {
					$this->wpdb->query( 'ROLLBACK' );
					throw $error;
				} finally {
					foreach ( array_merge( $changed_posts, $revision_ids ) as $post_id ) {
								\clean_post_cache( $post_id );
					}
				}
			}
		);
	}

	/**
	 * Never promise rollback on a legacy nontransactional WordPress table.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	private function require_transactional_tables(): void {
		$wpdb   = $this->wpdb;
		$tables = array( $this->wpdb->posts, $this->wpdb->prefix . 'mltr_links', $this->wpdb->prefix . 'mltr_instances' );
		$rows   = $this->wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name IN (%s, %s, %s)', $tables[0], $tables[1], $tables[2] ) );
		if ( count( $rows ?? array() ) !== count( $tables ) ) {
			throw new \RuntimeException( 'Could not verify transactional storage; no content was changed.' );
		}
		foreach ( $rows as $row ) {
			if ( 'innodb' !== strtolower( (string) $row->Engine ) ) {
				throw new \RuntimeException( 'Safe editing requires InnoDB for WordPress posts and link tables. Use the WordPress editor until those tables are converted.' );
			}
		}
	}

	/**
	 * Rebuild every occurrence, including per-occurrence rel flags and excerpts.
	 *
	 * @param \WP_Post $post Source WordPress post.
	 * @param array    $settings Plugin scan settings.
	 */
	private function sync_post( \WP_Post $post, array $settings ): void {
		$rows = array();
		foreach ( $this->extractor->extract_from_post( $post, $settings ) as $group ) {
			$id = $this->links->insert_or_get( $group['url'], $group['url_hash'], LinkType::External === $group['type'], $group['is_affiliate'], $group['affiliate_network'] );
			foreach ( $group['instances'] as $instance ) {
				$result = $instance['scan_result'];
				$rows[] = array_merge(
					$instance['rel_flags'],
					array(
						'link_id'       => $id,
						'post_id'       => $post->ID,
						'source_type'   => $result->source_type,
						'anchor_text'   => $result->anchor_text,
						'link_position' => $result->link_position ?? 0,
						'block_name'    => $result->block_name ?? '',
					)
				);
			}
		}
		$this->instances->sync_for_post( $post->ID, $rows, false );
	}
}
