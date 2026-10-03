<?php
/** Lifecycle tests keep WordPress/AS boundaries explicit and execute the real jobs. */
declare( strict_types=1 );

namespace MuriLinkTracker\Queue {
	function function_exists( string $name ): bool { return str_starts_with( $name, 'as_' ) || \function_exists( $name ); }
	function get_option( string $key, mixed $default = false ): mixed { return \MuriLinkTracker\Tests\Unit\QueueHarness::$options[ $key ] ?? $default; }
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool { \MuriLinkTracker\Tests\Unit\QueueHarness::$options[ $key ] = $value; return true; }
	function delete_option( string $key ): bool { unset( \MuriLinkTracker\Tests\Unit\QueueHarness::$options[ $key ] ); return true; }
	function delete_transient( string $key ): bool { return true; }
	function wp_generate_uuid4(): string { return 'test-run-' . ++\MuriLinkTracker\Tests\Unit\QueueHarness::$sequence; }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
	function do_action( string $hook, mixed ...$args ): void { \MuriLinkTracker\Tests\Unit\QueueHarness::$events[] = $hook; }
	function wp_suspend_cache_addition( ?bool $value = null ): bool { static $suspended = false; if ( null !== $value ) { $suspended = $value; } return $suspended; }
	function wp_convert_hr_to_bytes( string $value ): int { return 1024 * 1024 * 512; }
	function as_get_scheduled_actions( array $query, string $format ): array {
		$ids = array();
		foreach ( \MuriLinkTracker\Tests\Unit\QueueHarness::$actions as $id => $action ) {
			if ( ( ! isset( $query['hook'] ) || $query['hook'] === $action['hook'] ) && ( ! isset( $query['args'] ) || $query['args'] === $action['args'] ) ) { $ids[] = $id; }
		}
		return $ids;
	}
	function as_enqueue_async_action( string $hook, array $args, string $group ): int {
		$id = ++\MuriLinkTracker\Tests\Unit\QueueHarness::$sequence;
		\MuriLinkTracker\Tests\Unit\QueueHarness::$actions[ $id ] = array( 'hook' => $hook, 'args' => $args );
		return $id;
	}
	function as_schedule_single_action( int $when, string $hook, array $args, string $group ): int { return as_enqueue_async_action( $hook, $args, $group ); }
	function as_next_scheduled_action( string $hook, array $args, string $group ): int|false { $ids = as_get_scheduled_actions( array( 'hook' => $hook ), 'ids' ); return $ids ? $ids[0] : false; }
	function as_schedule_recurring_action( int $when, int $interval, string $hook, array $args, string $group ): int { return as_enqueue_async_action( $hook, $args, $group ); }
}

namespace MuriLinkTracker\Tests\Unit {
	use MuriLinkTracker\Database\InstancesRepository;
	use MuriLinkTracker\Database\LinksRepository;
	use MuriLinkTracker\Models\Enums\LinkStatus;
	use MuriLinkTracker\Models\Link;
	use MuriLinkTracker\Queue\BatchOrchestrator;
	use MuriLinkTracker\Queue\CheckJob;
	use MuriLinkTracker\Queue\ScanJob;
	use MuriLinkTracker\Queue\ScanStore;
	use MuriLinkTracker\Queue\SchedulerBootstrap;
	use MuriLinkTracker\Scanner\HttpChecker;
	use MuriLinkTracker\Scanner\InternalLinkChecker;
	use MuriLinkTracker\Scanner\LinkExtractor;
	use PHPUnit\Framework\TestCase;

	class QueueHarness {
		public static array $options = array();
		public static array $actions = array();
		public static array $events = array();
		public static int $sequence = 0;
	}

