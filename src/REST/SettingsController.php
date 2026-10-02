<?php
/**
 * REST controller for settings endpoints.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Handles REST API endpoints for plugin settings.
 *
 * @since 1.0.0
 */
class SettingsController extends \WP_REST_Controller {

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
	protected $rest_base = 'settings';

	/**
	 * Default settings values.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'scan_post_types'    => array( 'post', 'page' ),
		'check_timeout'      => 15,
		'batch_size'         => 50,
		'recheck_interval'   => 7,
		'excluded_urls'      => array(),
		'scan_custom_fields' => false,
		'http_request_delay' => 300,
		'exclude_media'      => true,
		'density'            => 'balanced',
	);

	/**
	 * Registers REST routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => $this->get_settings( ... ),
					'permission_callback' => $this->check_permissions( ... ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => $this->update_settings( ... ),
					'permission_callback' => $this->check_permissions( ... ),
					'args'                => $this->get_update_args(),
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
		return current_user_can( 'manage_options' );
	}

	/**
	 * Retrieves the current settings merged with defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = $this->get_merged_settings();

		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * Updates settings (partial update allowed).
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$current = $this->get_merged_settings();
		$params  = $request->get_json_params();
		$updated = $current;
		$errors  = array();

		if ( isset( $params['scan_post_types'] ) ) {
			if ( ! is_array( $params['scan_post_types'] ) ) {
				$errors[] = __( 'scan_post_types must be an array.', 'muri-link-tracker' );
			} else {
				$updated['scan_post_types'] = array_map( 'sanitize_text_field', $params['scan_post_types'] );
			}
		}

		if ( isset( $params['check_timeout'] ) ) {
			$value = (int) $params['check_timeout'];
			if ( $value < 5 || $value > 60 ) {
				$errors[] = __( 'check_timeout must be between 5 and 60.', 'muri-link-tracker' );
			} else {
				$updated['check_timeout'] = $value;
			}
		}

		if ( isset( $params['batch_size'] ) ) {
			$value = (int) $params['batch_size'];
			if ( $value < 10 || $value > 200 ) {
				$errors[] = __( 'batch_size must be between 10 and 200.', 'muri-link-tracker' );
			} else {
				$updated['batch_size'] = $value;
			}
		}

		if ( isset( $params['recheck_interval'] ) ) {
			$value = (int) $params['recheck_interval'];
			if ( $value < 1 || $value > 30 ) {
				$errors[] = __( 'recheck_interval must be between 1 and 30.', 'muri-link-tracker' );
			} else {
				$updated['recheck_interval'] = $value;
			}
		}

		if ( isset( $params['excluded_urls'] ) ) {
			if ( ! is_array( $params['excluded_urls'] ) ) {
				$errors[] = __( 'excluded_urls must be an array.', 'muri-link-tracker' );
			} else {
				$updated['excluded_urls'] = array_values( array_filter( array_map( 'sanitize_text_field', $params['excluded_urls'] ) ) );
				if ( count( $updated['excluded_urls'] ) > 500 ) {
					$errors[] = __( 'Use at most 500 URL exclusion patterns.', 'muri-link-tracker' );
				}
				foreach ( $updated['excluded_urls'] as $pattern ) {
					if ( strlen( $pattern ) > 2048 ) {
						$errors[] = __( 'Each URL exclusion pattern must be at most 2048 bytes.', 'muri-link-tracker' );
						break;
					}
				}
			}
		}

		if ( isset( $params['scan_custom_fields'] ) ) {
			$updated['scan_custom_fields'] = (bool) $params['scan_custom_fields'];
		}

		if ( isset( $params['http_request_delay'] ) ) {
			$value = (int) $params['http_request_delay'];
			if ( $value < 0 || $value > 5000 ) {
				$errors[] = __( 'http_request_delay must be between 0 and 5000.', 'muri-link-tracker' );
			} else {
				$updated['http_request_delay'] = $value;
			}
		}

		if ( isset( $params['exclude_media'] ) ) {
			$updated['exclude_media'] = (bool) $params['exclude_media'];
		}
 
		if ( isset( $params['density'] ) ) {
			$value = sanitize_text_field( $params['density'] );
			if ( ! in_array( $value, array( 'comfortable', 'balanced', 'compact' ), true ) ) {
				$errors[] = __( 'density must be comfortable, balanced, or compact.', 'muri-link-tracker' );
			} else {
				$updated['density'] = $value;
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'mltr_invalid_settings',
				implode( ' ', $errors ),
				array( 'status' => 400 )
			);
		}

		if ( ! update_option( 'mltr_settings', $updated ) && get_option( 'mltr_settings', array() ) !== $updated ) {
			return new \WP_Error( 'mltr_settings_unavailable', __( 'The settings could not be saved. Please retry.', 'muri-link-tracker' ), array( 'status' => 503 ) );
		}

		return new \WP_REST_Response( $updated, 200 );
	}

	/**
	 * Returns the current settings merged with defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private function get_merged_settings(): array {
		$stored = get_option( 'mltr_settings', array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::DEFAULTS, $stored );
	}

	/**
	 * Returns args schema for the update endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_args(): array {
		return array(
			'scan_post_types'    => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'check_timeout'      => array(
				'type'    => 'integer',
				'minimum' => 5,
				'maximum' => 60,
			),
			'batch_size'         => array(
				'type'    => 'integer',
				'minimum' => 10,
				'maximum' => 200,
			),
			'recheck_interval'   => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 30,
			),
			'excluded_urls'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'scan_custom_fields' => array(
				'type' => 'boolean',
			),
			'http_request_delay' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 5000,
			),
			'exclude_media'      => array(
				'type' => 'boolean',
			),
			'density'            => array(
				'type' => 'string',
				'enum' => array( 'comfortable', 'balanced', 'compact' ),
			),
		);
	}
}
