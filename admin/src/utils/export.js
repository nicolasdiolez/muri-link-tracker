import { __ } from '@wordpress/i18n';
/**
 * Respect WordPress subdirectory installations and plain permalinks.
 * @param {Object} config Injected WordPress REST configuration.
 * @param {Object} params Active link filters.
 */
export function buildExportUrl( config, params ) {
	if ( ! config?.restUrl || ! config?.nonce ) {
		throw new Error(
			__(
				'Export is unavailable. Reload this page and try again.',
				'muri-link-tracker'
			)
		);
	}
	const url = new URL( config.restUrl, window.location.href );
	if ( url.searchParams.has( 'rest_route' ) ) {
		url.searchParams.set(
			'rest_route',
			url.searchParams.get( 'rest_route' ).replace( /\/?$/, '/' ) +
				'links/export'
		);
	} else {
		url.pathname = url.pathname.replace( /\/?$/, '/' ) + 'links/export';
	}
	const keys = {
		status: 'status',
		search: 'search',
		orderby: 'orderby',
		order: 'order',
		linkType: 'link_type',
		isAffiliate: 'is_affiliate',
		affiliateNetwork: 'affiliate_network',
	};
	for ( const [ key, name ] of Object.entries( keys ) ) {
		if ( params[ key ] !== undefined && params[ key ] !== '' ) {
			url.searchParams.set( name, String( params[ key ] ) );
		}
	}
	url.searchParams.set( '_wpnonce', config.nonce );
	return url.toString();
}
