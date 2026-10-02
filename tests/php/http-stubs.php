<?php
/** Isolated WordPress HTTP boundary for transport-policy unit tests. */

if ( ! class_exists( 'SafeHttpStub' ) ) {
	class SafeHttpStub {
		public static array $responses = array();
		public static array $requests = array();
		public static function reset(): void {
			self::$responses = array();
			self::$requests = array();
		}
	}
}
if ( ! function_exists( 'wp_safe_remote_request' ) ) {
	function wp_safe_remote_request( string $url, array $args = array() ): array|WP_Error {
		SafeHttpStub::$requests[] = array( 'url' => $url, 'args' => $args );
		if ( SafeHttpStub::$responses ) {
			WpHttpStub::$request_log[] = array( 'url' => $url, 'method' => $args['method'] );
			return array_shift( SafeHttpStub::$responses );
		}
		return WpHttpStub::get_response( $args['method'], $url );
	}
}
if ( ! class_exists( 'WP_Http' ) ) {
	/** Only relative-URL composition is simulated; no HTTP request is performed. */
	class WP_Http {
		public static function make_absolute_url( string $url, string $base ): string {
			if ( parse_url( $url, PHP_URL_SCHEME ) ) {
				return $url;
			}
			$parts = parse_url( $base );
			if ( str_starts_with( $url, '//' ) ) {
				return $parts['scheme'] . ':' . $url;
			}
			$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			if ( str_starts_with( $url, '/' ) ) {
				return $origin . $url;
			}
			if ( str_starts_with( $url, '?' ) || str_starts_with( $url, '#' ) ) {
				return $origin . ( $parts['path'] ?? '/' ) . $url;
			}
			$path = dirname( $parts['path'] ?? '/' ) . '/' . $url;
			$segments = array();
			foreach ( explode( '/', $path ) as $segment ) {
				if ( '..' === $segment ) {
					array_pop( $segments );
				} elseif ( '' !== $segment && '.' !== $segment ) {
					$segments[] = $segment;
				}
			}
			return $origin . '/' . implode( '/', $segments );
		}
	}
}
