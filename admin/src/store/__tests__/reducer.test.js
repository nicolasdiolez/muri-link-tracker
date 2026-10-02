import { DEFAULT_VIEW } from '../../utils/link-view';
import reducer from '../reducer';

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

describe( 'store/reducer', () => {
	it( 'returns default state', () => {
		expect( reducer( undefined, { type: 'UNKNOWN' } ) ).toEqual(
			DEFAULT_STATE
		);
	} );

	it( 'handles SET_LINKS', () => {
		const items = [ { id: 1, url: 'https://example.com' } ];
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_LINKS',
			items,
			total: 42,
			totalPages: 2,
		} );

		expect( state.links ).toBe( items );
		expect( state.total ).toBe( 42 );
		expect( state.totalPages ).toBe( 2 );
		// Other fields unchanged.
		expect( state.scanStatus ).toBeNull();
		expect( state.settings ).toBeNull();
	} );

	it( 'handles SET_SCAN_STATUS', () => {
		const status = { status: 'running', scanned_posts: 10 };
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SCAN_STATUS',
			status,
		} );

		expect( state.scanStatus ).toBe( status );
		expect( state.links ).toEqual( [] );
	} );

	it( 'handles SET_SETTINGS', () => {
		const settings = { check_timeout: 15, batch_size: 50 };
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_SETTINGS',
			settings,
		} );

		expect( state.settings ).toBe( settings );
	} );

	it( 'handles SET_CURRENT_LINK', () => {
		const link = { id: 5, url: 'https://test.com' };
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_CURRENT_LINK',
			link,
		} );

		expect( state.currentLink ).toBe( link );
	} );

	it( 'handles SET_LOADING', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_LOADING',
			key: 'links',
			value: true,
		} );

		expect( state.isLoading ).toEqual( { links: true } );
	} );

	it( 'preserves other loading keys on SET_LOADING', () => {
		const prev = {
			...DEFAULT_STATE,
			isLoading: { links: true },
		};
		const state = reducer( prev, {
			type: 'SET_LOADING',
			key: 'scan',
			value: true,
		} );

		expect( state.isLoading ).toEqual( { links: true, scan: true } );
	} );

	it( 'does not mutate previous state', () => {
		const prev = { ...DEFAULT_STATE };
		const state = reducer( prev, {
			type: 'SET_LINKS',
			items: [ { id: 1 } ],
			total: 1,
			totalPages: 1,
		} );

		expect( state ).not.toBe( prev );
		expect( prev.links ).toEqual( [] );
	} );

	it( 'ignores unknown action types', () => {
		const prev = { ...DEFAULT_STATE, total: 99 };
		const state = reducer( prev, { type: 'DOES_NOT_EXIST' } );

		expect( state ).toBe( prev );
	} );
} );
