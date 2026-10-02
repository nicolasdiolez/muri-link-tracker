<?php
/** Transaction ownership and bounded instance writes. @package MuriLinkTracker\Tests\Unit */
declare( strict_types=1 );
namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Database\InstancesRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

#[CoversClass(InstancesRepository::class)]
class InstancesRepositoryTest extends TestCase {
	private InstancesRepository $repository;
	private InstancesDatabaseStub $database;

	protected function setUp(): void {
		parent::setUp();
		$this->database = new InstancesDatabaseStub();
		$this->repository = new InstancesRepository( $this->database );
	}

	public function test_bulk_insert_uses_bounded_batches_and_one_transaction(): void {
		$this->database->rows = 0;
		$this->repository->bulk_insert( array_fill( 0, 205, $this->instance() ) );
		$this->assertSame( 205, $this->database->rows );
		$this->assertSame( array( 'START TRANSACTION', 'INSERT', 'INSERT', 'INSERT', 'COMMIT' ), $this->database->commands );
		$this->assertSame( array( 100, 100, 5 ), $this->database->batch_sizes );
		$this->assertCount( 1001, $this->database->prepared[0]['params'] );
		$this->assertCount( 51, $this->database->prepared[2]['params'] );
	}

	public function test_bulk_insert_empty_input_does_not_open_a_transaction(): void {
		$this->repository->bulk_insert( array() );
		$this->assertSame( array(), $this->database->commands );
	}

	public function test_empty_sync_deletes_existing_rows_and_commits(): void {
		$this->repository->sync_for_post( 42, array() );
		$this->assertSame( 0, $this->database->rows );
		$this->assertSame( array( 'START TRANSACTION', 'DELETE', 'COMMIT' ), $this->database->commands );
	}

	public function test_failure_in_a_later_batch_rolls_back_the_delete_and_earlier_inserts(): void {
		$this->database->fail_command = 4;
		try {
			$this->repository->sync_for_post( 42, array_fill( 0, 101, $this->instance() ) );
			$this->fail( 'A failed SQL insert must be surfaced.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Could not update link occurrences.', $error->getMessage() );
		}
		$this->assertSame( array( 'START TRANSACTION', 'DELETE', 'INSERT', 'INSERT', 'ROLLBACK' ), $this->database->commands );
		$this->assertSame( 2, $this->database->rows, 'The original occurrences must survive a failed replacement.' );
	}

	#[DataProvider('managed_failure_positions')]
	public function test_database_failures_never_commit_partial_replacement( int $failure_at, array $commands ): void {
		$this->database->fail_command = $failure_at;
		try {
			$this->repository->sync_for_post( 42, array( $this->instance() ) );
			$this->fail( 'A failed database operation must be surfaced.' );
		} catch ( \RuntimeException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		}
		$this->assertSame( $commands, $this->database->commands );
		$this->assertSame( 2, $this->database->rows );
	}

	public static function managed_failure_positions(): array {
		return array(
			'begin fails before destructive writes' => array( 1, array( 'START TRANSACTION' ) ),
			'delete fails before insert' => array( 2, array( 'START TRANSACTION', 'DELETE', 'ROLLBACK' ) ),
			'commit failure rolls back' => array( 4, array( 'START TRANSACTION', 'DELETE', 'INSERT', 'COMMIT', 'ROLLBACK' ) ),
		);
	}

	public function test_caller_owned_transaction_is_not_started_committed_or_rolled_back_by_sync(): void {
		$this->database->query( 'START TRANSACTION' );
		$this->repository->sync_for_post( 42, array( $this->instance() ), false );
		$this->assertSame( array( 'START TRANSACTION', 'DELETE', 'INSERT' ), $this->database->commands );
		$this->assertSame( 1, $this->database->rows );
		$this->database->query( 'ROLLBACK' );
		$this->assertSame( 2, $this->database->rows );
	}

	public function test_caller_owns_rollback_after_an_insert_failure(): void {
		$this->database->query( 'START TRANSACTION' );
		$this->database->fail_command = 3;
		try {
			$this->repository->sync_for_post( 42, array( $this->instance() ), false );
			$this->fail( 'A failed caller-owned write must be surfaced.' );
		} catch ( \RuntimeException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		}
		$this->assertSame( array( 'START TRANSACTION', 'DELETE', 'INSERT' ), $this->database->commands );
		$this->assertSame( 0, $this->database->rows );
		$this->database->query( 'ROLLBACK' );
		$this->assertSame( 2, $this->database->rows );
	}

