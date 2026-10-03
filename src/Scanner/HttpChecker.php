<?php
/**
 * Bounded HTTP checks through the WordPress safe HTTP API.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Scanner;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Models\Enums\LinkStatus;

/**
 * Validates every destination before following redirects. WordPress handles TLS,
 * proxies and transport-level validation. DNS checks are defence in depth, not a
 * guarantee against DNS rebinding; that also requires outbound network controls.
 */
class HttpChecker {
	private const GET_FALLBACK_CODES = array( 403, 405, 501 );
	private const REDIRECT_CODES     = array( 301, 302, 303, 307, 308 );
	private const MAX_REDIRECTS      = 5;
	private const MAX_RESPONSE_SIZE  = 65536;

	/**
	 * Site URL for relative destinations.
	 *
	 * @var string
	 */
	private readonly string $site_url;
	/**
	 * HTTP user agent.
	 *
	 * @var string
	 */
	private readonly string $user_agent;
	/**
	 * Total HTTP budget in seconds.
	 *
	 * @var int
	 */
	private readonly int $timeout;
	/**
	 * Optional DNS resolver for isolated tests.
	 *
	 * @var \Closure(string): array<string>|null
	 */
	private readonly ?\Closure $resolver;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param int           $timeout  Total HTTP budget per URL, in seconds.
	 * @param string        $site_url Site URL used for relative input URLs.
	 * @param \Closure|null $resolver Optional DNS resolver for isolated tests.
	 */
	public function __construct( int $timeout = 15, string $site_url = '', ?\Closure $resolver = null ) {
		$this->timeout    = max( 1, min( 60, $timeout ) );
		$this->site_url   = '' !== $site_url ? $site_url : \home_url();
		$this->user_agent = 'MuriLinkTracker/' . MLTR_VERSION . '; +' . $this->site_url;
		$this->resolver   = $resolver;
	}

