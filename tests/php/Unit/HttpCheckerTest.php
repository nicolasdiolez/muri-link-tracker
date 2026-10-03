<?php
/**
 * Unit tests for HttpChecker.
 *
 * @package MuriLinkTracker\Tests\Unit
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Models\Enums\LinkStatus;
use MuriLinkTracker\Scanner\HttpChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use WpHttpStub;
use SafeHttpStub;

require_once dirname( __DIR__ ) . '/http-stubs.php';

/**
 * Unit tests for HttpChecker.
 */
#[CoversClass(HttpChecker::class)]
class HttpCheckerTest extends TestCase {

	private HttpChecker $checker;

	protected function setUp(): void {
		parent::setUp();
		WpHttpStub::reset();
		SafeHttpStub::reset();
		$this->checker = new HttpChecker( timeout: 5, site_url: 'https://example.com', resolver: static fn( string $host ): array => array( '93.184.215.14' ) );
	}

	protected function tearDown(): void {
		WpHttpStub::reset();
		SafeHttpStub::reset();
		parent::tearDown();
	}

	public function test_check_returns_ok_for_200(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'https://target.com/page' );

		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( LinkStatus::Ok, $result['status_category'] );
		$this->assertNull( $result['error'] );
	}

	public function test_check_returns_redirect_for_301(): void {
		WpHttpStub::$next_response = $this->make_response( 301 );

		$result = $this->checker->check( 'https://target.com/old' );

		$this->assertSame( 301, $result['http_status'] );
		$this->assertSame( LinkStatus::Redirect, $result['status_category'] );
	}

	public function test_check_returns_redirect_for_302(): void {
		WpHttpStub::$next_response = $this->make_response( 302 );

		$result = $this->checker->check( 'https://target.com/temp' );

		$this->assertSame( 302, $result['http_status'] );
		$this->assertSame( LinkStatus::Redirect, $result['status_category'] );
	}

	public function test_check_returns_broken_for_404(): void {
		WpHttpStub::$next_response = $this->make_response( 404 );

		$result = $this->checker->check( 'https://target.com/missing' );

		$this->assertSame( 404, $result['http_status'] );
		$this->assertSame( LinkStatus::Broken, $result['status_category'] );
	}

	public function test_check_returns_broken_for_410(): void {
		WpHttpStub::$next_response = $this->make_response( 410 );

		$result = $this->checker->check( 'https://target.com/gone' );

		$this->assertSame( 410, $result['http_status'] );
		$this->assertSame( LinkStatus::Broken, $result['status_category'] );
	}

	public function test_check_returns_error_for_500(): void {
		WpHttpStub::$next_response = $this->make_response( 500 );

		$result = $this->checker->check( 'https://target.com/error' );

		$this->assertSame( 500, $result['http_status'] );
		$this->assertSame( LinkStatus::Error, $result['status_category'] );
	}

	public function test_check_returns_timeout_on_timeout_error(): void {
		WpHttpStub::$next_response = new \WP_Error( 'http_request_failed', 'Connection timed out' );

		$result = $this->checker->check( 'https://target.com/slow' );

		$this->assertSame( 0, $result['http_status'] );
		$this->assertSame( LinkStatus::Timeout, $result['status_category'] );
		$this->assertStringContainsString( 'timed out', $result['error'] );
	}

	public function test_check_returns_error_on_generic_wp_error(): void {
		WpHttpStub::$next_response = new \WP_Error( 'ssl_error', 'SSL certificate problem' );

		$result = $this->checker->check( 'https://target.com/bad-ssl' );

		$this->assertSame( 0, $result['http_status'] );
		$this->assertSame( LinkStatus::Error, $result['status_category'] );
		$this->assertStringContainsString( 'SSL', $result['error'] );
	}

	public function test_check_falls_back_to_get_on_405(): void {
		WpHttpStub::$next_response = $this->make_response( 405 );
		WpHttpStub::$get_response  = $this->make_response( 200 );

		$result = $this->checker->check( 'https://target.com/no-head' );

		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( LinkStatus::Ok, $result['status_category'] );

		// Verify both HEAD and GET were called.
		$methods = array_column( WpHttpStub::$request_log, 'method' );
		$this->assertContains( 'HEAD', $methods );
		$this->assertContains( 'GET', $methods );
	}

	public function test_check_falls_back_to_get_on_403(): void {
		WpHttpStub::$next_response = $this->make_response( 403 );
		WpHttpStub::$get_response  = $this->make_response( 200 );

		$result = $this->checker->check( 'https://target.com/forbidden-head' );

		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( LinkStatus::Ok, $result['status_category'] );
	}

	public function test_check_captures_response_time(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'https://target.com/page' );

		$this->assertIsInt( $result['response_time'] );
		$this->assertGreaterThanOrEqual( 0, $result['response_time'] );
	}

	public function test_check_resolves_relative_url(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$this->checker->check( '/internal-page' );

		// Verify the request was made with the absolute URL.
		$this->assertNotEmpty( WpHttpStub::$request_log );
		$this->assertStringStartsWith( 'https://example.com/', WpHttpStub::$request_log[0]['url'] );
	}

	public function test_check_resolves_protocol_relative_url(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$this->checker->check( '//cdn.example.com/resource' );

		$this->assertStringStartsWith( 'https://', WpHttpStub::$request_log[0]['url'] );
	}

	// --- Phase 5: Redirect chain and loop detection ---

	public function test_check_returns_redirect_chain_keys(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'https://target.com/page' );

		$this->assertArrayHasKey( 'redirect_chain', $result );
		$this->assertArrayHasKey( 'is_redirect_loop', $result );
	}

	public function test_check_null_chain_for_direct_200(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'https://target.com/page' );

		$this->assertNull( $result['redirect_chain'] );
		$this->assertFalse( $result['is_redirect_loop'] );
	}

	public function test_check_extracts_redirect_chain(): void {
		SafeHttpStub::$responses = array(
			$this->make_response( 301, array( 'location' => 'https://b.com/' ) ),
			$this->make_response( 302, array( 'location' => '/final' ) ),
			$this->make_response( 200 ),
		);
		$result = $this->checker->check( 'https://a.com/' );
		$this->assertCount( 2, $result['redirect_chain'] );
		$this->assertSame( 'https://a.com/', $result['redirect_chain'][0]['url'] );
		$this->assertSame( 301, $result['redirect_chain'][0]['status'] );
		$this->assertSame( 'https://b.com/final', $result['final_url'] );
		$this->assertFalse( $result['is_redirect_loop'] );
	}

	public function test_check_detects_redirect_loop_in_chain(): void {
		SafeHttpStub::$responses = array(
			$this->make_response( 301, array( 'location' => 'https://b.com/' ) ),
			$this->make_response( 302, array( 'location' => 'https://a.com/' ) ),
		);
		$result = $this->checker->check( 'https://a.com/' );
		$this->assertTrue( $result['is_redirect_loop'] );
		$this->assertCount( 2, SafeHttpStub::$requests );
	}

	public function test_check_detects_redirect_loop_from_wp_error(): void {
		WpHttpStub::$next_response = new \WP_Error( 'http_request_failed', 'Too many redirects' );

		$result = $this->checker->check( 'https://target.com/loop' );

		$this->assertTrue( $result['is_redirect_loop'] );
		$this->assertStringStartsWith( 'redirect_loop:', $result['error'] );
		$this->assertSame( LinkStatus::Error, $result['status_category'] );
	}

	// --- SSRF Prevention ---

	public function test_check_blocks_loopback_ip(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'http://127.0.0.1/admin' );

		$this->assertSame( 0, $result['http_status'] );
		$this->assertSame( LinkStatus::Skipped, $result['status_category'] );
		$this->assertSame( 'ssrf_blocked', $result['error'] );
	}

	public function test_check_blocks_private_ip(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'http://192.168.1.1/admin' );

		$this->assertSame( 0, $result['http_status'] );
		$this->assertSame( LinkStatus::Skipped, $result['status_category'] );
		$this->assertSame( 'ssrf_blocked', $result['error'] );
	}

	public function test_check_blocks_cloud_metadata_ip(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'http://169.254.169.254/latest/meta-data/' );

		$this->assertSame( 0, $result['http_status'] );
		$this->assertSame( LinkStatus::Skipped, $result['status_category'] );
		$this->assertSame( 'ssrf_blocked', $result['error'] );
	}

	public function test_check_allows_public_url(): void {
		WpHttpStub::$next_response = $this->make_response( 200 );

		$result = $this->checker->check( 'https://google.com/page' );

		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( LinkStatus::Ok, $result['status_category'] );
	}

	public function test_nonpublic_literals_and_unsafe_urls_never_reach_transport(): void {
		foreach ( array(
			'http://[::1]/', 'http://[fd00:ec2::254]/', 'http://[fe80::1]/',
			'http://[::ffff:127.0.0.1]/', 'http://10.0.0.1/', 'http://100.64.0.1/',
			'http://0.0.0.0/', 'http://224.0.0.1/', 'file:///etc/passwd',
			'ftp://example.com/file', 'https://user:pass@example.com/', '', 'http:///invalid',
			'http://example.com:22/', 'http://metadata.google.internal./',
		) as $url ) {
			$result = $this->checker->check( $url );
			$this->assertSame( LinkStatus::Skipped, $result['status_category'], $url );
		}
		$this->assertSame( array(), SafeHttpStub::$requests );
	}

	public function test_every_resolved_address_must_be_public(): void {
		foreach ( array( array(), array( '93.184.215.14', '10.0.0.1' ), array( '93.184.215.14', 'fd00::1' ) ) as $addresses ) {
			$checker = new HttpChecker( 5, 'https://example.com', static fn(): array => $addresses );
			$this->assertSame( LinkStatus::Skipped, $checker->check( 'https://target.com/' )['status_category'] );
		}
		$this->assertSame( array(), SafeHttpStub::$requests );
	}

	public function test_redirect_to_private_destination_is_blocked_before_second_request(): void {
		foreach ( array( 'http://127.0.0.1/admin', 'http://[::1]/', 'http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd' ) as $target ) {
			SafeHttpStub::reset();
			SafeHttpStub::$responses = array( $this->make_response( 302, array( 'location' => $target ) ) );
			$result = $this->checker->check( 'https://target.com/' );
			$this->assertSame( LinkStatus::Skipped, $result['status_category'] );
			$this->assertSame( 'ssrf_blocked', $result['error'] );
			$this->assertCount( 1, SafeHttpStub::$requests );
		}
	}

	public function test_dns_is_rechecked_before_get_fallback(): void {
		$calls = 0;
		$checker = new HttpChecker( 5, 'https://example.com', static function () use ( &$calls ): array {
			return ++$calls === 1 ? array( '93.184.215.14' ) : array( '127.0.0.1' );
		} );
		SafeHttpStub::$responses = array( $this->make_response( 405 ) );
		$result = $checker->check( 'https://target.com/' );
		$this->assertSame( LinkStatus::Skipped, $result['status_category'] );
		$this->assertCount( 1, SafeHttpStub::$requests );
	}

	public function test_safe_transport_arguments_and_shared_time_budget(): void {
		SafeHttpStub::$responses = array( $this->make_response( 405 ), $this->make_response( 200 ) );
		$this->checker->check( 'https://target.com/' );
		$this->assertCount( 2, SafeHttpStub::$requests );
		$this->assertSame( 'HEAD', SafeHttpStub::$requests[0]['args']['method'] );
		$this->assertSame( 'GET', SafeHttpStub::$requests[1]['args']['method'] );
		foreach ( SafeHttpStub::$requests as $request ) {
			$this->assertSame( 0, $request['args']['redirection'] );
			$this->assertTrue( $request['args']['reject_unsafe_urls'] );
			$this->assertTrue( $request['args']['sslverify'] );
			$this->assertSame( 65536, $request['args']['limit_response_size'] );
			$this->assertFalse( $request['args']['decompress'] );
			$this->assertLessThanOrEqual( 5, $request['args']['timeout'] );
		}
		$this->assertLessThanOrEqual( SafeHttpStub::$requests[0]['args']['timeout'], SafeHttpStub::$requests[1]['args']['timeout'] );
	}

	public function test_redirect_limit_is_bounded(): void {
		for ( $i = 1; $i <= 6; ++$i ) {
			SafeHttpStub::$responses[] = $this->make_response( 302, array( 'location' => '/step-' . $i ) );
		}
		$result = $this->checker->check( 'https://target.com/' );
		$this->assertSame( 'too_many_redirects', $result['error'] );
		$this->assertCount( 6, SafeHttpStub::$requests );
		$this->assertSame( 5, $result['redirect_count'] );
	}

	public function test_connection_failure_is_not_misreported_as_timeout(): void {
		WpHttpStub::$next_response = new \WP_Error( 'http_request_failed', 'SSL certificate problem' );
		$this->assertSame( LinkStatus::Error, $this->checker->check( 'https://target.com/' )['status_category'] );
	}

	public function test_no_content_is_a_successful_http_response(): void {
		WpHttpStub::$next_response = $this->make_response( 204 );
		$this->assertSame( LinkStatus::Ok, $this->checker->check( 'https://target.com/' )['status_category'] );
	}

	/**
	 * Helper to create a mock HTTP response.
	 *
	 * @param int   $code    HTTP status code.
	 * @param array $headers Response headers.
	 * @return array
	 */
	private function make_response( int $code, array $headers = [] ): array {
		return [
			'response'      => [ 'code' => $code ],
			'headers'       => $headers,
			'http_response' => null,
		];
	}

}
