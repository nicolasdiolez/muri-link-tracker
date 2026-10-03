<?php
/**
 * CSV exporter for link data.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\REST;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\QueryBuilder;
use MuriLinkTracker\Models\Link;

/**
 * Generates and streams a CSV file from the link database.
 *
 * Extracted from LinksController to keep the controller focused on
 * request routing and permission checks.
 *
 * @since 1.0.0
 */
class CsvExporter {

	/**
	 * Generates a rewindable CSV stream for all links matching $args.
	 *
	 * Reads fixed batches and spills CSV data beyond 2 MiB to a temporary file.
	 * The caller owns the returned stream and must close it after serving.
	 *
	 * @since 1.0.0
	 *
	 * @param QueryBuilder        $query_builder  Filtered query builder.
	 * @param InstancesRepository $instances_repo Instances CRUD repository.
	 * @param array<string,mixed> $args           Query args (status, link_type, orderby, etc.).
	 * @return resource Rewound UTF-8 CSV stream, including the header row.
	 * @throws \RuntimeException When the temporary stream cannot be written or read.
	 * @throws \Throwable When a database batch cannot be exported.
	 */
	public function export( QueryBuilder $query_builder, InstancesRepository $instances_repo, array $args ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$csv = fopen( 'php://temp/maxmemory:2097152', 'w+b' );
		if ( false === $csv ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Serialized as a REST error, not rendered as HTML.
			throw new \RuntimeException( __( 'Unable to create the CSV export. Please try again.', 'muri-link-tracker' ) );
		}

		try {

			$this->write_row(
				$csv,
				array(
					__( 'ID', 'muri-link-tracker' ),
					__( 'URL', 'muri-link-tracker' ),
					__( 'Final URL', 'muri-link-tracker' ),
					__( 'HTTP Status', 'muri-link-tracker' ),
					__( 'Status', 'muri-link-tracker' ),
					__( 'Type', 'muri-link-tracker' ),
					__( 'Affiliate', 'muri-link-tracker' ),
					__( 'Network', 'muri-link-tracker' ),
					__( 'Cloaked', 'muri-link-tracker' ),
					__( 'Redirect Count', 'muri-link-tracker' ),
					__( 'Response Time (ms)', 'muri-link-tracker' ),
					__( 'Instances', 'muri-link-tracker' ),
					__( 'Last Checked', 'muri-link-tracker' ),
					__( 'Last Error', 'muri-link-tracker' ),
				)
			);

			foreach ( $query_builder->export_batches( $args ) as $items ) {

				$link_ids        = array_map( fn( Link $link ) => $link->id, $items );
				$instance_counts = $instances_repo->count_by_link_ids( $link_ids );

				foreach ( $items as $link ) {
					$is_cloaked = $link->is_affiliate && ! $link->is_external;
					$this->write_row(
						$csv,
						array(
							$link->id,
							$this->sanitize_csv_value( $link->url ),
							$this->sanitize_csv_value( $link->final_url ?? '' ),
							$link->http_status ?? '',
							$link->status_category->value,
							$link->is_external ? __( 'external', 'muri-link-tracker' ) : __( 'internal', 'muri-link-tracker' ),
							$link->is_affiliate ? __( 'yes', 'muri-link-tracker' ) : __( 'no', 'muri-link-tracker' ),
							$this->sanitize_csv_value( $link->affiliate_network ?? '' ),
							$is_cloaked ? __( 'yes', 'muri-link-tracker' ) : __( 'no', 'muri-link-tracker' ),
							$link->redirect_count,
							$link->response_time ?? '',
							$instance_counts[ $link->id ] ?? 0,
							$link->last_checked?->format( 'Y-m-d H:i:s' ) ?? '',
							$this->sanitize_csv_value( $link->last_error ?? '' ),
						)
					);
				}
			}

			if ( ! rewind( $csv ) ) {
				throw new \RuntimeException( __( 'Unable to read the CSV export. Please try again.', 'muri-link-tracker' ) );
			}
			return $csv;
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Temporary stream, not a WordPress file.
			fclose( $csv );
			throw $error;
		}
	}

	/**
	 * Intercepts a REST response for CSV export to send raw CSV instead of JSON.
	 *
	 * Called via the `rest_pre_serve_request` filter. Returns true to signal
	 * that the request has been served, preventing JSON serialization.
	 *
	 * @since 1.0.0
	 *
	 * @param bool              $served  Whether the request has already been served.
	 * @param \WP_HTTP_Response $result  Response object.
	 * @param \WP_REST_Request  $request Current REST request.
	 * @param \WP_REST_Server   $server  REST server instance.
	 * @return bool True if this method served the response, original $served otherwise.
	 */
	public function serve_response( bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keep the WordPress callback signature.
		$csv = $result->get_data();
		if (
			$served ||
			'/muri-link-tracker/v1/links/export' !== $request->get_route() ||
			200 !== $result->get_status() ||
			'csv' !== ( $result->get_headers()['X-MLTR-Export'] ?? null ) ||
			! is_resource( $csv ) ||
			'stream' !== get_resource_type( $csv )
		) {
			return $served;
		}

		try {
			if ( 'HEAD' !== $request->get_method() ) {
				// The REST server already sent the response's CSV headers.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw, sanitized CSV stream.
				fpassthru( $csv );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Temporary stream, not a WordPress file.
			fclose( $csv );
		}
		return true;
	}

	/**
	 * Writes one RFC 4180 compatible row and refuses a truncated export.
	 *
	 * @param resource $stream Destination stream.
	 * @param array    $values CSV cells.
	 * @throws \RuntimeException When the stream cannot accept another row.
	 */
	private function write_row( $stream, array $values ): void {
		if ( false === fputcsv( $stream, $values, ',', '"', '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Serialized as a REST error, not rendered as HTML.
			throw new \RuntimeException( __( 'Unable to write the CSV export. Please try again.', 'muri-link-tracker' ) );
		}
	}

	/**
	 * Sanitizes a string value for safe CSV output.
	 *
	 * Prevents CSV injection by prefixing cells starting with formula
	 * characters (=, +, -, @), including after whitespace, with an apostrophe.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw cell value.
	 * @return string Sanitized value safe for CSV.
	 */
	public function sanitize_csv_value( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		if ( preg_match( '/^[\x00-\x20]*[=+@-]/', $value ) || in_array( $value[0], array( "\t", "\r", "\n" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
