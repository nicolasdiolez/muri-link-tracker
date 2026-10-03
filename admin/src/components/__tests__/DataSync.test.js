import { act, render } from '@testing-library/react';
import DataSync from '../DataSync';
const mockFetch = jest.fn();
const mockPoll = jest.fn();
const mockRefresh = jest.fn();
let mockStatus;
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( callback ) =>
		callback( () => ( {
			isLoading: () => false,
			getScanStatus: () => mockStatus,
			getRechecks: () => [],
			getError: () => null,
		} ) ),
	useDispatch: () => ( {
		fetchScanStatus: mockFetch,
		pollRechecks: mockPoll,
		refreshData: mockRefresh,
	} ),
} ) );
jest.mock( '../../store', () => ( { STORE_NAME: 'test' } ) );
jest.mock( '@wordpress/components', () => ( {
	Notice: ( { children } ) => <div>{ children }</div>,
	Button: ( { children } ) => <button>{ children }</button>,
} ) );
beforeEach( () => {
	jest.useFakeTimers();
	jest.clearAllMocks();
	mockStatus = { status: 'running' };
	mockPoll.mockResolvedValue();
} );
afterEach( () => jest.useRealTimers() );
it( 'continues polling after a failed request and stops after unmount', async () => {
	mockFetch
		.mockResolvedValueOnce( null )
		.mockResolvedValue( { status: 'running' } );
	const { unmount } = render( <DataSync /> );
	await act( async () => {
		jest.advanceTimersByTime( 5000 );
	} );
	expect( mockFetch ).toHaveBeenCalledTimes( 1 );
	await act( async () => {
		jest.advanceTimersByTime( 5000 );
	} );
	expect( mockFetch ).toHaveBeenCalledTimes( 2 );
	unmount();
	await act( async () => {
		jest.advanceTimersByTime( 10000 );
	} );
	expect( mockFetch ).toHaveBeenCalledTimes( 2 );
} );
it( 'refreshes statistics and the canonical list on completion', () => {
	mockStatus = { status: 'complete' };
	render( <DataSync /> );
	expect( mockRefresh ).toHaveBeenCalledTimes( 1 );
	expect( mockFetch ).not.toHaveBeenCalled();
} );
