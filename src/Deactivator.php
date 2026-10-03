<?php
/**
 * Plugin deactivation handler.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation via register_deactivation_hook().
 *
 * @since 1.0.0
 */
class Deactivator {

	/**
	 * Unschedules all Action Scheduler actions owned by this plugin.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate(): void {
		global $wpdb;
		$store = new \MuriLinkTracker\Queue\ScanStore( $wpdb );
		$store->exclusive(
			static function () use ( $store ): void {
				$run = $store->current_run();
				if ( null !== $run && 'running' === $run['status'] ) {
					$store->update_run( $run['id'], array( 'status' => 'cancelled' ) );
				}
				$store->invalidate_manual_checks();
			}
		);
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'muri-link-tracker' );
		}
	}
}