	class MemoryScanStore extends ScanStore {
		public ?array $run = null;
		public array $jobs = array();
		public array $posts = array();
		public string $generation = 'initial';
		public function __construct() {}
		public function exclusive( callable $callback ): mixed { return $callback(); }
		public function transaction( callable $callback ): mixed { return $callback(); }
		public function current_run(): ?array { return $this->run; }
		public function find_run( string $id ): ?array { return isset( $this->run['id'] ) && $id === $this->run['id'] ? $this->run : null; }
		public function is_active( string $id ): bool { return null !== $this->run && $id === $this->run['id'] && 'running' === $this->run['status']; }
		public function create_run( string $type, string $phase ): array {
			$this->run = array( 'id' => \MuriLinkTracker\Queue\wp_generate_uuid4(), 'scan_type' => $type, 'phase' => $phase, 'status' => 'running', 'planning_done' => 0, 'scan_cursor' => 0, 'check_cursor' => 0, 'started_at' => '2026-10-02 12:00:00', 'last_scan_date' => null );
			return $this->run;
		}
		public function update_run( string $id, array $data ): void { if ( $this->find_run( $id ) ) { $this->run = array_merge( $this->run, $data ); } }
		public function create_job( string $id, string $phase, array $ids ): int {
			$job_id = count( $this->jobs ) + 1;
			$this->jobs[ $job_id ] = array( 'id' => $job_id, 'scan_id' => $id, 'phase' => $phase, 'item_ids' => $ids, 'item_count' => count( $ids ), 'completed_items' => 0, 'status' => 'pending', 'attempts' => 0, 'lease_token' => null );
			return $job_id;
		}
		public function find_job( int $id ): ?array { return $this->jobs[ $id ] ?? null; }
		public function claim_job( int $id, string $token ): ?array {
			$job = $this->find_job( $id );
			if ( null === $job || ! $this->is_active( $job['scan_id'] ) || 'pending' !== $job['status'] ) { return null; }
			$this->jobs[ $id ]['status'] = 'running'; $this->jobs[ $id ]['lease_token'] = $token;
			return $this->jobs[ $id ];
		}
		public function owns_job( int $id, string $token ): bool { $j = $this->find_job( $id ); return null !== $j && $this->is_active( $j['scan_id'] ) && 'running' === $j['status'] && $token === $j['lease_token']; }
		public function checkpoint( int $id, string $token, int $offset, bool $complete = false ): void { if ( ! $this->owns_job( $id, $token ) ) { throw new \RuntimeException( 'revoked' ); } $this->jobs[ $id ]['completed_items'] = $offset; $this->jobs[ $id ]['status'] = $complete ? 'complete' : 'running'; }
		public function release_job( int $id, string $token ): void { if ( $this->owns_job( $id, $token ) ) { $this->jobs[ $id ]['status'] = 'pending'; } }
		public function fail_job( int $id, string $token, string $message ): void { if ( $this->owns_job( $id, $token ) ) { ++$this->jobs[ $id ]['attempts']; $this->jobs[ $id ]['status'] = $this->jobs[ $id ]['attempts'] < 3 ? 'pending' : 'failed'; } }
		public function recover_expired( string $id ): void {}
		public function resume_jobs( string $id ): void { foreach ( $this->jobs as &$job ) { if ( $id === $job['scan_id'] && in_array( $job['status'], array( 'failed', 'running' ), true ) ) { $job['status'] = 'pending'; $job['attempts'] = 0; $job['lease_token'] = null; } } }
		public function pending_jobs( string $id, int $limit = 100 ): array { return array_values( array_filter( $this->jobs, fn( $j ) => $id === $j['scan_id'] && 'pending' === $j['status'] ) ); }
		public function aggregate( string $id, string $phase ): array {
			$counts = array( 'total_items' => 0, 'processed' => 0, 'pending' => 0, 'running' => 0, 'failed' => 0, 'total_jobs' => 0 );
			foreach ( $this->jobs as $j ) { if ( $id === $j['scan_id'] && $phase === $j['phase'] ) { $counts['total_items'] += $j['item_count']; $counts['processed'] += $j['completed_items']; ++$counts['total_jobs']; if ( isset( $counts[ $j['status'] ] ) ) { ++$counts[ $j['status'] ]; } } }
			return $counts;
		}
		public function work_state( string $id, string $phase ): array { $counts = $this->aggregate( $id, $phase ); return array( 'failed' => $counts['failed'] > 0, 'unfinished' => $counts['pending'] + $counts['running'] > 0 ); }
		public function next_post_ids( array $run, array $types, int $limit ): array { return array_slice( array_values( array_filter( $this->posts, fn( $id ) => $id > $run['scan_cursor'] ) ), 0, $limit ); }
		public function delete_runs(): void { $this->run = null; $this->jobs = array(); }
		public function manual_generation(): string { return $this->generation; }
		public function invalidate_manual_checks(): void { $this->generation = 'reset'; }
		public function prune(): void {}
	}

	class QueueLifecycleTest extends TestCase {
		private MemoryScanStore $store;
		private LinksRepository $links;
		private InstancesRepository $instances;
		private BatchOrchestrator $orchestrator;

		protected function setUp(): void {
			QueueHarness::$actions = QueueHarness::$options = QueueHarness::$events = array();
			QueueHarness::$options['mltr_settings'] = array( 'http_request_delay' => 0, 'recheck_interval' => 7 );
			if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
			$this->store = new MemoryScanStore();
			$this->links = $this->createMock( LinksRepository::class );
			$this->links->method( 'get_category_stats' )->willReturn( array_fill_keys( array( 'total', 'ok_count', 'broken_count', 'redirect_count', 'error_count', 'timeout_count', 'skipped_count', 'pending_count' ), 0 ) );
			$this->instances = $this->createMock( InstancesRepository::class );
			$this->orchestrator = new BatchOrchestrator( $this->links, $this->instances, $this->store );
		}

