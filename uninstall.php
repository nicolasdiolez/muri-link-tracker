<?php
/**
 * Uninstall handler — runs when the plugin is deleted from WordPress admin.
 *
 * Drops all custom tables, deletes options, and clears scheduled actions.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

/*
 * Drop custom database tables.
 */
( new \MuriLinkTracker\Database\Migrator() )->drop_tables();

/*
 * Delete plugin options.
 */
delete_option( 'mltr_db_version' );
delete_option( 'mltr_settings' );
delete_option( 'mltr_active_scan_id' );
delete_option( 'mltr_queue_generation' );
delete_option( 'mltr_first_scan_date' );
delete_option( 'mltr_review_notice_dismissed' );

/*
 * Unschedule all Action Scheduler actions.
 */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'muri-link-tracker' );
}

/*
 * Clean up transients.
 */
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mltr_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mltr_' ) . '%'
	)
);
delete_option( 'mltr_last_scan_date' );
