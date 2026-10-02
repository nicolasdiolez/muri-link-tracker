/**
 * Link details and explicit editing of URLs and tracked rel attributes.
 *
 * @package
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Modal,
	Button,
	TextControl,
	CheckboxControl,
	Spinner,
	Notice,
	// WordPress currently exports its accessible confirmation dialog under this name.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

const REL_TOKENS = [ 'nofollow', 'sponsored', 'ugc' ];
const SOURCE_LABELS = {
	post_content: __( 'Post content', 'muri-link-tracker' ),
	post_excerpt: __( 'Post excerpt', 'muri-link-tracker' ),
	custom_field: __( 'Custom field', 'muri-link-tracker' ),
	block_attribute: __( 'Block attribute', 'muri-link-tracker' ),
};
const trackedRel = ( instance ) =>
	[
		instance.relNofollow && 'nofollow',
		instance.relSponsored && 'sponsored',
		instance.relUgc && 'ugc',
	]
		.filter( Boolean )
		.join( ' ' );

// Never turn unchecked API data into a javascript: or data: navigation.
const safeHref = ( value, allowRelative = false ) => {
	if ( typeof value !== 'string' || ! value.trim() ) {
		return undefined;
	}
	try {
		const parsed = allowRelative
			? new URL( value, window.location.href )
			: new URL( value );
		return [ 'http:', 'https:' ].includes( parsed.protocol )
			? parsed.href
			: undefined;
	} catch {
		return undefined;
	}
};

// Editing also accepts site-relative paths, following the REST validation rules.
const validEditableUrl = ( value ) => {
	if ( ! value ) {
		return false;
	}
	for ( const character of value ) {
		const code = character.charCodeAt( 0 );
		if ( code <= 32 || code === 127 || character === '\\' ) {
			return false;
		}
	}
	if ( value.startsWith( '/' ) ) {
		return ! value.startsWith( '//' );
	}
	const authority = value.match( /^https?:\/\/([^/?#]+)/i )?.[ 1 ];
	if ( ! authority || authority.includes( '@' ) ) {
		return false;
	}
	try {
		const parsed = new URL( value );
		return Boolean(
			parsed.hostname && ! parsed.username && ! parsed.password
		);
	} catch {
		return false;
	}
};

const SourceLink = ( { href, children, allowRelative = false } ) => {
	const safeUrl = safeHref( href, allowRelative );
	return safeUrl ? (
		<a href={ safeUrl } target="_blank" rel="noopener noreferrer">
			{ children }
		</a>
	) : (
		<span>{ children }</span>
	);
};

const LinkEditModal = ( { linkId, onClose } ) => {
	const { currentLink, loading, error } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			currentLink: store.getCurrentLink(),
			loading: store.isLoading( 'currentLink' ),
			error: store.getError( 'currentLink' ),
		};
	}, [] );
	const { fetchLink, updateLink, setCurrentLink } = useDispatch( STORE_NAME );
	const [ formLinkId, setFormLinkId ] = useState( null );
	const [ originalUrl, setOriginalUrl ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ relTokens, setRelTokens ] = useState( [] );
	const [ harmonizeRel, setHarmonizeRel ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( '' );
	const [ confirmClose, setConfirmClose ] = useState( false );
	const activeLinkId = useRef( linkId );
	activeLinkId.current = linkId;
	const link = currentLink?.id === linkId ? currentLink : null;
	const instances = link?.instances || [];
	const relValues = [ ...new Set( instances.map( trackedRel ) ) ];
	const readOnly = instances.some(
		( instance ) => instance.editable === false
	);
	const dirty = url.trim() !== originalUrl || harmonizeRel;
	const invalidUrl = ! validEditableUrl( url );
	const ready = ! loading && ! error && link && formLinkId === linkId;

	useEffect( () => {
		activeLinkId.current = linkId;
		setFormLinkId( null );
		setSaving( false );
		setSaveError( '' );
		setConfirmClose( false );
		fetchLink( linkId );
		return () => {
			activeLinkId.current = null;
			setCurrentLink( null );
		};
	}, [ linkId, fetchLink, setCurrentLink ] );

	useEffect( () => {
		// Late responses for another link cannot populate this form. Repeated
		// store updates must not overwrite an in-progress edit either.
		if ( currentLink?.id === linkId && formLinkId !== linkId ) {
			setUrl( currentLink.url || '' );
			setOriginalUrl( currentLink.url || '' );
			setRelTokens( [
				...new Set(
					( currentLink.instances || [] )
						.map( trackedRel )
						.join( ' ' )
						.split( ' ' )
						.filter( Boolean )
				),
			] );
			setHarmonizeRel( false );
			setFormLinkId( linkId );
		}
	}, [ currentLink, linkId, formLinkId ] );

	const handleClose = () => {
		if ( saving ) {
			return;
		}
		if ( ready && dirty ) {
			setConfirmClose( true );
		} else {
			onClose( false );
		}
	};

	const handleSave = async () => {
		if ( ! ready || readOnly || saving || ! dirty || invalidUrl ) {
			return;
		}
		const data = {};
		if ( url.trim() !== originalUrl ) {
			data.url = url.trim();
		}
		// Omission preserves the complete original rel on every occurrence.
		if ( harmonizeRel ) {
			data.rel = REL_TOKENS.filter( ( token ) =>
				relTokens.includes( token )
			).join( ' ' );
		}
		setSaving( true );
		setSaveError( '' );
		try {
			const result = await updateLink( linkId, data );
			if ( activeLinkId.current !== linkId ) {
				return;
			}
			if ( result ) {
				onClose( true );
			} else {
				setSaveError(
					__(
						'The link could not be saved. Your changes are still here; please try again.',
						'muri-link-tracker'
					)
				);
			}
		} catch ( saveFailure ) {
			if ( activeLinkId.current === linkId ) {
				setSaveError(
					saveFailure.message ||
						__(
							'The link could not be saved. Please try again.',
							'muri-link-tracker'
						)
				);
			}
		} finally {
			if ( activeLinkId.current === linkId ) {
				setSaving( false );
			}
		}
	};

	return (
		<Modal
			title={ __( 'Link details and editing', 'muri-link-tracker' ) }
			onRequestClose={ handleClose }
			isDismissible={ ! saving }
			shouldCloseOnEsc={ ! saving }
			shouldCloseOnClickOutside={ ! saving }
			className="mltr-edit-modal"
		>
			{ ( loading || ( ! error && ! ready ) ) && (
				<div role="status">
					<Spinner />
					{ __( 'Loading link details…', 'muri-link-tracker' ) }
				</div>
			) }
			{ ! loading && error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error.message || error }</p>
					<Button
						variant="secondary"
						onClick={ () => fetchLink( linkId ) }
					>
						{ __( 'Retry', 'muri-link-tracker' ) }
					</Button>
				</Notice>
			) }
			{ ready && (
				<>
					{ readOnly && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This link has occurrences that cannot be safely edited here. Use the source page links below to make changes.',
								'muri-link-tracker'
							) }
						</Notice>
					) }
					<TextControl
						label={ __( 'URL', 'muri-link-tracker' ) }
						value={ url }
						onChange={ setUrl }
						disabled={ saving || readOnly }
						aria-invalid={ invalidUrl }
						help={
							invalidUrl
								? __(
										'Enter an HTTP(S) URL or a site-relative path such as /page, without spaces or credentials.',
										'muri-link-tracker'
								  )
								: __(
										'Changing this URL updates its occurrences in published content.',
										'muri-link-tracker'
								  )
						}
						__nextHasNoMarginBottom
					/>
					<p>
						{ relValues.length > 1
							? __(
									'Mixed rel attributes: occurrences have different tracked values. Original attributes are preserved by default.',
									'muri-link-tracker'
							  )
							: __(
									'Original rel attributes are preserved unless you choose to harmonize them.',
									'muri-link-tracker'
							  ) }
					</p>
					<CheckboxControl
						label={ __(
							'Harmonize tracked rel attributes across all occurrences',
							'muri-link-tracker'
						) }
						checked={ harmonizeRel }
						onChange={ setHarmonizeRel }
						disabled={ saving || readOnly }
						__nextHasNoMarginBottom
					/>
					{ harmonizeRel && (
						<fieldset>
							<legend>
								{ __(
									'Tracked rel attributes',
									'muri-link-tracker'
								) }
							</legend>
							<p>
								{ __(
									'Apply the selected nofollow, sponsored and ugc values to every occurrence. Other tokens, such as noopener and noreferrer, are preserved. Uncheck all three to remove only the tracked attributes.',
									'muri-link-tracker'
								) }
							</p>
							{ REL_TOKENS.map( ( token ) => (
								<CheckboxControl
									key={ token }
									label={ token }
									checked={ relTokens.includes( token ) }
									onChange={ ( checked ) =>
										setRelTokens( ( previous ) =>
											checked
												? [ ...previous, token ]
												: previous.filter(
														( value ) =>
															value !== token
												  )
										)
									}
									disabled={ saving || readOnly }
									__nextHasNoMarginBottom
								/>
							) ) }
						</fieldset>
					) }
					{ link.lastError && (
						<Notice status="warning" isDismissible={ false }>
							<strong>
								{ __(
									'Last check error:',
									'muri-link-tracker'
								) }
							</strong>{ ' ' }
							{ link.lastError }
						</Notice>
					) }
					{ link.finalUrl && (
						<p>
							<strong>
								{ __(
									'Final destination:',
									'muri-link-tracker'
								) }
							</strong>{ ' ' }
							<SourceLink href={ link.finalUrl }>
								{ link.finalUrl }
							</SourceLink>
						</p>
					) }
					{ Array.isArray( link.redirectChain ) &&
						link.redirectChain.length > 0 && (
							<div>
								<h3>
									{ __(
										'Redirect chain',
										'muri-link-tracker'
									) }
								</h3>
								<ol>
									{ link.redirectChain.map(
										( hop, index ) => (
											<li
												key={ `${ index }-${ hop.url }` }
											>
												{ hop.status
													? `HTTP ${ hop.status } — `
													: '' }
												<SourceLink href={ hop.url }>
													{ hop.url }
												</SourceLink>
											</li>
										)
									) }
								</ol>
							</div>
						) }
					{ instances.length > 0 && (
						<div className="mltr-edit-modal__instances">
							<h3>{ __( 'Found in:', 'muri-link-tracker' ) }</h3>
							<ul>
								{ instances.map( ( inst ) => (
									<li key={ inst.id }>
										<SourceLink
											href={ inst.postEditUrl }
											allowRelative
										>
											{ inst.postTitle ||
												sprintf(
													/* translators: %d: source post ID. */
													__(
														'Post #%d',
														'muri-link-tracker'
													),
													inst.postId
												) }
										</SourceLink>
										{ inst.anchorText && (
											<span className="mltr-edit-modal__anchor">{ ` — "${ inst.anchorText }"` }</span>
										) }
										<div>
											{ __(
												'Source:',
												'muri-link-tracker'
											) }{ ' ' }
											{ SOURCE_LABELS[
												inst.sourceType
											] ||
												inst.sourceType ||
												__(
													'Unknown',
													'muri-link-tracker'
												) }
											{ ' · ' }
											{ __(
												'Tracked rel:',
												'muri-link-tracker'
											) }{ ' ' }
											{ trackedRel( inst ) ||
												__(
													'None',
													'muri-link-tracker'
												) }
										</div>
										{ inst.editable === false && (
											<p>
												{ inst.readOnlyReason ||
													__(
														'Edit this occurrence in its source page.',
														'muri-link-tracker'
													) }
											</p>
										) }
									</li>
								) ) }
							</ul>
						</div>
					) }
					{ saveError && (
						<Notice status="error" isDismissible={ false }>
							{ saveError }
						</Notice>
					) }
					<div className="mltr-edit-modal__actions">
						<Button
							variant="primary"
							onClick={ handleSave }
							isBusy={ saving }
							disabled={
								saving || readOnly || ! dirty || invalidUrl
							}
						>
							{ __( 'Save', 'muri-link-tracker' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ handleClose }
							disabled={ saving }
						>
							{ __( 'Cancel', 'muri-link-tracker' ) }
						</Button>
					</div>
				</>
			) }
			<ConfirmDialog
				isOpen={ confirmClose }
				onConfirm={ () => onClose( false ) }
				onCancel={ () => setConfirmClose( false ) }
				confirmButtonText={ __(
					'Discard changes',
					'muri-link-tracker'
				) }
				cancelButtonText={ __( 'Keep editing', 'muri-link-tracker' ) }
			>
				{ __(
					'Discard your unsaved changes to this link?',
					'muri-link-tracker'
				) }
			</ConfirmDialog>
		</Modal>
	);
};

export default LinkEditModal;
