import { viewToApiParams } from '../utils/link-view';
/**
 * Store selectors.
 *
 * @package
 * @since   1.0.0
 */

export function getLinks( state ) {
	return state.links;
}

export function getTotal( state ) {
	return state.total;
}

export function getTotalPages( state ) {
	return state.totalPages;
}

export function getScanStatus( state ) {
	return state.scanStatus;
}

export function getStats( state ) {
	return state.stats;
}

export function getSettings( state ) {
	return state.settings;
}

export function getCurrentLink( state ) {
	return state.currentLink;
}

export function isLoading( state, key ) {
	return !! state.isLoading[ key ];
}

export function getError( state, key ) {
	return state.errors?.[ key ] || null;
}
export function getLinksView( state ) {
	return state.linksView;
}
export function getLinksQuery( state ) {
	return viewToApiParams( state.linksView );
}
export function getRechecks( state ) {
	return state.rechecks;
}
