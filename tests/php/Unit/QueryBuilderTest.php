<?php
/**
 * Unit tests for QueryBuilder.
 *
 * @package MuriLinkTracker\Tests\Unit
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Database\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit tests for QueryBuilder.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderTest extends TestCase {

	private QueryBuilder $builder;
	private WpdbStub $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb    = new WpdbStub();
		$this->builder = new QueryBuilder( $this->wpdb );
	}

	public function test_default_query_no_filters(): void {
		$this->wpdb->var_result     = 2;
		$this->wpdb->results_result = [
			$this->make_link_row( 1 ),
			$this->make_link_row( 2 ),
		];

		$result = $this->builder->query();

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 1, $result['items'][0]->id );
		$this->assertSame( 2, $result['items'][1]->id );
	}

	public function test_returns_empty_when_total_is_zero(): void {
		$this->wpdb->var_result = 0;

		$result = $this->builder->query();

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['items'] );
		// Should only run the count query, not the main query.
		$this->assertCount( 1, $this->wpdb->prepare_log );
	}

	public function test_status_filter(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1, 'broken' ) ];

		$result = $this->builder->query( [ 'status' => 'broken' ] );

		$this->assertSame( 1, $result['total'] );
		// Verify status_category appears in the prepared SQL.
		$this->assertStringContainsString( 'status_category', $this->wpdb->last_query );
	}

	public function test_link_type_external_filter(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'link_type' => 'external' ] );

		$this->assertStringContainsString( 'is_external', $this->wpdb->last_query );
	}

	public function test_link_type_internal_filter(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'link_type' => 'internal' ] );

		$this->assertStringContainsString( 'is_external', $this->wpdb->last_query );
	}

	public function test_is_affiliate_filter(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'is_affiliate' => true ] );

		$this->assertStringContainsString( 'is_affiliate', $this->wpdb->last_query );
	}

	public function test_rel_filter_triggers_join(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'rel' => 'nofollow' ] );

		$this->assertStringContainsString( 'INNER JOIN', $this->wpdb->last_query );
		$this->assertStringContainsString( 'rel_nofollow', $this->wpdb->last_query );
	}

	public function test_rel_dofollow_filter(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'rel' => 'dofollow' ] );

		$this->assertStringContainsString( 'is_dofollow', $this->wpdb->last_query );
	}

	public function test_search_filter_triggers_join(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'search' => 'example' ] );

		$this->assertStringContainsString( 'INNER JOIN', $this->wpdb->last_query );
		$this->assertStringContainsString( 'LIKE', $this->wpdb->last_query );
	}

	public function test_post_id_filter_triggers_join(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'post_id' => 42 ] );

		$this->assertStringContainsString( 'INNER JOIN', $this->wpdb->last_query );
		$this->assertStringContainsString( 'post_id', $this->wpdb->last_query );
	}

	public function test_combined_filters_with_join(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [
			'status'  => 'broken',
			'rel'     => 'sponsored',
			'post_id' => 10,
		] );

		$this->assertStringContainsString( 'DISTINCT', $this->wpdb->last_query );
		$this->assertStringContainsString( 'INNER JOIN', $this->wpdb->last_query );
		$this->assertStringContainsString( 'status_category', $this->wpdb->last_query );
		$this->assertStringContainsString( 'rel_sponsored', $this->wpdb->last_query );
		$this->assertStringContainsString( 'post_id', $this->wpdb->last_query );
	}

	public function test_orderby_whitelist_valid_column(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'orderby' => 'http_status' ] );

		$this->assertStringContainsString( 'http_status', $this->wpdb->last_query );
	}

	public function test_orderby_whitelist_invalid_defaults_to_created_at(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'orderby' => 'DROP TABLE;--' ] );

		$this->assertStringContainsString( 'created_at', $this->wpdb->last_query );
		$this->assertStringNotContainsString( 'DROP', $this->wpdb->last_query );
	}

	public function test_order_asc(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'order' => 'asc' ] );

		$this->assertStringContainsString( 'ASC', $this->wpdb->last_query );
	}

	public function test_order_invalid_defaults_to_desc(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'order' => 'invalid' ] );

		$this->assertStringContainsString( 'DESC', $this->wpdb->last_query );
	}

	public function test_per_page_clamped_to_max_100(): void {
		$this->wpdb->var_result     = 200;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'per_page' => 999 ] );

		// The LIMIT should be 100, not 999.
		$this->assertStringContainsString( 'LIMIT 100', $this->wpdb->last_query );
	}

	public function test_per_page_minimum_is_1(): void {
		$this->wpdb->var_result     = 5;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'per_page' => 0 ] );

		$this->assertStringContainsString( 'LIMIT 1', $this->wpdb->last_query );
	}

	public function test_pagination_offset_calculation(): void {
		$this->wpdb->var_result     = 100;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'page' => 3, 'per_page' => 25 ] );

		// Page 3 with 25 per page = offset 50.
		$this->assertStringContainsString( 'OFFSET 50', $this->wpdb->last_query );
	}

	public function test_page_minimum_is_1(): void {
		$this->wpdb->var_result     = 50;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'page' => 0 ] );

		$this->assertStringContainsString( 'OFFSET 0', $this->wpdb->last_query );
	}

	public function test_no_join_without_instance_filters(): void {
		$this->wpdb->var_result     = 1;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];

		$this->builder->query( [ 'status' => 'ok', 'link_type' => 'external' ] );

		$this->assertStringNotContainsString( 'JOIN', $this->wpdb->last_query );
		$this->assertStringNotContainsString( 'DISTINCT', $this->wpdb->last_query );
	}

	public function test_tied_sort_values_have_a_stable_id_order_on_every_page(): void {
		$this->wpdb->var_result = 100;
		$this->wpdb->results_result = [ $this->make_link_row( 1 ) ];
		$this->builder->query( [ 'orderby' => 'http_status', 'order' => 'asc', 'page' => 2 ] );
		$this->assertStringContainsString( 'ORDER BY l.http_status ASC, l.id ASC LIMIT 25 OFFSET 25', $this->wpdb->last_query );
		$this->builder->query( [ 'orderby' => 'last_checked', 'order' => 'desc' ] );
		$this->assertStringContainsString( 'ORDER BY l.last_checked DESC, l.id DESC', $this->wpdb->last_query );
	}

	public function test_export_uses_bounded_cursor_batches_without_counts_or_offsets(): void {
		$this->wpdb->var_result = 6;
		$this->wpdb->results_queue = [
			[ $this->make_link_row( 1 ), $this->make_link_row( 3 ) ],
			[ $this->make_link_row( 5 ) ],
		];
		$batches = iterator_to_array( $this->builder->export_batches( [ 'page' => 99, 'per_page' => 1 ], 2 ) );
		$this->assertSame( [ 1, 3, 5 ], array_map( fn( $link ) => $link->id, array_merge( ...$batches ) ) );
		$sql = implode( "\n", $this->wpdb->prepare_log );
		$this->assertStringNotContainsString( 'COUNT(', $sql );
		$this->assertStringNotContainsString( 'OFFSET', $sql );
		$this->assertStringContainsString( 'MAX(id)', $sql );
		$this->assertStringContainsString( 'l.id > 0 AND l.id <= 6 ORDER BY l.id ASC LIMIT 2', $sql );
		$this->assertStringContainsString( 'l.id > 3 AND l.id <= 6 ORDER BY l.id ASC LIMIT 2', $sql );
		$this->assertCount( 3, $this->wpdb->prepare_log );
	}

	public function test_export_preserves_all_filters_and_distinct_instance_matches(): void {
		$this->wpdb->var_result = 4;
		$this->wpdb->results_result = [ $this->make_link_row( 4 ) ];
		iterator_to_array( $this->builder->export_batches( [
			'status' => 'broken', 'link_type' => 'external', 'is_affiliate' => false,
			'affiliate_network' => 'network', 'rel' => 'sponsored', 'search' => '50% sale', 'post_id' => 42,
		] ) );
		$sql = $this->wpdb->last_query;
		foreach ( [ 'DISTINCT l.*', 'INNER JOIN', "l.status_category = 'broken'", 'l.is_external = 1', 'l.is_affiliate = 0', "l.affiliate_network = 'network'", 'i.rel_sponsored = 1', 'i.post_id = 42', '(l.url LIKE', 'OR i.anchor_text LIKE' ] as $filter ) {
			$this->assertStringContainsString( $filter, $sql );
		}
	}

	public function test_export_clamps_its_batch_and_stops_at_initial_upper_bound(): void {
		$this->wpdb->var_result = 2;
		$this->wpdb->results_result = [ $this->make_link_row( 2 ) ];
		$this->assertCount( 1, iterator_to_array( $this->builder->export_batches( [], 999999 ) ) );
		$this->assertStringContainsString( 'LIMIT 500', $this->wpdb->last_query );
		$this->assertStringContainsString( 'l.id <= 2', $this->wpdb->last_query );
	}

	public function test_export_reports_database_failures_instead_of_an_empty_success(): void {
		$this->wpdb->last_error = 'Connection unavailable';
		$this->expectException( \RuntimeException::class );
		iterator_to_array( $this->builder->export_batches() );
	}

	/**
	 * Creates a mock database row object for a link.
	 *
	 * @param int    $id     Link ID.
	 * @param string $status Status category.
	 * @return object
	 */
	private function make_link_row( int $id, string $status = 'ok' ): object {
		return (object) [
			'id'                => $id,
			'url'               => 'https://example.com/' . $id,
			'url_hash'          => hash( 'sha256', 'https://example.com/' . $id ),
			'final_url'         => null,
			'http_status'       => 200,
			'status_category'   => $status,
			'is_external'       => 1,
			'is_affiliate'      => 0,
			'affiliate_network' => null,
			'response_time'     => 150,
			'redirect_count'    => 0,
			'last_checked'      => '2025-01-15 10:00:00',
			'check_count'       => 1,
			'last_error'        => null,
			'created_at'        => '2025-01-01 00:00:00',
			'updated_at'        => '2025-01-15 10:00:00',
		];
	}
}

