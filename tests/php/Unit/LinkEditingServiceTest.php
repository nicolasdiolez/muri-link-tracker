<?php

declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Models\Link;
use MuriLinkTracker\Models\LinkInstance;
use MuriLinkTracker\Scanner\LinkEditingService;
use MuriLinkTracker\Scanner\LinkHtmlEditor;
use PHPUnit\Framework\TestCase;

/** Exercises the content/inventory transaction with failures at its boundaries. */
final class LinkEditingServiceTest extends TestCase {
	private EditingDatabase $db;
	private LinksRepository $links;
	private InstancesRepository $instances;
	private LinkEditingService $service;

	protected function setUp(): void {
		$GLOBALS['mltr_test_caps'] = array();
		$GLOBALS['mltr_test_options'] = array();
		$GLOBALS['mltr_test_revisions'] = array();
		unset( $GLOBALS['mltr_test_revision_result'], $GLOBALS['mltr_test_blocks'], $GLOBALS['mltr_test_revisions_enabled'] );
		$this->db = new EditingDatabase();
		$this->db->post = new \WP_Post( (object) array(
			'ID' => 5, 'post_status' => 'publish',
			'post_content' => '<!-- unchanged --><p><a href="https://old.test" rel="sponsored noopener"><img src="/a.jpg"></a></p>',
			'post_excerpt' => '<a href="https://old.test" rel="external">Excerpt</a>',
		) );
		$this->links = $this->createMock( LinksRepository::class );
		$this->instances = $this->createMock( InstancesRepository::class );
		$this->db->link_row = self::link_row();
		$this->service = new LinkEditingService( $this->db, $this->links, $this->instances, new LinkHtmlEditor( $this->db ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mltr_test_caps'], $GLOBALS['mltr_test_options'], $GLOBALS['mltr_test_revisions'], $GLOBALS['mltr_test_revision_result'], $GLOBALS['mltr_test_blocks'], $GLOBALS['mltr_test_revisions_enabled'] );
	}

	public function test_url_collision_rebuilds_content_and_excerpt_into_existing_link(): void {
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ), self::instance( 'post_excerpt' ) ) );
		$destination = Link::from_db_row( self::link_row( 2, 'https://new.test' ) );
		$this->links->method( 'find_by_hash' )->willReturn( $destination );
		$this->links->method( 'find' )->with( 2 )->willReturn( $destination );
		$this->links->method( 'insert_or_get' )->willReturn( 2 );
		$this->links->expects( $this->never() )->method( 'update_url' );
		$this->links->expects( $this->once() )->method( 'merge_into' )->with( 1, 2 )->willReturn( true );
		$this->instances->expects( $this->once() )->method( 'sync_for_post' )->with( 5, $this->callback( function ( array $rows ): bool {
			$this->assertCount( 2, $rows );
			$this->assertSame( array( 'post_content', 'post_excerpt' ), array_column( $rows, 'source_type' ) );
			$this->assertTrue( $rows[0]['rel_sponsored'] );
			$this->assertFalse( $rows[1]['rel_sponsored'] );
			$this->assertSame( array( 2, 2 ), array_column( $rows, 'link_id' ) );
			return true;
		} ), false );

