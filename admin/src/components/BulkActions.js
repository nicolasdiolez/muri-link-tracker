/**
 * BulkActions — DataViews action definitions for link rows.
 *
 * @package
 * @since   1.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Returns the actions array for DataViews.
 *
 * @param {Object}   handlers           Callback handlers.
 * @param {Function} handlers.onRecheck Recheck handler.
 * @param {Function} handlers.onDelete  Delete handler.
 * @param {Function} handlers.onEdit    Edit handler (single item only).
 * @return {Array} DataViews actions configuration.
 */
export function getLinkActions( { onRecheck, onDelete, onEdit } ) {
	return [
		{
			id: 'edit',
			label: __( 'Details & edit', 'muri-link-tracker' ),
			isPrimary: true,
			supportsBulk: false,
			callback: ( items ) => {
				if ( items.length === 1 ) {
					onEdit( items[ 0 ].id );
				}
			},
		},
		{
			id: 'recheck',
			label: __( 'Recheck', 'muri-link-tracker' ),
			supportsBulk: true,
			callback: ( items ) => onRecheck( items.map( ( i ) => i.id ) ),
		},
		{
			id: 'delete',
			label: __( 'Remove from content', 'muri-link-tracker' ),
			isDestructive: true,
			supportsBulk: true,
			callback: ( items ) => onDelete( items.map( ( i ) => i.id ) ),
		},
	];
}
