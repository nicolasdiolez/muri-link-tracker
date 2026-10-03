<?php
/**
 * Surgical link edits which leave unrelated markup and Gutenberg comments intact.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Scanner;

defined( 'ABSPATH' ) || exit;

/** Edits anchor byte ranges while preserving surrounding HTML. */
class LinkHtmlEditor {
	/**
	 * Initialize the service dependencies.
	 *
	 * @param \wpdb $wpdb WordPress database connection.
	 */
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * Change only requested attributes. Unexposed rel tokens belong to each
	 * occurrence and must survive a global change to the three tracked flags.
	 *
	 * @param string      $html Original source HTML.
	 * @param string      $old_url Exact URL to replace.
	 * @param string|null $new_url Replacement URL, or null to retain the URL.
	 * @param string|null $new_rel Requested managed rel flags, or null to retain them.
	 */
	public function replace_link_in_html( string $html, string $old_url, ?string $new_url, ?string $new_rel ): string {
		$edits = array();
		foreach ( $this->anchor_tokens( $html ) as $token ) {
			if ( $token['closing'] || $this->attribute( $token['html'], 'href' ) !== $old_url ) {
				continue;
			}
			$tag = $token['html'];
			if ( null !== $new_url && $new_url !== $old_url ) {
				$tag = $this->set_attribute( $tag, 'href', $new_url );
			}
			if ( null !== $new_rel ) {
				$rel         = $this->attribute( $tag, 'rel' ) ?? '';
				$updated_rel = self::merge_rel( $rel, $new_rel );
				if ( $updated_rel !== $rel ) {
					$tag = $this->set_attribute( $tag, 'rel', '' === $updated_rel ? null : $updated_rel );
				}
			}
			if ( $tag !== $token['html'] ) {
				$edits[] = array( $token['offset'], strlen( $token['html'] ), $tag );
			}
		}
		return $this->apply_edits( $html, $edits );
	}

