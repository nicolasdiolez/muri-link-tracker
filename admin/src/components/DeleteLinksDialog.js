import { useEffect, useState } from '@wordpress/element';
import { Modal, Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { fetchLinkFromApi } from '../utils/api';

export default function DeleteLinksDialog( { ids, onConfirm, onClose } ) {
	const [ remainingIds, setRemainingIds ] = useState( ids );
	const [ partial, setPartial ] = useState( false );
	const [ details, setDetails ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		let cancelled = false;
		setDetails( null );
		const load = async () => {
			try {
				const result = [];
				for ( let index = 0; index < remainingIds.length; index += 5 ) {
					result.push(
						...( await Promise.all(
							remainingIds
								.slice( index, index + 5 )
								.map( fetchLinkFromApi )
						) )
					);
					if ( cancelled ) {
						return;
					}
				}
				setDetails( result );
			} catch ( failure ) {
				if ( ! cancelled ) {
					setError(
						failure.message ||
							__(
								'Unable to load the affected content. Close and try again.',
								'muri-link-tracker'
							)
					);
				}
			}
		};
		load();
		return () => {
			cancelled = true;
		};
	}, [ remainingIds ] );
	const sources = new Map();
	for ( const link of details || [] ) {
		for ( const instance of link.instances || [] ) {
			sources.set( instance.postId, instance );
		}
	}
	const unsupported = details?.some( ( link ) =>
		link.instances?.some( ( instance ) => instance.editable === false )
	);
	const confirm = async () => {
		setBusy( true );
		try {
			const result = await onConfirm( remainingIds );
			if ( ! result || result.failed ) {
				const detailsText = ( result?.failures || [] )
					.map( ( failure ) => failure.message )
					.join( ' ' );
				setError(
					detailsText ||
						__(
							'Some links could not be removed. Close this dialog to review the updated list and try again.',
							'muri-link-tracker'
						)
				);
				const failedIds = ( result?.failures || [] ).map( ( failure ) =>
					Number( failure.id )
				);
				if ( result?.success > 0 ) {
					if ( failedIds.length ) {
						setRemainingIds(
							remainingIds.filter( ( id ) =>
								failedIds.includes( Number( id ) )
							)
						);
					} else {
						setPartial( true );
					}
				}
				return;
			}
			onClose();
		} finally {
			setBusy( false );
		}
	};
	return (
		<Modal
			title={ __( 'Remove links from content', 'muri-link-tracker' ) }
			onRequestClose={ () => ! busy && onClose() }
		>
			<p>
				{ __(
					'This removes the selected links from every affected article, retaining their content. It also removes them from this inventory. This is not an ignore action.',
					'muri-link-tracker'
				) }
			</p>
			{ ! details && ! error && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ details && (
				<>
					<p>
						{ sprintf(
							/* translators: 1: number of URLs, 2: number of affected articles. */
							__(
								'%1$d URL(s) across %2$d article(s) will be affected.',
								'muri-link-tracker'
							),
							remainingIds.length,
							sources.size
						) }
					</p>
					<ul>
						{ [ ...sources.entries() ].map( ( [ id, source ] ) => (
							<li key={ id }>
								{ source.postTitle || `#${ id }` }
							</li>
						) ) }
					</ul>
				</>
			) }
			{ unsupported && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Some sources cannot be edited safely here. Open their source articles to remove these links.',
						'muri-link-tracker'
					) }
				</Notice>
			) }
			<div className="mltr-edit-modal__actions">
				<Button
					variant="tertiary"
					disabled={ busy }
					onClick={ onClose }
				>
					{ __( 'Cancel', 'muri-link-tracker' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ busy || ! details || unsupported || partial }
					onClick={ confirm }
				>
					{ __( 'Remove links from content', 'muri-link-tracker' ) }
				</Button>
			</div>
		</Modal>
	);
}
