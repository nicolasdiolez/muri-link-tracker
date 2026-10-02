<?php
/** Failed reads must never look like an empty, successfully finished queue. */
declare( strict_types=1 );
namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Queue\ScanStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FailingQueueDatabase extends \wpdb {
	public string $options = 'wp_options';
	public function get_var( $query = null, $x = 0, $y = 0 ): mixed { $this->last_error = 'Injected database outage'; return null; }
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ): ?object { $this->last_error = 'Injected database outage'; return null; }
	public function get_results( $query = null, $output = 'OBJECT' ): array { $this->last_error = 'Injected database outage'; return array(); }
	public function get_col( $query = null ): array { $this->last_error = 'Injected database outage'; return array(); }
}

class ScanStoreFailureTest extends TestCase {
	public static function failed_reads(): array {
		return array(
			'current run' => array( 'current_run', array() ),
			'run by ID' => array( 'find_run', array( 'scan' ) ),
			'job by ID' => array( 'find_job', array( 1 ) ),
			'pending jobs' => array( 'pending_jobs', array( 'scan' ) ),
			'job aggregates' => array( 'aggregate', array( 'scan', 'checking' ) ),
			'unfinished work' => array( 'work_state', array( 'scan', 'checking' ) ),
			'post planning' => array( 'next_post_ids', array( array( 'scan_cursor' => 0, 'scan_type' => 'full' ), array( 'post' ), 10 ) ),
			'manual cancellation token' => array( 'manual_generation', array() ),
		);
	}

	#[DataProvider( 'failed_reads' )]
	public function test_database_read_failures_propagate( string $method, array $args ): void {
		$store = new ScanStore( new FailingQueueDatabase() );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'could not be read' );
		$store->$method( ...$args );
	}
}