	/**
	 * Remove the wrapper alone, preserving images, formatting and every child.
	 *
	 * @param string $html Original source HTML.
	 * @param string $url Requested link URL.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function unlink_in_html( string $html, string $url ): string {
		$edits = array();
		$open  = null;
		foreach ( $this->anchor_tokens( $html ) as $token ) {
			if ( ! $token['closing'] ) {
				if ( null !== $open ) {
					throw new \RuntimeException( 'The content contains nested or unclosed links. Edit it in WordPress first.' );
				}
				$open = $token;
				continue;
			}
			if ( null !== $open && $this->attribute( $open['html'], 'href' ) === $url ) {
				$edits[] = array( $open['offset'], strlen( $open['html'] ), '' );
				$edits[] = array( $token['offset'], strlen( $token['html'] ), '' );
			}
			$open = null;
		}
		if ( null !== $open && $this->attribute( $open['html'], 'href' ) === $url ) {
			throw new \RuntimeException( 'The link has no closing tag. Edit it in WordPress first.' );
		}
		return $this->apply_edits( $html, $edits );
	}

	/**
	 * Count current anchors, independently of a potentially stale inventory.
	 *
	 * @param string $html Original source HTML.
	 * @param string $url Requested link URL.
	 */
	public function count_matches( string $html, string $url ): int {
		$count = 0;
		foreach ( $this->anchor_tokens( $html ) as $token ) {
			if ( ! $token['closing'] && $this->attribute( $token['html'], 'href' ) === $url ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Replace managed rel flags while retaining all unexposed tokens.
	 *
	 * @param string $original Original value before the requested change.
	 * @param string $requested Requested managed rel flags.
	 */
	public static function merge_rel( string $original, string $requested ): string {
		$managed  = array( 'nofollow', 'sponsored', 'ugc', 'dofollow' );
		$existing = preg_split( '/\s+/', trim( $original ), -1, PREG_SPLIT_NO_EMPTY );
		$wanted   = preg_split( '/\s+/', strtolower( trim( $requested ) ), -1, PREG_SPLIT_NO_EMPTY );
		$existing = false === $existing ? array() : $existing;
		$wanted   = false === $wanted ? array() : $wanted;
		$tokens   = array_values( array_filter( $existing, static fn( string $token ): bool => ! in_array( strtolower( $token ), $managed, true ) ) );
		foreach ( array( 'nofollow', 'sponsored', 'ugc' ) as $token ) {
			if ( in_array( $token, $wanted, true ) ) {
				$tokens[] = $token;
			}
		}
		return implode( ' ', array_unique( $tokens ) );
	}

	/**
	 * Save exact previous content as a native revision before a guarded write.
	 * The caller owns the surrounding transaction and clears caches on commit
	 * AND rollback. Keeping post_modified is intentional (SEO publication dates).
	 *
	 * @param \WP_Post             $original Original value before the requested change.
	 * @param array<string,string> $fields Changed post_content/post_excerpt fields.
	 * @return int Revision ID, usable in WordPress's native revision screen.
	 *
	 * @throws \InvalidArgumentException When input cannot safely identify the requested mutation.
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	public function update_post_fields_silently( \WP_Post $original, array $fields ): int {
		$wpdb = $this->wpdb;
		if ( ! $fields || array_diff( array_keys( $fields ), array( 'post_content', 'post_excerpt' ) ) ) {
			throw new \InvalidArgumentException( 'Only content and excerpt can be changed.' );
		}
		if ( ! function_exists( '_wp_put_post_revision' ) ) {
			throw new \RuntimeException( 'WordPress revisions are unavailable; no content was changed.' );
		}
		// Always store the pre-edit snapshot, independently of whether it matches
		// the latest automatic revision. The service requires revisions enabled.
		$revision = \_wp_put_post_revision( $original );
		if ( \is_wp_error( $revision ) || ! $revision ) {
			throw new \RuntimeException( 'Could not save a recovery revision; no content was changed.' );
		}
		$assignments = array();
		$args        = array( $this->wpdb->posts );
		foreach ( $fields as $field => $value ) {
			$assignments[] = "$field = %s";
			$args[]        = $value;
		}
		$args[] = $original->ID;
		$args[] = $original->post_content;
		$args[] = $original->post_excerpt;
		$args[] = $original->post_modified_gmt;
		$sql    = 'UPDATE %i SET ' . implode( ', ', $assignments ) . ' WHERE ID = %d AND BINARY post_content = BINARY %s AND BINARY post_excerpt = BINARY %s AND post_modified_gmt = %s';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL structure is built from fixed fragments and placeholders; every value is passed to prepare().
		$updated = $this->wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		if ( 1 !== $updated ) {
			throw new \RuntimeException( 'The post changed concurrently or could not be saved. Refresh and retry.' );
		}
		return (int) $revision;
	}


	/**
	 * Read lexical tokens without serializing the document. Comments (including
	 * Gutenberg JSON), quoted > characters and raw-text elements are skipped.
	 * Ambiguous/incomplete markup is never normalized into a different document.
	 *
	 * @param string $html Original source HTML.
	 * @return array<int,array{offset:int,html:string,closing:bool}>
	 */
	private function anchor_tokens( string $html ): array {
		$tokens   = array();
		$length   = strlen( $html );
		$offset   = 0;
		$raw_tags = array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext' );
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Advance through byte offsets until strpos returns false; zero is a valid offset.
		while ( false !== ( $start = strpos( $html, '<', $offset ) ) ) {
			if ( '<![CDATA[' === substr( $html, $start, 9 ) ) {
				$end = strpos( $html, ']]>', $start + 9 );
				if ( false === $end ) {
					break;
				}
				$offset = $end + 3;
				continue;
			}

			if ( '<!--' === substr( $html, $start, 4 ) ) {
				$end = strpos( $html, '-->', $start + 4 );
				if ( false === $end ) {
					break;
				}
				$offset = $end + 3;
				continue;
			}
			if ( in_array( substr( $html, $start, 2 ), array( '<?', '<!' ), true ) ) {
				$end = strpos( $html, '>', $start + 2 );
				if ( false === $end ) {
					break;
				}
				$offset = $end + 1;
				continue;
			}
			if ( ! preg_match( '/\G<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)(?=[\s\/>])/', $html, $match, 0, $start ) ) {
				$offset = $start + 1;
				continue;
			}
			$quote = null;
			$end   = $start + strlen( $match[0] );
			for ( ; $end < $length; ++$end ) {
				$char = $html[ $end ];
				if ( null !== $quote ) {
					if ( $char === $quote ) {
						$quote = null;
					}
				} elseif ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( '>' === $char ) {
					break;
				}
			}
			if ( $end >= $length ) {
				break;
			}
			$offset  = $end + 1;
			$name    = strtolower( $match[2] );
			$closing = '/' === $match[1];
			if ( 'a' === $name ) {
				$tokens[] = array(
					'offset'  => $start,
					'html'    => substr( $html, $start, $offset - $start ),
					'closing' => $closing,
				);
			}
			if ( ! $closing && in_array( $name, $raw_tags, true ) ) {
				if ( 'plaintext' === $name || ! preg_match( '~</' . $name . '\s*>~i', $html, $raw_end, PREG_OFFSET_CAPTURE, $offset ) ) {
					break;
				}
				$offset = $raw_end[0][1] + strlen( $raw_end[0][0] );
			}
		}
		return $tokens;
	}

	/**
	 * Parse attribute values and byte offsets without serializing the tag.
	 *
	 * @param string $tag Original anchor start tag.
	 * @return array<string,array{offset:int,length:int,value:string}>
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	private function attributes( string $tag ): array {
		$attributes = array();
		$pattern    = '~\G\s+([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+)))?~';
		preg_match( '~^<a\b~i', $tag, $opening );
		$offset = strlen( $opening[0] ?? '' );
		while ( preg_match( $pattern, $tag, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset ) ) {
			$name = strtolower( $match[1][0] );
			if ( isset( $attributes[ $name ] ) ) {
				throw new \RuntimeException( 'The link contains duplicate attributes. Edit it in WordPress first.' );
			}
			$value               = $match[2][0] ?? $match[3][0] ?? $match[4][0] ?? '';
			$attributes[ $name ] = array(
				'offset' => $match[0][1],
				'length' => strlen( $match[0][0] ),
				'value'  => html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			);
			$offset             += strlen( $match[0][0] );
		}
		return $attributes;
	}

	/**
	 * Read one decoded attribute from an anchor tag.
	 *
	 * @param string $tag Original anchor start tag.
	 * @param string $name Attribute name.
	 */
	private function attribute( string $tag, string $name ): ?string {
		$value = $this->attributes( $tag )[ $name ]['value'] ?? null;
		return 'href' === $name && null !== $value ? trim( $value ) : $value;
	}

	/**
	 * Replace or remove one attribute without rewriting the rest of the tag.
	 *
	 * @param string      $tag Original anchor start tag.
	 * @param string      $name Attribute name.
	 * @param string|null $value Replacement attribute value, or null to remove it.
	 */
	private function set_attribute( string $tag, string $name, ?string $value ): string {
		$attributes  = $this->attributes( $tag );
		$replacement = null === $value ? '' : ' ' . $name . '="' . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) . '"';
		if ( isset( $attributes[ $name ] ) ) {
			$attribute = $attributes[ $name ];
			return substr_replace( $tag, $replacement, $attribute['offset'], $attribute['length'] );
		}
		return substr_replace( $tag, $replacement, -1, 0 );
	}

	/**
	 * Apply byte replacements from the end so earlier offsets stay valid.
	 *
	 * @param string                           $html Original source HTML.
	 * @param array<int,array{int,int,string}> $edits Byte offsets, lengths and replacement strings.
	 */
	private function apply_edits( string $html, array $edits ): string {
		usort( $edits, static fn( array $a, array $b ): int => $b[0] <=> $a[0] );
		foreach ( $edits as [ $start, $length, $replacement ] ) {
			$html = substr_replace( $html, $replacement, $start, $length );
		}
		return $html;
	}
}
