import { buildExportUrl } from '../export';
import { DEFAULT_VIEW, viewForFilter, viewToApiParams } from '../link-view';
it.each( [
	[
		'https://example.com/wp-json/muri-link-tracker/v1/',
		'/wp-json/muri-link-tracker/v1/links/export',
	],
	[
		'https://example.com/blog/wp-json/muri-link-tracker/v1/',
		'/blog/wp-json/muri-link-tracker/v1/links/export',
	],
] )( 'exports using the injected REST root %s', ( restUrl, pathname ) => {
	const url = new URL(
		buildExportUrl(
			{ restUrl, nonce: 'test-nonce' },
			{ status: 'broken', search: 'a&b', isAffiliate: false }
		)
	);
	expect( url.pathname ).toBe( pathname );
	expect( url.searchParams.get( '_wpnonce' ) ).toBe( 'test-nonce' );
	expect( url.searchParams.get( 'search' ) ).toBe( 'a&b' );
	expect( url.searchParams.get( 'is_affiliate' ) ).toBe( 'false' );
} );
it( 'supports plain permalinks without appending the endpoint to the query text', () => {
	const url = new URL(
		buildExportUrl(
			{
				restUrl:
					'https://example.com/blog/?rest_route=/muri-link-tracker/v1/',
				nonce: 'token',
			},
			{}
		)
	);
	expect( url.pathname ).toBe( '/blog/' );
	expect( url.searchParams.get( 'rest_route' ) ).toBe(
		'/muri-link-tracker/v1/links/export'
	);
} );
it( 'refuses an unauthenticated export', () =>
	expect( () =>
		buildExportUrl( { restUrl: 'https://example.com/' }, {} )
	).toThrow() );
it( 'drilldown replaces previous filters with one canonical view', () => {
	const view = viewForFilter(
		{ ...DEFAULT_VIEW, page: 4, search: 'old' },
		{ status: 'broken', linkType: 'external' }
	);
	expect( viewToApiParams( view ) ).toEqual(
		expect.objectContaining( {
			page: 1,
			status: 'broken',
			linkType: 'external',
		} )
	);
	expect( view.search ).toBe( '' );
} );
