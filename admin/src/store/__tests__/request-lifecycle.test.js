import { createRegistry, createReduxStore } from '@wordpress/data';
import reducer from '../reducer';
import * as actions from '../actions';
import * as selectors from '../selectors';
import * as api from '../../utils/api';

jest.mock( '../../utils/api', () => ( {
	fetchLinksFromApi: jest.fn(),
	fetchLinkFromApi: jest.fn(),
	fetchStatsApi: jest.fn(),
	fetchScanStatusApi: jest.fn(),
	startScanApi: jest.fn(),
	resumeScanApi: jest.fn(),
	bulkActionApi: jest.fn(),
	recheckLinkApi: jest.fn(),
} ) );
function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} );
	return { promise, resolve, reject };
}
function setup() {
	const registry = createRegistry();
	const notices = {
		createSuccessNotice: jest.fn( () => ( { type: 'NOTICE' } ) ),
		createErrorNotice: jest.fn( () => ( { type: 'NOTICE' } ) ),
	};
	registry.register(
		createReduxStore( 'core/notices', {
			reducer: ( state = [] ) => state,
			actions: notices,
		} )
	);
	registry.register(
		createReduxStore( 'mltr/test', { reducer, actions, selectors } )
	);
	return {
		dispatch: registry.dispatch( 'mltr/test' ),
		select: registry.select( 'mltr/test' ),
		notices,
	};
}
const response = ( id ) => ( { items: [ { id } ], total: 1, totalPages: 1 } );
beforeEach( () => jest.clearAllMocks() );

it( 'ignores an older response and keeps the latest list after a failed refresh', async () => {
	const { dispatch, select } = setup();
	const old = deferred();
	const latest = deferred();
	api.fetchLinksFromApi
		.mockReturnValueOnce( old.promise )
		.mockReturnValueOnce( latest.promise );
	const a = dispatch.fetchLinks( { search: 'old' } );
	const b = dispatch.fetchLinks( { search: 'new' } );
	latest.resolve( response( 2 ) );
	await b;
	old.resolve( response( 1 ) );
	await a;
	expect( select.getLinks() ).toEqual( [ { id: 2 } ] );
	api.fetchLinksFromApi.mockRejectedValueOnce( new Error( 'Offline' ) );
	await dispatch.fetchLinks();
	expect( select.getLinks() ).toEqual( [ { id: 2 } ] );
	expect( select.getError( 'links' ) ).toBe( 'Offline' );
	expect( select.isLoading( 'links' ) ).toBe( false );
} );

it( 'ignores detail A after opening B or closing the modal', async () => {
	const { dispatch, select } = setup();
	const a = deferred();
	const b = deferred();
	api.fetchLinkFromApi
		.mockReturnValueOnce( a.promise )
		.mockReturnValueOnce( b.promise );
	const first = dispatch.fetchLink( 1 );
	const second = dispatch.fetchLink( 2 );
	b.resolve( { id: 2 } );
	await second;
	a.resolve( { id: 1 } );
	await first;
	expect( select.getCurrentLink().id ).toBe( 2 );
	const closing = deferred();
	api.fetchLinkFromApi.mockReturnValueOnce( closing.promise );
	const pending = dispatch.fetchLink( 3 );
	dispatch.setCurrentLink( null );
	closing.resolve( { id: 3 } );
	await pending;
	expect( select.getCurrentLink() ).toBeNull();
} );

it( 'refreshes the canonical search, filters and page after a mutation', async () => {
	const { dispatch, select } = setup();
	dispatch.setLinksView( {
		...select.getLinksView(),
		page: 2,
		search: 'article',
		filters: [
			{ field: 'statusCategory', operator: 'is', value: 'broken' },
		],
	} );
	api.fetchLinksFromApi.mockResolvedValue( {
		items: [],
		total: 50,
		totalPages: 2,
	} );
	await dispatch.fetchLinks();
	expect( api.fetchLinksFromApi ).toHaveBeenLastCalledWith(
		expect.objectContaining( {
			page: 2,
			search: 'article',
			status: 'broken',
		} )
	);
} );

it( 'moves back to an existing page after deleting the last item', async () => {
	const { dispatch, select } = setup();
	dispatch.setLinksView( { ...select.getLinksView(), page: 3 } );
	api.fetchLinksFromApi.mockResolvedValue( {
		items: [],
		total: 26,
		totalPages: 2,
	} );
	await dispatch.fetchLinks();
	expect( select.getLinksView().page ).toBe( 2 );
	expect( api.fetchLinksFromApi ).toHaveBeenLastCalledWith(
		expect.objectContaining( { page: 2 } )
	);
} );

it.each( [
	{ status: 'running', phase: 'checking' },
	{ status: { status: 'running', phase: 'checking' }, scanBatches: 1 },
] )( 'normalizes scan responses to objects: %j', async ( result ) => {
	const { dispatch, select } = setup();
	api.resumeScanApi.mockResolvedValue( result );
	await dispatch.resumeScan();
	expect( select.getScanStatus() ).toEqual( {
		status: 'running',
		phase: 'checking',
	} );
} );

it( 'does not announce success for an HTTP 200 scan error', async () => {
	const { dispatch, select, notices } = setup();
	api.startScanApi.mockResolvedValue( {
		status: 'error',
		error_message: 'Queue unavailable',
	} );
	await dispatch.startScan();
	expect( select.getError( 'scan' ) ).toBe( 'Queue unavailable' );
	expect( notices.createSuccessNotice ).not.toHaveBeenCalled();
} );

it( 'shows partial bulk failures persistently', async () => {
	const { dispatch, select } = setup();
	api.bulkActionApi.mockResolvedValue( {
		success: 1,
		failed: 1,
		failures: [ { id: 7, message: 'Read-only source' } ],
	} );
	await dispatch.bulkAction( 'delete', [ 6, 7 ] );
	expect( select.getError( 'mutation' ) ).toContain( 'Read-only source' );
	expect( select.getError( 'mutation' ) ).toContain(
		'1 succeeded; 1 failed'
	);
} );

it( 'tracks queued checks until a new result arrives and refreshes the current query', async () => {
	const { dispatch, select } = setup();
	dispatch.setLinks(
		[ { id: 8, checkCount: 1, lastChecked: 'before' } ],
		1,
		1
	);
	api.recheckLinkApi.mockResolvedValue( { status: 'pending' } );
	api.fetchLinkFromApi.mockResolvedValue( {
		id: 8,
		statusCategory: 'ok',
		checkCount: 2,
		lastChecked: 'after',
	} );
	api.fetchLinksFromApi.mockResolvedValue( response( 8 ) );
	api.fetchStatsApi.mockResolvedValue( {} );
	await dispatch.recheckLink( 8 );
	expect( select.getRechecks() ).toHaveLength( 1 );
	await dispatch.pollRechecks();
	expect( select.getRechecks() ).toHaveLength( 0 );
	expect( api.fetchLinksFromApi ).toHaveBeenCalled();
} );

it( 'does not restore stale statistics after a newer refresh', async () => {
	const { dispatch, select } = setup();
	const old = deferred();
	const latest = deferred();
	api.fetchStatsApi
		.mockReturnValueOnce( old.promise )
		.mockReturnValueOnce( latest.promise );
	const a = dispatch.fetchStats();
	const b = dispatch.fetchStats();
	latest.resolve( { byCategory: { total: 0 } } );
	await b;
	old.resolve( { byCategory: { total: 100 } } );
	await a;
	expect( select.getStats().byCategory.total ).toBe( 0 );
} );
