<?php
/**
 * Query builder for filtered, paginated link queries.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Database;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Models\Link;

/**
 * Builds filtered SQL queries against the mltr_links table
 * with optional JOIN on mltr_instances for rel/search/post_id filters.
 *
 * @since 1.0.0
 */
class QueryBuilder {

	/**
	 * Fully qualified links table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private readonly string $links_table;

	/**
	 * Fully qualified instances table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private readonly string $instances_table;

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
		$this->links_table     = $this->wpdb->prefix . 'mltr_links';
		$this->instances_table = $this->wpdb->prefix . 'mltr_instances';
	}

	/**
	 * Executes a filtered, paginated query on the links table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string $status       Filter by status category.
	 *     @type string $link_type    Filter by link type (internal|external).
	 *     @type bool   $is_affiliate Filter by affiliate flag.
	 *     @type string $rel          Filter by rel attribute.
	 *     @type string $search       Search in URL and anchor text.
	 *     @type int    $post_id      Filter by source post ID.
	 *     @type string $orderby      Column to order by.
	 *     @type string $order        Order direction (asc|desc).
	 *     @type int    $page         Page number.
	 *     @type int    $per_page     Items per page.
	 * }
	 * @return array{items: Link[], total: int}
	 */
	public function query( array $args = array() ): array {
		[ $join_sql, $where_sql, $where_params, $select, $needs_join ] = $this->filter_parts( $args );

		// --- Count total ---

		$count_select = $needs_join ? 'COUNT(DISTINCT l.id)' : 'COUNT(*)';
		$count_sql    = "SELECT $count_select FROM %i l" . $join_sql . $where_sql;

		if ( ! empty( $where_params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built with prepare() and whitelisted column names only.
			$total = (int) $this->wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->wpdb->prepare( $count_sql, $this->links_table, ...$where_params )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built with prepare() and whitelisted column names only.
			$total = (int) $this->wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->wpdb->prepare( $count_sql, $this->links_table )
			);
		}

		if ( 0 === $total ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		// --- Ordering ---

		$orderby_column = $this->sanitize_orderby( $args['orderby'] ?? '' );
		$order          = $this->sanitize_order( $args['order'] ?? '' );

		// --- Pagination ---

		$per_page = min( max( (int) ( $args['per_page'] ?? 25 ), 1 ), 100 );
		$page     = max( (int) ( $args['page'] ?? 1 ), 1 );
		$offset   = ( $page - 1 ) * $per_page;

		// --- Main query ---

		$query_sql = "SELECT $select FROM %i l" . $join_sql . $where_sql
			. " ORDER BY l.$orderby_column $order, l.id $order LIMIT %d OFFSET %d";

		$all_params = array( $this->links_table, ...$where_params, $per_page, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built with prepare() and whitelisted column names only.
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->prepare( $query_sql, ...$all_params )
		);

		$items = array_map( array( Link::class, 'from_db_row' ), $rows );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Iterates every matching link in bounded batches, using a stable ID cursor.
	 *
	 * Export deliberately orders by ID rather than mutable display columns.
	 * The initial upper bound excludes later insertions; deleting an earlier
	 * row cannot shift an OFFSET and skip another link. Filters are identical
	 * to the table query, with no repeated COUNT or growing OFFSET.
	 *
	 * @param array<string,mixed> $args Filter arguments; pagination and sort are ignored.
	 * @param int                 $batch_size Maximum links held in each batch (1–500).
	 * @return \Generator<int, array<Link>>
	 * @throws \RuntimeException When a database query fails.
	 */
	public function export_batches( array $args = array(), int $batch_size = 100 ): \Generator {
		[ $join_sql, $where_sql, $where_params, $select ] = $this->filter_parts( $args );
		$batch_size                                       = min( max( $batch_size, 1 ), 500 );
		$maximum_id                                       = (int) $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is prepared with %i.
			$this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $this->links_table )
		);
		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Serialized as a REST error, not rendered as HTML.
			throw new \RuntimeException( __( 'Unable to read links for export. Please try again.', 'muri-link-tracker' ) );
		}
		$cursor     = 0;
		$where_sql .= ( '' === $where_sql ? ' WHERE ' : ' AND ' ) . 'l.id > %d AND l.id <= %d';
		$sql        = "SELECT $select FROM %i l" . $join_sql . $where_sql . ' ORDER BY l.id ASC LIMIT %d';
		while ( $cursor < $maximum_id ) {
			$rows = $this->export_rows(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Shared filters contain only placeholders and fixed SQL fragments.
				$this->wpdb->prepare( $sql, $this->links_table, ...array_merge( $where_params, array( $cursor, $maximum_id, $batch_size ) ) )
			);
			if ( empty( $rows ) ) {
				break;
			}
			$items  = array_map( array( Link::class, 'from_db_row' ), $rows );
			$cursor = $items[ count( $items ) - 1 ]->id;
			yield $items;
			if ( count( $items ) < $batch_size ) {
				break;
			}
		}
	}

	/**
	 * Runs one export batch and checks the error from that database operation.
	 *
	 * @param string $sql Fully prepared SQL.
	 * @return array<object> Database rows.
	 * @throws \RuntimeException When the database query fails.
	 */
	private function export_rows( string $sql ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only called with fully prepared SQL from export_batches().
		$rows = $this->wpdb->get_results( $sql );
		if ( '' !== $this->wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Serialized as a REST error, not rendered as HTML.
			throw new \RuntimeException( __( 'Unable to read links for export. Please try again.', 'muri-link-tracker' ) );
		}
		return $rows;
	}

	/**
	 * Builds the shared, parameterized filters for display and export queries.
	 *
	 * @param array<string,mixed> $args Query filters.
	 * @return array{string, string, array, string, bool}
	 */
	private function filter_parts( array $args ): array {
		$where_clauses = array();
		$where_params  = array();
		$needs_join    = false;

		// --- Filters on mltr_links ---

		if ( ! empty( $args['status'] ) ) {
			if ( 'redirect' === $args['status'] ) {
				$where_clauses[] = 'l.redirect_count > 0';
			} else {
				$where_clauses[] = 'l.status_category = %s';
				$where_params[]  = $args['status'];
			}
		}

		if ( ! empty( $args['link_type'] ) ) {
			$is_external     = 'external' === $args['link_type'] ? 1 : 0;
			$where_clauses[] = 'l.is_external = %d';
			$where_params[]  = $is_external;
		}

		if ( isset( $args['is_affiliate'] ) ) {
			$where_clauses[] = 'l.is_affiliate = %d';
			$where_params[]  = (int) $args['is_affiliate'];
		}

		if ( isset( $args['is_cloaked'] ) && $args['is_cloaked'] ) {
			$where_clauses[] = 'l.is_affiliate = 1 AND l.is_external = 0';
		}

		if ( ! empty( $args['affiliate_network'] ) ) {
			$where_clauses[] = 'l.affiliate_network = %s';
			$where_params[]  = $args['affiliate_network'];
		}

		// --- Filters requiring JOIN on mltr_instances ---

		if ( ! empty( $args['rel'] ) ) {
			$needs_join = true;
			$rel_clause = match ( $args['rel'] ) {
				'nofollow'  => 'i.rel_nofollow = 1',
				'sponsored' => 'i.rel_sponsored = 1',
				'ugc'       => 'i.rel_ugc = 1',
				'dofollow'  => 'i.is_dofollow = 1',
				default     => null,
			};
			if ( null !== $rel_clause ) {
				$where_clauses[] = $rel_clause;
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$needs_join      = true;
			$like            = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses[] = '(l.url LIKE %s OR i.anchor_text LIKE %s)';
			$where_params[]  = $like;
			$where_params[]  = $like;
		}

		if ( ! empty( $args['post_id'] ) ) {
			$needs_join      = true;
			$where_clauses[] = 'i.post_id = %d';
			$where_params[]  = (int) $args['post_id'];
		}

		// --- Build SQL fragments ---

		$join_sql  = $needs_join
			? $this->wpdb->prepare( ' INNER JOIN %i i ON l.id = i.link_id', $this->instances_table )
			: '';
		$where_sql = ! empty( $where_clauses ) ? ' WHERE ' . implode( ' AND ', $where_clauses ) : '';
		$select    = $needs_join ? 'DISTINCT l.*' : 'l.*';

		return array( $join_sql, $where_sql, $where_params, $select, $needs_join );
	}

	/**
	 * Sanitizes the orderby column to prevent SQL injection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $orderby Raw orderby value.
	 * @return string Safe column name.
	 */
	private function sanitize_orderby( string $orderby ): string {
		return match ( $orderby ) {
			'url'          => 'url',
			'http_status'  => 'http_status',
			'last_checked' => 'last_checked',
			'created_at'   => 'created_at',
			default        => 'created_at',
		};
	}

	/**
	 * Sanitizes the order direction.
	 *
	 * @since 1.0.0
	 *
	 * @param string $order Raw order value.
	 * @return string 'ASC' or 'DESC'.
	 */
	private function sanitize_order( string $order ): string {
		return 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';
	}
}