/**
 * Minimal wpdb stub for unit testing QueryBuilder.
 *
 * Records prepared SQL and returns configurable results.
 */
class WpdbStub extends \wpdb {

	/** @var string */
	public string $prefix = 'wp_';

	/** @var int|string Return value for get_var(). */
	public int|string $var_result = 0;

	/** @var array Return value for get_results(). */
	public array $results_result = [];

	/** @var array Sequential result batches for cursor tests. */
	public array $results_queue = [];

	/** @var string Last SQL query prepared. */
	public string $last_query = '';

	/** @var array<int, string> Log of all prepared queries. */
	public array $prepare_log = [];

	/**
	 * Mimics $wpdb->prepare().
	 *
	 * Performs basic placeholder replacement for test inspection.
	 *
	 * @param string $query  SQL query with placeholders.
	 * @param mixed  ...$args Values to substitute.
	 * @return string
	 */
	public function prepare( $query, ...$args ): string {
		$result = (string) $query;
		$params = $args;
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$params = $args[0];
		}
		foreach ( $params as $arg ) {
			if ( is_int( $arg ) ) {
				$result = preg_replace( '/%[dis]/', (string) $arg, $result, 1 );
			} elseif ( is_string( $arg ) ) {
				$result = preg_replace( '/%[si]/', "'" . $arg . "'", $result, 1 );
			}
		}
		$this->prepare_log[] = $result;
		return $result;
	}

	/**
	 * Mimics $wpdb->get_var().
	 *
	 * @param string $query SQL query.
	 * @return int|string|null
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ): int|string|null {
		if ( $query ) {
			$this->last_query = (string) $query;
		}
		return $this->var_result;
	}

	/**
	 * Mimics $wpdb->get_results().
	 *
	 * @param string $query SQL query.
	 * @return array
	 */
	public function get_results( $query = null, $output = \OBJECT ): array {
		if ( $query ) {
			$this->last_query = (string) $query;
		}
		return $this->results_queue ? array_shift( $this->results_queue ) : $this->results_result;
	}

	/**
	 * Mimics $wpdb->esc_like().
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}
}
