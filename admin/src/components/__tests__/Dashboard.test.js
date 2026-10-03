import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
import Dashboard from '../Dashboard';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	sprintf: ( str, ...args ) => {
		let i = 0;
		return str.replace( /%[0-9]*\$?[ds%]/g, () => args[ i++ ] ?? '' );
	},
} ) );

// Track useSelect calls — select() must return an object with selector methods.
let mockSelectReturn = {};
const mockFetchStats = jest.fn();
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( mapSelect ) => {
		const selectorObj = {};
		for ( const key of Object.keys( mockSelectReturn ) ) {
			selectorObj[ key ] = () => mockSelectReturn[ key ];
		}
		return mapSelect( () => selectorObj );
	},
	useDispatch: () => ( { fetchStats: mockFetchStats } ),
} ) );

// Stub ScanPanel.
jest.mock( '../ScanPanel', () => () => <div data-testid="scan-panel" /> );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, ...props } ) => (
		<button { ...props }>{ children }</button>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	ButtonGroup: ( { children } ) => <div>{ children }</div>,
} ) );

jest.mock( '../../store', () => ( { STORE_NAME: 'muri-link-tracker' } ) );

describe( 'Dashboard', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockSelectReturn = {
			getStats: null,
			getScanStatus: null,
			getError: null,
			isLoading: false,
		};
	} );

	it( 'renders dash values when scanStatus is null', () => {
		mockSelectReturn.getScanStatus = null;

		render( <Dashboard /> );

		const values = screen.getAllByText( '—' );
		expect( values.length ).toBe( 6 );
	} );

	it( 'renders scan status values', () => {
		mockSelectReturn.getScanStatus = {
			status: 'running',
			total_links: 500,
			broken_count: 12,
			redirect_count: 45,
			checked_links: 300,
		};

		render( <Dashboard /> );

		expect( screen.getByText( '500' ) ).toBeInTheDocument();
		expect( screen.getByText( '12' ) ).toBeInTheDocument();
		expect( screen.getByText( '45' ) ).toBeInTheDocument();
		expect( screen.getByText( '300' ) ).toBeInTheDocument();
	} );

	it( 'renders card labels', () => {
		mockSelectReturn.getScanStatus = null;

		render( <Dashboard /> );

		expect( screen.getByText( 'Total Links' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Broken' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Redirects' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Checked' ) ).toBeInTheDocument();
	} );

	it( 'includes ScanPanel', () => {
		mockSelectReturn.getScanStatus = null;

		render( <Dashboard /> );

		expect( screen.getByTestId( 'scan-panel' ) ).toBeInTheDocument();
	} );

	it( 'uses refreshed statistics instead of a completed scan snapshot', () => {
		mockSelectReturn.getScanStatus = {
			status: 'complete',
			total_links: 500,
			broken_count: 12,
			error_count: 5,
		};
		mockSelectReturn.getStats = {
			byCategory: {
				total: 20,
				broken_count: 0,
				error_count: 0,
				pending_count: 2,
			},
		};
		render( <Dashboard /> );
		expect(
			screen.getByLabelText( 'Total Links: 20' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Broken: 0' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Errors: 0' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Checked: 18' ) ).toBeInTheDocument();
	} );

	it( 'keeps an exact zero from the running scan instead of falling back to stale errors', () => {
		mockSelectReturn.getScanStatus = {
			status: 'running',
			total_links: 0,
			error_count: 0,
			timeout_count: 0,
			skipped_count: 8,
		};
		mockSelectReturn.getStats = {
			byCategory: { total: 500, error_count: 12 },
		};
		render( <Dashboard /> );
		expect( screen.getByLabelText( 'Total Links: 0' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Errors: 0' ) ).toBeInTheDocument();
	} );

	it( 'opens matching links through native accessible card buttons', () => {
		const onFilterLinks = jest.fn();
		mockSelectReturn.getStats = {
			byCategory: { total: 20, broken_count: 3, redirect_count: 2 },
			byNetwork: { amazon: 4 },
		};
		render( <Dashboard onFilterLinks={ onFilterLinks } /> );
		const cases = [
			[ 'Total Links: 20', {} ],
			[ 'Broken: 3', { status: 'broken' } ],
			[ 'Redirects: 2', { status: 'redirect' } ],
			[ 'Internal: 0', { linkType: 'internal' } ],
			[ 'External: 0', { linkType: 'external' } ],
			[ 'Affiliate: 0', { isAffiliate: true } ],
			[ 'Cloaked: 0', { linkType: 'internal', isAffiliate: true } ],
			[ 'Amazon: 4', { affiliateNetwork: 'amazon' } ],
		];
		for ( const [ name, filters ] of cases ) {
			const button = screen.getByRole( 'button', { name } );
			expect( button ).toHaveAttribute( 'type', 'button' );
			fireEvent.click( button );
			expect( onFilterLinks ).toHaveBeenLastCalledWith( filters );
		}
		expect(
			screen.queryByRole( 'button', { name: 'Loops: 0' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows a persistent statistics error and retries', () => {
		mockSelectReturn.getError = 'Statistics unavailable';
		mockSelectReturn.getStats = { byCategory: { total: 20 } };
		render( <Dashboard /> );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Statistics unavailable'
		);
		expect(
			screen.getByText( 'The figures below may be out of date.' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByText( 'Retry statistics' ) );
		expect( mockFetchStats ).toHaveBeenCalledTimes( 1 );
	} );
} );

it.each( [ '', 'unknown' ] )(
	'labels an unidentified network without a misleading drilldown (%s)',
	( network ) => {
		mockSelectReturn.getStats = {
			byCategory: { total: 28 },
			byNetwork: { [ network ]: 28 },
		};
		render( <Dashboard onFilterLinks={ jest.fn() } /> );
		expect( screen.getByText( 'Unknown network' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Unknown network: 28' } )
		).not.toBeInTheDocument();
	}
);
