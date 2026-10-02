<?php
/**
 * WordPress function stubs for unit testing without loading WordPress.
 *
 * These stubs provide minimal implementations of WordPress functions
 * used by the plugin classes under test.
 *
 * @package MuriLinkTracker\Tests
 */

namespace {

	function absint( mixed $value ): int { return abs( (int) $value ); }
	function current_user_can( string $capability, mixed ...$args ): bool {
		return $GLOBALS['mltr_test_caps'][ $capability . ( isset( $args[0] ) ? ':' . $args[0] : '' ) ] ?? $GLOBALS['mltr_test_caps'][ $capability ] ?? true;
	}
	function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['mltr_test_options'][ $name ] ?? $default; }
	function wp_revisions_enabled( WP_Post $post ): bool { return $GLOBALS['mltr_test_revisions_enabled'] ?? true; }
	function has_blocks( string $content ): bool { return str_contains( $content, '<!-- wp:' ); }
	function parse_blocks( string $content ): array { return $GLOBALS['mltr_test_blocks'] ?? array( array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $content, 'innerContent' => array( $content ) ) ); }
	function _wp_put_post_revision( array|object $post, bool $autosave = false ): int|WP_Error {
		$GLOBALS['mltr_test_revisions'][] = $post;
		return $GLOBALS['mltr_test_revision_result'] ?? count( $GLOBALS['mltr_test_revisions'] );
	}
	class WP_REST_Controller { protected $namespace; protected $rest_base; }
	class WP_REST_Request {
		private array $params = array();
		public function __construct( private string $method = 'GET', private string $route = '' ) {}
		public function get_route(): string { return $this->route; }
		public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
		public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
		public function get_params(): array { return $this->params; }
		public function get_json_params(): array { return $this->params; }
	}
	class WP_HTTP_Response {
		public function __construct( protected mixed $data = null, protected int $status = 200, protected array $headers = array() ) {}
		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function get_headers(): array { return $this->headers; }
	}
	class WP_REST_Response extends WP_HTTP_Response {}
	class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; }

	// Translation functions.
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			return $text;
		}
	}

	// URL functions.
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( string $path = '' ): string {
			return 'https://example.com' . $path;
		}
	}

	if ( ! function_exists( 'url_to_postid' ) ) {
		/**
		 * Stub for url_to_postid(). Override UrlToPostIdStub::$next_id in tests.
		 */
		function url_to_postid( string $url ): int {
			return UrlToPostIdStub::$next_id;
		}
	}

	if ( ! function_exists( 'wp_upload_dir' ) ) {
		function wp_upload_dir(): array {
			return array(
				'basedir' => '/tmp/wp-uploads',
				'baseurl' => 'https://example.com/wp-content/uploads',
			);
		}
	}

	/**
	 * Stub for url_to_postid() return value control.
	 */
	class UrlToPostIdStub {
		public static int $next_id = 0;
		/** @var array<int, object|null> */
		public static array $posts = array();
		public static function reset(): void {
			self::$next_id = 0;
			self::$posts   = array();
		}
	}

	// WP_Post stub.
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public int $ID = 0;
			public string $post_status = 'publish';
			public string $post_content = '';
			public string $post_excerpt = '';
			public string $post_title = '';
			public string $post_type = 'post';
			public string $post_modified = '2026-01-01 00:00:00';
			public string $post_modified_gmt = '2026-01-01 00:00:00';
			public function __construct( ?object $data = null ) {
				if ( $data ) {
					foreach ( get_object_vars( $data ) as $key => $value ) {
						$this->$key = $value;
					}
				}
			}
		}
	}

	// get_post stub — integrates with UrlToPostIdStub::$posts for testing.
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( int|object|null $post = null, string $output = 'OBJECT', string $filter = 'raw' ): ?WP_Post {
			if ( is_int( $post ) && isset( UrlToPostIdStub::$posts[ $post ] ) ) {
				$data = UrlToPostIdStub::$posts[ $post ];
				return new WP_Post( $data );
			}
			if ( is_int( $post ) && $post > 0 ) {
				// Default: return a published post.
				$obj = new WP_Post();
				$obj->ID = $post;
				$obj->post_status = 'publish';
				return $obj;
			}
			return null;
		}
	}

	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( string $url, int $component = -1 ): mixed {
			return parse_url( $url, $component );
		}
	}

	// Filter functions.
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
			return $value;
		}
	}

	// HTTP functions for HttpChecker tests.
	if ( ! function_exists( 'wp_remote_head' ) ) {
		function wp_remote_head( string $url, array $args = [] ): array|\WP_Error {
			return WpHttpStub::get_response( 'HEAD', $url );
		}
	}

	if ( ! function_exists( 'wp_remote_get' ) ) {
		function wp_remote_get( string $url, array $args = [] ): array|\WP_Error {
			return WpHttpStub::get_response( 'GET', $url );
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( array|\WP_Error $response ): int {
			if ( is_wp_error( $response ) ) {
				return 0;
			}
			return (int) ( $response['response']['code'] ?? 0 );
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		function wp_remote_retrieve_header( array|\WP_Error $response, string $header ): string {
			if ( is_wp_error( $response ) ) {
				return '';
			}
			return $response['headers'][ strtolower( $header ) ] ?? '';
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	// Database constants.
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	// wpdb stub class.
	if ( ! class_exists( 'wpdb' ) ) {
		class wpdb {
			public string $prefix = 'wp_';
			public string $posts  = 'wp_posts';
			public int $insert_id = 0;
			public string $last_error = '';
			public function prepare( $query, ...$args ): string {
				return (string) $query;
			}
			public function get_var( $query = null, $x = 0, $y = 0 ): mixed {
				return null;
			}
			public function get_row( $query = null, $output = 'OBJECT', $y = 0 ): ?object {
				return null;
			}
			public function get_results( $query = null, $output = 'OBJECT' ): array {
				return [];
			}
			public function query( $query ): int|bool {
				return 0;
			}
			public function update( $table, array $data, array $where, $format = null, $where_format = null ): int|false {
				return 1;
			}
			public function esc_like( $text ): string {
				return addcslashes( (string) $text, '_%\\' );
			}
		}
	}

	// Alias used by LinkHtmlEditorTest.
	if ( ! class_exists( 'WpdbStub' ) ) {
		class WpdbStub extends wpdb {}
	}

	// Post cache function.
	if ( ! function_exists( 'clean_post_cache' ) ) {
		function clean_post_cache( int $post_id ): void {}
	}

	// WP_Error stub class.
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;
			private mixed $data;
			public function __construct( string $code = '', string $message = '', mixed $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data = $data;
			}
			public function get_error_data(): mixed { return $this->data; }
			public function get_error_code(): string {
				return $this->code;
			}
			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	/**
	 * Configurable HTTP stub for testing HttpChecker.
	 */
	class WpHttpStub {
		public static array|\WP_Error|null $next_response = null;
		public static array|\WP_Error|null $get_response = null;
		public static array $request_log = [];

		public static function get_response( string $method, string $url ): array|\WP_Error {
			self::$request_log[] = [ 'method' => $method, 'url' => $url ];
			if ( 'GET' === $method && null !== self::$get_response ) {
				return self::$get_response;
			}
			return self::$next_response ?? [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'http_response' => null,
			];
		}

		public static function reset(): void {
			self::$next_response = null;
			self::$get_response  = null;
			self::$request_log   = [];
		}
	}
}

namespace WpOrg\Requests {
	class Requests {
		public static function request_multiple( array $requests, array $options = [] ): array {
			$responses = [];
			foreach ( $requests as $key => $req ) {
				$url    = is_array( $req ) ? $req['url'] : $req;
				$method = is_array( $req ) ? ($req['options']['type'] ?? 'GET') : 'GET';
				$wp_resp = \WpHttpStub::get_response( $method, $url );
				if ( \is_wp_error( $wp_resp ) ) {
					$error_code = $wp_resp->get_error_code();
					$int_code   = is_numeric( $error_code ) ? (int) $error_code : 0;
					$responses[$key] = new Exception( $wp_resp->get_error_message(), $int_code );
					continue;
				}
				$resp = new Response();
				$resp->status_code = $wp_resp['response']['code'] ?? 200;
				$resp->url         = $url;
				if ( isset( $wp_resp['http_response'] ) && $wp_resp['http_response'] ) {
					$resp->history = $wp_resp['http_response']->get_response_object()->history ?? [];
				}
				$responses[$key] = $resp;
			}
			return $responses;
		}
	}
	class Response {
		public $status_code = 200;
		public $url = '';
		public $history = [];
	}
	class Exception extends \Exception {}
}