		public function test_status_is_read_only_and_running_job_prevents_completion(): void {
			$run = $this->store->create_run( 'full', 'checking' );
			$this->store->update_run( $run['id'], array( 'planning_done' => 1 ) );
			$id = $this->store->create_job( $run['id'], 'checking', array( 1 ) );
			$this->store->claim_job( $id, 'worker' );
			$status = $this->orchestrator->get_status();
			self::assertSame( 'running', $status['status'] );
			self::assertSame( 1, $status['running_jobs'] );
			self::assertSame( array(), QueueHarness::$actions );
			$this->orchestrator->advance( $run['id'] );
			self::assertSame( 'running', $this->store->run['status'] );
		}

		public function test_finished_extraction_schedules_server_transition_without_status_poll(): void {
			$run = $this->store->create_run( 'delta', 'scanning' );
			$this->store->update_run( $run['id'], array( 'planning_done' => 1 ) );
			$id = $this->store->create_job( $run['id'], 'scanning', array( 42 ) );
			$extractor = $this->createMock( LinkExtractor::class );
			$extractor->method( 'extract_from_post' )->willReturn( array() );
			( new ScanJob( $extractor, $this->links, $this->instances, $this->store ) )->process_batch( $id );
			self::assertSame( 'complete', $this->store->jobs[ $id ]['status'] );
			self::assertContains( SchedulerBootstrap::COORDINATOR_HOOK, array_column( QueueHarness::$actions, 'hook' ) );
			$this->orchestrator->advance( $run['id'] );
			self::assertSame( 'checking', $this->store->run['phase'] );
			$this->links->expects( self::once() )->method( 'find_check_ids_after' )->with( 0, 100, 7, false )->willReturn( array() );
			$this->orchestrator->advance( $run['id'] );
			self::assertSame( 'complete', $this->store->run['status'] );
			self::assertSame( $run['started_at'], QueueHarness::$options['mltr_last_scan_date'] );
		}

		public function test_extraction_failure_is_bounded_and_resumable(): void {
			$run = $this->store->create_run( 'full', 'scanning' );
			$this->store->update_run( $run['id'], array( 'planning_done' => 1 ) );
			$id = $this->store->create_job( $run['id'], 'scanning', array( 42 ) );
			$extractor = $this->createMock( LinkExtractor::class );
			$extractor->method( 'extract_from_post' )->willThrowException( new \RuntimeException( 'parser failed' ) );
			$job = new ScanJob( $extractor, $this->links, $this->instances, $this->store );
			for ( $i = 0; $i < 4; ++$i ) { $job->process_batch( $id ); }
			self::assertSame( 3, $this->store->jobs[ $id ]['attempts'] );
			self::assertSame( 'failed', $this->store->jobs[ $id ]['status'] );
			$this->orchestrator->advance( $run['id'] );
			self::assertSame( 'error', $this->store->run['status'] );
			self::assertTrue( $this->orchestrator->resume() );
			self::assertSame( 'pending', $this->store->jobs[ $id ]['status'] );
			self::assertSame( 0, $this->store->jobs[ $id ]['attempts'] );
		}

		public function test_reset_during_http_fences_late_result(): void {
			$run = $this->store->create_run( 'full', 'checking' );
			$id = $this->store->create_job( $run['id'], 'checking', array( 8 ) );
			$this->links->method( 'find' )->willReturn( $this->link() );
			$this->links->expects( self::never() )->method( 'update_check_result' );
			$http = $this->createMock( HttpChecker::class );
			$http->method( 'check' )->willReturnCallback( function (): array { $this->orchestrator->reset(); return $this->checkResult(); } );
			( new CheckJob( $http, $this->createMock( InternalLinkChecker::class ), $this->links, $this->store ) )->process_batch( $id );
			self::assertNull( $this->store->current_run() );
			self::assertSame( 'reset', $this->store->manual_generation() );
		}

		public function test_cancel_and_resume_revoke_old_worker_even_when_run_id_is_reused(): void {
			$run = $this->store->create_run( 'full', 'checking' );
			$id = $this->store->create_job( $run['id'], 'checking', array( 8 ) );
			$this->links->method( 'find' )->willReturn( $this->link() );
			$this->links->expects( self::never() )->method( 'update_check_result' );
			$http = $this->createMock( HttpChecker::class );
			$http->method( 'check' )->willReturnCallback( function (): array { $this->orchestrator->cancel(); $this->orchestrator->resume(); return $this->checkResult(); } );
			( new CheckJob( $http, $this->createMock( InternalLinkChecker::class ), $this->links, $this->store ) )->process_batch( $id );
			self::assertSame( 'pending', $this->store->jobs[ $id ]['status'] );
			self::assertSame( 0, $this->store->jobs[ $id ]['completed_items'] );
		}

