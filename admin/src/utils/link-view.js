export const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	sort: { field: 'createdAt', direction: 'desc' },
	page: 1,
	perPage: 25,
	layout: { density: 'balanced' },
	fields: [
		'url',
		'httpStatus',
		'statusCategory',
		'isExternal',
		'lastChecked',
	],
};

const ORDERBY_MAP = {
	url: 'url',
	httpStatus: 'http_status',
	lastChecked: 'last_checked',
	createdAt: 'created_at',
};
export function viewToApiParams( view ) {
	const params = { page: view.page, perPage: view.perPage };
	if ( view.search ) {
		params.search = view.search;
	}
	if ( view.sort ) {
		params.orderby = ORDERBY_MAP[ view.sort.field ] || 'created_at';
		params.order = view.sort.direction;
	}
	for ( const filter of view.filters || [] ) {
		if ( filter.field === 'statusCategory' && filter.value ) {
			params.status = filter.value;
		}
		if ( filter.field === 'isExternal' && filter.value !== undefined ) {
			params.linkType = filter.value === 'true' ? 'external' : 'internal';
		}
		if ( filter.field === 'isAffiliate' && filter.value !== undefined ) {
			params.isAffiliate = filter.value === 'true';
		}
		if ( filter.field === 'affiliateNetwork' && filter.value ) {
			params.affiliateNetwork = filter.value;
		}
	}
	return params;
}

export function viewForFilter( view, params ) {
	const filters = [];
	if ( params.status ) {
		filters.push( {
			field: 'statusCategory',
			operator: 'is',
			value: params.status,
		} );
	}
	if ( params.linkType ) {
		filters.push( {
			field: 'isExternal',
			operator: 'is',
			value: String( params.linkType === 'external' ),
		} );
	}
	if ( params.isAffiliate !== undefined ) {
		filters.push( {
			field: 'isAffiliate',
			operator: 'is',
			value: String( params.isAffiliate ),
		} );
	}
	if ( params.affiliateNetwork ) {
		filters.push( {
			field: 'affiliateNetwork',
			operator: 'is',
			value: params.affiliateNetwork,
		} );
	}
	return { ...view, page: 1, search: '', filters };
}
