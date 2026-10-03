/**
 * REST API wrapper functions.
 *
 * @package
 * @since   1.0.0
 */

import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { API_NAMESPACE } from './constants';

/**
 * Fetches paginated links with filters.
 *
 * @param {Object} params Query parameters (camelCase, converted to snake_case).
 * @return {Promise<{items: Array, total: number, totalPages: number}>} Fetch results.
 */
export async function fetchLinksFromApi( params = {} ) {
	const query = {};
	if ( params.status ) {
		query.status = params.status;
	}
	if ( params.linkType ) {
		query.link_type = params.linkType;
	}
	if ( params.isAffiliate !== undefined && params.isAffiliate !== '' ) {
		query.is_affiliate = params.isAffiliate;
	}
	if ( params.affiliateNetwork ) {
		query.affiliate_network = params.affiliateNetwork;
	}
	if ( params.rel ) {
		query.rel = params.rel;
	}
	if ( params.search ) {
		query.search = params.search;
	}
	if ( params.postId ) {
		query.post_id = params.postId;
	}
	if ( params.orderby ) {
		query.orderby = params.orderby;
	}
	if ( params.order ) {
		query.order = params.order;
	}
	if ( params.page ) {
		query.page = params.page;
	}
	if ( params.perPage ) {
		query.per_page = params.perPage;
	}

	const path = addQueryArgs( `${ API_NAMESPACE }/links`, query );
	const response = await apiFetch( { path, parse: false } );
	const items = await response.json();
	const total = parseInt( response.headers.get( 'X-WP-Total' ), 10 ) || 0;
	const totalPages =
		parseInt( response.headers.get( 'X-WP-TotalPages' ), 10 ) || 0;

	return { items, total, totalPages };
}

export async function fetchLinkFromApi( id ) {
	return apiFetch( { path: `${ API_NAMESPACE }/links/${ id }` } );
}

export async function updateLinkApi( id, data ) {
	return apiFetch( {
		path: `${ API_NAMESPACE }/links/${ id }`,
		method: 'PUT',
		data,
	} );
}

export async function deleteLinkApi( id ) {
	return apiFetch( {
		path: `${ API_NAMESPACE }/links/${ id }`,
		method: 'DELETE',
	} );
}

export async function recheckLinkApi( id ) {
	return apiFetch( {
		path: `${ API_NAMESPACE }/links/${ id }/recheck`,
		method: 'POST',
	} );
}

export async function bulkActionApi( action, ids ) {
	return apiFetch( {
		path: `${ API_NAMESPACE }/links/bulk`,
		method: 'POST',
		data: { action, ids },
	} );
}

export async function fetchStatsApi() {
	return apiFetch( { path: `${ API_NAMESPACE }/links/stats` } );
}

export async function fetchScanStatusApi() {
	return apiFetch( { path: `${ API_NAMESPACE }/scan/status` } );
}

export async function startScanApi( scanType = 'full' ) {
	return apiFetch( {
		path: `${ API_NAMESPACE }/scan/start`,
		method: 'POST',
		data: { scan_type: scanType },
	} );
}

export async function cancelScanApi() {
	return apiFetch( {
		path: `${ API_NAMESPACE }/scan/cancel`,
		method: 'POST',
	} );
}

export async function resumeScanApi() {
	return apiFetch( {
		path: `${ API_NAMESPACE }/scan/resume`,
		method: 'POST',
	} );
}

export async function resetScanApi() {
	return apiFetch( {
		path: `${ API_NAMESPACE }/scan/reset`,
		method: 'POST',
	} );
}

export async function fetchSettingsApi() {
	return apiFetch( { path: `${ API_NAMESPACE }/settings` } );
}

export async function updateSettingsApi( data ) {
	return apiFetch( {
		path: `${ API_NAMESPACE }/settings`,
		method: 'PUT',
		data,
	} );
}
