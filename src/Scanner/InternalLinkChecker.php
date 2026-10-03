<?php
/**
 * Internal link checker — bypasses HTTP for local URLs.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Scanner;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Models\Enums\LinkStatus;

/**
 * Resolves known internal content locally, with an optional safe HTTP fallback.
 *
 * Uses WordPress APIs (url_to_postid, get_page_by_path) for pages
 * and file_exists() for media/uploads, eliminating self-DDoS risk
 * on shared hosting.
 *
 * @since 1.0.0
 */
class InternalLinkChecker {

	/**
	 * Site URL for resolving paths.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	/**
	 * Site URL for relative destinations.
	 *
	 * @var string
	 */
	private readonly string $site_url;

	/**
	 * Upload directory base path.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private readonly string $upload_basedir;

	/**
	 * Upload directory base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private readonly string $upload_baseurl;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string           $site_url       Optional site URL for testability. Defaults to home_url().
	 * @param string           $upload_basedir Optional upload basedir for testability.
	 * @param string           $upload_baseurl Optional upload baseurl for testability.
	 * @param HttpChecker|null $http_checker   Bounded fallback for unknown routes.
	 */
	public function __construct(
		string $site_url = '',
		string $upload_basedir = '',
		string $upload_baseurl = '',
		private readonly ?HttpChecker $http_checker = null,
	) {
		$this->site_url = '' !== $site_url ? $site_url : \home_url();

		if ( '' !== $upload_basedir && '' !== $upload_baseurl ) {
			$this->upload_basedir = $upload_basedir;
			$this->upload_baseurl = $upload_baseurl;
		} else {
			$uploads              = \wp_upload_dir();
			$this->upload_basedir = $uploads['basedir'];
			$this->upload_baseurl = $uploads['baseurl'];
		}
	}

	/**
	 * Checks a batch of internal URLs, using the optional fallback if needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $urls Array of internal URLs to check.
	 * @return array<string, array{
	 *     http_status: int,
	 *     status_category: LinkStatus,
	 *     final_url: string|null,
	 *     response_time: int,
	 *     redirect_count: int,
	 *     redirect_chain: null,
	 *     is_redirect_loop: bool,
	 *     error: string|null,
	 * }> Keyed by the original URL.
	 */
	public function check_batch( array $urls ): array {
		$results = array();
		foreach ( $urls as $url ) {
			$results[ $url ] = $this->check( $url );
		}
		return $results;
	}

	/**
	 * Checks a single internal URL.
	 *
	 * Strategy:
	 * 1. If it looks like an upload file (under wp-content/uploads/), check file_exists().
	 * 2. Otherwise, try url_to_postid() to resolve it as published content.
	 * 3. If unresolved, perform the optional safe HTTP check or report unverified.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The internal URL to check.
	 * @return array{
	 *     http_status: int,
	 *     status_category: LinkStatus,
	 *     final_url: string|null,
	 *     response_time: int,
	 *     redirect_count: int,
	 *     redirect_chain: null,
	 *     is_redirect_loop: bool,
	 *     error: string|null,
	 * }
	 */
	public function check( string $url ): array {
		$abs_url    = $this->ensure_absolute_url( $url );
		$start_time = \microtime( true );

		// 1. Check if this is an upload / media file.
		if ( $this->is_upload_url( $abs_url ) ) {
			$exists  = $this->check_upload_file( $abs_url );
			$elapsed = (int) \round( ( \microtime( true ) - $start_time ) * 1000 );
			return $this->build_result( $exists, $elapsed );
		}

		// 2. Try to resolve as a WordPress post/page/CPT.
		$post_id = \url_to_postid( $abs_url );
		$elapsed = (int) \round( ( \microtime( true ) - $start_time ) * 1000 );

		if ( $post_id > 0 ) {
			$post = \get_post( $post_id );
			// Only consider published posts as OK.
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				return $this->build_result( true, $elapsed );
			}
			return $this->build_result( false, $elapsed, 'post_not_published' );
		}

		// 3. Archives, taxonomies and custom rewrites need an actual HTTP check.
		if ( null !== $this->http_checker ) {
			return $this->http_checker->check( $abs_url );
		}
		$result                    = $this->build_result( false, $elapsed, 'internal_unverified' );
		$result['http_status']     = 0;
		$result['status_category'] = LinkStatus::Skipped;
		return $result;
	}

	/**
	 * Checks whether a URL points to the uploads directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $abs_url Absolute URL.
	 * @return bool
	 */
	private function is_upload_url( string $abs_url ): bool {
		$path_url = explode( '#', explode( '?', $abs_url, 2 )[0], 2 )[0];
		return '' !== $this->upload_baseurl
			&& \str_starts_with( $path_url, rtrim( $this->upload_baseurl, '/' ) . '/' );
	}

	/**
	 * Checks if an upload file exists on disk.
	 *
	 * @since 1.0.0
	 *
	 * @param string $abs_url Absolute URL pointing to an upload.
	 * @return bool
	 */
	private function check_upload_file( string $abs_url ): bool {
		$path_url      = explode( '#', explode( '?', $abs_url, 2 )[0], 2 )[0];
		$relative_path = rawurldecode( \substr( $path_url, \strlen( rtrim( $this->upload_baseurl, '/' ) ) ) );
		if ( str_contains( $relative_path, "\0" ) || str_contains( $relative_path, '\\' ) || in_array( '..', explode( '/', $relative_path ), true ) ) {
			return false;
		}
		$base = realpath( $this->upload_basedir );
		$file = realpath( $this->upload_basedir . $relative_path );
		return false !== $base && false !== $file
			&& str_starts_with( $file, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR )
			&& is_file( $file );
	}

	/**
	 * Resolves a relative URL to absolute.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The URL to resolve.
	 * @return string Absolute URL.
	 */
	private function ensure_absolute_url( string $url ): string {
		if ( \str_starts_with( $url, '//' ) ) {
			$parsed = \wp_parse_url( $this->site_url );
			$scheme = $parsed['scheme'] ?? 'https';
			return $scheme . ':' . $url;
		}

		if ( \str_starts_with( $url, '/' ) ) {
			return \rtrim( $this->site_url, '/' ) . $url;
		}

		return $url;
	}

	/**
	 * Builds a standard check result array.
	 *
	 * @since 1.0.0
	 *
	 * @param bool        $exists        Whether the resource was found.
	 * @param int         $response_time Elapsed time in milliseconds.
	 * @param string|null $note          Optional note for the error field.
	 * @return array{
	 *     http_status: int,
	 *     status_category: LinkStatus,
	 *     final_url: string|null,
	 *     response_time: int,
	 *     redirect_count: int,
	 *     redirect_chain: null,
	 *     is_redirect_loop: bool,
	 *     error: string|null,
	 * }
	 */
	private function build_result( bool $exists, int $response_time, ?string $note = null ): array {
		return array(
			'http_status'      => $exists ? 200 : 404,
			'status_category'  => $exists ? LinkStatus::Ok : LinkStatus::Broken,
			'final_url'        => null,
			'response_time'    => $response_time,
			'redirect_count'   => 0,
			'redirect_chain'   => null,
			'is_redirect_loop' => false,
			'error'            => $note,
		);
	}
}
