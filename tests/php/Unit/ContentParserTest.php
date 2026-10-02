<?php
/**
 * Unit tests for ContentParser.
 *
 * @package MuriLinkTracker\Tests\Unit
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Scanner\ContentParser;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit tests for ContentParser.
 */
#[CoversClass(ContentParser::class)]
class ContentParserTest extends TestCase {

	private ContentParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new ContentParser();
	}

	public function test_parse_extracts_simple_link(): void {
		$html    = '<p><a href="https://example.com">Example</a></p>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 1, $results );
		$this->assertSame( 'https://example.com', $results[0]->url );
	}

	public function test_parse_extracts_multiple_links(): void {
		$html = '<p><a href="https://a.com">A</a> and <a href="https://b.com">B</a></p>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 2, $results );
		$this->assertSame( 'https://a.com', $results[0]->url );
		$this->assertSame( 'https://b.com', $results[1]->url );
	}

	public function test_parse_skips_fragment_only(): void {
		$html    = '<a href="#section">Jump</a>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 0, $results );
	}

	public function test_parse_skips_mailto(): void {
		$html    = '<a href="mailto:user@example.com">Email</a>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 0, $results );
	}

	public function test_parse_skips_tel(): void {
		$html    = '<a href="tel:+1234567890">Call</a>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 0, $results );
	}

	public function test_parse_skips_javascript(): void {
		$html    = '<a href="javascript:void(0)">Click</a>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 0, $results );
	}

	public function test_parse_skips_empty_href(): void {
		$html    = '<a href="">Empty</a>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 0, $results );
	}

	public function test_parse_extracts_anchor_text(): void {
		$html    = '<a href="https://example.com">  Click Here  </a>';
		$results = $this->parser->parse( $html );

		$this->assertSame( 'Click Here', $results[0]->anchor_text );
	}

	public function test_parse_extracts_nested_anchor_text(): void {
		$html    = '<a href="https://example.com"><strong>Bold</strong> text</a>';
		$results = $this->parser->parse( $html );

		$this->assertSame( 'Bold text', $results[0]->anchor_text );
	}

	public function test_parse_extracts_rel_attribute(): void {
		$html    = '<a href="https://example.com" rel="nofollow sponsored">Link</a>';
		$results = $this->parser->parse( $html );

		$this->assertSame( 'nofollow sponsored', $results[0]->rel );
	}

	public function test_parse_returns_empty_rel_when_absent(): void {
		$html    = '<a href="https://example.com">Link</a>';
		$results = $this->parser->parse( $html );

		$this->assertSame( '', $results[0]->rel );
	}

	public function test_parse_handles_empty_html(): void {
		$results = $this->parser->parse( '' );

		$this->assertCount( 0, $results );
	}

	public function test_parse_handles_html_without_links(): void {
		$html    = '<p>No links here</p>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 0, $results );
	}

	public function test_parse_handles_malformed_html(): void {
		$html    = '<p><a href="https://example.com">Unclosed<p>Another paragraph';
		$results = $this->parser->parse( $html );

		$this->assertCount( 1, $results );
		$this->assertSame( 'https://example.com', $results[0]->url );
	}

	public function test_parse_sets_source_type(): void {
		$html    = '<a href="https://example.com">Link</a>';
		$results = $this->parser->parse( $html, 'post_excerpt' );

		$this->assertSame( 'post_excerpt', $results[0]->source_type );
	}

	public function test_parse_defaults_to_post_content_source_type(): void {
		$html    = '<a href="https://example.com">Link</a>';
		$results = $this->parser->parse( $html );

		$this->assertSame( 'post_content', $results[0]->source_type );
	}

	public function test_parse_tracks_link_position(): void {
		$html = '<a href="https://a.com">A</a><a href="https://b.com">B</a><a href="https://c.com">C</a>';
		$results = $this->parser->parse( $html );

		$this->assertSame( 0, $results[0]->link_position );
		$this->assertSame( 1, $results[1]->link_position );
		$this->assertSame( 2, $results[2]->link_position );
	}

	public function test_parse_sets_null_block_name(): void {
		$html    = '<a href="https://example.com">Link</a>';
		$results = $this->parser->parse( $html );

		$this->assertNull( $results[0]->block_name );
	}

	public function test_parse_handles_relative_urls(): void {
		$html    = '<a href="/about">About</a>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 1, $results );
		$this->assertSame( '/about', $results[0]->url );
	}

	public function test_parse_skips_data_urls(): void {
		$html    = '<a href="data:text/html,<h1>Hello</h1>">Data</a>';
		$results = $this->parser->parse( $html );

		$this->assertCount( 0, $results );
	}

	public function test_parse_handles_uppercase_tags_and_attribute_whitespace(): void {
		$results = $this->parser->parse( '<A HREF = "  HTTPS://example.com/About  ">About</A>' );
		$this->assertCount( 1, $results );
		$this->assertSame( 'HTTPS://example.com/About', $results[0]->url );
	}

	public function test_parse_skips_all_non_http_schemes_case_insensitively(): void {
		$schemes = array( 'MAILTO:user@example.com', 'TEL:+12345', 'JaVaScRiPt:alert(1)', 'DATA:text/plain,test', 'FTP://files.example.com/a', 'blob:https://example.com/id', 'custom+app://open' );
		foreach ( $schemes as $url ) {
			$this->assertSame( array(), $this->parser->parse( '<a href="  ' . $url . '  ">Link</a>' ), $url );
		}
		$this->assertCount( 1, $this->parser->parse( '<a href="//example.com/page">Link</a>' ) );
		$this->assertCount( 1, $this->parser->parse( '<a href="../page">Link</a>' ) );
	}

	public function test_parse_restores_the_callers_libxml_error_mode(): void {
		$original = libxml_use_internal_errors();
		try {
			foreach ( array( false, true ) as $mode ) {
				libxml_use_internal_errors( $mode );
				$this->parser->parse( '<a href="https://example.com"><invalid>Link</a>' );
				$this->assertSame( $mode, libxml_use_internal_errors() );
			}
		} finally {
			libxml_use_internal_errors( $original );
		}
	}
}
