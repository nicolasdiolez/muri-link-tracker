<?php
/** Extraction settings and source isolation regressions. @package MuriLinkTracker\Tests\Unit */
declare( strict_types=1 );
namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Scanner\BlockParser;
use MuriLinkTracker\Scanner\ContentParser;
use MuriLinkTracker\Scanner\LinkClassifier;
use MuriLinkTracker\Scanner\LinkExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once dirname( __DIR__ ) . '/extractor-stubs.php';

#[CoversClass(LinkExtractor::class)]
class LinkExtractorTest extends TestCase {
	private LinkExtractor $extractor;
	private \WP_Post $post;

	protected function setUp(): void {
		parent::setUp();
		\MLTRTestPostMeta::$values = array();
		\MLTRTestPostMeta::$reads = array();
		\MLTRTestPostMeta::$protected_keys = array();
		$parser = new ContentParser();
		$this->extractor = new LinkExtractor( $parser, new BlockParser( $parser ), new LinkClassifier( 'https://example.com' ) );
		$this->post = new \WP_Post( (object) array( 'ID' => 42, 'post_content' => '<a href="https://example.com/page">Content</a>' ) );
	}

	protected function tearDown(): void {
		\MLTRTestPostMeta::$values = array();
		\MLTRTestPostMeta::$reads = array();
		\MLTRTestPostMeta::$protected_keys = array();
		parent::tearDown();
	}

	public function test_boolean_custom_fields_setting_reads_only_public_html_values(): void {
		\MLTRTestPostMeta::$protected_keys = array( 'protected_by_filter' );
		\MLTRTestPostMeta::$values[42] = array(
			'public_html' => '<a href="https://shop.test/product" rel="sponsored">Product</a>',
			'_private_html' => '<a href="https://secret.test/token">Secret</a>',
			'protected_by_filter' => '<a href="https://secret.test/other">Secret</a>',
			'public_array' => array( '<a href="https://array.test">Array</a>' ),
			'plain_text' => 'https://plaintext.test/page',
		);
		$results = $this->extractor->extract_from_post( $this->post, array( 'scan_custom_fields' => true ) );
		$this->assertCount( 2, $results );
		$custom = $results[ LinkExtractor::hash_url( 'https://shop.test/product' ) ];
		$this->assertSame( 'custom_field', $custom['instances'][0]['scan_result']->source_type );
		$this->assertTrue( $custom['instances'][0]['rel_flags']['rel_sponsored'] );
		$this->assertTrue( $custom['is_affiliate'] );
		$this->assertSame( array( 'public_html', 'public_array', 'plain_text' ), array_column( \MLTRTestPostMeta::$reads, 1 ) );
	}

	public function test_disabled_custom_fields_are_not_read(): void {
		\MLTRTestPostMeta::$values[42] = array( 'public_html' => '<a href="https://shop.test/product">Product</a>' );
		$results = $this->extractor->extract_from_post( $this->post, array( 'scan_custom_fields' => false ) );
		$this->assertCount( 1, $results );
		$this->assertSame( array(), \MLTRTestPostMeta::$reads );
	}

	public function test_boolean_custom_fields_handles_posts_without_meta(): void {
		$this->assertCount( 1, $this->extractor->extract_from_post( $this->post, array( 'scan_custom_fields' => true ) ) );
		$this->assertSame( array(), \MLTRTestPostMeta::$reads );
	}

	public function test_legacy_explicit_field_list_scans_only_selected_fields(): void {
		\MLTRTestPostMeta::$values[42] = array(
			'selected' => '<a href="https://shop.test/product">Product</a>',
			'other' => '<a href="https://other.test">Other</a>',
		);
		$results = $this->extractor->extract_from_post( $this->post, array( 'scan_custom_fields' => array( 'selected' ) ) );
		$this->assertCount( 2, $results );
		$this->assertSame( array( array( 42, 'selected', true ) ), \MLTRTestPostMeta::$reads );
	}

	public function test_exclusions_apply_to_content_excerpts_and_public_custom_fields(): void {
		$this->post->post_content .= '<a href="/private/content">Excluded</a>';
		$this->post->post_excerpt = '<a href="https://example.com/private/excerpt">Excluded</a><a href="https://example.com/page" rel="nofollow">Excerpt</a>';
		\MLTRTestPostMeta::$values[42] = array( 'html' => '<a href="//example.com/private/meta">Excluded</a><a href="https://example.com/page">Meta</a>' );
		$results = $this->extractor->extract_from_post( $this->post, array( 'scan_custom_fields' => true, 'excluded_urls' => array( '/private/*' ) ) );
		$this->assertCount( 1, $results );
		$link = reset( $results );
		$this->assertSame( 'https://example.com/page', $link['url'] );
		$this->assertCount( 3, $link['instances'] );
		$this->assertSame( array( 'post_content', 'post_excerpt', 'custom_field' ), array_map( static fn( array $instance ): string => $instance['scan_result']->source_type, $link['instances'] ) );
		$this->assertTrue( $link['instances'][1]['rel_flags']['rel_nofollow'] );
		$this->assertFalse( $link['instances'][0]['rel_flags']['rel_nofollow'] );
	}

	public function test_media_exclusions_inspect_extension_without_query_and_preserve_other_urls(): void {
		$this->post->post_content .= '<a href="https://cdn.test/IMAGE.PDF?download=1">PDF</a><a href="https://cdn.test/page?image=x.pdf">Page</a>';
		$with_media = $this->extractor->extract_from_post( $this->post, array( 'exclude_media' => false ) );
		$without_media = $this->extractor->extract_from_post( $this->post, array( 'exclude_media' => true ) );
		$this->assertCount( 3, $with_media );
		$this->assertCount( 2, $without_media );
		$this->assertArrayNotHasKey( LinkExtractor::hash_url( 'https://cdn.test/IMAGE.PDF?download=1' ), $without_media );
		$this->assertArrayHasKey( LinkExtractor::hash_url( 'https://cdn.test/page?image=x.pdf' ), $without_media );
	}
}
