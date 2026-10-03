import { fetchScanStatus, fetchStats, fetchSettings } from './actions';
export function getScanStatus() {
	return ( { dispatch } ) => dispatch( fetchScanStatus() );
}
export function getStats() {
	return ( { dispatch } ) => dispatch( fetchStats() );
}
export function getSettings() {
	return ( { dispatch } ) => dispatch( fetchSettings() );
}
