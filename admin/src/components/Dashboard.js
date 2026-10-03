/**
 * Dashboard — Summary cards and scan controls.
 *
 * @package
 * @since   1.0.0
 */

import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { STORE_NAME } from '../store';
import ScanPanel from './ScanPanel';

const Dashboard = ( { onFilterLinks } ) => {
	const scanStatus = useSelect(
		( select ) => select( STORE_NAME ).getScanStatus(),
		[]
	);

	const stats = useSelect(
		( select ) => select( STORE_NAME ).getStats(),
		[]
	);

	const error = useSelect(
		( select ) => select( STORE_NAME ).getError( 'stats' ),
		[]
	);
	const loading = useSelect(
		( select ) => select( STORE_NAME ).isLoading( 'stats' ),
		[]
	);
	const { fetchStats } = useDispatch( STORE_NAME );
	// Completed scan snapshots become stale after links are rechecked or removed.
	const running = scanStatus?.status === 'running';
	const counts = running ? scanStatus : stats?.byCategory;
	const renderCard = ( card ) => {
		const clickable = !! onFilterLinks && !! card.filters;
		const Tag = clickable ? 'button' : 'div';
		return (
			<Tag
				key={ card.key }
				className={ `mltr-summary-card ${ card.className }${
					clickable ? ' mltr-summary-card--action' : ''
				}` }
				aria-label={ `${ card.label }: ${ card.value }` }
				{ ...( clickable
					? {
							type: 'button',
							onClick: () => onFilterLinks( card.filters ),
					  }
					: {} ) }
			>
				<span className="mltr-summary-card__value">{ card.value }</span>
				<span className="mltr-summary-card__label">{ card.label }</span>
			</Tag>
		);
	};

	let checkedCount = '—';
	if ( running ) {
		checkedCount = counts?.checked_links ?? '—';
	} else if ( counts?.total !== undefined ) {
		checkedCount =
			Number( counts.total ) - Number( counts.pending_count ?? 0 );
	}

	const overviewCards = [
		{
			key: 'total',
			filters: {},
			label: __( 'Total Links', 'muri-link-tracker' ),
			value: ( running ? counts?.total_links : counts?.total ) ?? '—',
			className: '',
		},
		{
			key: 'ok',
			filters: { status: 'ok' },
			label: __( 'OK Links', 'muri-link-tracker' ),
			value: counts?.ok_count ?? '—',
			className: 'mltr-summary-card--ok',
		},
		{
			key: 'redirects',
			filters: { status: 'redirect' },
			label: __( 'Redirects', 'muri-link-tracker' ),
			value: counts?.redirect_count ?? '—',
			className: 'mltr-summary-card--redirect',
		},
		{
			key: 'broken',
			filters: { status: 'broken' },
			label: __( 'Broken', 'muri-link-tracker' ),
			value: counts?.broken_count ?? '—',
			className: 'mltr-summary-card--broken',
		},
		{
			key: 'errors',
			label: __( 'Errors', 'muri-link-tracker' ),
			value: counts
				? Number( counts.error_count ?? 0 ) +
				  Number( counts.timeout_count ?? 0 )
				: '—',
			className: 'mltr-summary-card--broken',
		},
		{
			key: 'checked',
			label: __( 'Checked', 'muri-link-tracker' ),
			value: checkedCount,
			className: '',
		},
	];

	const byCategory = stats?.byCategory;
	const typeCards = byCategory
		? [
				{
					key: 'internal',
					filters: { linkType: 'internal' },
					label: __( 'Internal', 'muri-link-tracker' ),
					value: byCategory.internal_count ?? 0,
					className: 'mltr-summary-card--internal',
				},
				{
					key: 'external',
					filters: { linkType: 'external' },
					label: __( 'External', 'muri-link-tracker' ),
					value: byCategory.external_count ?? 0,
					className: 'mltr-summary-card--external',
				},
				{
					key: 'affiliate',
					filters: { isAffiliate: true },
					label: __( 'Affiliate', 'muri-link-tracker' ),
					value: byCategory.affiliate_count ?? 0,
					className: 'mltr-summary-card--affiliate',
				},
				{
					key: 'cloaked',
					filters: { linkType: 'internal', isAffiliate: true },
					label: __( 'Cloaked', 'muri-link-tracker' ),
					value: byCategory.cloaked_count ?? 0,
					className: 'mltr-summary-card--cloaked',
				},
		  ]
		: null;

	const byNetwork = stats?.byNetwork;
	const networkCards = byNetwork
		? Object.entries( byNetwork ).map( ( [ network, count ] ) => ( {
				key: network,
				filters:
					network.trim() && network !== 'unknown'
						? { affiliateNetwork: network }
						: undefined,
				label:
					network.trim() && network !== 'unknown'
						? network.charAt( 0 ).toUpperCase() + network.slice( 1 )
						: __( 'Unknown network', 'muri-link-tracker' ),
				value: count,
				className: 'mltr-summary-card--affiliate',
		  } ) )
		: null;

	const redirectCards = byCategory
		? [
				{
					key: 'single-redirect',
					label: __( 'Single (1 hop)', 'muri-link-tracker' ),
					value: byCategory.single_redirect_count ?? 0,
					className: 'mltr-summary-card--redirect',
				},
				{
					key: 'chain-redirect',
					label: __( 'Chains (2+)', 'muri-link-tracker' ),
					value: byCategory.chain_redirect_count ?? 0,
					className: 'mltr-summary-card--redirect',
				},
				{
					key: 'loop-redirect',
					label: __( 'Loops', 'muri-link-tracker' ),
					value: byCategory.loop_count ?? 0,
					className: 'mltr-summary-card--broken',
				},
		  ]
		: null;

	return (
		<div className="mltr-dashboard">
			<ScanPanel />
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error.message || error }</p>
					{ stats && (
						<p>
							{ __(
								'The figures below may be out of date.',
								'muri-link-tracker'
							) }
						</p>
					) }
					<Button
						variant="secondary"
						onClick={ () => fetchStats() }
						disabled={ loading }
					>
						{ __( 'Retry statistics', 'muri-link-tracker' ) }
					</Button>
				</Notice>
			) }

			<div className="mltr-summary-cards">
				{ overviewCards.map( renderCard ) }
			</div>

			{ typeCards && (
				<div className="mltr-dashboard__section">
					<h3>{ __( 'Link Types', 'muri-link-tracker' ) }</h3>
					<div className="mltr-summary-cards">
						{ typeCards.map( renderCard ) }
					</div>
				</div>
			) }

			{ networkCards && networkCards.length > 0 && (
				<div className="mltr-dashboard__section">
					<h3>{ __( 'Affiliate Networks', 'muri-link-tracker' ) }</h3>
					<div className="mltr-summary-cards">
						{ networkCards.map( renderCard ) }
					</div>
				</div>
			) }

			{ redirectCards && (
				<div className="mltr-dashboard__section">
					<h3>{ __( 'Redirections', 'muri-link-tracker' ) }</h3>
					<div className="mltr-summary-cards">
						{ redirectCards.map( renderCard ) }
					</div>
				</div>
			) }

			<div className="mltr-dashboard__section mltr-dashboard__support">
				<div className="mltr-support-card">
					<div className="mltr-support-card__content">
						<h3>
							{ __( 'Support & Feedback', 'muri-link-tracker' ) }
						</h3>
						<p>
							{ __(
								'Are we missing a feature? Found a bug? Or just want to say thanks?',
								'muri-link-tracker'
							) }
						</p>
					</div>
					<div className="mltr-support-card__actions">
						<a
							href="https://wordpress.org/support/plugin/muri-link-tracker/"
							target="_blank"
							rel="noopener noreferrer"
							className="button"
						>
							{ __( 'Get Support', 'muri-link-tracker' ) }
						</a>
						<a
							href="https://wordpress.org/support/plugin/muri-link-tracker/reviews/#new-post"
							target="_blank"
							rel="noopener noreferrer"
							className="button button-primary"
						>
							{ __( 'Leave a Review', 'muri-link-tracker' ) }
						</a>
					</div>
				</div>
			</div>
		</div>
	);
};

export default Dashboard;
