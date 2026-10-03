import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SettingsPanel from '../SettingsPanel';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	sprintf: ( str, ...args ) =>
		str.replace(
			/%([1-9])\$[sd]/g,
			( match, position ) => args[ Number( position ) - 1 ]
		),
} ) );

const mockUpdateSettings = jest.fn();
const mockFetchSettings = jest.fn();
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
		updateSettings: mockUpdateSettings,
		fetchSettings: mockFetchSettings,
	} ),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
	TextControl: ( { label, value, onChange, help } ) => (
		<div>
			<label htmlFor={ `mock-${ label }` }>{ label }</label>
			<input
				id={ `mock-${ label }` }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
			{ help && <span>{ help }</span> }
		</div>
	),
	TextareaControl: ( { label, value, onChange } ) => (
		<div>
			<label htmlFor={ `mock-${ label }` }>{ label }</label>
			<textarea
				id={ `mock-${ label }` }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		</div>
	),
	ToggleControl: ( { label, checked, onChange } ) => (
		<div>
			<label htmlFor={ `mock-${ label }` }>{ label }</label>
			<input
				id={ `mock-${ label }` }
				type="checkbox"
				checked={ checked }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
		</div>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	Spinner: () => <div data-testid="spinner" />,
	Panel: ( { children } ) => <div>{ children }</div>,
	PanelBody: ( { title, children } ) => (
		<fieldset>
			<legend>{ title }</legend>
			{ children }
		</fieldset>
	),
	PanelRow: ( { children } ) => <div>{ children }</div>,
} ) );

jest.mock( '../../store', () => ( { STORE_NAME: 'muri-link-tracker' } ) );

const DEFAULT_SETTINGS = {
	scan_post_types: [ 'post', 'page' ],
	batch_size: 50,
	scan_custom_fields: false,
	check_timeout: 15,
	http_request_delay: 300,
	recheck_interval: 7,
	excluded_urls: [ 'https://ignore.com' ],
};

