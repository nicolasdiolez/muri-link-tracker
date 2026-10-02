/**
 * LinkTable — DataViews-powered links table with server-side pagination.
 *
 * @package
 * @since   1.0.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { DataViews } from '@wordpress/dataviews';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { DEFAULT_VIEW, viewToApiParams } from '../utils/link-view';
import { buildExportUrl } from '../utils/export';
import DeleteLinksDialog from './DeleteLinksDialog';
import { STORE_NAME } from '../store';
import { STATUS_LABELS, STATUS_COLORS } from '../utils/constants';
import { getLinkActions } from './BulkActions';
import FilterBar from './FilterBar';

const LinkTable = ( { onEditLink } ) => {
	const [ deleteIds, setDeleteIds ] = useState( null );
	const [ exportError, setExportError ] = useState( null );

	const {
		links,
		total,
		totalPages,
		loading,
		settings,
		view,
		error,
		mutationError,
		recheckError,
		rechecking,
	} = useSelect(
		( select ) => ( {
			links: select( STORE_NAME ).getLinks(),
			total: select( STORE_NAME ).getTotal(),
			totalPages: select( STORE_NAME ).getTotalPages(),
			loading: select( STORE_NAME ).isLoading( 'links' ),
			settings: select( STORE_NAME ).getSettings(),
			view: select( STORE_NAME ).getLinksView(),
			error: select( STORE_NAME ).getError( 'links' ),
			mutationError: select( STORE_NAME ).getError( 'mutation' ),
			recheckError: select( STORE_NAME ).getError( 'rechecks' ),
			rechecking: select( STORE_NAME ).getRechecks().length,
		} ),
		[]
	);

	const {
		fetchLinks,
		deleteLink,
		recheckLink,
		bulkAction,
		updateSettings,
		setLinksView: setView,
		fetchStats,
	} = useDispatch( STORE_NAME );

	const quickStatus =
		view.filters.find( ( filter ) => filter.field === 'statusCategory' )
			?.value || '';
	const queryKey = JSON.stringify( viewToApiParams( view ) );
	useEffect( () => {
		fetchLinks( JSON.parse( queryKey ) );
	}, [ queryKey, fetchLinks ] );

	const appliedDensity = useRef( null );
	// Initialize the saved density without coupling layout changes to REST queries.
	useEffect( () => {
		if (
			settings?.density &&
			settings.density !== appliedDensity.current
		) {
			appliedDensity.current = settings.density;
			setView( {
				...view,
				layout: { ...view.layout, density: settings.density },
			} );
		}
	}, [ settings?.density, view, setView ] );

	// Persist changes only when DataViews triggers it.
	const handleViewChange = useCallback(
		( newView ) => {
			const oldDensity = view.layout?.density;
			const newDensity = newView.layout?.density;

			setView( newView );

			if ( newDensity && newDensity !== oldDensity ) {
				updateSettings( { density: newDensity } );
			}
		},
		[ view.layout?.density, updateSettings, setView ]
	);

	const handleStatusChange = useCallback(
		( status ) => {
			const filters = view.filters.filter(
				( filter ) => filter.field !== 'statusCategory'
			);
			if ( status ) {
				filters.push( {
					field: 'statusCategory',
					operator: 'is',
					value: status,
				} );
			}
			setView( { ...view, filters, page: 1 } );
		},
		[ view, setView ]
	);

	const handleRecheck = useCallback(
		async ( ids ) => {
			if ( ids.length === 1 ) {
				await recheckLink( ids[ 0 ] );
			} else {
				await bulkAction( 'recheck', ids );
			}
			await fetchLinks();
		},
		[ recheckLink, bulkAction, fetchLinks ]
	);

	const handleDelete = useCallback(
		async ( ids ) => {
			let result;
			if ( ids.length === 1 ) {
				const success = await deleteLink( ids[ 0 ] );
				result = { success: success ? 1 : 0, failed: success ? 0 : 1 };
			} else {
				result = await bulkAction( 'delete', ids );
			}
			await fetchLinks();
			fetchStats();
			return result;
		},
		[ deleteLink, bulkAction, fetchLinks, fetchStats ]
	);

	const handleExport = useCallback( () => {
		try {
			setExportError( null );
			window.open(
				buildExportUrl( window.mltrData, viewToApiParams( view ) ),
				'_blank',
				'noopener,noreferrer'
			);
		} catch ( failure ) {
			setExportError( failure.message );
		}
	}, [ view ] );

	const fields = [
		{
			id: 'url',
			label: __( 'URL', 'muri-link-tracker' ),
			enableSorting: true,
			enableGlobalSearch: true,
			render: ( { item } ) => (
				<a
					href={
						/^https?:\/\//i.test( item.url ) ? item.url : undefined
					}
					target="_blank"
					rel="noopener noreferrer"
					className="mltr-link-url"
					title={ item.url }
				>
					{ item.url.length > 60
						? item.url.substring( 0, 60 ) + '…'
						: item.url }
				</a>
			),
		},
		{
			id: 'httpStatus',
			label: __( 'HTTP', 'muri-link-tracker' ),
			enableSorting: true,
			render: ( { item } ) => (
				<span
					className={ `mltr-http-status mltr-http-status--${ item.statusCategory }` }
				>
					{ item.httpStatus || '—' }
				</span>
			),
		},
		{
			id: 'statusCategory',
			label: __( 'Status', 'muri-link-tracker' ),
			enableSorting: false,
			elements: Object.entries( STATUS_LABELS ).map(
				( [ value, label ] ) => ( { value, label } )
			),
			filterBy: { operators: [ 'is' ] },
			render: ( { item } ) => (
				<span
					className={ `mltr-badge mltr-badge--${ item.statusCategory }` }
					style={ { color: STATUS_COLORS[ item.statusCategory ] } }
				>
					{ STATUS_LABELS[ item.statusCategory ] ||
						item.statusCategory }
				</span>
			),
		},
		{
			id: 'isExternal',
			label: __( 'Type', 'muri-link-tracker' ),
			elements: [
				{
					value: 'true',
					label: __( 'External', 'muri-link-tracker' ),
				},
				{
					value: 'false',
					label: __( 'Internal', 'muri-link-tracker' ),
				},
			],
			filterBy: { operators: [ 'is' ] },
			render: ( { item } ) => (
				<span className="mltr-link-type">
					{ item.isExternal
						? __( 'External', 'muri-link-tracker' )
						: __( 'Internal', 'muri-link-tracker' ) }
				</span>
			),
		},
		{
			id: 'isAffiliate',
			label: __( 'Affiliate', 'muri-link-tracker' ),
			elements: [
				{ value: 'true', label: __( 'Yes', 'muri-link-tracker' ) },
				{ value: 'false', label: __( 'No', 'muri-link-tracker' ) },
			],
			filterBy: { operators: [ 'is' ] },
			render: ( { item } ) =>
				item.isAffiliate
					? __( 'Yes', 'muri-link-tracker' )
					: __( 'No', 'muri-link-tracker' ),
		},
		{
			id: 'affiliateNetwork',
			label: __( 'Affiliate network', 'muri-link-tracker' ),
			type: 'text',
			filterBy: { operators: [ 'is' ] },
			render: ( { item } ) => item.affiliateNetwork || '—',
		},
		{
			id: 'lastChecked',
			label: __( 'Last Checked', 'muri-link-tracker' ),
			enableSorting: true,
			render: ( { item } ) =>
				item.lastChecked
					? new Date( item.lastChecked ).toLocaleDateString()
					: '—',
		},
	];

	const actions = getLinkActions( {
		onRecheck: handleRecheck,
		onDelete: setDeleteIds,
		onEdit: onEditLink,
	} );

	return (
		<div
			className={ `mltr-link-table is-density-${
				view.layout?.density || 'balanced'
			}` }
		>
			{ [ error, mutationError, recheckError, exportError ]
				.filter( Boolean )
				.map( ( text, index ) => (
					<Notice
						key={ index }
						status="error"
						isDismissible={ false }
					>
						{ text }
						{ index === 0 && error && (
							<Button
								variant="link"
								onClick={ () => fetchLinks() }
							>
								{ __( 'Retry', 'muri-link-tracker' ) }
							</Button>
						) }
					</Notice>
				) ) }
			{ rechecking > 0 && (
				<p role="status">
					{ __(
						'Rechecks are queued. Results refresh automatically.',
						'muri-link-tracker'
					) }
				</p>
			) }
			<div className="mltr-link-table__toolbar">
				<FilterBar
					currentStatus={ quickStatus }
					onStatusChange={ handleStatusChange }
				/>
				<Button
					variant="tertiary"
					onClick={ () =>
						setView( { ...DEFAULT_VIEW, layout: view.layout } )
					}
				>
					{ __( 'Clear filters', 'muri-link-tracker' ) }
				</Button>
				<Button variant="secondary" onClick={ () => fetchLinks() }>
					{ __( 'Refresh', 'muri-link-tracker' ) }
				</Button>
				<Button variant="secondary" onClick={ handleExport }>
					{ __( 'Export CSV', 'muri-link-tracker' ) }
				</Button>
			</div>

			<DataViews
				data={ links }
				fields={ fields }
				view={ view }
				onChangeView={ handleViewChange }
				paginationInfo={ { totalItems: total, totalPages } }
				actions={ actions }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ loading }
				defaultLayouts={ { table: {} } }
			/>
			{ deleteIds && (
				<DeleteLinksDialog
					ids={ deleteIds }
					onConfirm={ handleDelete }
					onClose={ () => setDeleteIds( null ) }
				/>
			) }
		</div>
	);
};

export default LinkTable;
