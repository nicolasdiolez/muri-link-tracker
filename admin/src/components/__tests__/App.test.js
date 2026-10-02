import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
import App from '../../App';
import { DEFAULT_VIEW } from '../../utils/link-view';
const mockSetView = jest.fn();
const mockSyncMount = jest.fn();
const mockSyncUnmount = jest.fn();
jest.mock( '../../store', () => ( { STORE_NAME: 'test' } ) );
jest.mock( '@wordpress/notices', () => ( { store: 'notices' } ), {
	virtual: true,
} );
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( callback ) =>
		callback( ( name ) =>
			name === 'notices'
				? { getNotices: () => [] }
				: {
						getLinksView: () =>
							require( '../../utils/link-view' ).DEFAULT_VIEW,
				  }
		),
	useDispatch: () => ( {
		setLinksView: mockSetView,
		fetchLinks: jest.fn(),
		fetchStats: jest.fn(),
		removeNotice: jest.fn(),
	} ),
} ) );
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, 'aria-current': current } ) => (
		<button onClick={ onClick } aria-current={ current }>
			{ children }
		</button>
	),
	Modal: ( { children, title } ) => (
		<div role="dialog" aria-label={ title }>
			{ children }
		</div>
	),
	SnackbarList: () => null,
} ) );
jest.mock( '../Dashboard', () => ( { onFilterLinks } ) => (
	<button onClick={ () => onFilterLinks( { status: 'broken' } ) }>
		Broken card
	</button>
) );
jest.mock( '../LinkTable', () => () => <div>Links table</div> );
jest.mock( '../LinkEditModal', () => () => null );
jest.mock( '../SettingsPanel', () => ( { onDirtyChange } ) => (
	<button onClick={ () => onDirtyChange( true ) }>Edit settings</button>
) );
jest.mock( '../DataSync', () => () => {
	const { useEffect: effect } = require( '@wordpress/element' );
	effect( () => {
		mockSyncMount();
		return mockSyncUnmount;
	}, [] );
	return null;
} );
beforeEach( () => jest.clearAllMocks() );
it( 'keeps global polling mounted across section navigation and supports card drilldown', () => {
	render( <App /> );
	fireEvent.click( screen.getByText( 'Broken card' ) );
	expect( screen.getByText( 'Links table' ) ).toBeInTheDocument();
	expect( mockSetView ).toHaveBeenCalledWith(
		expect.objectContaining( {
			...DEFAULT_VIEW,
			filters: [
				{ field: 'statusCategory', operator: 'is', value: 'broken' },
			],
		} )
	);
	fireEvent.click( screen.getByRole( 'button', { name: 'Settings' } ) );
	expect( mockSyncMount ).toHaveBeenCalledTimes( 1 );
	expect( mockSyncUnmount ).not.toHaveBeenCalled();
} );
it( 'keeps settings mounted when abandoning navigation is cancelled', () => {
	render( <App /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Settings' } ) );
	fireEvent.click( screen.getByText( 'Edit settings' ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Links' } ) );
	expect(
		screen.getByRole( 'dialog', { name: 'Discard unsaved settings?' } )
	).toBeInTheDocument();
	expect( screen.getByText( 'Edit settings' ) ).toBeInTheDocument();
	fireEvent.click( screen.getByText( 'Keep editing' ) );
	expect( screen.queryByText( 'Links table' ) ).not.toBeInTheDocument();
	fireEvent.click( screen.getByRole( 'button', { name: 'Links' } ) );
	fireEvent.click( screen.getByText( 'Discard changes' ) );
	expect( screen.getByText( 'Links table' ) ).toBeInTheDocument();
} );
