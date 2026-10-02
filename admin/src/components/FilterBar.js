/**
 * FilterBar — Quick-access filter buttons above the links table.
 *
 * @package
 * @since   1.0.0
 */

import { Button, ButtonGroup } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const QUICK_FILTERS = [
	{ key: '', label: __( 'All', 'muri-link-tracker' ) },
	{ key: 'broken', label: __( 'Broken', 'muri-link-tracker' ) },
	{ key: 'redirect', label: __( 'Redirects', 'muri-link-tracker' ) },
	{ key: 'ok', label: __( 'OK', 'muri-link-tracker' ) },
	{ key: 'pending', label: __( 'Pending', 'muri-link-tracker' ) },
];

const FilterBar = ( { currentStatus, onStatusChange } ) => {
	return (
		<div className="mltr-filter-bar">
			<ButtonGroup
				aria-label={ __(
					'Filter links by status',
					'muri-link-tracker'
				) }
			>
				{ QUICK_FILTERS.map( ( filter ) => (
					<Button
						key={ filter.key }
						aria-pressed={ currentStatus === filter.key }
						variant={
							currentStatus === filter.key
								? 'primary'
								: 'secondary'
						}
						onClick={ () => onStatusChange( filter.key ) }
					>
						{ filter.label }
					</Button>
				) ) }
			</ButtonGroup>
		</div>
	);
};

export default FilterBar;
