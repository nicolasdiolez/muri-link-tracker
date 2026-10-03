<?php
/** URL exclusion matching regressions. @package MuriLinkTracker\Tests\Unit */
declare( strict_types=1 );
namespace MuriLinkTracker\Tests\Unit;

use MuriLinkTracker\Scanner\UrlExclusions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

#[CoversClass(UrlExclusions::class)]
class UrlExclusionsTest extends TestCase {
	#[DataProvider('matching_cases')]
	public function test_matches_only_configured_url_patterns( string $url, array $patterns, bool $matches ): void {
		$this->assertSame( $matches, UrlExclusions::matches( $url, $patterns ) );
	}

	public static function matching_cases(): array {
		return array(
			'exact URL' => array( 'https://example.com/page', array( 'https://example.com/page' ), true ),
			'prefix anchored' => array( 'https://other.com/?url=https://example.com/page', array( 'https://example.com/page' ), false ),
			'suffix anchored' => array( 'https://example.com/page-extra', array( 'https://example.com/page' ), false ),
			'dots are literal' => array( 'https://exampleXcom/page', array( 'https://example.com/page' ), false ),
			'wildcard path' => array( 'https://example.com/private/path?x=1', array( 'https://example.com/private/*' ), true ),
			'wildcard empty match' => array( 'https://example.com/private/', array( 'https://example.com/private/*' ), true ),
			'wildcard anchored host' => array( 'https://example.com.evil.test/private/page', array( 'https://example.com/private/*' ), false ),
			'multiple wildcards' => array( 'https://shop.example.com/products/item?ref=1', array( 'https://*.example.com/products/*' ), true ),
			'regex metacharacters literal' => array( 'https://example.com/a+b[1](x)?x=1&y=$^|~', array( 'https://example.com/a+b[1](x)?x=1&y=$^|~' ), true ),
			'question mark not wildcard' => array( 'https://example.com/ab', array( 'https://example.com/a?' ), false ),
			'root relative URL' => array( '/private/page', array( 'https://example.com/private/*' ), true ),
			'root relative rule' => array( 'https://example.com/private/page', array( '/private/*' ), true ),
			'relative rule stays on site' => array( 'https://other.com/private/page', array( '/private/*' ), false ),
			'protocol relative URL' => array( '//other.com/page', array( 'https://other.com/*' ), true ),
			'protocol relative rule' => array( 'https://other.com/page', array( '//other.com/*' ), true ),
			'scheme remains significant' => array( 'http://example.com/page', array( 'https://example.com/page' ), false ),
			'path case remains significant' => array( 'https://example.com/Page', array( 'https://example.com/page' ), false ),
			'whitespace trimmed' => array( "  /private/page\n", array( " /private/* \n" ), true ),
			'empty and malformed rules ignored' => array( 'https://example.com/page', array( '', ' ', null, 42, false, array() ), false ),
		);
	}
}
