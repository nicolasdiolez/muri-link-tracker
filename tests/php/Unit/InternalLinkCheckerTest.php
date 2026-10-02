<?php
/**
 * Unit tests for InternalLinkChecker.
 *
 * @package MuriLinkTracker\Tests\Unit
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Models\Enums\LinkStatus;
use MuriLinkTracker\Scanner\InternalLinkChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use UrlToPostIdStub;

/**
 * Unit tests for InternalLinkChecker.
 */
#[CoversClass(InternalLinkChecker::class)]
class InternalLinkCheckerTest extends TestCase {

	private InternalLinkChecker $checker;

	protected function setUp(): void {
		parent::setUp();
		UrlToPostIdStub::reset();

		// Create the upload directory for media tests.
		if ( ! is_dir( '/tmp/wp-uploads/2024/01' ) ) {
			mkdir( '/tmp/wp-uploads/2024/01', 0777, true );
		}

		$this->checker = new InternalLinkChecker(
			site_url: 'https://example.com',
			upload_basedir: '/tmp/wp-uploads',
			upload_baseurl: 'https://example.com/wp-content/uploads',
		);
	}

	protected function tearDown(): void {
		UrlToPostIdStub::reset();

		// Clean up test files.
		if ( file_exists( '/tmp/wp-uploads/2024/01/photo.jpg' ) ) {
			unlink( '/tmp/wp-uploads/2024/01/photo.jpg' );
		}

		parent::tearDown();
	}

	public function test_check_published_page_returns_ok(): void {
		UrlToPostIdStub::$next_id = 42;

		// get_post stub in stubs.php returns an object with post_status = 'publish'.
		UrlToPostIdStub::$posts[42] = (object) array(
			'ID'          => 42,
			'post_status' => 'publish',
		);

		$result = $this->checker->check( '/about/' );

		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( LinkStatus::Ok, $result['status_category'] );
		$this->assertNull( $result['error'] );
	}

	public function test_check_unknown_page_is_unverified_without_http_fallback(): void {
		UrlToPostIdStub::$next_id = 0;

		$result = $this->checker->check( '/unknown-page/' );

		// Unknown routes must not be reported as an observed HTTP 200.
		$this->assertSame( 0, $result['http_status'] );
		$this->assertSame( LinkStatus::Skipped, $result['status_category'] );
		$this->assertSame( 'internal_unverified', $result['error'] );
	}

	public function test_check_draft_page_returns_broken(): void {
		UrlToPostIdStub::$next_id = 99;

		UrlToPostIdStub::$posts[99] = (object) array(
			'ID'          => 99,
			'post_status' => 'draft',
		);

		$result = $this->checker->check( '/draft-page/' );

		$this->assertSame( 404, $result['http_status'] );
		$this->assertSame( LinkStatus::Broken, $result['status_category'] );
		$this->assertSame( 'post_not_published', $result['error'] );
	}

	public function test_check_media_file_exists_returns_ok(): void {
		// Create a test file.
		file_put_contents( '/tmp/wp-uploads/2024/01/photo.jpg', 'fake image data' );

		$result = $this->checker->check( 'https://example.com/wp-content/uploads/2024/01/photo.jpg' );

		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( LinkStatus::Ok, $result['status_category'] );
	}

	public function test_check_media_file_missing_returns_broken(): void {
		$result = $this->checker->check( 'https://example.com/wp-content/uploads/2024/01/missing.jpg' );

		$this->assertSame( 404, $result['http_status'] );
		$this->assertSame( LinkStatus::Broken, $result['status_category'] );
	}

	public function test_check_returns_correct_result_format(): void {
		UrlToPostIdStub::$next_id = 0;

		$result = $this->checker->check( '/any-page/' );

		$this->assertArrayHasKey( 'http_status', $result );
		$this->assertArrayHasKey( 'status_category', $result );
		$this->assertArrayHasKey( 'final_url', $result );
		$this->assertArrayHasKey( 'response_time', $result );
		$this->assertArrayHasKey( 'redirect_count', $result );
		$this->assertArrayHasKey( 'redirect_chain', $result );
		$this->assertArrayHasKey( 'is_redirect_loop', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 0, $result['redirect_count'] );
		$this->assertNull( $result['redirect_chain'] );
		$this->assertFalse( $result['is_redirect_loop'] );
	}

	public function test_check_batch_returns_keyed_results(): void {
		UrlToPostIdStub::$next_id = 0;

		$urls    = array( '/page-a/', '/page-b/' );
		$results = $this->checker->check_batch( $urls );

		$this->assertCount( 2, $results );
		$this->assertArrayHasKey( '/page-a/', $results );
		$this->assertArrayHasKey( '/page-b/', $results );
	}

	public function test_check_resolves_relative_url(): void {
		UrlToPostIdStub::$next_id = 0;

		// Relative URL should be resolved against site_url.
		$result = $this->checker->check( '/contact/' );

		// Relative resolution does not invent a successful HTTP response.
		$this->assertSame( LinkStatus::Skipped, $result['status_category'] );
	}
	public function test_unknown_route_uses_safe_http_fallback_and_returns_real_404(): void {
		$http = $this->createMock( \MuriLinkTracker\Scanner\HttpChecker::class );
		$expected = array( 'http_status' => 404, 'status_category' => LinkStatus::Broken );
		$http->expects( $this->once() )->method( 'check' )->with( 'https://example.com/missing/' )->willReturn( $expected );
		$checker = new InternalLinkChecker( 'https://example.com', '/tmp/wp-uploads', 'https://example.com/wp-content/uploads', $http );
		$this->assertSame( $expected, $checker->check( '/missing/' ) );
	}

	public function test_known_published_page_does_not_make_loopback_request(): void {
		UrlToPostIdStub::$next_id = 42;
		$http = $this->createMock( \MuriLinkTracker\Scanner\HttpChecker::class );
		$http->expects( $this->never() )->method( 'check' );
		$checker = new InternalLinkChecker( 'https://example.com', '/tmp/wp-uploads', 'https://example.com/wp-content/uploads', $http );
		$this->assertSame( LinkStatus::Ok, $checker->check( '/published/' )['status_category'] );
	}

	public function test_media_query_and_fragment_do_not_hide_existing_file(): void {
		file_put_contents( '/tmp/wp-uploads/2024/01/photo.jpg', 'fake image' );
		$result = $this->checker->check( 'https://example.com/wp-content/uploads/2024/01/photo.jpg?ver=2#image' );
		$this->assertSame( LinkStatus::Ok, $result['status_category'] );
	}

	public function test_media_traversal_cannot_probe_files_outside_uploads(): void {
		$result = $this->checker->check( 'https://example.com/wp-content/uploads/../../etc/passwd' );
		$this->assertSame( LinkStatus::Broken, $result['status_category'] );
	}

}