describe( 'SettingsPanel', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockSelectValues = {
			getError: null,
			getSettings: DEFAULT_SETTINGS,
			isLoading: false,
		};
		mockUpdateSettings.mockResolvedValue( DEFAULT_SETTINGS );
	} );

	it( 'shows spinner when settings are not loaded', () => {
		mockSelectValues.getSettings = null;
		mockSelectValues.isLoading = true;

		render( <SettingsPanel /> );

		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'renders all settings fields when loaded', () => {
		mockSelectValues.getSettings = DEFAULT_SETTINGS;
		mockSelectValues.isLoading = false;

		render( <SettingsPanel /> );

		expect( screen.getByLabelText( 'Post types to scan' ) ).toHaveValue(
			'post, page'
		);
		expect( screen.getByLabelText( 'Batch size' ) ).toHaveValue( '50' );
		expect( screen.getByLabelText( 'Timeout (seconds)' ) ).toHaveValue(
			'15'
		);
		expect(
			screen.getByLabelText( 'Delay between requests (ms)' )
		).toHaveValue( '300' );
		expect(
			screen.getByLabelText( 'Recheck interval (days)' )
		).toHaveValue( '7' );
	} );

	it( 'renders panel section titles', () => {
		mockSelectValues.getSettings = DEFAULT_SETTINGS;
		mockSelectValues.isLoading = false;

		render( <SettingsPanel /> );

		expect( screen.getByText( 'Scanning' ) ).toBeInTheDocument();
		expect( screen.getByText( 'HTTP Checks' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Exclusions' ) ).toBeInTheDocument();
	} );

	it( 'calls updateSettings on Save', async () => {
		mockSelectValues.getSettings = DEFAULT_SETTINGS;
		mockSelectValues.isLoading = false;

		render( <SettingsPanel /> );

		fireEvent.click( screen.getByText( 'Save Settings' ) );

		expect( mockUpdateSettings ).toHaveBeenCalledWith(
			expect.objectContaining( {
				batch_size: 50,
				check_timeout: 15,
			} )
		);
		await waitFor( () =>
			expect( screen.getByText( 'Save Settings' ) ).not.toBeDisabled()
		);
	} );

	it( 'updates batch_size field locally on change', () => {
		mockSelectValues.getSettings = DEFAULT_SETTINGS;
		mockSelectValues.isLoading = false;

		render( <SettingsPanel /> );

		const input = screen.getByLabelText( 'Batch size' );
		fireEvent.change( input, { target: { value: '100' } } );

		expect( input ).toHaveValue( '100' );
	} );
} );

describe( 'SettingsPanel drafts and recovery', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockSelectValues = {
			getError: null,
			getSettings: DEFAULT_SETTINGS,
			isLoading: false,
		};
		mockUpdateSettings.mockImplementation( async ( settings ) => settings );
	} );

	it( 'preserves trailing commas, line breaks and empty numbers while typing', () => {
		render( <SettingsPanel /> );
		const types = screen.getByLabelText( 'Post types to scan' );
		for ( const value of [
			'post',
			'post,',
			'post, ',
			'post, p',
			'post, page,',
		] ) {
			fireEvent.change( types, { target: { value } } );
			expect( types ).toHaveValue( value );
		}
		const urls = screen.getByLabelText( 'Excluded URLs' );
		for ( const value of [
			'https://example.com',
			'https://example.com\n',
			'https://example.com\nhttps://other.com\n',
		] ) {
			fireEvent.change( urls, { target: { value } } );
			expect( urls ).toHaveValue( value );
		}
		const batch = screen.getByLabelText( 'Batch size' );
		fireEvent.change( batch, { target: { value: '' } } );
		expect( batch ).toHaveValue( '' );
		expect( mockUpdateSettings ).not.toHaveBeenCalled();
	} );

	it( 'normalizes only when saving, then adopts the server response', async () => {
		mockUpdateSettings.mockResolvedValue( {
			...DEFAULT_SETTINGS,
			scan_post_types: [ 'product' ],
			batch_size: 80,
			excluded_urls: [],
		} );
		const onDirtyChange = jest.fn();
		render( <SettingsPanel onDirtyChange={ onDirtyChange } /> );
		fireEvent.change( screen.getByLabelText( 'Post types to scan' ), {
			target: { value: ' product, product, ' },
		} );
		fireEvent.change( screen.getByLabelText( 'Excluded URLs' ), {
			target: { value: '\n https://test.com \n\n' },
		} );
		fireEvent.change( screen.getByLabelText( 'Batch size' ), {
			target: { value: '75' },
		} );
		expect( onDirtyChange ).toHaveBeenLastCalledWith( true );
		fireEvent.click( screen.getByText( 'Save Settings' ) );
		expect( mockUpdateSettings ).toHaveBeenCalledWith(
			expect.objectContaining( {
				scan_post_types: [ 'product' ],
				excluded_urls: [ 'https://test.com' ],
				batch_size: 75,
			} )
		);
		await waitFor( () =>
			expect( screen.getByLabelText( 'Batch size' ) ).toHaveValue( '80' )
		);
		expect( screen.getByLabelText( 'Post types to scan' ) ).toHaveValue(
			'product'
		);
		expect( onDirtyChange ).toHaveBeenLastCalledWith( false );
		expect(
			screen.queryByText( 'You have unsaved changes.' )
		).not.toBeInTheDocument();
	} );

	it.each( [
		[ 'Batch size', '' ],
		[ 'Batch size', '201' ],
		[ 'Batch size', '10.5' ],
		[ 'Timeout (seconds)', '4' ],
		[ 'Delay between requests (ms)', '-1' ],
		[ 'Delay between requests (ms)', '5001' ],
		[ 'Recheck interval (days)', '31' ],
	] )( 'blocks invalid %s value %s locally', ( label, value ) => {
		render( <SettingsPanel /> );
		fireEvent.change( screen.getByLabelText( label ), {
			target: { value },
		} );
		fireEvent.click( screen.getByText( 'Save Settings' ) );
		expect( mockUpdateSettings ).not.toHaveBeenCalled();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Check the highlighted fields'
		);
	} );

	it( 'keeps a zero delay and retains a draft after a failed save', async () => {
		mockUpdateSettings.mockResolvedValue( null );
		render( <SettingsPanel /> );
		fireEvent.change(
			screen.getByLabelText( 'Delay between requests (ms)' ),
			{ target: { value: '0' } }
		);
		fireEvent.click( screen.getByText( 'Save Settings' ) );
		expect( mockUpdateSettings ).toHaveBeenCalledWith(
			expect.objectContaining( { http_request_delay: 0 } )
		);
		await waitFor( () =>
			expect( screen.getByText( 'Save Settings' ) ).not.toBeDisabled()
		);
		expect(
			screen.getByLabelText( 'Delay between requests (ms)' )
		).toHaveValue( '0' );
		expect(
			screen.getByText( 'You have unsaved changes.' )
		).toBeInTheDocument();
	} );

	it( 'replaces a failed initial load spinner with a persistent retry', () => {
		mockSelectValues.getSettings = null;
		mockSelectValues.getError = 'Unable to load settings';
		render( <SettingsPanel /> );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Unable to load settings'
		);
		expect( screen.queryByTestId( 'spinner' ) ).not.toBeInTheDocument();
		fireEvent.click( screen.getByText( 'Reload settings' ) );
		expect( mockFetchSettings ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'preserves edits when refreshed settings arrive and confirms discarding', () => {
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValue( false );
		const { rerender } = render( <SettingsPanel /> );
		fireEvent.change( screen.getByLabelText( 'Batch size' ), {
			target: { value: '85' },
		} );
		mockSelectValues.getSettings = { ...DEFAULT_SETTINGS, batch_size: 90 };
		rerender( <SettingsPanel /> );
		expect( screen.getByLabelText( 'Batch size' ) ).toHaveValue( '85' );
		fireEvent.click( screen.getByText( 'Discard changes' ) );
		expect( screen.getByLabelText( 'Batch size' ) ).toHaveValue( '85' );
		confirm.mockReturnValue( true );
		fireEvent.click( screen.getByText( 'Discard changes' ) );
		expect( screen.getByLabelText( 'Batch size' ) ).toHaveValue( '50' );
		confirm.mockRestore();
	} );

	it( 'warns before unloading dirty settings and removes the guard on unmount', () => {
		const { unmount } = render( <SettingsPanel /> );
		fireEvent.change( screen.getByLabelText( 'Batch size' ), {
			target: { value: '85' },
		} );
		const event = new Event( 'beforeunload', { cancelable: true } );
		window.dispatchEvent( event );
		expect( event.defaultPrevented ).toBe( true );
		unmount();
		const after = new Event( 'beforeunload', { cancelable: true } );
		window.dispatchEvent( after );
		expect( after.defaultPrevented ).toBe( false );
	} );
} );
