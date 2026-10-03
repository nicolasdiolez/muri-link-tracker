<?php
/** CSV export contract and bounded-memory regression tests. */
declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Database\QueryBuilder;
use MuriLinkTracker\Models\Link;
use MuriLinkTracker\REST\CsvExporter;
use MuriLinkTracker\REST\LinksController;
use MuriLinkTracker\Scanner\LinkHtmlEditor;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase {
	private function controller( CsvExporter $exporter ): LinksController {
		return new LinksController(
			$this->createMock( QueryBuilder::class ),
			$this->createMock( LinksRepository::class ),
			$this->createMock( InstancesRepository::class ),
			$this->createMock( LinkHtmlEditor::class ),
			$exporter
		);
	}

	public function test_endpoint_marks_only_successful_stream_responses_as_csv(): void {
		$stream = fopen( 'php://temp', 'w+b' );
		$exporter = $this->createMock( CsvExporter::class );
		$exporter->expects( $this->once() )->method( 'export' )
			->with( $this->anything(), $this->anything(), [ 'is_affiliate' => false, 'per_page' => 100 ] )
			->willReturn( $stream );
		$request = new CsvRestRequest();
		$request->set_param( 'is_affiliate', false );
		$response = $this->controller( $exporter )->export_csv( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $stream, $response->get_data() );
		$this->assertSame( 'text/csv; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertStringContainsString( '.csv', $response->get_headers()['Content-Disposition'] );
		fclose( $stream );
	}

	public function test_endpoint_returns_a_rest_error_when_export_fails(): void {
		$exporter = $this->createMock( CsvExporter::class );
		$exporter->method( 'export' )->willThrowException( new \RuntimeException( 'Cannot write temporary stream' ) );
		$result = $this->controller( $exporter )->export_csv( new CsvRestRequest() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_endpoint_rejects_json_transforms_before_building_a_stream(): void {
		$exporter = $this->createMock( CsvExporter::class );
		$exporter->expects( $this->never() )->method( 'export' );
		$controller = $this->controller( $exporter );
		foreach ( [ '_fields', '_envelope' ] as $parameter ) {
			$request = new CsvRestRequest();
			$request->set_param( $parameter, '1' );
			$result = $controller->export_csv( $request );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}
	private function link( int $id, string $url = 'https://example.com/"quoted",path' ): Link {
		return Link::from_db_row( (object) array(
			'id' => $id, 'url' => $url, 'url_hash' => hash( 'sha256', $url ), 'final_url' => null,
			'http_status' => 200, 'status_category' => 'ok', 'is_external' => 1, 'is_affiliate' => 1,
			'affiliate_network' => '=unsafe()', 'response_time' => 15, 'redirect_count' => 0,
			'last_checked' => null, 'check_count' => 1, 'last_error' => "\n@formula()",
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	public function test_export_returns_a_rewound_stream_and_preserves_csv_cells_and_counts(): void {
		$link = $this->link( 7 );
		$query = $this->createMock( QueryBuilder::class );
		$query->expects( $this->never() )->method( 'query' );
		$query->expects( $this->once() )->method( 'export_batches' )->with( [ 'is_affiliate' => false ] )
			->willReturn( ( static function () use ( $link ) { yield [ $link ]; } )() );
		$instances = $this->createMock( InstancesRepository::class );
		$instances->expects( $this->once() )->method( 'count_by_link_ids' )->with( [ 7 ] )->willReturn( [ 7 => 3 ] );
		$stream = ( new CsvExporter() )->export( $query, $instances, [ 'is_affiliate' => false ] );
		$this->assertIsResource( $stream );
		$this->assertSame( 0, ftell( $stream ) );
		$header = fgetcsv( $stream, null, ',', '"', '' );
		$row = fgetcsv( $stream, null, ',', '"', '' );
		$this->assertCount( 14, $header );
		$this->assertSame( $link->url, $row[1] );
		$this->assertSame( "'=unsafe()", $row[7] );
		$this->assertSame( '3', $row[11] );
		$this->assertSame( "'\n@formula()", $row[13] );
		$this->assertFalse( fgetcsv( $stream, null, ',', '"', '' ) );
		fclose( $stream );
	}

	public function test_large_export_spills_to_disk_instead_of_allocating_the_csv_string(): void {
		$link = $this->link( 1, 'https://example.com/' . str_repeat( 'x', 2048 ) );
		$query = $this->createMock( QueryBuilder::class );
		$query->method( 'export_batches' )->willReturn( ( static function () use ( $link ) {
			for ( $batch = 0; $batch < 60; ++$batch ) { yield array_fill( 0, 100, $link ); }
		} )() );
		$instances = $this->createMock( InstancesRepository::class );
		$instances->method( 'count_by_link_ids' )->willReturn( [] );
		$before = memory_get_usage();
		$stream = ( new CsvExporter() )->export( $query, $instances, [] );
		$allocated = memory_get_usage() - $before;
		$this->assertIsResource( $stream );
		$this->assertGreaterThan( 12 * 1024 * 1024, fstat( $stream )['size'] );
		$this->assertLessThan( 4 * 1024 * 1024, $allocated );
		fclose( $stream );
	}

	public function test_stream_is_served_without_json_and_closed_after_sending(): void {
		$stream = fopen( 'php://temp', 'w+b' );
		fwrite( $stream, "ID,URL\n1,https://example.com\n" ); rewind( $stream );
		$response = new \WP_REST_Response( $stream, 200, [ 'X-MLTR-Export' => 'csv' ] );
		ob_start();
		$served = ( new CsvExporter() )->serve_response( false, $response, new CsvRestRequest(), new \WP_REST_Server() );
		$output = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( "ID,URL\n1,https://example.com\n", $output );
		$this->assertFalse( is_resource( $stream ) );
	}

	public function test_head_request_closes_the_stream_without_sending_a_body(): void {
		$stream = fopen( 'php://temp', 'w+b' ); fwrite( $stream, 'secret' ); rewind( $stream );
		$response = new \WP_REST_Response( $stream, 200, [ 'X-MLTR-Export' => 'csv' ] );
		ob_start();
		$served = ( new CsvExporter() )->serve_response( false, $response, new CsvRestRequest( 'HEAD' ), new \WP_REST_Server() );
		$this->assertSame( '', ob_get_clean() );
		$this->assertTrue( $served );
		$this->assertFalse( is_resource( $stream ) );
	}

	public function test_auth_errors_and_unmarked_responses_are_never_converted_to_csv(): void {
		foreach ( [ 200, 401, 403, 500 ] as $status ) {
			$response = new \WP_REST_Response( [ 'message' => 'Permission denied' ], $status );
			ob_start();
			$served = ( new CsvExporter() )->serve_response( false, $response, new CsvRestRequest(), new \WP_REST_Server() );
			$this->assertSame( '', ob_get_clean() );
			$this->assertFalse( $served );
			$this->assertSame( $status, $response->get_status() );
		}
	}

	public function test_another_route_or_previously_served_response_is_left_alone(): void {
		foreach ( [ [ false, '/other/links/export' ], [ true, '/muri-link-tracker/v1/links/export' ] ] as [ $already_served, $route ] ) {
			$stream = fopen( 'php://temp', 'w+b' );
			$response = new \WP_REST_Response( $stream, 200, [ 'X-MLTR-Export' => 'csv' ] );
			ob_start();
			$served = ( new CsvExporter() )->serve_response( $already_served, $response, new CsvRestRequest( 'GET', $route ), new \WP_REST_Server() );
			$this->assertSame( '', ob_get_clean() );
			$this->assertSame( $already_served, $served );
			$this->assertIsResource( $stream );
			fclose( $stream );
		}
	}

	public function test_formulas_after_whitespace_are_neutralized(): void {
		$exporter = new CsvExporter();
		foreach ( [ '=1+1', '+cmd', '-10', '@SUM(1)', " \t=SUM(1)", "\n@SUM(1)" ] as $value ) {
			$this->assertSame( "'" . $value, $exporter->sanitize_csv_value( $value ) );
		}
		$this->assertSame( 'https://example.com?q=1+2', $exporter->sanitize_csv_value( 'https://example.com?q=1+2' ) );
	}
}

/** Implements the real REST method accessor missing from the minimal unit stub. */
class CsvRestRequest extends \WP_REST_Request {
	public function __construct( private string $csv_method = 'GET', string $route = '/muri-link-tracker/v1/links/export' ) { parent::__construct( $csv_method, $route ); }
	public function get_method(): string { return $this->csv_method; }
	public function has_param( string $key ): bool { return null !== $this->get_param( $key ); }
}