		$result = $this->service->edit( Link::from_db_row( $this->db->link_row ), 'https://new.test', null );
		$this->assertSame( 2, $result['link']->id );
		$this->assertSame( 1, $result['updated_posts'] );
		$this->assertCount( 1, $result['revisions'] );
		$this->assertStringContainsString( 'rel="sponsored noopener"', $this->db->post_writes[0][1] );
		$this->assertStringContainsString( 'https://new.test', $this->db->post_writes[0][2] );
		$this->assertContains( 'COMMIT', $this->db->queries );
		$this->assertNotContains( 'ROLLBACK', $this->db->queries );
		$this->assertStringContainsString( 'AND BINARY post_content = BINARY %s', $this->db->write_sql );
		$this->assertStringNotContainsString( 'SET post_modified', $this->db->write_sql );
	}

	public function test_unlink_keeps_image_and_returns_recovery_revision(): void {
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ), self::instance( 'post_excerpt' ) ) );
		$this->links->expects( $this->once() )->method( 'delete' )->with( 1, false )->willReturn( true );
		$this->instances->expects( $this->once() )->method( 'sync_for_post' )->with( 5, array(), false );
		$result = $this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
		$this->assertSame( '<!-- unchanged --><p><img src="/a.jpg"></p>', $this->db->post_writes[0][1] );
		$this->assertSame( 'Excerpt', $this->db->post_writes[0][2] );
		$this->assertCount( 1, $result['revisions'] );
	}

	public function test_read_only_source_prevents_every_write(): void {
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ), self::instance( 'block_attribute' ) ) );
		$this->links->expects( $this->never() )->method( 'delete' );
		try {
			$this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
			$this->fail( 'A mixed-source link must not be partially unlinked.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'block attribute', $error->getMessage() );
		}
		$this->assertSame( array(), $this->db->post_writes );
		$this->assertSame( array(), $GLOBALS['mltr_test_revisions'] );
		$this->assertContains( 'ROLLBACK', $this->db->queries );
	}

	public function test_per_post_permission_failure_prevents_any_write(): void {
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ) ) );
		$GLOBALS['mltr_test_caps']['edit_post:5'] = false;
		try {
			$this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
			$this->fail( 'Global admin capabilities cannot bypass a per-post denial.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'cannot edit every', $error->getMessage() );
		}
		$this->assertSame( array(), $this->db->post_writes );
		$this->assertContains( 'ROLLBACK', $this->db->queries );
	}

	public function test_instance_failure_rolls_back_content_and_revision_transaction(): void {
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ) ) );
		$this->instances->method( 'sync_for_post' )->willThrowException( new \RuntimeException( 'Simulated instance insert failure.' ) );
		try {
			$this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
			$this->fail( 'A failed inventory write must fail the content operation.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Simulated instance insert failure.', $error->getMessage() );
		}
		$this->assertContains( 'ROLLBACK', $this->db->queries );
		$this->assertNotContains( 'COMMIT', $this->db->queries );
	}

	public function test_concurrent_post_change_cannot_be_overwritten(): void {
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ) ) );
		$this->db->post_write_result = 0;
		$this->instances->expects( $this->never() )->method( 'sync_for_post' );
		try {
			$this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
			$this->fail( 'A compare-and-swap failure must not commit.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'concurrently', $error->getMessage() );
		}
		$this->assertContains( 'ROLLBACK', $this->db->queries );
		$this->assertNotContains( 'COMMIT', $this->db->queries );
	}

	public function test_missing_revision_prevents_post_write(): void {
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ) ) );
		$GLOBALS['mltr_test_revision_result'] = new \WP_Error( 'no_revision', 'Insert failed' );
		$this->expectException( \RuntimeException::class );
		try {
			$this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
		} finally {
			$this->assertSame( array(), $this->db->post_writes );
			$this->assertNotContains( 'COMMIT', $this->db->queries );
		}
	}

	public function test_disabled_revisions_prevent_an_inaccessible_recovery_snapshot(): void {
		$GLOBALS['mltr_test_revisions_enabled'] = false;
		$this->instances->method( 'find_by_link' )->willReturn( array( self::instance( 'post_content' ) ) );
		$this->expectException( \RuntimeException::class );
		try {
			$this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
		} finally {
			$this->assertSame( array(), $this->db->post_writes );
			$this->assertSame( array(), $GLOBALS['mltr_test_revisions'] );
		}
	}

	public function test_nontransactional_posts_are_refused_before_any_write(): void {
		$this->db->engine = 'MyISAM';
		$this->expectException( \RuntimeException::class );
		try {
			$this->service->edit( Link::from_db_row( $this->db->link_row ), null, null, true );
		} finally {
			$this->assertSame( array(), $this->db->queries );
		}
	}

	public static function link_row( int $id = 1, string $url = 'https://old.test' ): object {
		return (object) array( 'id' => $id, 'url' => $url, 'url_hash' => hash( 'sha256', $url ), 'final_url' => null, 'http_status' => 200, 'status_category' => 'ok', 'is_external' => 1, 'is_affiliate' => 1, 'affiliate_network' => null, 'response_time' => 100, 'redirect_count' => 0, 'redirect_chain' => null, 'last_checked' => '2026-01-01 00:00:00', 'check_count' => 1, 'last_error' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' );
	}

	private static function instance( string $source ): LinkInstance {
		return new LinkInstance( 1, 1, 5, $source, '', false, true, false, false, 0, null, new \DateTimeImmutable() );
	}
}

final class EditingDatabase extends \wpdb {
	public object $link_row;
	public \WP_Post $post;
	public array $queries = array();
	public array $post_writes = array();
	public string $write_sql = '';
	public int|false $post_write_result = 1;
	public string $engine = 'InnoDB';
	private array $prepared = array();

	public function prepare( $query, ...$args ): string {
		$this->prepared[ $query ] = $args;
		return $query;
	}
	public function get_var( $query = null, $x = 0, $y = 0 ): mixed { return 1; }
	public function get_results( $query = null, $output = 'OBJECT' ): array {
		return array_fill( 0, 3, (object) array( 'Engine' => $this->engine ) );
	}
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ): ?object {
		return ( $this->prepared[ $query ][0] ?? '' ) === $this->posts
			? (object) array_merge( get_object_vars( $this->post ), array( 'ID' => (string) $this->post->ID ) )
			: $this->link_row;
	}
	public function query( $query ): int|bool {
		$this->queries[] = $query;
		if ( str_starts_with( $query, 'UPDATE %i SET post_' ) ) {
			$this->write_sql = $query;
			$this->post_writes[] = $this->prepared[ $query ];
			return $this->post_write_result;
		}
		return 1;
	}
}
