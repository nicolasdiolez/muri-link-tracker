import {
	setLinks,
	setScanStatus,
	setSettings,
	setCurrentLink,
	setLoading,
	fetchLinks,
	startScan,
	cancelScan,
	fetchSettings,
	updateSettings,
	updateLink,
	deleteLink,
	recheckLink,
	bulkAction,
	fetchScanStatus,
} from '../actions';

// Mock the API module.
jest.mock( '../../utils/api', () => ( {
	fetchLinksFromApi: jest.fn(),
	fetchLinkFromApi: jest.fn(),
	startScanApi: jest.fn(),
	cancelScanApi: jest.fn(),
	fetchScanStatusApi: jest.fn(),
	fetchSettingsApi: jest.fn(),
	updateSettingsApi: jest.fn(),
	updateLinkApi: jest.fn(),
	deleteLinkApi: jest.fn(),
	recheckLinkApi: jest.fn(),
	bulkActionApi: jest.fn(),
} ) );

const api = require( '../../utils/api' );

describe( 'store/actions — plain action creators', () => {
	it( 'setLinks creates SET_LINKS action', () => {
		const items = [ { id: 1 } ];
		expect( setLinks( items, 10, 1 ) ).toEqual( {
			type: 'SET_LINKS',
			items,
			total: 10,
			totalPages: 1,
		} );
	} );

	it( 'setScanStatus creates SET_SCAN_STATUS action', () => {
		const status = { status: 'running' };
		expect( setScanStatus( status ) ).toEqual( {
			type: 'SET_SCAN_STATUS',
			status,
		} );
	} );

	it( 'setSettings creates SET_SETTINGS action', () => {
		const settings = { batch_size: 50 };
		expect( setSettings( settings ) ).toEqual( {
			type: 'SET_SETTINGS',
			settings,
		} );
	} );

	it( 'setCurrentLink creates SET_CURRENT_LINK action', () => {
		const link = { id: 5 };
		expect( setCurrentLink( link ) ).toEqual( {
			type: 'SET_CURRENT_LINK',
			link,
		} );
	} );

	it( 'setLoading creates SET_LOADING action', () => {
		expect( setLoading( 'links', true ) ).toEqual( {
			type: 'SET_LOADING',
			key: 'links',
			value: true,
		} );
	} );
} );

