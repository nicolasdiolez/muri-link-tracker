<?php
/**
 * URL exclusion rules shared by extraction and HTTP checks.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );
namespace MuriLinkTracker\Scanner;

defined( 'ABSPATH' ) || exit;

/** Matches user-configured URL exclusions. */
final class UrlExclusions {
	/**
	 * Match exact URLs or anchored patterns containing an asterisk.
	 *
	 * @param string       $url URL from source content.
	 * @param array<mixed> $patterns User configured exclusions, never raw regular expressions.
	 */
	public static function matches( string $url, array $patterns ): bool {
		$url = self::absolute( trim( $url ) );
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) || '' === trim( $pattern ) ) {
				continue;
			}
			$pattern = self::absolute( trim( $pattern ) );
			$regex   = '~^' . str_replace( '\\*', '.*', preg_quote( $pattern, '~' ) ) . '$~D';
			if ( 1 === preg_match( $regex, $url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve site-relative exclusion patterns to absolute URLs.
	 *
	 * @param string $url Requested link URL.
	 */
	private static function absolute( string $url ): string {
		if ( str_starts_with( $url, '//' ) ) {
			$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
			return ( $scheme ? $scheme : 'https' ) . ':' . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			$site = wp_parse_url( home_url() );
			return ( $site['scheme'] ?? 'https' ) . '://' . ( $site['host'] ?? '' )
				. ( isset( $site['port'] ) ? ':' . $site['port'] : '' ) . $url;
		}
		return $url;
	}
}
