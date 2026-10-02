<?php
/**
 * Database table creation and migration.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Handles creation and deletion of custom database tables via dbDelta().
 *
 * @since 1.0.0
 */
class Migrator {

	public const DB_VERSION = '2';

	/** Upgrade existing installations as well as fresh activations. */
	public function maybe_migrate(): void {
		if ( self::DB_VERSION !== get_option( 'mltr_db_version' ) ) {
			$this->create_tables();
		}
	}

	/**
	 * Creates the plugin database tables.
	 *
	 * @since 1.0.0
	 */
	public function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql_links = "CREATE TABLE {$wpdb->prefix}mltr_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url text NOT NULL,
			url_hash char(64) NOT NULL,
			final_url text DEFAULT NULL,
			http_status smallint(6) DEFAULT NULL,
			status_category varchar(20) NOT NULL DEFAULT 'pending',
			is_external tinyint(1) NOT NULL DEFAULT 0,
			is_affiliate tinyint(1) NOT NULL DEFAULT 0,
			affiliate_network varchar(50) DEFAULT NULL,
			response_time int(11) DEFAULT NULL,
			redirect_count tinyint(4) NOT NULL DEFAULT 0,
			redirect_chain text DEFAULT NULL,
			last_checked datetime DEFAULT NULL,
			check_count int(11) NOT NULL DEFAULT 0,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY idx_status_category (status_category),
			KEY idx_last_checked (last_checked),
			KEY idx_is_external (is_external),
			KEY idx_is_affiliate (is_affiliate),
		KEY idx_redirect_count (redirect_count)
		) {$charset_collate};";

		$sql_instances = "CREATE TABLE {$wpdb->prefix}mltr_instances (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			link_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			source_type varchar(30) NOT NULL DEFAULT 'post_content',
			anchor_text text DEFAULT NULL,
			rel_nofollow tinyint(1) NOT NULL DEFAULT 0,
			rel_sponsored tinyint(1) NOT NULL DEFAULT 0,
			rel_ugc tinyint(1) NOT NULL DEFAULT 0,
			is_dofollow tinyint(1) NOT NULL DEFAULT 1,
			link_position int(11) DEFAULT NULL,
			block_name varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_link_id (link_id),
			KEY idx_post_id (post_id),
			KEY idx_source_type (source_type),
			KEY idx_rel_nofollow (rel_nofollow),
			KEY idx_rel_sponsored (rel_sponsored),
			KEY idx_is_dofollow (is_dofollow)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_links );
		dbDelta( $sql_instances );
		dbDelta( "CREATE TABLE {$wpdb->prefix}mltr_scans (
			id char(36) NOT NULL,
			scan_type varchar(10) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			phase varchar(20) NOT NULL DEFAULT 'scanning',
			started_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			error_message text DEFAULT NULL,
			scan_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			check_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			planning_done tinyint(1) NOT NULL DEFAULT 0,
			last_scan_date datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_status_started (status,started_at)
		) {$charset_collate};" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}mltr_scan_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id char(36) NOT NULL,
			phase varchar(20) NOT NULL,
			batch_key bigint(20) unsigned NOT NULL,
			item_ids longtext NOT NULL,
			item_count int(11) NOT NULL,
			completed_items int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts int(11) NOT NULL DEFAULT 0,
			lease_token char(36) DEFAULT NULL,
			lease_until datetime DEFAULT NULL,
			error_message text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_phase_batch (scan_id,phase,batch_key),
			KEY scan_status (scan_id,status),
			KEY scan_phase_status (scan_id,phase,status)
		) {$charset_collate};" );

		update_option( 'mltr_db_version', self::DB_VERSION );
	}

	/**
	 * Drops the plugin database tables.
	 *
	 * @since 1.0.0
	 */
	public function drop_tables(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mltr_scan_jobs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mltr_scans" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mltr_instances" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mltr_links" );
	}
}
