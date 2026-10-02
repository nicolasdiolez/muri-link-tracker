import { __, sprintf } from '@wordpress/i18n';
import {
	fetchLinksFromApi,
	fetchLinkFromApi,
	updateLinkApi,
	deleteLinkApi,
	recheckLinkApi,
	bulkActionApi,
	fetchStatsApi,
	fetchScanStatusApi,
	startScanApi,
	cancelScanApi,
	resumeScanApi,
	resetScanApi,
	fetchSettingsApi,
	updateSettingsApi,
} from '../utils/api';

let linksRequest = 0;
let detailRequest = 0;
let scanRequest = 0;
let statsRequest = 0;
export function setLinks( items, total, totalPages ) {
	return { type: 'SET_LINKS', items, total, totalPages };
}
export function setScanStatus( status ) {
	return { type: 'SET_SCAN_STATUS', status };
}
export function setSettings( settings ) {
	return { type: 'SET_SETTINGS', settings };
}
export function setStats( stats ) {
	return { type: 'SET_STATS', stats };
}
export function setCurrentLink( link ) {
	if ( link === null ) {
		detailRequest++;
	}
	return { type: 'SET_CURRENT_LINK', link };
}
export function setLoading( key, value ) {
	return { type: 'SET_LOADING', key, value };
}
export function setError( key, error ) {
	return { type: 'SET_ERROR', key, error };
}
export function setLinksView( view ) {
	return { type: 'SET_LINKS_VIEW', view };
}
export function setRechecks( rechecks ) {
	return { type: 'SET_RECHECKS', rechecks };
}
const message = ( error ) =>
	error?.message ||
	__( 'The request failed. Please try again.', 'muri-link-tracker' );
function notice( registry, text, failed = false ) {
	registry
		.dispatch( 'core/notices' )
		[ failed ? 'createErrorNotice' : 'createSuccessNotice' ]( text, {
			type: 'snackbar',
		} );
}
export function normalizeScanStatus( result ) {
	const status = typeof result?.status === 'object' ? result.status : result;
	if ( ! status || typeof status.status !== 'string' ) {
		throw new Error(
			__(
				'Invalid scan response. Please refresh the status.',
				'muri-link-tracker'
			)
		);
	}
	return status;
}

export function fetchLinks( params ) {
	return async ( { dispatch, select } ) => {
		const request = ++linksRequest;
		const query = params || select?.getLinksQuery() || {};
		dispatch( setLoading( 'links', true ) );
		dispatch( setError( 'links', null ) );
		try {
			const result = await fetchLinksFromApi( query );
			if ( request !== linksRequest ) {
				return null;
			}
			if (
				result.totalPages > 0 &&
				query.page > result.totalPages &&
				select
			) {
				dispatch(
					setLinksView( {
						...select.getLinksView(),
						page: result.totalPages,
					} )
				);
				return await dispatch(
					fetchLinks( { ...query, page: result.totalPages } )
				);
			}
			dispatch(
				setLinks( result.items, result.total, result.totalPages )
			);
			return result;
		} catch ( error ) {
			if ( request === linksRequest ) {
				dispatch( setError( 'links', message( error ) ) );
			}
			return null;
		} finally {
			if ( request === linksRequest ) {
				dispatch( setLoading( 'links', false ) );
			}
		}
	};
}

export function fetchLink( id ) {
	return async ( { dispatch } ) => {
		const request = ++detailRequest;
		dispatch( { type: 'SET_CURRENT_LINK', link: null } );
		dispatch( setError( 'currentLink', null ) );
		dispatch( setLoading( 'currentLink', true ) );
		try {
			const link = await fetchLinkFromApi( id );
			if ( request === detailRequest ) {
				dispatch( setCurrentLink( link ) );
			}
			return request === detailRequest ? link : null;
		} catch ( error ) {
			if ( request === detailRequest ) {
				dispatch( setError( 'currentLink', message( error ) ) );
			}
			return null;
		} finally {
			if ( request === detailRequest ) {
				dispatch( setLoading( 'currentLink', false ) );
			}
		}
	};
}

