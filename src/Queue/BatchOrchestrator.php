<?php
/**
 * Server-driven scan lifecycle with durable jobs.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Queue;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;

/** Coordinates bounded background scans. */
class BatchOrchestrator {

	/**
	 * Durable scan state.
	 *
	 * @var ScanStore
	 */
	private readonly ScanStore $store;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param LinksRepository     $links_repo Links repo.
	 * @param InstancesRepository $instances_repo Instances repo.
	 * @param ScanStore|null      $store Durable scan state repository.
	 */
	public function __construct(
		private readonly LinksRepository $links_repo,
		private readonly InstancesRepository $instances_repo,
		?ScanStore $store = null,
	) {
		global $wpdb;
		$this->store = $store ?? new ScanStore( $wpdb );
	}

	/**
	 * Create a scan after checking scheduler and concurrency constraints.
	 *
	 * @param string $scan_type Full, delta or recheck scan type.
	 *
	 * @throws \InvalidArgumentException When input cannot safely identify the requested mutation.
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function start_scan( string $scan_type = 'full' ): int {
		if ( ! SchedulerBootstrap::is_available() ) {
			throw new \RuntimeException( 'Action Scheduler is not available.' );
		}
		if ( ! in_array( $scan_type, array( 'full', 'delta' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid scan type.' );
		}
		$run = $this->store->exclusive(
			function () use ( $scan_type ): array {
				$current = $this->store->current_run();
				if ( null !== $current && 'running' === $current['status'] ) {
						throw new \RuntimeException( 'A scan is already in progress.' );
				}
				return $this->store->create_run( $scan_type, 'scanning' );
			}
		);
		if ( 0 === SchedulerBootstrap::enqueue_coordinator( $run['id'] ) ) {
			$this->store->update_run(
				$run['id'],
				array(
					'status'        => 'error',
					'error_message' => 'The scan could not be queued. Please resume it.',
				)
			);
			throw new \RuntimeException( 'The scan could not be queued. Please resume it.' );
		}
		return 1;
	}

	/**
	 * Plan bounded batches and advance only after THIS run's jobs actually finish.
	 *
	 * @param string $scan_id Persisted scan identifier.
	 *
	 * @throws \RuntimeException When scan planning fails; the original error is propagated.
	 */
	public function advance( string $scan_id ): void {
		try {
			$keep_planning = $this->store->exclusive(
				function () use ( $scan_id ): bool {
					if ( ! $this->store->is_active( $scan_id ) ) {
							return false;
					}
					$this->store->recover_expired( $scan_id );
					$run = $this->store->find_run( $scan_id );
					if ( null === $run ) {
						return false;
					}
					if ( ! (bool) $run['planning_done'] ) {
						$this->store->transaction( fn() => $this->plan_page( $run ) );
						$run = $this->store->find_run( $scan_id );
					}
					$work = $this->store->work_state( $scan_id, $run['phase'] );
					if ( $work['failed'] ) {
						$this->store->update_run(
							$scan_id,
							array(
								'status'        => 'error',
								'error_message' => 'A scan job failed after three attempts. Resume to retry the remaining work.',
							)
						);
						return false;
					}
					if ( ! (bool) $run['planning_done'] ) {
						return true;
					}
					if ( $work['unfinished'] ) {
						return false;
					}
					if ( 'scanning' === $run['phase'] ) {
						$this->instances_repo->cleanup_unpublished();
						$this->links_repo->cleanup_orphans();
						$this->store->update_run(
							$scan_id,
							array(
								'phase'         => 'checking',
								'planning_done' => 0,
							)
						);
						delete_transient( 'mltr_stats_cache' );
						return true;
					}
					$this->store->update_run(
						$scan_id,
						array(
							'status'        => 'complete',
							'finished_at'   => gmdate( 'Y-m-d H:i:s' ),
							'error_message' => null,
						)
					);
					if ( 'recheck' !== $run['scan_type'] ) {
						update_option( 'mltr_last_scan_date', $run['started_at'], false );
					}
					delete_transient( 'mltr_stats_cache' );
					do_action( 'mltr/scan/complete', $scan_id );
					return false;
				}
			);
			$this->dispatch_pending( $scan_id );
			if ( $keep_planning ) {
				if ( 0 === SchedulerBootstrap::enqueue_coordinator( $scan_id ) ) {
					throw new \RuntimeException( 'The next scan planning step could not be queued.' );
				}
			}
		} catch ( QueueBusyException ) {
			SchedulerBootstrap::enqueue_coordinator( $scan_id, 5 );
		} catch ( \Throwable $error ) {
			// Report planning/enqueue failures instead of silently leaving a healthy-looking run.
			try {
				$this->store->exclusive(
					function () use ( $scan_id, $error ): void {
						if ( $this->store->is_active( $scan_id ) ) {
								$this->store->update_run(
									$scan_id,
									array(
										'status'        => 'error',
										'error_message' => 'Scan planning failed. Resume to retry. ' . substr( $error->getMessage(), 0, 500 ),
									)
								);
						}
					}
				);
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the original planning failure when persisting its diagnostic also fails.
			} catch ( \Throwable ) {
				// A database outage is also reported to Action Scheduler; the watchdog can recover.
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only log infrastructure diagnostics when WP_DEBUG is enabled.
				error_log( '[MuriLinkTracker] Coordinator: ' . $error->getMessage() );
			}
			throw $error;
		}
	}

