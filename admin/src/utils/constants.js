/**
 * Shared constants for the Muri Link Tracker admin UI.
 *
 * @package
 * @since   1.0.0
 */

import { __ } from '@wordpress/i18n';

export const STORE_NAME = 'muri-link-tracker';
export const API_NAMESPACE = '/muri-link-tracker/v1';

export const STATUS_LABELS = {
	pending: __( 'Pending', 'muri-link-tracker' ),
	ok: __( 'OK', 'muri-link-tracker' ),
	redirect: __( 'Redirect', 'muri-link-tracker' ),
	broken: __( 'Broken', 'muri-link-tracker' ),
	error: __( 'Error', 'muri-link-tracker' ),
	timeout: __( 'Timeout', 'muri-link-tracker' ),
	skipped: __( 'Skipped', 'muri-link-tracker' ),
};

export const STATUS_COLORS = {
	pending: '#757575',
	ok: '#007a20',
	redirect: '#805900',
	broken: '#b32d2e',
	error: '#b32d2e',
	timeout: '#996800',
	skipped: '#757575',
};

export const LINK_TYPE_OPTIONS = [
	{ value: 'external', label: __( 'External', 'muri-link-tracker' ) },
	{ value: 'internal', label: __( 'Internal', 'muri-link-tracker' ) },
];

export const REL_OPTIONS = [
	{ value: 'nofollow', label: __( 'Nofollow', 'muri-link-tracker' ) },
	{ value: 'sponsored', label: __( 'Sponsored', 'muri-link-tracker' ) },
	{ value: 'ugc', label: __( 'UGC', 'muri-link-tracker' ) },
	{ value: 'dofollow', label: __( 'Dofollow', 'muri-link-tracker' ) },
];

export const AFFILIATE_TYPE_OPTIONS = [
	{ value: 'all', label: __( 'All Affiliates', 'muri-link-tracker' ) },
	{
		value: 'cloaked',
		label: __( 'Cloaked (Internal)', 'muri-link-tracker' ),
	},
	{
		value: 'direct',
		label: __( 'Direct (External)', 'muri-link-tracker' ),
	},
];