export function fetchScanStatus() {
	return async ( { dispatch } ) => {
		const request = ++scanRequest;
		try {
			const status = normalizeScanStatus( await fetchScanStatusApi() );
			if ( request !== scanRequest ) {
				return null;
			}
			dispatch( setScanStatus( status ) );
			dispatch(
				setError(
					'scan',
					status.status === 'error'
						? status.error_message ||
								__(
									'The scan could not start.',
									'muri-link-tracker'
								)
						: null
				)
			);
			return status;
		} catch ( error ) {
			if ( request === scanRequest ) {
				dispatch( setError( 'scan', message( error ) ) );
			}
			return null;
		}
	};
}

function scanAction( api, successText, reset = false ) {
	return async ( { dispatch, registry } ) => {
		const request = ++scanRequest;
		dispatch( setLoading( 'scan', true ) );
		dispatch( setError( 'scan', null ) );
		try {
			const status = normalizeScanStatus( await api() );
			if ( request !== scanRequest ) {
				return null;
			}
			dispatch( setScanStatus( status ) );
			if ( status.status === 'error' ) {
				throw new Error(
					status.error_message ||
						__( 'The scan could not start.', 'muri-link-tracker' )
				);
			}
			if ( reset ) {
				++linksRequest;
				dispatch( setLinks( [], 0, 0 ) );
				dispatch( setLoading( 'links', false ) );
				dispatch( setRechecks( [] ) );
				dispatch( fetchStats() );
			}
			notice( registry, successText );
			return status;
		} catch ( error ) {
			if ( request === scanRequest ) {
				dispatch( setError( 'scan', message( error ) ) );
				notice( registry, message( error ), true );
			}
			return null;
		} finally {
			if ( request === scanRequest ) {
				dispatch( setLoading( 'scan', false ) );
			}
		}
	};
}
export function startScan( type = 'full' ) {
	return scanAction(
		() => startScanApi( type ),
		__( 'Scan started.', 'muri-link-tracker' )
	);
}
export function cancelScan() {
	return scanAction(
		cancelScanApi,
		__( 'Scan cancelled.', 'muri-link-tracker' )
	);
}
export function resumeScan() {
	return scanAction(
		resumeScanApi,
		__( 'Scan resumed.', 'muri-link-tracker' )
	);
}
export function resetScan() {
	return scanAction(
		resetScanApi,
		__( 'Scan data reset.', 'muri-link-tracker' ),
		true
	);
}

export function fetchStats() {
	return async ( { dispatch } ) => {
		const request = ++statsRequest;
		dispatch( setLoading( 'stats', true ) );
		try {
			const stats = await fetchStatsApi();
			if ( request !== statsRequest ) {
				return null;
			}
			dispatch( setStats( stats ) );
			dispatch( setError( 'stats', null ) );
			return stats;
		} catch ( error ) {
			if ( request === statsRequest ) {
				dispatch( setError( 'stats', message( error ) ) );
			}
			return null;
		} finally {
			if ( request === statsRequest ) {
				dispatch( setLoading( 'stats', false ) );
			}
		}
	};
}
export function fetchSettings() {
	return async ( { dispatch } ) => {
		dispatch( setLoading( 'settings', true ) );
		dispatch( setError( 'settings', null ) );
		try {
			const settings = await fetchSettingsApi();
			dispatch( setSettings( settings ) );
			return settings;
		} catch ( error ) {
			dispatch( setError( 'settings', message( error ) ) );
			return null;
		} finally {
			dispatch( setLoading( 'settings', false ) );
		}
	};
}
export function updateSettings( data ) {
	return async ( { dispatch, registry } ) => {
		dispatch( setLoading( 'settings', true ) );
		dispatch( setError( 'settings', null ) );
		try {
			const settings = await updateSettingsApi( data );
			dispatch( setSettings( settings ) );
			notice( registry, __( 'Settings saved.', 'muri-link-tracker' ) );
			return settings;
		} catch ( error ) {
			dispatch( setError( 'settings', message( error ) ) );
			notice( registry, message( error ), true );
			return null;
		} finally {
			dispatch( setLoading( 'settings', false ) );
		}
	};
}
export function updateLink( id, data ) {
	return async ( { registry } ) => {
		try {
			const result = await updateLinkApi( id, data );
			notice( registry, __( 'Link updated.', 'muri-link-tracker' ) );
			return result;
		} catch ( error ) {
			notice( registry, message( error ), true );
			return null;
		}
	};
}
export function deleteLink( id ) {
	return async ( { registry, dispatch } ) => {
		dispatch?.( setError( 'mutation', null ) );
		try {
			await deleteLinkApi( id );
			notice(
				registry,
				__( 'Link removed from content.', 'muri-link-tracker' )
			);
			return true;
		} catch ( error ) {
			dispatch?.( setError( 'mutation', message( error ) ) );
			notice( registry, message( error ), true );
			return false;
		}
	};
}