	/**
	 * Persist the next bounded page of scan jobs.
	 *
	 * @param array $run Persisted scan state.
	 */
	private function plan_page( array $run ): void {
		$settings = get_option( 'mltr_settings', array() );
		if ( 'scanning' === $run['phase'] ) {
			$size  = max( 10, min( 200, (int) ( $settings['batch_size'] ?? 50 ) ) );
			$size  = max( 1, min( 200, (int) apply_filters( 'mltr/scanner/batch_size', $size ) ) );
			$types = array_values( array_filter( (array) ( $settings['scan_post_types'] ?? array( 'post', 'page' ) ), 'is_string' ) );
			$ids   = $this->store->next_post_ids( $run, $types, $size );
			if ( $ids ) {
				$this->store->create_job( $run['id'], 'scanning', $ids );
			}
			$this->store->update_run(
				$run['id'],
				array(
					'scan_cursor'   => $ids ? max( $ids ) : (int) $run['scan_cursor'],
					'planning_done' => count( $ids ) < $size ? 1 : 0,
				)
			);
		} else {
			$days = max( 1, (int) ( $settings['recheck_interval'] ?? 7 ) );
			$ids  = $this->links_repo->find_check_ids_after( (int) $run['check_cursor'], 100, $days, 'full' === $run['scan_type'] );
			// One HTTP URL per action keeps even slow hosts from monopolizing a worker.
			foreach ( $ids as $id ) {
				$this->store->create_job( $run['id'], 'checking', array( $id ) );
			}
			$this->store->update_run(
				$run['id'],
				array(
					'check_cursor'  => $ids ? max( $ids ) : (int) $run['check_cursor'],
					'planning_done' => count( $ids ) < 100 ? 1 : 0,
				)
			);
		}
	}

	/**
	 * Schedule pending jobs for the current scan.
	 *
	 * @param string $scan_id Persisted scan identifier.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	private function dispatch_pending( string $scan_id ): void {
		if ( ! $this->store->is_active( $scan_id ) ) {
			return;
		}
		foreach ( $this->store->pending_jobs( $scan_id ) as $job ) {
			if ( 0 === SchedulerBootstrap::enqueue_job( (int) $job['id'], $job['phase'] ) ) {
				throw new \RuntimeException( 'A scan job could not be queued.' );
			}
		}
	}

	/** A real recurring action recovers killed workers and failed enqueue attempts. */
	public function watchdog(): void {
		$run = $this->store->current_run();
		if ( null !== $run && 'running' === $run['status'] ) {
			$this->advance( $run['id'] );
		}
	}

	/** Start a background scan of links whose checks have expired. */
	public function recheck_stale_links(): void {
		$run = $this->store->exclusive(
			function (): ?array {
				$current = $this->store->current_run();
				if ( null !== $current && in_array( $current['status'], array( 'running', 'cancelled', 'error' ), true ) ) {
						return null;
				}
				return $this->store->create_run( 'recheck', 'checking' );
			}
		);
		if ( null !== $run ) {
			SchedulerBootstrap::enqueue_coordinator( $run['id'] );
		}
	}

