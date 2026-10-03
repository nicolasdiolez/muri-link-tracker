<?php

declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Database\LinksRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LinksRepositoryTest extends TestCase {
	public function test_failed_insert_never_returns_zero_as_a_valid_link_id(): void {
		$db = new LinksWriteDatabase();
		$db->fail_prefix = 'INSERT INTO';
		$this->expectException( \RuntimeException::class );
		( new LinksRepository( $db ) )->insert_or_get( '/page', hash( 'sha256', '/page' ), false, false, null );
	}

	public function test_bulk_insert_rolls_back_when_one_statement_fails(): void {
		$db = new LinksWriteDatabase();
		$db->fail_prefix = 'INSERT INTO';
		try {
			( new LinksRepository( $db ) )->bulk_insert( array( array( 'url' => '/page', 'url_hash' => hash( 'sha256', '/page' ), 'is_external' => false, 'is_affiliate' => false, 'affiliate_network' => null ) ) );
			$this->fail( 'A failed SQL insert must propagate.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'inventory', $error->getMessage() );
		}
		$this->assertContains( 'START TRANSACTION', $db->queries );
		$this->assertContains( 'ROLLBACK', $db->queries );
		$this->assertNotContains( 'COMMIT', $db->queries );
	}

	public function test_delete_cannot_commit_after_instance_deletion_failure(): void {
		$db = new LinksWriteDatabase();
		$db->fail_prefix = 'DELETE FROM %i WHERE link_id';
		try {
			( new LinksRepository( $db ) )->delete( 5 );
			$this->fail( 'A failed instance deletion must propagate.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'instances', $error->getMessage() );
		}
		$this->assertContains( 'ROLLBACK', $db->queries );
		$this->assertNotContains( 'DELETE FROM %i WHERE id = %d', $db->queries );
		$this->assertNotContains( 'COMMIT', $db->queries );
	}

	public function test_merge_never_deletes_source_after_failed_reference_transfer(): void {
		$db = new LinksWriteDatabase();
		$db->fail_prefix = 'UPDATE %i SET link_id';
		$this->assertFalse( ( new LinksRepository( $db ) )->merge_into( 1, 2 ) );
		$this->assertCount( 1, $db->queries );
	}

	public function test_reset_deletion_failure_never_looks_like_an_empty_table(): void {
		$db = new LinksWriteDatabase();
		$db->fail_prefix = 'DELETE FROM';
		$this->expectException( \RuntimeException::class );
		( new LinksRepository( $db ) )->truncate();
	}

	#[DataProvider( 'read_failure_operations' )]
	public function test_sql_read_failure_never_becomes_an_empty_success( string $method, array $arguments ): void {
		$db = new LinksWriteDatabase();
		$db->read_error = 'Simulated SELECT permission failure';
		$repo = new LinksRepository( $db );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Could not read the link inventory.' );
		$repo->$method( ...$arguments );
	}

	public static function read_failure_operations(): array {
		return array(
			'cursor must not complete a scan' => array( 'find_check_ids_after', array( 0, 50, 7 ) ),
			'checkable count must not report zero' => array( 'count_checkable', array() ),
			'ID lookup must not pretend a missing link' => array( 'find', array( 1 ) ),
			'hash lookup must not pretend a new URL' => array( 'find_by_hash', array( hash( 'sha256', '/test' ) ) ),
			'batch lookup must not drop all links' => array( 'find_by_ids', array( array( 1, 2 ) ) ),
			'stale lookup must not skip work' => array( 'find_pending_or_stale', array( 50, 7 ) ),
			'status statistics must not become empty' => array( 'count_by_status', array() ),
			'network statistics must not become empty' => array( 'count_by_network', array() ),
			'category statistics must not become zero' => array( 'get_category_stats', array() ),
		);
	}

	public function test_successful_empty_queries_keep_their_normal_meaning(): void {
		$repo = new LinksRepository( new LinksWriteDatabase() );
		$this->assertSame( array(), $repo->find_check_ids_after( 0, 50 ) );
		$this->assertSame( 0, $repo->count_checkable() );
		$this->assertNull( $repo->find( 1 ) );
		$this->assertNull( $repo->find_by_hash( hash( 'sha256', '/missing' ) ) );
		$this->assertSame( array(), $repo->find_by_ids( array( 1, 2 ) ) );
		$this->assertSame( array(), $repo->find_pending_or_stale( 50 ) );
		$this->assertSame( array(), $repo->count_by_status() );
		$this->assertSame( array(), $repo->count_by_network() );
		$this->assertSame( 0, $repo->get_category_stats()['total'] );
	}

	public function test_failed_orphan_cleanup_never_reports_zero_deleted(): void {
		$db = new LinksWriteDatabase();
		$db->fail_prefix = 'DELETE l';
		$this->expectException( \RuntimeException::class );
		( new LinksRepository( $db ) )->cleanup_orphans();
	}

	public function test_cursor_selection_requires_live_instances_and_respects_staleness(): void {
		$db = new LinksWriteDatabase();
		$db->rows = array( (object) array( 'id' => '8' ), (object) array( 'id' => '10' ) );
		$repo = new LinksRepository( $db );
		$this->assertSame( array( 8, 10 ), $repo->find_check_ids_after( 5, 2, 7 ) );
		$this->assertStringContainsString( 'EXISTS (SELECT 1', $db->last_sql );
		$this->assertStringContainsString( 'l.id > %d', $db->last_sql );
		$this->assertStringContainsString( 'INTERVAL %d DAY', $db->last_sql );
		$this->assertSame( array( 'wp_mltr_links', 5, 'wp_mltr_instances', 7, 2 ), $db->last_args );
		$repo->find_check_ids_after( 5, 2, 7, true );
		$this->assertStringNotContainsString( 'INTERVAL', $db->last_sql );
		$this->assertStringContainsString( 'EXISTS (SELECT 1', $db->last_sql );
	}
}

final class LinksWriteDatabase extends \wpdb {
	public array $queries = array();
	public array $rows = array();
	public string $fail_prefix = 'never';
	public string $read_error = '';
	public string $last_sql = '';
	public array $last_args = array();
	public function prepare( $query, ...$args ): string {
		$this->last_sql = $query;
		$this->last_args = $args;
		return $query;
	}
	public function query( $query ): int|bool {
		$this->queries[] = $query;
		return str_starts_with( $query, $this->fail_prefix ) ? false : 1;
	}
	public function get_results( $query = null, $output = 'OBJECT' ): array {
		$this->last_error = $this->read_error;
		return '' !== $this->read_error ? array() : $this->rows;
	}
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ): ?object {
		$this->last_error = $this->read_error;
		return null;
	}
	public function get_var( $query = null, $x = 0, $y = 0 ): mixed {
		$this->last_error = $this->read_error;
		return '0';
	}
}
