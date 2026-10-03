<?php
/**
 * Action Scheduler integration; all execution stays outside status requests.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Queue;

defined( 'ABSPATH' ) || exit;

/** Schedules durable jobs and recurring maintenance. */
class SchedulerBootstrap {
	public const GROUP              = 'muri-link-tracker';
	public const SCAN_BATCH_HOOK    = 'mltr/scan/process_batch';
	public const CHECK_BATCH_HOOK   = 'mltr/check/process_batch';
	public const COORDINATOR_HOOK   = 'mltr/scan/advance';
	public const WATCHDOG_HOOK      = 'mltr/scan/watchdog';
	public const RECHECK_DAILY_HOOK = 'mltr/recheck/daily';
	public const CLEANUP_HOOK       = 'mltr/maintenance/cleanup';

	/** Check whether Action Scheduler is ready. */
	public static function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Avoid duplicate pending deliveries; running deliveries may enqueue their continuation.
	 *
	 * @param string $hook Action Scheduler hook name.
	 * @param array  $args Arguments passed to the scheduled action.
	 * @param int    $delay Delay before dispatch, in seconds.
	 */
	private static function enqueue( string $hook, array $args, int $delay = 0 ): int {
		if ( ! self::is_available() ) {
			return 0;
		}
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);
		if ( $pending ) {
			return (int) reset( $pending );
		}
		return $delay > 0
			? (int) as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP )
			: (int) as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Schedule a durable extraction or HTTP job.
	 *
	 * @param int    $id Record identifier.
	 * @param string $phase Extraction or HTTP checking phase.
	 * @param int    $delay Delay before dispatch, in seconds.
	 */
	public static function enqueue_job( int $id, string $phase, int $delay = 0 ): int {
		return self::enqueue( 'scanning' === $phase ? self::SCAN_BATCH_HOOK : self::CHECK_BATCH_HOOK, array( $id ), $delay );
	}

	/**
	 * Schedule the next planning step for a scan.
	 *
	 * @param string $scan_id Persisted scan identifier.
	 * @param int    $delay Delay before dispatch, in seconds.
	 */
	public static function enqueue_coordinator( string $scan_id, int $delay = 0 ): int {
		return self::enqueue( self::COORDINATOR_HOOK, array( $scan_id ), $delay );
	}

	/**
	 * Backward-compatible entrypoint used by the REST recheck actions.
	 *
	 * @param array $link_ids Identifiers of links to recheck.
	 */
	public static function enqueue_check_batch( array $link_ids ): int {
		global $wpdb;
		$generation = ( new ScanStore( $wpdb ) )->manual_generation();
		$last       = 0;
		foreach ( array_unique( array_map( 'absint', $link_ids ) ) as $id ) {
			if ( $id > 0 ) {
				$action = self::enqueue(
					self::CHECK_BATCH_HOOK,
					array(
						array(
							'manual_id'  => $id,
							'generation' => $generation,
							'attempt'    => 0,
						),
					)
				);
				if ( 0 === $action ) {
					return 0;
				}
				$last = $action;
			}
		}
		return $last;
	}

	/**
	 * Schedule another bounded attempt for a manual check.
	 *
	 * @param array $payload Manual check payload and cancellation token.
	 */
	public static function retry_manual( array $payload ): void {
		$payload['attempt'] = (int) ( $payload['attempt'] ?? 0 ) + 1;
		if ( $payload['attempt'] < ScanStore::MAX_ATTEMPTS ) {
			self::enqueue( self::CHECK_BATCH_HOOK, array( $payload ), 30 * $payload['attempt'] );
		}
	}

	/** Ensure the daily recheck action is scheduled. */
	public static function schedule_daily_recheck(): void {
		self::ensure_recurring_actions();
	}

	/** Register missing recheck and maintenance actions. */
	public static function ensure_recurring_actions(): void {
		if ( ! self::is_available() ) {
			return;
		}
		foreach ( array(
			self::WATCHDOG_HOOK      => 60,
			self::RECHECK_DAILY_HOOK => DAY_IN_SECONDS,
			self::CLEANUP_HOOK       => DAY_IN_SECONDS,
		) as $hook => $interval ) {
			if ( false === as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + $interval, $interval, $hook, array(), self::GROUP );
			}
		}
	}

	/** Reserved for plugin deactivation; scan cancellation never removes recurring actions. */
	public static function cancel_all(): void {
		if ( self::is_available() ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/** Count pending deliveries in the plugin action group. */
	public static function get_pending_count(): int {
		if ( ! self::is_available() ) {
			return 0;
		}
		$count = 0;
		foreach ( array( self::SCAN_BATCH_HOOK, self::CHECK_BATCH_HOOK, self::COORDINATOR_HOOK ) as $hook ) {
			$count += count(
				as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => self::GROUP,
						'status'   => 'pending',
						'per_page' => 0,
					),
					'ids'
				)
			);
		}
		return $count;
	}

	/** Report scheduler availability and recurring action health. */
	public static function get_diagnostics(): array {
		$initialized = class_exists( 'ActionScheduler', false ) && \ActionScheduler::is_initialized();
		return array(
			'as_available'      => self::is_available(),
			'as_initialized'    => $initialized,
			'pending_count'     => $initialized ? self::get_pending_count() : 0,
			'wp_cron_enabled'   => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'as_cron_scheduled' => (bool) wp_next_scheduled( 'action_scheduler_run_queue' ),
		);
	}
}
