import { DEFAULT_VIEW } from '../utils/link-view';
/**
 * Store reducer.
 *
 * @package
 * @since   1.0.0
 */

const DEFAULT_STATE = {
	links: [],
	total: 0,
	totalPages: 0,
	scanStatus: null,
	stats: null,
	settings: null,
	currentLink: null,
	isLoading: {},
	errors: {},
	linksView: DEFAULT_VIEW,
	rechecks: [],
};

export default function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_ERROR':
			return {
				...state,
				errors: { ...state.errors, [ action.key ]: action.error },
			};
		case 'SET_LINKS_VIEW':
			return { ...state, linksView: action.view };
		case 'SET_RECHECKS':
			return { ...state, rechecks: action.rechecks };
		case 'SET_LINKS':
			return {
				...state,
				links: action.items,
				total: action.total,
				totalPages: action.totalPages,
			};

		case 'SET_SCAN_STATUS':
			return { ...state, scanStatus: action.status };

		case 'SET_STATS':
			return { ...state, stats: action.stats };

		case 'SET_SETTINGS':
			return { ...state, settings: action.settings };

		case 'SET_CURRENT_LINK':
			return { ...state, currentLink: action.link };

		case 'SET_LOADING':
			return {
				...state,
				isLoading: { ...state.isLoading, [ action.key ]: action.value },
			};

		default:
			return state;
	}
}
