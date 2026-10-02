<?php
/**
 * Unit tests for LinkHtmlEditor.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Scanner\LinkHtmlEditor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LinkHtmlEditor class which handles DOM-based link modifications.
 *
 * @since 1.0.0
 */
#[\PHPUnit\Framework\Attributes\CoversClass( LinkHtmlEditor::class )]
class LinkHtmlEditorTest extends TestCase {

	/**
	 * LinkHtmlEditor instance with a mock wpdb.
	 *
	 * @since 1.0.0
	 * @var LinkHtmlEditor
	 */
	private LinkHtmlEditor $editor;

	/**
	 * Sets up the test instance.
	 *
	 * @since 1.0.0
	 */
	protected function setUp(): void {
		parent::setUp();
		// wpdb is stubbed in tests/php/stubs.php (loaded by PHPUnit bootstrap).
		$this->editor = new LinkHtmlEditor( new \wpdb() );
	}

	// -------------------------------------------------------------------------
	// replace_link_in_html — URL replacement
	// -------------------------------------------------------------------------

	/**
	 * @since 1.0.0
	 */
	public function test_replace_link_in_html_replaces_url(): void {
		$html     = '<p>Visit <a href="https://old.com">Old</a> now.</p>';
		$result   = $this->editor->replace_link_in_html( $html, 'https://old.com', 'https://new.com', null );

		$this->assertStringContainsString( 'href="https://new.com"', $result );
		$this->assertStringNotContainsString( 'href="https://old.com"', $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_replace_link_in_html_replaces_rel(): void {
		$html   = '<p><a href="https://example.com">Link</a></p>';
		$result = $this->editor->replace_link_in_html( $html, 'https://example.com', null, 'nofollow' );

		$this->assertStringContainsString( 'rel="nofollow"', $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_replace_link_in_html_removes_rel_when_empty_string(): void {
		$html   = '<p><a href="https://example.com" rel="nofollow">Link</a></p>';
		$result = $this->editor->replace_link_in_html( $html, 'https://example.com', null, '' );

		$this->assertStringNotContainsString( 'rel=', $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_replace_link_in_html_replaces_both_url_and_rel(): void {
		$html   = '<p><a href="https://old.com" rel="dofollow">Link</a></p>';
		$result = $this->editor->replace_link_in_html( $html, 'https://old.com', 'https://new.com', 'nofollow' );

		$this->assertStringContainsString( 'href="https://new.com"', $result );
		$this->assertStringContainsString( 'rel="nofollow"', $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_replace_link_in_html_returns_unchanged_when_url_not_found(): void {
		$html   = '<p><a href="https://other.com">Link</a></p>';
		$result = $this->editor->replace_link_in_html( $html, 'https://notfound.com', 'https://new.com', null );

		$this->assertSame( $html, $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_replace_link_in_html_returns_empty_string_for_empty_html(): void {
		$result = $this->editor->replace_link_in_html( '', 'https://example.com', 'https://new.com', null );

		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// unlink_in_html
	// -------------------------------------------------------------------------

	/**
	 * @since 1.0.0
	 */
	public function test_unlink_in_html_removes_link_and_preserves_text(): void {
		$html   = '<p>Click <a href="https://example.com">here</a> now.</p>';
		$result = $this->editor->unlink_in_html( $html, 'https://example.com' );

		$this->assertStringContainsString( 'here', $result );
		$this->assertStringNotContainsString( '<a ', $result );
		$this->assertStringNotContainsString( 'href=', $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_unlink_in_html_removes_multiple_occurrences(): void {
		$html = '<p><a href="https://example.com">One</a> and <a href="https://example.com">Two</a>.</p>';

		$result = $this->editor->unlink_in_html( $html, 'https://example.com' );

		$this->assertStringContainsString( 'One', $result );
		$this->assertStringContainsString( 'Two', $result );
		$this->assertStringNotContainsString( 'href=', $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_unlink_in_html_leaves_other_links_intact(): void {
		$html   = '<p><a href="https://remove.com">Remove</a> and <a href="https://keep.com">Keep</a>.</p>';
		$result = $this->editor->unlink_in_html( $html, 'https://remove.com' );

		$this->assertStringContainsString( 'href="https://keep.com"', $result );
		$this->assertStringNotContainsString( 'href="https://remove.com"', $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_unlink_in_html_returns_unchanged_when_url_not_found(): void {
		$html   = '<p><a href="https://other.com">Link</a></p>';
		$result = $this->editor->unlink_in_html( $html, 'https://notfound.com' );

		$this->assertSame( $html, $result );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_unlink_in_html_returns_empty_string_for_empty_html(): void {
		$result = $this->editor->unlink_in_html( '', 'https://example.com' );

		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// sanitize_csv_value (via CsvExporter — tested indirectly here for awareness)
	// -------------------------------------------------------------------------

	public function test_unlink_preserves_images_formatting_and_gutenberg_bytes(): void {
		$html = '<!-- wp:paragraph {"dropCap":true} -->' . "\n" . '<p>é <a href="https://example.com"><img src="a.jpg" alt="Été"><strong>été &amp; hiver</strong></a></p>' . "\n<!-- /wp:paragraph -->";
		$expected = str_replace( array( '<a href="https://example.com">', '</a>' ), '', $html );
		$this->assertSame( $expected, $this->editor->unlink_in_html( $html, 'https://example.com' ) );
	}

	public function test_url_only_preserves_each_rel_and_unrelated_markup_exactly(): void {
		$html = '<!-- wp:html --> <a href="https://old.com" rel="sponsored noopener">A</a><a href="https://old.com" rel="external">B</a> <!-- /wp:html -->';
		$this->assertSame( str_replace( 'https://old.com', 'https://new.com', $html ), $this->editor->replace_link_in_html( $html, 'https://old.com', 'https://new.com', null ) );
	}

	public function test_explicit_rel_change_keeps_per_occurrence_unexposed_tokens(): void {
		$html = '<a href="/x" rel="sponsored noopener noreferrer">A</a><a href="/x" rel="external ugc">B</a>';
		$this->assertSame( '<a href="/x" rel="noopener noreferrer nofollow">A</a><a href="/x" rel="external nofollow">B</a>', $this->editor->replace_link_in_html( $html, '/x', null, 'nofollow' ) );
		$this->assertSame( '<a href="/x" rel="noopener noreferrer">A</a><a href="/x" rel="external">B</a>', $this->editor->replace_link_in_html( $html, '/x', null, '' ) );
	}

	public function test_entities_quoted_angle_brackets_and_mixed_case(): void {
		$html = "<A title='a > b' HREF='https://old.com/?a=1&amp;b=2'><em>é</em></A>";
		$this->assertSame( "<A title='a > b' href=\"https://new.com/?a=2&amp;b=3\"><em>é</em></A>", $this->editor->replace_link_in_html( $html, 'https://old.com/?a=1&b=2', 'https://new.com/?a=2&b=3', null ) );
	}

	public function test_comments_scripts_and_textareas_are_not_edited(): void {
		$inert = <<<'HTML'
<!-- <a href="/x">fake</a> --><script>const html = '<a href="/x">fake</a>';</script><textarea><a href="/x">fake</a></textarea>
HTML;
		$html = $inert . '<a href="/x"><img src="/x.jpg"></a>';
		$this->assertSame( $inert . '<img src="/x.jpg">', $this->editor->unlink_in_html( $html, '/x' ) );
		$this->assertSame( $inert . '<a href="/y"><img src="/x.jpg"></a>', $this->editor->replace_link_in_html( $html, '/x', '/y', null ) );
	}

	public function test_nested_anchors_fail_without_serializing_content(): void {
		$this->expectException( \RuntimeException::class );
		$this->editor->unlink_in_html( '<a href="/x">A<a href="/y">B</a></a>', '/x' );
	}

	public function test_unclosed_anchor_fails_without_silent_success(): void {
		$this->expectException( \RuntimeException::class );
		$this->editor->unlink_in_html( '<a href="/x"><img src="/image.jpg">', '/x' );
	}

	public function test_duplicate_href_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		$this->editor->replace_link_in_html( '<a href="/x" href="/y">link</a>', '/x', '/z', null );
	}
	public function test_padded_href_matches_the_normalized_extracted_url(): void {
		$html = '<p><a href="  https://old.com  ">Link</a></p>';
		$this->assertSame( '<p><a href="https://new.com">Link</a></p>', $this->editor->replace_link_in_html( $html, 'https://old.com', 'https://new.com', null ) );
		$this->assertSame( '<p>Link</p>', $this->editor->unlink_in_html( $html, 'https://old.com' ) );
	}
}