	/**
	 * Validate a URL and each redirect within a shared HTTP budget.
	 *
	 * @param string $url URL to check.
	 * @return array<string, mixed>
	 */
	public function check( string $url ): array {
		$started = microtime( true );
		if ( '' === trim( $url ) ) {
			return $this->result( $url, $url, $started, array(), 0, LinkStatus::Skipped, 'invalid_url' );
		}
		$deadline = $started + $this->timeout;
		$current  = $this->absolute_url( trim( $url ), rtrim( $this->site_url, '/' ) . '/' );
		$chain    = array();
		$visited  = array();
		$method   = 'HEAD';

		while ( true ) {
			if ( $this->is_unsafe_url( $current ) ) {
				return $this->result( $url, $current, $started, $chain, 0, LinkStatus::Skipped, 'ssrf_blocked' );
			}
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0 ) {
				return $this->result( $url, $current, $started, $chain, 0, LinkStatus::Timeout, 'HTTP check timed out.' );
			}

			// Disable automatic redirects: each Location must pass the same checks.
			// The response cap also applies to HEAD on misbehaving servers.
			$response = \wp_safe_remote_request(
				$current,
				array(
					'method'              => $method,
					'timeout'             => $remaining,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'sslverify'           => true,
					'user-agent'          => $this->user_agent,
					'limit_response_size' => self::MAX_RESPONSE_SIZE,
					'decompress'          => false,
					'cookies'             => array(),
					'headers'             => array( 'Accept-Encoding' => 'identity' ),
				)
			);

			if ( \is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				$lower   = strtolower( $message );
				$is_loop = str_contains( $lower, 'too many redirects' ) || str_contains( $lower, 'redirect loop' );
				$status  = str_contains( $lower, 'timed out' ) || str_contains( $lower, 'timeout' ) ? LinkStatus::Timeout : LinkStatus::Error;
				return $this->result( $url, $current, $started, $chain, 0, $is_loop ? LinkStatus::Error : $status, $is_loop ? 'redirect_loop: ' . $message : $message, $is_loop );
			}

			$code = (int) \wp_remote_retrieve_response_code( $response );
			if ( 'HEAD' === $method && in_array( $code, self::GET_FALLBACK_CODES, true ) ) {
				$method = 'GET';
				continue;
			}

			$location = \wp_remote_retrieve_header( $response, 'location' );
			if ( in_array( $code, self::REDIRECT_CODES, true ) && is_string( $location ) && '' !== trim( $location ) ) {
				if ( count( $chain ) >= self::MAX_REDIRECTS ) {
					return $this->result( $url, $current, $started, $chain, 0, LinkStatus::Error, 'too_many_redirects' );
				}
				$visited[ $current ] = true;
				$chain[]             = array(
					'url'    => $current,
					'status' => $code,
				);
				$next                = $this->absolute_url( trim( $location ), $current );
				if ( isset( $visited[ $next ] ) ) {
					return $this->result( $url, $current, $started, $chain, 0, LinkStatus::Error, 'redirect_loop: repeated URL', true );
				}
				$current = $next;
				continue;
			}

			$status = $code >= 200 && $code < 300 ? LinkStatus::Ok : LinkStatus::from_http_status( $code );
			return $this->result( $url, $current, $started, $chain, $code, $status );
		}
	}

	/**
	 * Checks sequentially to retain the WordPress safe HTTP transport.
	 * Queue workers must yield between URLs instead of submitting large batches.
	 *
	 * @param string[] $urls URLs to check.
	 * @return array<string, array<string, mixed>>
	 */
	public function check_batch( array $urls ): array {
		$results = array();
		foreach ( $urls as $url ) {
			$results[ $url ] = $this->check( $url );
		}
		return $results;
	}

	/**
	 * Resolve an HTTP destination and discard its fragment.
	 *
	 * @param string $url  URL or redirect Location.
	 * @param string $base Base URL.
	 * @return string
	 */
	private function absolute_url( string $url, string $base ): string {
		// Fragments never reach the server and must not bypass loop detection.
		// Keep explicit schemes intact so malformed absolute URLs cannot become
		// innocent-looking relative paths before validation.
		$absolute = preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ? $url : \WP_Http::make_absolute_url( $url, $base );
		return explode( '#', $absolute, 2 )[0];
	}

	/**
	 * Fail closed for invalid URLs, userinfo, unsafe ports and nonpublic DNS.
	 * Re-run before every request, including the GET fallback.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private function is_unsafe_url( string $url ): bool {
		if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return true;
		}
		$parsed = \wp_parse_url( $url );
		if ( ! is_array( $parsed ) || ! in_array( strtolower( $parsed['scheme'] ?? '' ), array( 'http', 'https' ), true )
			|| empty( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] )
			|| ( isset( $parsed['port'] ) && ! in_array( $parsed['port'], array( 80, 443, 8080 ), true ) ) ) {
			return true;
		}
		$host = strtolower( trim( $parsed['host'], '[]' ) );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! $this->is_public_ip( $host );
		}
		if ( ! preg_match( '/^(?=.{1,253}\.?$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.?$/D', $host ) ) {
			return true;
		}
		$host = rtrim( $host, '.' );
		if ( in_array( $host, array( 'localhost', 'metadata.google.internal' ), true ) || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}
		$addresses = $this->resolver ? ( $this->resolver )( $host ) : $this->resolve_addresses( $host );
		if ( empty( $addresses ) ) {
			return true;
		}
		foreach ( $addresses as $address ) {
			if ( ! $this->is_public_ip( $address ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve both IPv4 and IPv6 addresses for destination validation.
	 *
	 * @param string $host DNS hostname.
	 * @return string[]
	 */
	private function resolve_addresses( string $host ): array {
		// Native DNS has its own system timeout. Never assume that an IPv4 answer
		// describes the IPv6 destination, and never accept unresolved hostnames.
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $records ) {
			return array();
		}
		$addresses = array();
		foreach ( $records as $record ) {
			if ( isset( $record['ip'] ) ) {
				$addresses[] = $record['ip'];
			} elseif ( isset( $record['ipv6'] ) ) {
				$addresses[] = $record['ipv6'];
			}
		}
		return $addresses;
	}

	/**
	 * Reject addresses outside publicly routable unicast ranges.
	 *
	 * @param string $ip IPv4 or IPv6 address without URL brackets.
	 * @return bool
	 */
	private function is_public_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE ) ) {
			return false;
		}
		// Restrict IPv6 to global unicast 2000::/3, excluding 6to4 and Teredo.
		// Translation and tunnel ranges must not hide a private IPv4 destination.
		$packed = inet_pton( $ip );
		if ( false !== $packed && 4 === strlen( $packed ) && ord( $packed[0] ) >= 224 ) {
			return false;
		}
		if ( false !== $packed && 16 === strlen( $packed ) ) {
			return ( ord( $packed[0] ) & 0xe0 ) === 0x20
				&& ! str_starts_with( $packed, "\x20\x02" )
				&& ! str_starts_with( $packed, "\x20\x01\x00\x00" );
		}
		return true;
	}

	/**
	 * Build the normalized HTTP check result.
	 *
	 * @param string            $original Original input URL.
	 * @param string            $current  Last destination.
	 * @param float             $started  Start timestamp.
	 * @param array<int, array> $chain    Followed redirects.
	 * @param int               $code     HTTP code, or zero if unverified.
	 * @param LinkStatus        $status   Result category.
	 * @param string|null       $error    Optional diagnostic.
	 * @param bool              $loop     Whether a redirect loop was detected.
	 * @return array<string, mixed>
	 */
	private function result( string $original, string $current, float $started, array $chain, int $code, LinkStatus $status, ?string $error = null, bool $loop = false ): array {
		return array(
			'http_status'      => $code,
			'status_category'  => $status,
			'final_url'        => $current !== $original ? $current : null,
			'response_time'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'redirect_count'   => count( $chain ),
			'redirect_chain'   => $chain ? $chain : null,
			'is_redirect_loop' => $loop,
			'error'            => $error,
		);
	}
}