	public function test_bulk_insert_can_participate_in_caller_owned_transaction(): void {
		$this->repository->bulk_insert( array( $this->instance() ), false );
		$this->assertSame( array( 'INSERT' ), $this->database->commands );
	}

	public function test_sync_rejects_mismatched_posts_before_deleting_any_occurrences(): void {
		$row = $this->instance();
		$row['post_id'] = 99;
		try {
			$this->repository->sync_for_post( 42, array( $row ) );
			$this->fail( 'An occurrence for a different post must not be inserted.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertSame( 'An occurrence belongs to a different post.', $error->getMessage() );
		}
		$this->assertSame( array(), $this->database->commands );
		$this->assertSame( 2, $this->database->rows );
	}

	public function test_invalid_link_id_restores_previously_deleted_occurrences(): void {
		$row = $this->instance();
		$row['link_id'] = 0;
		try {
			$this->repository->sync_for_post( 42, array( $row ) );
			$this->fail( 'An occurrence without an existing link must not be inserted.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		}
		$this->assertSame( array( 'START TRANSACTION', 'DELETE', 'ROLLBACK' ), $this->database->commands );
		$this->assertSame( 2, $this->database->rows );
	}

	public function test_nullable_position_remains_sql_null_and_anchor_text_is_bound(): void {
		$row = $this->instance();
		$row['link_position'] = null;
		$row['anchor_text'] = "Quoted ' text %d; DROP TABLE wp_posts;";
		$this->repository->bulk_insert( array( $row ), false );
		$query = $this->database->prepared[0];
		$this->assertStringContainsString( ', NULL, %s )', $query['sql'] );
		$this->assertStringNotContainsString( $row['anchor_text'], $query['sql'] );
		$this->assertSame( $row['anchor_text'], $query['params'][4] );
		$this->assertCount( 10, $query['params'] );
	}

	public function test_read_errors_are_not_reported_as_an_empty_occurrence_list(): void {
		$this->database->last_error = 'Database unavailable';
		$this->expectException( \RuntimeException::class );
		$this->repository->find_by_link( 10 );
	}

	private function instance(): array {
		return array(
			'link_id' => 10, 'post_id' => 42, 'source_type' => 'post_content', 'anchor_text' => 'Example',
			'rel_nofollow' => false, 'rel_sponsored' => true, 'rel_ugc' => false, 'is_dofollow' => false,
			'link_position' => 0, 'block_name' => 'core/paragraph',
		);
	}
}

/** Models transactional row counts and injects SQL failures without a database service. */
class InstancesDatabaseStub extends \wpdb {
	public array $commands = array();
	public array $prepared = array();
	public array $batch_sizes = array();
	public int $rows = 2;
	public int $fail_command = -1;
	private ?int $transaction_rows = null;

	public function prepare( $query, ...$args ): string {
		$this->prepared[] = array( 'sql' => $query, 'params' => $args );
		return $query;
	}

	public function query( $query ): int|bool {
		$command = str_starts_with( $query, 'INSERT' ) ? 'INSERT' : ( str_starts_with( $query, 'DELETE' ) ? 'DELETE' : $query );
		$this->commands[] = $command;
		if ( count( $this->commands ) === $this->fail_command ) {
			return false;
		}
		switch ( $command ) {
			case 'START TRANSACTION':
				$this->transaction_rows = $this->rows;
				break;
			case 'ROLLBACK':
				$this->rows = $this->transaction_rows ?? $this->rows;
				$this->transaction_rows = null;
				break;
			case 'COMMIT':
				$this->transaction_rows = null;
				break;
			case 'DELETE':
				$this->rows = 0;
				break;
			case 'INSERT':
				$count = substr_count( substr( $query, strpos( $query, 'VALUES ' ) ), '(' );
				$this->batch_sizes[] = $count;
				$this->rows += $count;
				break;
		}
		return 1;
	}
}
