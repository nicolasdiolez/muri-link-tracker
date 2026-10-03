<?php
/**
 * Instances repository for CRUD operations on the mltr_instances table.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Database;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Models\LinkInstance;

/**
 * Handles all database operations for the mltr_instances table.
 *
 * @since 1.0.0
 */
class InstancesRepository {

	/**
	 * Fully qualified table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private readonly string $table;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \wpdb $wpdb WordPress database abstraction.
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
	) {
		$this->table = $this->wpdb->prefix . 'mltr_instances';
	}

	/**
	 * Finds all instances for a given post.
	 *
	 * @since 1.0.0
	 * @param int $post_id WordPress post ID.
	 * @return LinkInstance[]
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function find_by_post( int $post_id ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM %i WHERE post_id = %d ORDER BY link_position ASC',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$post_id
			)
		);

		if ( ! is_array( $rows ) || ! empty( $this->wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not read link occurrences.' );
		}
		return array_map( array( LinkInstance::class, 'from_db_row' ), $rows );
	}

	/**
	 * Finds all instances for a given link.
	 *
	 * @since 1.0.0
	 * @param int $link_id FK to mltr_links.id.
	 * @return LinkInstance[]
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function find_by_link( int $link_id ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM %i WHERE link_id = %d',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$link_id
			)
		);

		if ( ! is_array( $rows ) || ! empty( $this->wpdb->last_error ) ) {
			throw new \RuntimeException( 'Could not read link occurrences.' );
		}
		return array_map( array( LinkInstance::class, 'from_db_row' ), $rows );
	}

	/**
	 * Deletes all instances for a given post.
	 *
	 * @since 1.0.0
	 * @param int $post_id WordPress post ID.
	 * @return int Number of deleted rows.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function delete_by_post( int $post_id ): int {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'DELETE FROM %i WHERE post_id = %d',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$post_id
			)
		);

		if ( false === $deleted ) {
			throw new \RuntimeException( 'Could not delete link occurrences.' );
		}
		return (int) $deleted;
	}

	/**
	 * Bulk inserts instances using a transaction for performance.
	 *
	 * @since 1.0.0
	 *
	 * @param array $instances Occurrences with link/post IDs, source, anchor text, rel flags, position and block name.
	 * @param bool  $transaction Whether this call owns the surrounding transaction.
	 * @return void
	 */
	public function bulk_insert( array $instances, bool $transaction = true ): void {
		if ( empty( $instances ) ) {
			return;
		}
		$this->write_atomically( fn() => $this->insert_rows( $instances ), $transaction );
	}

	/**
	 * Replace occurrences atomically; false lets the content editor own the transaction.
	 *
	 * @param int   $post_id Source post.
	 * @param array $instances Replacement rows.
	 * @param bool  $transaction Whether to manage a transaction.
	 *
	 * @throws \InvalidArgumentException When input cannot safely identify the requested mutation.
	 */
	public function sync_for_post( int $post_id, array $instances, bool $transaction = true ): void {
		foreach ( $instances as $instance ) {
			if ( (int) $instance['post_id'] !== $post_id ) {
				throw new \InvalidArgumentException( 'An occurrence belongs to a different post.' );
			}
		}
		$this->write_atomically(
			function () use ( $post_id, $instances ): void {
				$this->delete_by_post( $post_id );
				$this->insert_rows( $instances );
			},
			$transaction
		);
	}

	/**
	 * Run the inventory mutation within an optional transaction.
	 *
	 * @param callable $write Inventory mutation to execute.
	 * @param bool     $transaction Whether this call owns the surrounding transaction.
	 *
	 * @throws \Throwable When the guarded operation fails; the original error is propagated.
	 */
	private function write_atomically( callable $write, bool $transaction ): void {
		if ( $transaction ) {
			$this->checked_query( 'START TRANSACTION' );
		}
		try {
			$write();
			if ( $transaction ) {
				$this->checked_query( 'COMMIT' );
			}
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$this->wpdb->query( 'ROLLBACK' );
			}
			throw $error;
		}
	}

	/**
	 * Insert rows.
	 *
	 * @param array $instances Occurrences to insert in bounded groups.
	 *
	 * @throws \InvalidArgumentException When input cannot safely identify the requested mutation.
	 */
	private function insert_rows( array $instances ): void {
		$wpdb = $this->wpdb;
		foreach ( array_chunk( $instances, 100 ) as $chunk ) {
			$values = array();
			$params = array( $this->table );
			foreach ( $chunk as $row ) {
				if ( (int) $row['link_id'] < 1 || (int) $row['post_id'] < 1 ) {
					throw new \InvalidArgumentException( 'An occurrence needs an existing link and post.' );
				}
				$position = $row['link_position'] ?? null;
				$values[] = '( %d, %d, %s, %s, %d, %d, %d, %d, ' . ( null === $position ? 'NULL' : '%d' ) . ', %s )';
				array_push(
					$params,
					(int) $row['link_id'],
					(int) $row['post_id'],
					(string) $row['source_type'],
					(string) ( $row['anchor_text'] ?? '' ),
					(int) $row['rel_nofollow'],
					(int) $row['rel_sponsored'],
					(int) $row['rel_ugc'],
					(int) $row['is_dofollow']
				);
				if ( null !== $position ) {
					$params[] = (int) $position;
				}
				$params[] = (string) ( $row['block_name'] ?? '' );
			}
			$sql = 'INSERT INTO %i (link_id, post_id, source_type, anchor_text, rel_nofollow, rel_sponsored, rel_ugc, is_dofollow, link_position, block_name) VALUES ' . implode( ', ', $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL structure is built from fixed fragments and placeholders; every value is passed to prepare().
			$this->checked_query( $wpdb->prepare( $sql, ...$params ) );
		}
	}

	/** Removes occurrences from deleted or unpublished sources. */
	public function cleanup_unpublished(): int {
		$wpdb = $this->wpdb;
		return $this->checked_query(
			$wpdb->prepare(
				'DELETE i FROM %i i LEFT JOIN %i p ON p.ID = i.post_id WHERE p.ID IS NULL OR p.post_status <> %s',
				$this->table,
				$this->wpdb->posts,
				'publish'
			)
		);
	}

	/**
	 * Execute a prepared inventory mutation and reject database failures.
	 *
	 * @param string $sql SQL already prepared by the caller.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	private function checked_query( string $sql ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The private helper accepts only SQL prepared by its repository callers.
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not update link occurrences.' );
		}
		return (int) $result;
	}

	/**
	 * Counts instances grouped by link ID for a batch of link IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $link_ids Array of link IDs.
	 * @return array<int, int> Map of link_id => instance count.
	 */
	public function count_by_link_ids( array $link_ids ): array {
		$wpdb = $this->wpdb;
		if ( empty( $link_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT link_id, COUNT(*) as count FROM %i WHERE link_id IN ($placeholders) GROUP BY link_id",
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				...$link_ids
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->link_id ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Counts the number of instances for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id WordPress post ID.
	 * @return int Instance count.
	 */
	public function count_by_post( int $post_id ): int {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_id = %d',
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table,
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$post_id
			)
		);

		return (int) $count;
	}

	/**
	 * Deletes all instances from the table.
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
			throw new \RuntimeException( 'Could not delete link occurrences.' );
		}
		return (int) $deleted;
	}
}