		public function test_recurring_actions_are_real_and_idempotent(): void {
			SchedulerBootstrap::ensure_recurring_actions();
			SchedulerBootstrap::ensure_recurring_actions();
			self::assertCount( 3, QueueHarness::$actions );
			self::assertContains( SchedulerBootstrap::RECHECK_DAILY_HOOK, array_column( QueueHarness::$actions, 'hook' ) );
			self::assertContains( SchedulerBootstrap::CLEANUP_HOOK, array_column( QueueHarness::$actions, 'hook' ) );
		}

		public function test_daily_recheck_preserves_cancelled_work_and_uses_real_interval(): void {
			$run = $this->store->create_run( 'full', 'scanning' );
			$this->orchestrator->cancel();
			$this->orchestrator->recheck_stale_links();
			self::assertSame( $run['id'], $this->store->run['id'] );
			$this->store->run = null;
			$this->orchestrator->recheck_stale_links();
			$this->links->expects( self::once() )->method( 'find_check_ids_after' )->with( 0, 100, 7, false )->willReturn( array( 8 ) );
			$this->orchestrator->advance( $this->store->run['id'] );
			self::assertSame( 'recheck', $this->store->run['scan_type'] );
			self::assertSame( array( 8 ), $this->store->jobs[1]['item_ids'] );
		}

		public static function rescan_cases(): array { return array( 'last occurrence removed' => array( false ), 'old and new URLs' => array( true ) ); }

		#[\PHPUnit\Framework\Attributes\DataProvider( 'rescan_cases' )]
		public function test_rescan_reclassifies_old_and_new_urls_after_replacing_instances( bool $has_new_url ): void {
			$run = $this->store->create_run( 'full', 'scanning' );
			$id = $this->store->create_job( $run['id'], 'scanning', array( 42 ) );
			$old = new \MuriLinkTracker\Models\LinkInstance( 1, 8, 42, 'post_content', 'old', false, true, false, false, 0, null, new \DateTimeImmutable() );
			$this->instances->method( 'find_by_post' )->willReturn( array( $old ) );
			$synced = false;
			$this->instances->method( 'sync_for_post' )->willReturnCallback( static function () use ( &$synced ): void { $synced = true; } );
			$new = new Link( 9, 'https://public.example/new', 'newhash', null, null, LinkStatus::Pending, true, true, null, null, 0, null, null, 0, null, new \DateTimeImmutable(), new \DateTimeImmutable() );
			$expected_ids = $has_new_url ? array( 8, 9 ) : array( 8 );
			$this->links->method( 'find_by_ids' )->with( $expected_ids )->willReturn( $has_new_url ? array( $this->link(), $new ) : array( $this->link() ) );
			$this->links->method( 'insert_or_get' )->willReturn( 9 );
			$refreshed = array();
			$this->links->method( 'refresh_classification' )->willReturnCallback( function ( int $link_id, bool $external, bool $url_affiliate, ?string $network ) use ( &$refreshed, &$synced ): void {
				self::assertTrue( $synced, 'Refresh must read the new occurrence flags.' );
				self::assertFalse( $url_affiliate, 'Do not treat the extracted sponsored hint as a URL-derived affiliate signal.' );
				$refreshed[] = $link_id;
			} );
			$extractor = $this->createMock( LinkExtractor::class );
			$extractor->method( 'extract_from_post' )->willReturn( $has_new_url ? array( array(
				'url' => $new->url, 'url_hash' => $new->url_hash, 'type' => \MuriLinkTracker\Models\Enums\LinkType::External, 'is_affiliate' => true, 'affiliate_network' => null,
				'instances' => array( array( 'scan_result' => new \MuriLinkTracker\Models\ScanResult( $new->url, 'new', 'sponsored', 'post_content', 0, null ), 'rel_flags' => array( 'rel_nofollow' => false, 'rel_sponsored' => true, 'rel_ugc' => false, 'is_dofollow' => false ) ) ),
			) ) : array() );
			( new ScanJob( $extractor, $this->links, $this->instances, $this->store ) )->process_batch( $id );
			self::assertSame( $expected_ids, $refreshed );
			self::assertSame( 'complete', $this->store->jobs[ $id ]['status'] );
		}

		private function link(): Link { return new Link( 8, 'https://public.example/path', 'hash', null, null, LinkStatus::Pending, true, false, null, null, 0, null, null, 0, null, new \DateTimeImmutable(), new \DateTimeImmutable() ); }
		private function checkResult(): array { return array( 'http_status' => 200, 'status_category' => LinkStatus::Ok, 'final_url' => null, 'response_time' => 1, 'redirect_count' => 0, 'redirect_chain' => null, 'is_redirect_loop' => false, 'error' => null ); }
	}
}
