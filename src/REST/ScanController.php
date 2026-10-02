<?php
/**
 * REST controller for scan endpoints.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\REST;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Queue\BatchOrchestrator;
use MuriLinkTracker\Queue\SchedulerBootstrap;

/**
 * Handles REST API endpoints for scan lifecycle (start, status, cancel).
 *
 * @since 1.0.0
 */
class ScanController extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $namespace = 'muri-link-tracker/v1';

	/**
	 * REST base route.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $rest_base = 'scan';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param BatchOrchestrator $orchestrator Batch orchestrator.
	 */
	public function __construct(
		private readonly BatchOrchestrator $orchestrator,
	) {}

	/**
	 * Registers REST routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/start',
array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $this->start_scan( ... ),
					'permission_callback' => $this->check_permissions( ... ),
					'args'                => array(
						'scan_type' => array(
							'type'              => 'string',
							'default'           => 'full',
							'enum'              => array( 'full', 'delta' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => $this->get_status( ... ),
					'permission_callback' => $this->check_permissions( ... ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/cancel',
array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $this->cancel_scan( ... ),
					'permission_callback' => $this->check_permissions( ... ),
				),
			)
		);

		if ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/debug',
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => $this->get_debug_info( ... ),
						'permission_callback' => $this->check_permissions( ... ),
					),
				)
			);
		}

		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/resume',
array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $this->resume_scan( ... ),
					'permission_callback' => $this->check_permissions( ... ),
				),
			)
		);

		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reset',
array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $this->reset_scan( ... ),
					'permission_callback' => $this->check_permissions( ... ),
				),
			)
		);
	}

	/**
	 * Permission check for all endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool
	 */
	public function check_permissions( \WP_REST_Request $request ): bool {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * Checks rate-limit for resource-intensive actions.
	 *
	 * Uses a transient lock to prevent rapid-fire requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action Action identifier (e.g. 'start', 'reset').
	 * @return \WP_Error|null WP_Error if rate-limited, null if OK.
	 */
	private function check_rate_limit( string $action ): ?\WP_Error {
		$transient_key = 'mltr_rate_limit_' . $action;

		if ( false !== \get_transient( $transient_key ) ) {
			return new \WP_Error(
				'mltr_rate_limited',
				\__( 'Please wait a few seconds before trying again.', 'muri-link-tracker' ),
				array( 'status' => 429 )
			);
		}

		\set_transient( $transient_key, 1, 10 );

		return null;
	}

	/**
	 * Starts a scan (full or delta).
	 *
	 * Launches both the link extraction (scan) and HTTP verification (check) phases.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_scan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->guard( function () use ( $request ): \WP_REST_Response|\WP_Error {
			$rate_error = $this->check_rate_limit( 'start' );
			if ( null !== $rate_error ) {
				return $rate_error;
			}

			$current_status = $this->orchestrator->get_status();

			if ( 'running' === $current_status['status'] ) {
				return new \WP_Error(
					'mltr_scan_already_running',
					\__( 'A scan is already in progress.', 'muri-link-tracker' ),
					array( 'status' => 409 )
				);
			}

			$scan_type    = $request->get_param( 'scan_type' );
			$scan_batches = $this->orchestrator->start_scan( $scan_type );

			$status = $this->orchestrator->get_status();
			$status['scanBatches'] = $scan_batches;
			return new \WP_REST_Response( $status, 200 );
		} );
	}

	/**
	 * Returns the current scan status.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->guard( function (): \WP_REST_Response {
			return new \WP_REST_Response( $this->orchestrator->get_status(), 200 );
		} );
	}

	/**
	 * Cancels the current scan.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function cancel_scan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->guard( function (): \WP_REST_Response {
			$this->orchestrator->cancel();

			return new \WP_REST_Response( $this->orchestrator->get_status(), 200 );
		} );
	}

	/**
	 * Resumes the current scan.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resume_scan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->guard( function (): \WP_REST_Response|\WP_Error {
			$rate_error = $this->check_rate_limit( 'resume' );
			if ( null !== $rate_error ) {
				return $rate_error;
			}

			$resumed = $this->orchestrator->resume();

			if ( ! $resumed ) {
				return new \WP_Error(
					'mltr_scan_cannot_resume',
					\__( 'Scan cannot be resumed. It may have already finished or was never started.', 'muri-link-tracker' ),
					array( 'status' => 400 )
				);
			}

			return new \WP_REST_Response( $this->orchestrator->get_status(), 200 );
		} );
	}

	/**
	 * Resets all scan data.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function reset_scan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->guard( function (): \WP_REST_Response|\WP_Error {
			$rate_error = $this->check_rate_limit( 'reset' );
			if ( null !== $rate_error ) {
				return $rate_error;
			}

			$this->orchestrator->reset();

			return new \WP_REST_Response( $this->orchestrator->get_status(), 200 );
		} );
	}

	/**
	 * Returns diagnostic information about Action Scheduler health.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_debug_info( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->guard( function (): \WP_REST_Response {
			return new \WP_REST_Response(
				array(
					'scan_status'  => $this->orchestrator->get_status(),
					'diagnostics'  => \MuriLinkTracker\Queue\SchedulerBootstrap::get_diagnostics(),
					'php_version'  => \PHP_VERSION,
					'wp_version'   => \get_bloginfo( 'version' ),
					'memory_limit' => \defined( 'WP_MEMORY_LIMIT' ) ? \WP_MEMORY_LIMIT : \ini_get( 'memory_limit' ),
					'timestamp'    => \gmdate( 'c' ),
				),
				200
			);
		} );
	}
	/** Translate infrastructure failures to retryable REST errors, without leaking SQL. */
	private function guard( callable $operation ): \WP_REST_Response|\WP_Error {
		try {
			return $operation();
		} catch ( \RuntimeException $error ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[MuriLinkTracker] Scan: ' . $error->getMessage() );
			}
			$busy = str_contains( $error->getMessage(), 'already in progress' );
			return new \WP_Error(
				$busy ? 'mltr_scan_already_running' : 'mltr_scan_unavailable',
				$busy ? __( 'A scan is already in progress.', 'muri-link-tracker' ) : __( 'The scan operation could not be completed. Please retry or check the server logs.', 'muri-link-tracker' ),
				array( 'status' => $busy ? 409 : 503 )
			);
		}
	}

}
