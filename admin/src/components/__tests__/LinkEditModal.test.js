import '@testing-library/jest-dom';
import {
	render,
	screen,
	fireEvent,
	waitFor,
	act,
} from '@testing-library/react';
import LinkEditModal from '../LinkEditModal';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	sprintf: ( str, value ) => str.replace( '%d', value ),
} ) );

const mockFetchLink = jest.fn();
const mockUpdateLink = jest.fn();
const mockSetCurrentLink = jest.fn();
let mockSelectValues;

jest.mock( '@wordpress/data', () => ( {
	useSelect: ( mapSelect ) =>
		mapSelect( () => ( {
			getCurrentLink: () => mockSelectValues.getCurrentLink,
			isLoading: () => mockSelectValues.isLoading,
			getError: () => mockSelectValues.getError,
		} ) ),
	useDispatch: () => ( {
		fetchLink: mockFetchLink,
		updateLink: mockUpdateLink,
		setCurrentLink: mockSetCurrentLink,
	} ),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Modal: ( { title, onRequestClose, children } ) => (
		<div role="dialog" aria-label={ title }>
			<button onClick={ onRequestClose }>Close dialog</button>
			{ children }
		</div>
	),
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
	TextControl: ( { label, value, onChange, help, disabled, ...props } ) => (
		<div>
			<label htmlFor={ `mock-${ label }` }>{ label }</label>
			<input
				id={ `mock-${ label }` }
				value={ value }
				disabled={ disabled }
				aria-invalid={ props[ 'aria-invalid' ] }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
			{ help && <span>{ help }</span> }
		</div>
	),
	CheckboxControl: ( { label, checked, onChange, disabled } ) => (
		<label htmlFor={ `mock-checkbox-${ label }` }>
			<input
				id={ `mock-checkbox-${ label }` }
				type="checkbox"
				checked={ checked }
				disabled={ disabled }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			{ label }
		</label>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	Spinner: () => <div data-testid="spinner" />,
	__experimentalConfirmDialog: ( {
		isOpen,
		children,
		onConfirm,
		onCancel,
		confirmButtonText,
		cancelButtonText,
	} ) =>
		isOpen ? (
			<div role="dialog" aria-label="Confirm discard">
				{ children }
				<button onClick={ onConfirm }>{ confirmButtonText }</button>
				<button onClick={ onCancel }>{ cancelButtonText }</button>
			</div>
		) : null,
} ) );

jest.mock( '../../store', () => ( { STORE_NAME: 'muri-link-tracker' } ) );

const MOCK_LINK = {
	id: 42,
	url: 'https://example.com/old',
	instances: [
		{
			id: 1,
			postId: 10,
			postTitle: 'My Post',
			postEditUrl: '/wp-admin/post.php?post=10&action=edit',
			sourceType: 'post_content',
			anchorText: 'Click here',
			relNofollow: true,
			relSponsored: false,
			relUgc: false,
			editable: true,
		},
	],
};

const changeUrl = ( value = 'https://example.com/new' ) =>
	fireEvent.change( screen.getByLabelText( 'URL' ), { target: { value } } );
const harmonize = () =>
	fireEvent.click(
		screen.getByLabelText(
			'Harmonize tracked rel attributes across all occurrences'
		)
	);

describe( 'LinkEditModal', () => {
	const onClose = jest.fn();

	beforeEach( () => {
		jest.resetAllMocks();
		mockSelectValues = {
			getCurrentLink: MOCK_LINK,
			isLoading: false,
			getError: null,
		};
		mockUpdateLink.mockResolvedValue( { id: 42 } );
	} );

	it( 'fetches the requested link and announces loading', () => {
		mockSelectValues.getCurrentLink = null;
		mockSelectValues.isLoading = true;
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		expect( mockFetchLink ).toHaveBeenCalledWith( 42 );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading link details'
		);
		expect( screen.queryByLabelText( 'URL' ) ).not.toBeInTheDocument();
	} );

	it( 'does not offer a write when nothing changed', () => {
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		expect( screen.getByLabelText( 'URL' ) ).toHaveValue( MOCK_LINK.url );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( mockUpdateLink ).not.toHaveBeenCalled();
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( onClose ).toHaveBeenCalledWith( false );
	} );

	it( 'sends only the changed URL, preserving all rel attributes by omission', async () => {
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		changeUrl( '  https://example.com/new  ' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( onClose ).toHaveBeenCalledWith( true ) );
		expect( mockUpdateLink ).toHaveBeenCalledWith( 42, {
			url: 'https://example.com/new',
		} );
	} );

	it( 'shows mixed attributes and requires explicit harmonization before sending rel', async () => {
		mockSelectValues.getCurrentLink = {
			...MOCK_LINK,
			instances: [
				...MOCK_LINK.instances,
				{
					...MOCK_LINK.instances[ 0 ],
					id: 2,
					postTitle: 'Sponsored Post',
					relNofollow: false,
					relSponsored: true,
				},
			],
		};
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		expect(
			screen.getByText( /Mixed rel attributes/ )
		).toBeInTheDocument();
		expect( screen.queryByLabelText( 'nofollow' ) ).not.toBeInTheDocument();
		harmonize();
		expect( screen.getByLabelText( 'nofollow' ) ).toBeChecked();
		expect( screen.getByLabelText( 'sponsored' ) ).toBeChecked();
		expect( screen.getByLabelText( 'ugc' ) ).not.toBeChecked();
		fireEvent.click( screen.getByLabelText( 'nofollow' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () =>
			expect( mockUpdateLink ).toHaveBeenCalledWith( 42, {
				rel: 'sponsored',
			} )
		);
	} );

	it( 'omits rel again if harmonization is switched off', async () => {
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		harmonize();
		fireEvent.click( screen.getByLabelText( 'sponsored' ) );
		harmonize();
		changeUrl();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () =>
			expect( mockUpdateLink ).toHaveBeenCalledWith( 42, {
				url: 'https://example.com/new',
			} )
		);
	} );

	it( 'allows explicitly clearing all tracked rel attributes', async () => {
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		harmonize();
		fireEvent.click( screen.getByLabelText( 'nofollow' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () =>
			expect( mockUpdateLink ).toHaveBeenCalledWith( 42, { rel: '' } )
		);
	} );

	it.each( [
		'javascript:alert(1)',
		'ftp://example.com/file',
		'/relative',
		'',
		'not a url',
	] )( 'blocks invalid destination %s', ( value ) => {
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		changeUrl( value );
		expect( screen.getByLabelText( 'URL' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		expect( mockUpdateLink ).not.toHaveBeenCalled();
	} );

	it( 'asks before discarding dirty edits and allows keeping them', () => {
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		changeUrl();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Close dialog' } )
		);
		expect( onClose ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'dialog', { name: 'Confirm discard' } )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Keep editing' } )
		);
		expect(
			screen.queryByRole( 'dialog', { name: 'Confirm discard' } )
		).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'URL' ) ).toHaveValue(
			'https://example.com/new'
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Discard changes' } )
		);
		expect( onClose ).toHaveBeenCalledWith( false );
	} );

	it( 'blocks closing and editing while a save is pending', async () => {
		let resolveSave;
		mockUpdateLink.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveSave = resolve;
			} )
		);
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		changeUrl();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( screen.getByLabelText( 'URL' ) ).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeDisabled();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Close dialog' } )
		);
		expect( onClose ).not.toHaveBeenCalled();
		await act( async () => resolveSave( { id: 42 } ) );
		expect( onClose ).toHaveBeenCalledWith( true );
	} );

	it( 'retains unsaved input after a failed save and permits retry', async () => {
		mockUpdateLink.mockResolvedValueOnce( null );
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		changeUrl();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await screen.findByText( /Your changes are still here/ );
		expect( onClose ).not.toHaveBeenCalled();
		expect( screen.getByLabelText( 'URL' ) ).toHaveValue(
			'https://example.com/new'
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( onClose ).toHaveBeenCalledWith( true ) );
	} );

	it( 'shows detail loading errors and retries the correct link', () => {
		mockSelectValues.getCurrentLink = null;
		mockSelectValues.getError = 'Network unavailable';
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Network unavailable'
		);
		expect( screen.queryByTestId( 'spinner' ) ).not.toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
		expect( mockFetchLink ).toHaveBeenNthCalledWith( 2, 42 );
	} );

	it( 'never displays or saves a stale response for another link', async () => {
		const { rerender } = render(
			<LinkEditModal linkId={ 42 } onClose={ onClose } />
		);
		changeUrl();
		rerender( <LinkEditModal linkId={ 99 } onClose={ onClose } /> );
		expect( mockFetchLink ).toHaveBeenLastCalledWith( 99 );
		expect( screen.queryByLabelText( 'URL' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Save' } )
		).not.toBeInTheDocument();
		mockSelectValues.getCurrentLink = {
			...MOCK_LINK,
			id: 99,
			url: 'https://example.com/second',
		};
		rerender( <LinkEditModal linkId={ 99 } onClose={ onClose } /> );
		expect( screen.getByLabelText( 'URL' ) ).toHaveValue(
			'https://example.com/second'
		);
		changeUrl( 'https://example.com/second-updated' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () =>
			expect( mockUpdateLink ).toHaveBeenCalledWith( 99, {
				url: 'https://example.com/second-updated',
			} )
		);
	} );

	it( 'does not replace unsaved edits when details refresh for the same link', () => {
		const { rerender } = render(
			<LinkEditModal linkId={ 42 } onClose={ onClose } />
		);
		changeUrl();
		mockSelectValues.getCurrentLink = {
			...MOCK_LINK,
			lastError: 'Request timed out',
		};
		rerender( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		expect( screen.getByLabelText( 'URL' ) ).toHaveValue(
			'https://example.com/new'
		);
	} );

	it( 'shows diagnostics and source details with safe external links', () => {
		mockSelectValues.getCurrentLink = {
			...MOCK_LINK,
			lastError: 'Request timed out',
			finalUrl: 'https://example.com/final',
			redirectChain: [
				{ url: 'https://example.com/first', status: 301 },
				{ url: 'javascript:alert(1)', status: 302 },
			],
		};
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		expect( screen.getByText( /Request timed out/ ) ).toBeInTheDocument();
		expect( screen.getByText( 'Redirect chain' ) ).toBeInTheDocument();
		expect( screen.getByText( /Click here/ ) ).toBeInTheDocument();
		expect( screen.getByText( /Post content/ ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'https://example.com/final' } )
		).toHaveAttribute( 'rel', 'noopener noreferrer' );
		expect(
			screen.getByRole( 'link', { name: 'My Post' } )
		).toHaveAttribute(
			'href',
			'http://localhost/wp-admin/post.php?post=10&action=edit'
		);
		expect( screen.getByText( 'javascript:alert(1)' ).tagName ).toBe(
			'SPAN'
		);
	} );

	it( 'blocks editing when any occurrence must be edited in its source', () => {
		mockSelectValues.getCurrentLink = {
			...MOCK_LINK,
			instances: [
				...MOCK_LINK.instances,
				{
					...MOCK_LINK.instances[ 0 ],
					id: 2,
					sourceType: 'custom_field',
					editable: false,
					readOnlyReason:
						'Edit this custom field in its source page.',
				},
			],
		};
		render( <LinkEditModal linkId={ 42 } onClose={ onClose } /> );
		expect( screen.getByLabelText( 'URL' ) ).toBeDisabled();
		expect(
			screen.getByLabelText(
				'Harmonize tracked rel attributes across all occurrences'
			)
		).toBeDisabled();
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		expect(
			screen.getByText( 'Edit this custom field in its source page.' )
		).toBeInTheDocument();
	} );
} );
