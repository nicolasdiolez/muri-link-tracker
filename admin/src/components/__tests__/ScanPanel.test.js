import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
import ScanPanel from '../ScanPanel';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	sprintf: ( str, ...args ) => {
		let i = 0;
		return str.replace( /%[0-9]*\$?[ds%]/g, () =>
			String( args[ i++ ] ?? '' )
		);
	},
} ) );

const mockStartScan = jest.fn();
const mockCancelScan = jest.fn();
const mockFetchScanStatus = jest.fn();

let mockSelectValues = {};
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( mapSelect ) => {
		const selectorObj = {};
		for ( const key of Object.keys( mockSelectValues ) ) {
			selectorObj[ key ] = () => mockSelectValues[ key ];
		}
		return mapSelect( () => selectorObj );
	},
	useDispatch: () => ( {
		startScan: mockStartScan,
		cancelScan: mockCancelScan,
		fetchScanStatus: mockFetchScanStatus,
	} ),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		children,
		onClick,
		variant,
		disabled,
		isDestructive,
		isBusy,
		...rest
	} ) => (
		<button
			onClick={ onClick }
			disabled={ disabled }
			data-variant={ variant }
			data-destructive={ isDestructive ? 'true' : undefined }
			data-busy={ isBusy ? 'true' : undefined }
			{ ...rest }
		>
			{ children }
		</button>
	),
} ) );

jest.mock( '../../store', () => ( { STORE_NAME: 'muri-link-tracker' } ) );

describe( 'ScanPanel', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		jest.useFakeTimers();
		mockSelectValues = {};
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'renders Scan all content and Scan changed content buttons when idle', () => {
		mockSelectValues.getScanStatus = { status: 'idle' };
		mockSelectValues.isLoading = false;

		render( <ScanPanel /> );

		expect( screen.getByText( 'Scan all content' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Scan changed content' )
		).toBeInTheDocument();
	} );

	it( 'calls startScan with full on Scan all content click', () => {
		mockSelectValues.getScanStatus = { status: 'idle' };
		mockSelectValues.isLoading = false;

		render( <ScanPanel /> );

		fireEvent.click( screen.getByText( 'Scan all content' ) );
		expect( mockStartScan ).toHaveBeenCalledWith( 'full' );
	} );

	it( 'calls startScan with delta on Scan changed content click', () => {
		mockSelectValues.getScanStatus = { status: 'idle' };
		mockSelectValues.isLoading = false;

		render( <ScanPanel /> );

		fireEvent.click( screen.getByText( 'Scan changed content' ) );
		expect( mockStartScan ).toHaveBeenCalledWith( 'delta' );
	} );

	it( 'shows Cancel Scan button when running', () => {
		mockSelectValues.getScanStatus = {
			status: 'running',
			total_posts: 100,
			scanned_posts: 50,
		};
		mockSelectValues.isLoading = false;

		render( <ScanPanel /> );

		expect( screen.getByText( 'Cancel Scan' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Scan all content' )
		).not.toBeInTheDocument();
	} );

	it( 'calls cancelScan on Cancel button click', () => {
		mockSelectValues.getScanStatus = {
			status: 'running',
			total_posts: 100,
			scanned_posts: 50,
		};
		mockSelectValues.isLoading = false;

		render( <ScanPanel /> );

		fireEvent.click( screen.getByText( 'Cancel Scan' ) );
		expect( mockCancelScan ).toHaveBeenCalled();
	} );

	it( 'shows progress bar when running', () => {
		mockSelectValues.getScanStatus = {
			status: 'running',
			total_posts: 200,
			scanned_posts: 100,
		};
		mockSelectValues.isLoading = false;

		const { container } = render( <ScanPanel /> );

		const fill = container.querySelector( '.mltr-progress-bar__fill' );
		expect( fill ).toBeInTheDocument();
		expect( fill.style.width ).toBe( '50%' );
	} );

	it( 'shows completion message when complete', () => {
		mockSelectValues.getScanStatus = {
			status: 'complete',
			total_links: 500,
			broken_count: 12,
		};
		mockSelectValues.isLoading = false;

		render( <ScanPanel /> );

		expect( screen.getByText( /Scan complete/ ) ).toBeInTheDocument();
	} );
} );
