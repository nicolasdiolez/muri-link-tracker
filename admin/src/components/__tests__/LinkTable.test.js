import '@testing-library/jest-dom';
import { render, screen, fireEvent } from '@testing-library/react';
import LinkTable from '../LinkTable';
import { DEFAULT_VIEW } from '../../utils/link-view';
let mockView;
let mockDataViews;
const mockFetch = jest.fn();
const mockDelete = jest.fn();
const mockUpdateSettings = jest.fn();
const mockSetView = jest.fn( ( view ) => {
	mockView = view;
} );
const mockLinks = [ { id: 7, url: 'https://example.com' } ];
jest.mock( '../../store', () => ( { STORE_NAME: 'test' } ) );
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( callback ) =>
		callback( () => ( {
			getLinks: () => mockLinks,
			getTotal: () => 1,
			getTotalPages: () => 1,
			isLoading: () => false,
			getSettings: () => null,
			getLinksView: () => mockView,
			getError: () => null,
			getRechecks: () => [],
		} ) ),
	useDispatch: () => ( {
		fetchLinks: mockFetch,
		deleteLink: mockDelete,
		setLinksView: mockSetView,
		updateSettings: mockUpdateSettings,
	} ),
} ) );
jest.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( props ) => {
		mockDataViews = props;
		return <div>Table</div>;
	},
} ) );
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, 'aria-pressed': pressed } ) => (
		<button onClick={ onClick } aria-pressed={ pressed }>
			{ children }
		</button>
	),
	ButtonGroup: ( { children } ) => <div>{ children }</div>,
	Notice: ( { children } ) => <div>{ children }</div>,
} ) );
jest.mock( '../DeleteLinksDialog', () => ( { ids } ) => (
	<div role="dialog">Confirm { ids.join( ',' ) }</div>
) );
beforeEach( () => {
	jest.clearAllMocks();
	mockView = { ...DEFAULT_VIEW, filters: [] };
} );
it( 'shares the status filter between quick controls and the table view', () => {
	const { rerender } = render( <LinkTable onEditLink={ jest.fn() } /> );
	fireEvent.click( screen.getByText( 'Broken' ) );
	rerender( <LinkTable onEditLink={ jest.fn() } /> );
	expect( mockDataViews.view.filters ).toEqual( [
		{ field: 'statusCategory', operator: 'is', value: 'broken' },
	] );
	expect( screen.getByText( 'Broken' ) ).toHaveAttribute(
		'aria-pressed',
		'true'
	);
	mockView = {
		...mockView,
		filters: [ { field: 'statusCategory', operator: 'is', value: 'ok' } ],
	};
	rerender( <LinkTable onEditLink={ jest.fn() } /> );
	expect( screen.getByText( 'OK' ) ).toHaveAttribute(
		'aria-pressed',
		'true'
	);
	expect( screen.getByText( 'Broken' ) ).toHaveAttribute(
		'aria-pressed',
		'false'
	);
} );
it( 'does not refetch server data for a density-only view change', () => {
	const { rerender } = render( <LinkTable onEditLink={ jest.fn() } /> );
	expect( mockFetch ).toHaveBeenCalledTimes( 1 );
	mockDataViews.onChangeView( {
		...mockView,
		layout: { density: 'compact' },
	} );
	rerender( <LinkTable onEditLink={ jest.fn() } /> );
	expect( mockFetch ).toHaveBeenCalledTimes( 1 );
	expect( mockUpdateSettings ).toHaveBeenCalledWith( { density: 'compact' } );
} );
it( 'routes destructive actions through confirmation', () => {
	const { rerender } = render( <LinkTable onEditLink={ jest.fn() } /> );
	// Invoke the DataViews action within a user event to flush React updates.
	const action = mockDataViews.actions.find(
		( item ) => item.id === 'delete'
	);
	const button = document.createElement( 'button' );
	button.onclick = () => action.callback( mockLinks );
	document.body.appendChild( button );
	fireEvent.click( button );
	button.remove();
	rerender( <LinkTable onEditLink={ jest.fn() } /> );
	expect( screen.getByRole( 'dialog' ) ).toHaveTextContent( 'Confirm 7' );
	expect( mockDelete ).not.toHaveBeenCalled();
} );
