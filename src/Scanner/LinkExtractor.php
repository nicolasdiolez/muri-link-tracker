<?php
/**
 * Link extraction orchestrator.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Scanner;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Models\Enums\LinkType;
use MuriLinkTracker\Models\ScanResult;

/**
 * Orchestrates link extraction from a WordPress post by combining
 * ContentParser, BlockParser, and LinkClassifier.
 *
 * @since 1.0.0
 */
class LinkExtractor {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ContentParser  $content_parser HTML link extractor.
	 * @param BlockParser    $block_parser   Gutenberg block extractor.
	 * @param LinkClassifier $classifier     Link type and affiliate classifier.
	 */
	public function __construct(
		private readonly ContentParser $content_parser,
		private readonly BlockParser $block_parser,
		private readonly LinkClassifier $classifier,
	) {}

	/**
	 * Extracts all links from a WordPress post, combining all parser outputs.
	 *
	 * Returns a deduplicated structure keyed by URL hash, with all instances
	 * that reference each unique URL.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post             $post     The post to extract links from.
	 * @param array<string, mixed> $settings Plugin settings from mltr_settings option.
	 * @return array<string, array{
	 *     url: string,
	 *     url_hash: string,
	 *     type: LinkType,
	 *     is_affiliate: bool,
	 *     affiliate_network: string|null,
	 *     instances: array<int, array{
	 *         scan_result: ScanResult,
	 *         rel_flags: array{rel_nofollow: bool, rel_sponsored: bool, rel_ugc: bool, is_dofollow: bool}
	 *     }>
	 * }> Grouped by url_hash.
	 */
	public function extract_from_post( \WP_Post $post, array $settings = array() ): array {
		$results = array();

		// Priority 1: Extract from Gutenberg blocks if supported and present.
		$has_blocks = \has_blocks( $post->post_content );
		if ( $has_blocks ) {
			$results = $this->block_parser->parse( $post->post_content );
		}

		// Priority 2: Extract from raw content (only if not already fully covered by blocks or if fallback is needed).
		if ( ! $has_blocks ) {
			$raw_results = $this->content_parser->parse( $post->post_content, 'post_content' );
			$results     = array_merge( $results, $raw_results );
		}

		// Priority 3: Extract from excerpt and custom fields.
		if ( ! empty( $post->post_excerpt ) ) {
			$excerpt_results = $this->content_parser->parse( $post->post_excerpt, 'post_excerpt' );
			$results         = array_merge( $results, $excerpt_results );
		}

		// Parse custom fields if enabled.
		$custom_fields = $settings['scan_custom_fields'] ?? array();
		if ( true === $custom_fields ) {
			$custom_fields = array_filter(
				\get_post_custom_keys( $post->ID ) ?? array(),
				static fn( string $key ): bool => ! \is_protected_meta( $key, 'post' )
			);
		}
		$custom_fields = is_array( $custom_fields ) ? $custom_fields : array();
		if ( ! empty( $custom_fields ) ) {
			foreach ( $custom_fields as $field ) {
				$value = \get_post_meta( $post->ID, $field, true );
				if ( ! empty( $value ) && \is_string( $value ) ) {
					$field_results = $this->content_parser->parse( $value, 'custom_field' );
					$results       = \array_merge( $results, $field_results );
				}
			}
		}

		if ( empty( $results ) ) {
			return array();
		}

		return $this->deduplicate_and_classify( $results, $settings );
	}

	/**
	 * Generates the SHA-256 hash used for URL deduplication.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The URL to hash.
	 * @return string 64-character hex SHA-256 hash.
	 */
	public static function hash_url( string $url ): string {
		return \hash( 'sha256', $url );
	}

	/**
	 * Deduplicates scan results by URL and classifies each unique URL.
	 *
	 * Groups all instances referencing the same URL under a single entry.
	 *
	 * @since 1.0.0
	 *
	 * @param ScanResult[]         $results  Raw scan results from all parsers.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, array{
	 *     url: string,
	 *     url_hash: string,
	 *     type: LinkType,
	 *     is_affiliate: bool,
	 *     affiliate_network: string|null,
	 *     instances: array<int, array{
	 *         scan_result: ScanResult,
	 *         rel_flags: array{rel_nofollow: bool, rel_sponsored: bool, rel_ugc: bool, is_dofollow: bool}
	 *     }>
	 * }>
	 */
	private function deduplicate_and_classify( array $results, array $settings = array() ): array {
		$grouped = array();

		foreach ( $results as $result ) {
			if ( UrlExclusions::matches( $result->url, $settings['excluded_urls'] ?? array() ) ) {
				continue;
			}
			$url_hash = self::hash_url( $result->url );

			// Check for media exclusion if enabled.
			if ( ! empty( $settings['exclude_media'] ) ) {
				if ( $this->is_media_url( $result->url ) ) {
					continue;
				}
			}

			// Initialize the group for this URL if not yet seen.
			if ( ! isset( $grouped[ $url_hash ] ) ) {
				$affiliate = $this->classifier->detect_affiliate( $result->url, $result->rel );

				$grouped[ $url_hash ] = array(
					'url'               => $result->url,
					'url_hash'          => $url_hash,
					'type'              => $this->classifier->classify_type( $result->url ),
					'is_affiliate'      => $affiliate['is_affiliate'],
					'affiliate_network' => $affiliate['network'],
					'instances'         => array(),
				);
			}

			// Add this instance.
			$grouped[ $url_hash ]['instances'][] = array(
				'scan_result' => $result,
				'rel_flags'   => $this->classifier->classify_rel( $result->rel ),
			);
		}

		return $grouped;
	}

	/**
	 * Checks if a URL points to a common media file extension.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The URL to check.
	 * @return bool True if media URL.
	 */
	private function is_media_url( string $url ): bool {
		$parsed = \wp_parse_url( $url );
		$path   = $parsed['path'] ?? '';

		if ( '' === $path ) {
			return false;
		}

		$extension  = \strtolower( \pathinfo( $path, PATHINFO_EXTENSION ) );
		$media_exts = array(
			// Images.
			'jpg',
			'jpeg',
			'png',
			'gif',
			'webp',
			'avif',
			'svg',
			'bmp',
			'ico',
			'tif',
			'tiff',
			// Documents.
			'pdf',
			'doc',
			'docx',
			'xls',
			'xlsx',
			'ppt',
			'pptx',
			'odt',
			'ods',
			'odp',
			// Archives.
			'zip',
			'rar',
			'7z',
			'tar',
			'gz',
			// Audio/Video.
			'mp3',
			'mp4',
			'm4a',
			'wav',
			'ogg',
			'webm',
			'mov',
			'avi',
			'mkv',
			'flv',
		);

		return \in_array( $extension, $media_exts, true );
	}
}
