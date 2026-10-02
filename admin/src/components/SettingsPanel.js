/**
 * SettingsPanel — Plugin settings form.
 *
 * @package
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Button,
	TextControl,
	TextareaControl,
	ToggleControl,
	Spinner,
	Panel,
	PanelBody,
	PanelRow,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

const NUMBER_FIELDS = {
	batch_size: {
		min: 10,
		max: 200,
		label: __( 'Batch size', 'muri-link-tracker' ),
	},
	check_timeout: {
		min: 5,
		max: 60,
		label: __( 'Timeout (seconds)', 'muri-link-tracker' ),
	},
	http_request_delay: {
		min: 0,
		max: 5000,
		label: __( 'Delay between requests (ms)', 'muri-link-tracker' ),
	},
	recheck_interval: {
		min: 1,
		max: 30,
		label: __( 'Recheck interval (days)', 'muri-link-tracker' ),
	},
};

// Keep separators and incomplete numbers intact until the user saves.
const createDraft = ( settings ) =>
	settings
		? {
				...settings,
				scan_post_types: ( settings.scan_post_types || [] ).join(
					', '
				),
				excluded_urls: ( settings.excluded_urls || [] ).join( '\n' ),
				...Object.fromEntries(
					Object.keys( NUMBER_FIELDS ).map( ( key ) => [
						key,
						String( settings[ key ] ?? '' ),
					] )
				),
		  }
		: null;

const SettingsPanel = ( { onDirtyChange } ) => {
	const { settings, loading, error } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			settings: store.getSettings(),
			loading: store.isLoading( 'settings' ),
			error: store.getError( 'settings' ),
		};
	}, [] );
	const { updateSettings, fetchSettings } = useDispatch( STORE_NAME );
	const [ form, setForm ] = useState( () => createDraft( settings ) );
	const [ savedForm, setSavedForm ] = useState( () =>
		createDraft( settings )
	);
	const [ validation, setValidation ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const dirty = JSON.stringify( form ) !== JSON.stringify( savedForm );
	const dirtyRef = useRef( dirty );
	dirtyRef.current = dirty;

	useEffect( () => {
		if ( settings && ! dirtyRef.current ) {
			const draft = createDraft( settings );
			setForm( draft );
			setSavedForm( draft );
		}
	}, [ settings ] );

	useEffect( () => {
		onDirtyChange?.( dirty );
	}, [ dirty, onDirtyChange ] );
	useEffect( () => () => onDirtyChange?.( false ), [ onDirtyChange ] );
	useEffect( () => {
		if ( ! dirty ) {
			return;
		}
		const warnBeforeUnload = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warnBeforeUnload );
		return () =>
			window.removeEventListener( 'beforeunload', warnBeforeUnload );
	}, [ dirty ] );

	const updateField = ( key, value ) => {
		setForm( ( previous ) => ( { ...previous, [ key ]: value } ) );
		setValidation( ( previous ) => ( {
			...previous,
			[ key ]: undefined,
		} ) );
	};

	const handleSave = async () => {
		const errors = {};
		const normalized = {
			...form,
			scan_post_types: [
				...new Set(
					form.scan_post_types
						.split( ',' )
						.map( ( value ) => value.trim() )
						.filter( Boolean )
				),
			],
			excluded_urls: [
				...new Set(
					form.excluded_urls
						.split( '\n' )
						.map( ( value ) => value.trim() )
						.filter( Boolean )
				),
			],
		};
		for ( const [ key, { min, max, label } ] of Object.entries(
			NUMBER_FIELDS
		) ) {
			const number = Number( form[ key ] );
			if (
				! form[ key ].trim() ||
				! Number.isInteger( number ) ||
				number < min ||
				number > max
			) {
				errors[ key ] = sprintf(
					/* translators: 1: field name, 2: minimum, 3: maximum. */
					__(
						'%1$s must be a whole number between %2$d and %3$d.',
						'muri-link-tracker'
					),
					label,
					min,
					max
				);
			}
			normalized[ key ] = number;
		}
		setValidation( errors );
		if ( Object.keys( errors ).length ) {
			return;
		}
		setSaving( true );
		try {
			const saved = await updateSettings( normalized );
			if ( saved ) {
				const draft = createDraft( saved );
				setForm( draft );
				setSavedForm( draft );
			}
		} finally {
			setSaving( false );
		}
	};

	const renderNumberField = ( key ) => (
		<PanelRow key={ key }>
			<TextControl
				{ ...NUMBER_FIELDS[ key ] }
				type="number"
				step={ 1 }
				value={ form[ key ] }
				onChange={ ( value ) => updateField( key, value ) }
				aria-invalid={ !! validation[ key ] }
				help={ validation[ key ] }
				__nextHasNoMarginBottom
			/>
		</PanelRow>
	);

	const errorNotice = error && (
		<Notice status="error" isDismissible={ false }>
			<p>{ error.message || error }</p>
			<Button
				variant="secondary"
				onClick={ () => fetchSettings() }
				disabled={ loading || saving }
			>
				{ __( 'Reload settings', 'muri-link-tracker' ) }
			</Button>
		</Notice>
	);
	if ( ! form ) {
		return errorNotice || <Spinner />;
	}
	return (
		<div className="mltr-settings">
			{ errorNotice }
			{ Object.values( validation ).some( Boolean ) && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Check the highlighted fields before saving.',
						'muri-link-tracker'
					) }
				</Notice>
			) }
			<fieldset
				disabled={ saving }
				style={ { border: 0, margin: 0, padding: 0 } }
			>
				<Panel>
					<PanelBody
						title={ __( 'Scanning', 'muri-link-tracker' ) }
						initialOpen
					>
						<PanelRow>
							<TextControl
								label={ __(
									'Post types to scan',
									'muri-link-tracker'
								) }
								value={ form.scan_post_types }
								onChange={ ( value ) =>
									updateField( 'scan_post_types', value )
								}
								help={ __(
									'Comma-separated list: post, page, product…',
									'muri-link-tracker'
								) }
								__nextHasNoMarginBottom
							/>
						</PanelRow>
						{ renderNumberField( 'batch_size' ) }
						<PanelRow>
							<ToggleControl
								label={ __(
									'Scan custom fields',
									'muri-link-tracker'
								) }
								help={ __(
									'Scan public custom fields (read only).',
									'muri-link-tracker'
								) }
								checked={ !! form.scan_custom_fields }
								onChange={ ( value ) =>
									updateField( 'scan_custom_fields', value )
								}
								__nextHasNoMarginBottom
							/>
						</PanelRow>
					</PanelBody>
					<PanelBody
						title={ __( 'HTTP Checks', 'muri-link-tracker' ) }
						initialOpen
					>
						{ [
							'check_timeout',
							'http_request_delay',
							'recheck_interval',
						].map( renderNumberField ) }
					</PanelBody>
					<PanelBody
						title={ __( 'Exclusions', 'muri-link-tracker' ) }
						initialOpen={ false }
					>
						<PanelRow>
							<ToggleControl
								label={ __(
									'Exclude media files',
									'muri-link-tracker'
								) }
								checked={ !! form.exclude_media }
								onChange={ ( value ) =>
									updateField( 'exclude_media', value )
								}
								help={ __(
									'Skip images, videos, and document files (PDF, etc.) during scanning.',
									'muri-link-tracker'
								) }
								__nextHasNoMarginBottom
							/>
						</PanelRow>
						<PanelRow>
							<TextareaControl
								label={ __(
									'Excluded URLs',
									'muri-link-tracker'
								) }
								value={ form.excluded_urls }
								onChange={ ( value ) =>
									updateField( 'excluded_urls', value )
								}
								help={ __(
									'One exact URL or wildcard pattern (*) per line.',
									'muri-link-tracker'
								) }
								rows={ 4 }
								__nextHasNoMarginBottom
							/>
						</PanelRow>
					</PanelBody>
				</Panel>
			</fieldset>
			<div className="mltr-settings__actions">
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ loading || saving }
					disabled={ loading || saving }
				>
					{ __( 'Save Settings', 'muri-link-tracker' ) }
				</Button>
				{ dirty && (
					<>
						<p role="status">
							{ __(
								'You have unsaved changes.',
								'muri-link-tracker'
							) }
						</p>
						<Button
							variant="tertiary"
							disabled={ loading || saving }
							onClick={ () => {
								if (
									// eslint-disable-next-line no-alert -- Confirm before discarding the user's draft.
									window.confirm(
										__(
											'Discard your unsaved settings?',
											'muri-link-tracker'
										)
									)
								) {
									setForm( savedForm );
									setValidation( {} );
								}
							} }
						>
							{ __( 'Discard changes', 'muri-link-tracker' ) }
						</Button>
					</>
				) }
			</div>
		</div>
	);
};

export default SettingsPanel;