describe( 'store/actions — thunks', () => {
	let dispatch;
	let registry;

	beforeEach( () => {
		dispatch = jest.fn();
		registry = {
			dispatch: jest.fn( () => ( {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			} ) ),
		};
		jest.clearAllMocks();
	} );

	describe( 'fetchLinks', () => {
		it( 'dispatches SET_LINKS on success', async () => {
			api.fetchLinksFromApi.mockResolvedValue( {
				items: [ { id: 1 } ],
				total: 1,
				totalPages: 1,
			} );

			const thunk = fetchLinks( { page: 1 } );
			await thunk( { dispatch } );

			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_LOADING',
				key: 'links',
				value: true,
			} );
			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_LINKS',
				items: [ { id: 1 } ],
				total: 1,
				totalPages: 1,
			} );
			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_LOADING',
				key: 'links',
				value: false,
			} );
		} );

		it( 'retains links and reports API errors', async () => {
			api.fetchLinksFromApi.mockRejectedValue(
				new Error( 'Network error' )
			);

			const thunk = fetchLinks();
			await thunk( { dispatch } );

			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_ERROR',
				key: 'links',
				error: 'Network error',
			} );
		} );
	} );

	describe( 'startScan', () => {
		it( 'dispatches scan status and notice on success', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.startScanApi.mockResolvedValue( {
				status: 'running',
			} );

			const thunk = startScan( 'full' );
			await thunk( { dispatch, registry } );

			expect( api.startScanApi ).toHaveBeenCalledWith( 'full' );
			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_SCAN_STATUS',
				status: { status: 'running' },
			} );
			expect( noticeActions.createSuccessNotice ).toHaveBeenCalled();
		} );

		it( 'dispatches error notice on failure', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.startScanApi.mockRejectedValue(
				new Error( 'Scan already running' )
			);

			const thunk = startScan( 'full' );
			await thunk( { dispatch, registry } );

			expect( noticeActions.createErrorNotice ).toHaveBeenCalledWith(
				'Scan already running',
				expect.any( Object )
			);
		} );
	} );

	describe( 'cancelScan', () => {
		it( 'dispatches scan status on success', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.cancelScanApi.mockResolvedValue( { status: 'cancelled' } );

			const thunk = cancelScan();
			await thunk( { dispatch, registry } );

			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_SCAN_STATUS',
				status: { status: 'cancelled' },
			} );
			expect( noticeActions.createSuccessNotice ).toHaveBeenCalled();
		} );
	} );

	describe( 'fetchSettings', () => {
		it( 'dispatches settings on success', async () => {
			const settings = { check_timeout: 15 };
			api.fetchSettingsApi.mockResolvedValue( settings );

			const thunk = fetchSettings();
			await thunk( { dispatch } );

			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_SETTINGS',
				settings,
			} );
		} );
	} );

	describe( 'updateSettings', () => {
		it( 'dispatches updated settings and notice', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			const settings = { check_timeout: 20 };
			api.updateSettingsApi.mockResolvedValue( settings );

			const thunk = updateSettings( { check_timeout: 20 } );
			await thunk( { dispatch, registry } );

			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_SETTINGS',
				settings,
			} );
			expect( noticeActions.createSuccessNotice ).toHaveBeenCalled();
		} );
	} );

	describe( 'updateLink', () => {
		it( 'returns result and shows notice on success', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			const result = { id: 1, url: 'https://new.com' };
			api.updateLinkApi.mockResolvedValue( result );

			const thunk = updateLink( 1, { url: 'https://new.com' } );
			const returned = await thunk( { registry } );

			expect( returned ).toBe( result );
			expect( noticeActions.createSuccessNotice ).toHaveBeenCalled();
		} );

		it( 'returns null on error', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.updateLinkApi.mockRejectedValue( new Error( 'fail' ) );

			const thunk = updateLink( 1, { url: 'bad' } );
			const returned = await thunk( { registry } );

			expect( returned ).toBeNull();
			expect( noticeActions.createErrorNotice ).toHaveBeenCalled();
		} );
	} );

	describe( 'deleteLink', () => {
		it( 'returns true on success', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.deleteLinkApi.mockResolvedValue( {} );

			const thunk = deleteLink( 1 );
			const returned = await thunk( { registry } );

			expect( returned ).toBe( true );
		} );

		it( 'returns false on error', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.deleteLinkApi.mockRejectedValue( new Error( 'fail' ) );

			const thunk = deleteLink( 1 );
			const returned = await thunk( { registry } );

			expect( returned ).toBe( false );
		} );
	} );

	describe( 'recheckLink', () => {
		it( 'returns true on success', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.recheckLinkApi.mockResolvedValue( {} );

			const thunk = recheckLink( 1 );
			const returned = await thunk( { registry } );

			expect( returned ).toBe( true );
		} );
	} );

	describe( 'bulkAction', () => {
		it( 'returns result on success', async () => {
			const noticeActions = {
				createSuccessNotice: jest.fn(),
				createErrorNotice: jest.fn(),
			};
			registry.dispatch.mockReturnValue( noticeActions );
			api.bulkActionApi.mockResolvedValue( { success: 3 } );

			const thunk = bulkAction( 'recheck', [ 1, 2, 3 ] );
			const returned = await thunk( { registry } );

			expect( returned ).toEqual( { success: 3 } );
			expect( api.bulkActionApi ).toHaveBeenCalledWith(
				'recheck',
				[ 1, 2, 3 ]
			);
		} );
	} );

	describe( 'fetchScanStatus', () => {
		it( 'dispatches scan status', async () => {
			api.fetchScanStatusApi.mockResolvedValue( {
				status: 'complete',
			} );

			const thunk = fetchScanStatus();
			await thunk( { dispatch } );

			expect( dispatch ).toHaveBeenCalledWith( {
				type: 'SET_SCAN_STATUS',
				status: { status: 'complete' },
			} );
		} );

		it( 'returns null on errors for the polling loop to retry', async () => {
			api.fetchScanStatusApi.mockRejectedValue( new Error( 'network' ) );

			const thunk = fetchScanStatus();
			await expect( thunk( { dispatch } ) ).resolves.toBeNull();
		} );
	} );
} );