	/** Remove obsolete occurrences and recover stalled scan planning. */
	public function cleanup(): void {
		$this->store->exclusive(
			function (): void {
				$this->instances_repo->cleanup_unpublished();
				$this->links_repo->cleanup_orphans();
				$this->store->prune();
				delete_transient( 'mltr_stats_cache' );
			}
		);
	}

	/** Cancel the active scan and revoke outstanding worker leases. */
	public function cancel(): void {
		$this->store->exclusive(
			function (): void {
				$run = $this->store->current_run();
				if ( null !== $run && in_array( $run['status'], array( 'running', 'error' ), true ) ) {
						$this->store->update_run( $run['id'], array( 'status' => 'cancelled' ) );
				}
			}
		);
	}

	/** Workers revalidate their token inside this same lock before any database write. */
	public function reset(): void {
		$this->store->exclusive(
			function (): void {
				$run = $this->store->current_run();
				if ( null !== $run ) {
						$this->store->update_run( $run['id'], array( 'status' => 'cancelled' ) );
				}
				$this->store->invalidate_manual_checks();
				$this->instances_repo->truncate();
				$this->links_repo->truncate();
				$this->store->delete_runs();
				delete_option( 'mltr_last_scan_date' );
				delete_transient( 'mltr_scan_status' );
				delete_transient( 'mltr_stats_cache' );
			}
		);
	}

	/** Resume an interrupted scan with a fresh bounded retry cycle. */
	public function resume(): bool {
		$scan_id = $this->store->exclusive(
			function (): ?string {
				$run = $this->store->current_run();
				if ( null === $run || ! in_array( $run['status'], array( 'cancelled', 'error' ), true ) ) {
						return null;
				}
				// Revoke old worker tokens before making the run active again.
				$this->store->resume_jobs( $run['id'] );
				$this->store->update_run(
					$run['id'],
					array(
						'status'        => 'running',
						'error_message' => null,
					)
				);
				return $run['id'];
			}
		);
		if ( null === $scan_id ) {
			return false;
		}
		SchedulerBootstrap::enqueue_coordinator( $scan_id );
		return true;
	}

	/** A read endpoint never runs jobs or advances the state machine. */
	public function get_status(): array {
		$run     = $this->store->current_run();
		$stats   = $this->links_repo->get_category_stats();
		$scan    = null !== $run ? $this->store->aggregate( $run['id'], 'scanning' ) : array();
		$check   = null !== $run ? $this->store->aggregate( $run['id'], 'checking' ) : array();
		$running = null !== $run && 'running' === $run['status'];
		return array(
			'scan_id'        => $run['id'] ?? null,
			'status'         => $run['status'] ?? 'idle',
			'phase'          => $run['phase'] ?? null,
			'scan_type'      => $run['scan_type'] ?? null,
			'total_posts'    => $scan['total_items'] ?? 0,
			'scanned_posts'  => $scan['processed'] ?? 0,
			'total_links'    => $running ? ( $check['total_items'] ?? 0 ) : $stats['total'],
			'checked_links'  => $running ? ( $check['processed'] ?? 0 ) : $stats['total'] - $stats['pending_count'],
			'ok_count'       => $stats['ok_count'],
			'broken_count'   => $stats['broken_count'],
			'redirect_count' => $stats['redirect_count'],
			'error_count'    => $stats['error_count'],
			'timeout_count'  => $stats['timeout_count'],
			'skipped_count'  => $stats['skipped_count'],
			'pending_count'  => $stats['pending_count'],
			'pending_jobs'   => ( $scan['pending'] ?? 0 ) + ( $check['pending'] ?? 0 ),
			'running_jobs'   => ( $scan['running'] ?? 0 ) + ( $check['running'] ?? 0 ),
			'failed_jobs'    => ( $scan['failed'] ?? 0 ) + ( $check['failed'] ?? 0 ),
			'started_at'     => isset( $run['started_at'] ) ? str_replace( ' ', 'T', $run['started_at'] ) . 'Z' : null,
			'error_message'  => $run['error_message'] ?? null,
		);
	}
}
