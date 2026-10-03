<?php
/**
 * Links repository for CRUD operations on the mltr_links table.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Database;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Models\Enums\LinkStatus;
use MuriLinkTracker\Models\Link;

/**
 * Handles all database operations for the mltr_links table.
 *
 * @since 1.0.0
 */
class LinksRepository {

	/**
	 * Fully qualified table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private readonly string $table;

	/**
	 * Initializes the repository with the WordPress database instance.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb WordPress database abstraction.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
	) {
		$this->table = $this->wpdb->prefix . 'mltr_links';
	}

	/**
	 * Finds a link by its ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Link ID.
	 * @return Link|null
	 */
	public function find( int $id ): ?Link {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$id
			)
		);
		$this->assert_read_succeeded();

		return null !== $row ? Link::from_db_row( $row ) : null;
	}

	/**
	 * Finds multiple links by their IDs in a single query.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $ids Link IDs.
	 * @return array<int, Link> Associative array keyed by link ID.
	 */
	public function find_by_ids( array $ids ): array {
		$wpdb = $this->wpdb;
		if ( empty( $ids ) ) {
			return array();
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM %i WHERE id IN ($placeholders)",
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				...$ids
			)
		);
		$this->assert_read_succeeded();

		$map = array();
		foreach ( $rows as $row ) {
			$link             = Link::from_db_row( $row );
			$map[ $link->id ] = $link;
		}

		return $map;
	}

	/**
	 * Finds a link by its URL hash.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url_hash SHA-256 hash of the URL.
	 * @return Link|null
	 */
	public function find_by_hash( string $url_hash ): ?Link {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM %i WHERE url_hash = %s',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$url_hash
			)
		);
		$this->assert_read_succeeded();

		return null !== $row ? Link::from_db_row( $row ) : null;
	}

	/**
	 * Inserts a new link or returns the existing one's ID if the URL hash already exists.
	 * Uses an atomic upsert to refresh classification and handle existing hashes.
	 *
	 * @since 1.0.0
	 * @param string      $url               The link URL.
	 * @param string      $url_hash          SHA-256 hash of the URL.
	 * @param bool        $is_external       Whether the link is external.
	 * @param bool        $is_affiliate      Whether the link is an affiliate link.
	 * @param string|null $affiliate_network Detected affiliate network name.
	 * @return int The link ID (newly inserted or existing).
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function insert_or_get(
		string $url,
		string $url_hash,
		bool $is_external,
		bool $is_affiliate,
		?string $affiliate_network,
	): int {
		$wpdb = $this->wpdb;
		// Update URL-derived classification on every extraction. The instance
		// EXISTS also preserves a sponsored hint from another source post.
		$result = $this->wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (url, url_hash, is_external, is_affiliate, affiliate_network) VALUES (%s, %s, %d, %d, %s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), is_external = VALUES(is_external), is_affiliate = (VALUES(is_affiliate) OR EXISTS (SELECT 1 FROM %i WHERE link_id = LAST_INSERT_ID() AND rel_sponsored = 1)), affiliate_network = VALUES(affiliate_network)',
				$this->table,
				$url,
				$url_hash,
				(int) $is_external,
				(int) $is_affiliate,
				$affiliate_network,
				$this->wpdb->prefix . 'mltr_instances'
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not store the link inventory.' );
		}
		if ( $this->wpdb->insert_id > 0 ) {
			return (int) $this->wpdb->insert_id;
		}
		$existing = $this->find_by_hash( $url_hash );
		if ( null === $existing ) {
			throw new \RuntimeException( 'The stored link could not be found.' );
		}
		return $existing->id;
	}

	/**
	 * Refresh classification and invalidate all checks after changing an URL.
	 *
	 * @param int         $id Record identifier.
	 * @param string      $url Requested link URL.
	 * @param bool        $is_external Whether the URL points outside this site.
	 * @param bool        $is_affiliate Whether this link has an affiliate hint.
	 * @param string|null $network Detected affiliate network, if any.
	 */
	public function update_url( int $id, string $url, bool $is_external, bool $is_affiliate, ?string $network ): bool {
		$wpdb = $this->wpdb;
		return false !== $this->wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET url = %s, url_hash = %s, is_external = %d, is_affiliate = %d, affiliate_network = %s, status_category = 'pending', http_status = NULL, last_checked = NULL, final_url = NULL, response_time = NULL, redirect_count = 0, redirect_chain = NULL, last_error = NULL, check_count = 0 WHERE id = %d",
				$this->table,
				$url,
				hash( 'sha256', $url ),
				(int) $is_external,
				(int) $is_affiliate,
				$network,
				$id
			)
		);
	}

	/**
	 * Transfer references into an already tracked URL. Caller owns transaction.
	 *
	 * @param int $from Source link identifier.
	 * @param int $to Destination link identifier.
	 */
	public function merge_into( int $from, int $to ): bool {
		$wpdb = $this->wpdb;
		if ( $from === $to ) {
			return true;
		}
		if ( false === $this->wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET link_id = %d WHERE link_id = %d',
				$this->wpdb->prefix . 'mltr_instances',
				$to,
				$from
			)
		) ) {
			return false;
		}
		return false !== $this->wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $this->table, $from ) );
	}

	/**
	 * Recompute the aggregate sponsored hint after instances have been replaced.
	 *
	 * @param int         $id Record identifier.
	 * @param bool        $is_external Whether the URL points outside this site.
	 * @param bool        $url_is_affiliate Whether the URL itself has an affiliate hint.
	 * @param string|null $network Detected affiliate network, if any.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function refresh_classification( int $id, bool $is_external, bool $url_is_affiliate, ?string $network ): void {
		$wpdb   = $this->wpdb;
		$result = $this->wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET is_external = %d, is_affiliate = (%d OR EXISTS (SELECT 1 FROM %i WHERE link_id = %d AND rel_sponsored = 1)), affiliate_network = %s WHERE id = %d',
				$this->table,
				(int) $is_external,
				(int) $url_is_affiliate,
				$this->wpdb->prefix . 'mltr_instances',
				$id,
				$network,
				$id
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not refresh link classification.' );
		}
	}

	/**
	 * Cursor-based selection avoids loading the entire inventory into memory.
	 *
	 * @param int  $cursor Last processed record identifier.
	 * @param int  $limit Maximum number of records to return.
	 * @param int  $recheck_days Freshness window in days.
	 * @param bool $force Whether to include links with fresh checks.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function find_check_ids_after( int $cursor, int $limit, int $recheck_days = 7, bool $force = false ): array {
		$wpdb  = $this->wpdb;
		$where = $force ? '' : " AND (l.status_category = 'pending' OR l.last_checked IS NULL OR l.last_checked < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))";
		$args  = array( $this->table, max( 0, $cursor ), $this->wpdb->prefix . 'mltr_instances' );
		if ( ! $force ) {
			$args[] = max( 1, $recheck_days );
		}
		$args[] = max( 1, $limit );
		$rows   = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The argument array includes the optional date placeholder only when its fixed SQL clause is present.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL structure is built from fixed fragments and placeholders; every value is passed to prepare().
				'SELECT l.id FROM %i l WHERE l.id > %d AND EXISTS (SELECT 1 FROM %i i WHERE i.link_id = l.id)' . $where . ' ORDER BY l.id ASC LIMIT %d',
				...$args
			)
		);
		$this->assert_read_succeeded();
		if ( null === $rows ) {
			throw new \RuntimeException( 'Could not select links for checking.' );
		}
		return array_map( static fn( object $row ): int => (int) $row->id, $rows );
	}

	/**
	 * Count referenced links that need an HTTP check.
	 *
	 * @param int  $recheck_days Freshness window in days.
	 * @param bool $force Whether to include links with fresh checks.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function count_checkable( int $recheck_days = 7, bool $force = false ): int {
		$wpdb  = $this->wpdb;
		$where = $force ? '' : " AND (l.status_category = 'pending' OR l.last_checked IS NULL OR l.last_checked < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))";
		$args  = array( $this->table, $this->wpdb->prefix . 'mltr_instances' );
		if ( ! $force ) {
			$args[] = max( 1, $recheck_days );
		}
		$count = $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The argument array includes the optional date placeholder only when its fixed SQL clause is present.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL structure is built from fixed fragments and placeholders; every value is passed to prepare().
				'SELECT COUNT(*) FROM %i l WHERE EXISTS (SELECT 1 FROM %i i WHERE i.link_id = l.id)' . $where,
				...$args
			)
		);
		$this->assert_read_succeeded();
		if ( null === $count ) {
			throw new \RuntimeException( 'Could not count links for checking.' );
		}
		return (int) $count;
	}

	/**
	 * Updates HTTP check results for a link.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $id              Link ID.
	 * @param int         $http_status     HTTP response code.
	 * @param LinkStatus  $status_category Resulting status category.
	 * @param string|null $final_url      Final URL after redirects.
	 * @param int         $response_time   Response time in milliseconds.
	 * @param int         $redirect_count  Number of redirect hops.
	 * @param string|null $redirect_chain JSON-encoded redirect chain or null.
	 * @param string|null $last_error     Error message if applicable.
	 * @return bool True on success.
	 */
	public function update_check_result(
		int $id,
		int $http_status,
		LinkStatus $status_category,
		?string $final_url,
		int $response_time,
		int $redirect_count,
		?string $redirect_chain,
		?string $last_error,
	): bool {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'UPDATE %i SET http_status = %d, status_category = %s, final_url = %s, response_time = %d, redirect_count = %d, redirect_chain = %s, last_error = %s, last_checked = %s, check_count = check_count + 1 WHERE id = %d',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$http_status,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$status_category->value,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$final_url,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$response_time,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$redirect_count,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$redirect_chain,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$last_error,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				current_time( 'mysql', true ),
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$id
			)
		);

		return false !== $updated;
	}

	/**
	 * Finds links that need an HTTP check (pending or stale).
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit        Max number of links to return.
	 * @param int $recheck_days Number of days before a link is considered stale.
	 * @return Link[]
	 */
	public function find_pending_or_stale( int $limit, int $recheck_days = 7 ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status_category = 'pending' OR (last_checked IS NOT NULL AND last_checked < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)) ORDER BY last_checked ASC, id ASC LIMIT %d",
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$recheck_days,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$limit
			)
		);
		$this->assert_read_succeeded();

		return array_map( array( Link::class, 'from_db_row' ), $rows );
	}

	/**
	 * Bulk inserts links using a transaction for performance.
	 * Refreshes classification for existing URLs and returns their IDs.
	 *
	 * @since 1.0.0
	 * @param array<int, array{url: string, url_hash: string, is_external: bool, is_affiliate: bool, affiliate_network: string|null}> $links Array of link data.
	 * @return array<string, int> Map of url_hash => link ID for all inserted/existing links.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 * @throws \Throwable When the guarded operation fails; the original error is propagated.
	 */
	public function bulk_insert( array $links ): array {
		if ( ! $links ) {
			return array();
		}
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Could not start the inventory transaction.' );
		}
		try {
			$map = array();
			foreach ( $links as $link ) {
				$map[ $link['url_hash'] ] = $this->insert_or_get( $link['url'], $link['url_hash'], $link['is_external'], $link['is_affiliate'], $link['affiliate_network'] );
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit the inventory transaction.' );
			}
			return $map;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Deletes a link and all its instances.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $id Link ID.
	 * @param bool $transaction Whether this call owns the surrounding transaction.
	 * @return bool True on success.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 * @throws \Throwable When the guarded operation fails; the original error is propagated.
	 */
	public function delete( int $id, bool $transaction = true ): bool {
		$wpdb = $this->wpdb;
		if ( $transaction && false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Could not start the deletion transaction.' );
		}
		try {
			if ( false === $this->wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE link_id = %d', $this->wpdb->prefix . 'mltr_instances', $id ) ) ) {
				throw new \RuntimeException( 'Could not delete link instances.' );
			}
			$deleted = $this->wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $this->table, $id ) );
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Could not delete the link.' );
			}
			if ( $transaction && false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit link deletion.' );
			}
			return $deleted > 0;
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$this->wpdb->query( 'ROLLBACK' );
			}
			throw $error;
		}
	}

	/**
	 * Counts links grouped by status category.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, int> e.g. ['ok' => 150, 'broken' => 3, ...].
	 */
	public function count_by_status(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT status_category, COUNT(*) as count FROM %i GROUP BY status_category',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table
			)
		);
		$this->assert_read_succeeded();

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->status_category ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Counts links grouped by affiliate network.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, int> e.g. ['amazon' => 42, 'awin' => 15].
	 */
	public function count_by_network(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(affiliate_network, ''), 'unknown') as network, COUNT(*) as count FROM %i WHERE is_affiliate = 1 GROUP BY affiliate_network ORDER BY count DESC",
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table
			)
		);
		$this->assert_read_succeeded();

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->network ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Returns comprehensive category statistics for the dashboard.
	 *
	 * Uses a single query with conditional aggregation for efficiency.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, int>
	 */
	public function get_category_stats(): array {
		$wpdb         = $this->wpdb;
		$like_pattern = $this->wpdb->esc_like( 'redirect_loop' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT
					COUNT(*) as total,
					SUM(is_external = 1) as external_count,
					SUM(is_external = 0) as internal_count,
					SUM(is_affiliate = 1) as affiliate_count,
					SUM(is_affiliate = 1 AND is_external = 0) as cloaked_count,
					SUM(is_affiliate = 1 AND is_external = 1) as direct_affiliate_count,
					SUM(CASE WHEN status_category = \'ok\' THEN 1 ELSE 0 END) as ok_count,
					SUM(CASE WHEN status_category = \'broken\' THEN 1 ELSE 0 END) as broken_count,
					SUM(CASE WHEN status_category = \'pending\' THEN 1 ELSE 0 END) as pending_count,
					SUM(CASE WHEN status_category = \'error\' THEN 1 ELSE 0 END) as error_count,
					SUM(CASE WHEN status_category = \'timeout\' THEN 1 ELSE 0 END) as timeout_count,
					SUM(CASE WHEN status_category = \'skipped\' THEN 1 ELSE 0 END) as skipped_count,
					SUM(redirect_count > 0) as redirect_count,
					SUM(redirect_count = 1) as single_redirect_count,
					SUM(redirect_count > 1) as chain_redirect_count,
					SUM(last_error LIKE %s) as loop_count
				FROM %i',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$like_pattern,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table
			)
		);
		$this->assert_read_succeeded();

		if ( null === $row ) {
			return array_fill_keys(
				array( 'total', 'external_count', 'internal_count', 'affiliate_count', 'cloaked_count', 'direct_affiliate_count', 'ok_count', 'broken_count', 'pending_count', 'error_count', 'timeout_count', 'skipped_count', 'redirect_count', 'single_redirect_count', 'chain_redirect_count', 'loop_count' ),
				0
			);
		}

		return array(
			'total'                  => (int) $row->total,
			'external_count'         => (int) $row->external_count,
			'internal_count'         => (int) $row->internal_count,
			'affiliate_count'        => (int) $row->affiliate_count,
			'cloaked_count'          => (int) $row->cloaked_count,
			'direct_affiliate_count' => (int) $row->direct_affiliate_count,
			'ok_count'               => (int) $row->ok_count,
			'broken_count'           => (int) $row->broken_count,
			'pending_count'          => (int) $row->pending_count,
			'error_count'            => (int) $row->error_count,
			'timeout_count'          => (int) $row->timeout_count,
			'skipped_count'          => (int) $row->skipped_count,
			'redirect_count'         => (int) $row->redirect_count,
			'single_redirect_count'  => (int) $row->single_redirect_count,
			'chain_redirect_count'   => (int) $row->chain_redirect_count,
			'loop_count'             => (int) $row->loop_count,
		);
	}

	/**
	 * Deletes orphan links that have no instances referencing them.
	 *
	 * @since 1.0.0
	 * @return int Number of deleted orphan links.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function cleanup_orphans(): int {
		$wpdb            = $this->wpdb;
		$instances_table = $this->wpdb->prefix . 'mltr_instances';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'DELETE l FROM %i l LEFT JOIN %i i ON l.id = i.link_id WHERE i.id IS NULL',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$instances_table
			)
		);

		if ( false === $deleted ) {
			throw new \RuntimeException( 'Could not clean orphan links.' );
		}
		return $deleted;
	}

	/**
	 * Deletes all links from the table.
	 *
	 * @since 1.0.0
	 * @return int Number of deleted rows.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function truncate(): int {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'DELETE FROM %i', $this->table )
		);

		if ( false === $deleted ) {
			throw new \RuntimeException( 'Could not clear the link inventory.' );
		}
		return (int) $deleted;
	}

	/**
	 * WordPress wpdb may return [] / null / 0 on failure as well as on an empty result.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	private function assert_read_succeeded(): void {
		if ( ! empty( $this->wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not read the link inventory. Please retry.' );
		}
	}
}
