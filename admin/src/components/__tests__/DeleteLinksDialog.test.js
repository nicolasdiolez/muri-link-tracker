import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import DeleteLinksDialog from '../DeleteLinksDialog';
import { fetchLinkFromApi } from '../../utils/api';
jest.mock( '../../utils/api', () => ( { fetchLinkFromApi: jest.fn() } ) );
jest.mock( '@wordpress/components', () => ( {
	Modal: ( { children, title } ) => (
		<div role="dialog" aria-label={ title }>
			{ children }
		</div>
	),
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
	Notice: ( { children } ) => <div role="alert">{ children }</div>,
	Spinner: () => <span>Loading</span>,
} ) );
const ids = [ 1, 2 ];
beforeEach( () => jest.clearAllMocks() );
it( 'loads and describes affected posts before allowing deletion', async () => {
	fetchLinkFromApi.mockResolvedValue( {
		instances: [ { postId: 4, postTitle: 'Article four', editable: true } ],
	} );
	const onConfirm = jest.fn().mockResolvedValue( { success: 2, failed: 0 } );
	const onClose = jest.fn();
	render(
		<DeleteLinksDialog
			ids={ ids }
			onConfirm={ onConfirm }
			onClose={ onClose }
		/>
	);
	expect(
		screen.getByRole( 'button', { name: 'Remove links from content' } )
	).toBeDisabled();
	await screen.findByText( '2 URL(s) across 1 article(s) will be affected.' );
	expect( onConfirm ).not.toHaveBeenCalled();
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Remove links from content' } )
	);
	await waitFor( () => expect( onConfirm ).toHaveBeenCalledWith( ids ) );
	expect( onClose ).toHaveBeenCalled();
} );
it( 'blocks mutation when source information cannot be loaded', async () => {
	fetchLinkFromApi.mockRejectedValue( new Error( 'Offline' ) );
	const onConfirm = jest.fn();
	render(
		<DeleteLinksDialog
			ids={ ids }
			onConfirm={ onConfirm }
			onClose={ jest.fn() }
		/>
	);
	await screen.findByText( 'Offline' );
	expect(
		screen.getByRole( 'button', { name: 'Remove links from content' } )
	).toBeDisabled();
	expect( onConfirm ).not.toHaveBeenCalled();
} );

it( 'keeps the confirmation open after a failed operation', async () => {
	fetchLinkFromApi.mockResolvedValue( {
		instances: [ { postId: 4, postTitle: 'Article four' } ],
	} );
	const onClose = jest.fn();
	const onConfirm = jest.fn().mockResolvedValue( {
		success: 0,
		failed: 2,
		failures: [ { id: 1, message: 'Content changed since scan' } ],
	} );
	render(
		<DeleteLinksDialog
			ids={ ids }
			onConfirm={ onConfirm }
			onClose={ onClose }
		/>
	);
	await screen.findByText( '2 URL(s) across 1 article(s) will be affected.' );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Remove links from content' } )
	);
	await screen.findByText( 'Content changed since scan' );
	expect( onClose ).not.toHaveBeenCalled();
} );