function watchRechecks( ids, dispatch, select ) {
	if ( ! dispatch || ! select ) {
		return;
	}
	const current = select.getRechecks();
	const links = select.getLinks();
	const additions = ids
		.filter( ( id ) => ! current.some( ( item ) => item.id === id ) )
		.map( ( id ) => {
			const link = links.find( ( item ) => item.id === id );
			return {
				id,
				checkCount: link?.checkCount,
				lastChecked: link?.lastChecked,
				until: Date.now() + 120000,
			};
		} );
	dispatch( setRechecks( [ ...current, ...additions ] ) );
	dispatch( setError( 'rechecks', null ) );
}
export function recheckLink( id ) {
	return async ( { registry, dispatch, select } ) => {
		dispatch?.( setError( 'mutation', null ) );
		try {
			await recheckLinkApi( id );
			watchRechecks( [ id ], dispatch, select );
			notice(
				registry,
				__(
					'Recheck scheduled. Results will refresh automatically.',
					'muri-link-tracker'
				)
			);
			return true;
		} catch ( error ) {
			dispatch?.( setError( 'mutation', message( error ) ) );
			notice( registry, message( error ), true );
			return false;
		}
	};
}
export function bulkAction( action, ids ) {
	return async ( { registry, dispatch, select } ) => {
		dispatch?.( setError( 'mutation', null ) );
		try {
			const result = await bulkActionApi( action, ids );
			const failed = Number( result.failed ) || 0;
			const text = sprintf(
				/* translators: 1: successful operations, 2: failed operations. */
				__( '%1$d succeeded; %2$d failed.', 'muri-link-tracker' ),
				result.success,
				failed
			);
			if ( failed ) {
				const details = ( result.failures || [] )
					.map(
						( failure ) => `#${ failure.id }: ${ failure.message }`
					)
					.join( ' ' );
				dispatch?.(
					setError( 'mutation', `${ text } ${ details }`.trim() )
				);
			}
			if ( action === 'recheck' ) {
				const failedIds = ( result.failures || [] ).map( ( failure ) =>
					Number( failure.id )
				);
				watchRechecks(
					ids.filter(
						( id ) => ! failedIds.includes( Number( id ) )
					),
					dispatch,
					select
				);
			}
			notice( registry, text, failed > 0 );
			return result;
		} catch ( error ) {
			dispatch?.( setError( 'mutation', message( error ) ) );
			notice( registry, message( error ), true );
			return null;
		}
	};
}

export function pollRechecks() {
	return async ( { dispatch, select } ) => {
		const pending = select.getRechecks();
		if ( ! pending.length ) {
			return;
		}
		const batch = pending.slice( 0, 10 );
		const done = [];
		let expired = false;
		await Promise.all(
			batch.map( async ( item ) => {
				if ( Date.now() > item.until ) {
					done.push( item.id );
					expired = true;
					return;
				}
				try {
					const result = await fetchLinkFromApi( item.id );
					if (
						result.statusCategory !== 'pending' &&
						( result.checkCount > item.checkCount ||
							result.lastChecked !== item.lastChecked )
					) {
						done.push( item.id );
					}
				} catch ( error ) {
					if ( error?.data?.status === 404 ) {
						done.push( item.id );
					}
				}
			} )
		);
		const latest = select
			.getRechecks()
			.filter( ( item ) => ! done.includes( item.id ) );
		// Rotate the batch so every queued link gets a turn.
		dispatch(
			setRechecks( [
				...latest.filter(
					( item ) =>
						! batch.some( ( checked ) => checked.id === item.id )
				),
				...latest.filter( ( item ) =>
					batch.some( ( checked ) => checked.id === item.id )
				),
			] )
		);
		if ( expired ) {
			dispatch(
				setError(
					'rechecks',
					__(
						'Some checks are still pending or unavailable. Refresh the list to see their latest results.',
						'muri-link-tracker'
					)
				)
			);
		}
		await dispatch( fetchLinks() );
		if ( done.length ) {
			await dispatch( fetchStats() );
		}
	};
}
export function refreshData() {
	return async ( { dispatch } ) =>
		Promise.all( [ dispatch( fetchStats() ), dispatch( fetchLinks() ) ] );
}
