import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Notice, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/** Keep background status updates alive when the user changes tabs. */
export default function DataSync() {
	const { active, completed, error } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			active:
				! store.isLoading( 'scan' ) &&
				( store.getScanStatus()?.status === 'running' ||
					store.getRechecks().length > 0 ),
			completed: store.getScanStatus()?.status === 'complete',
			error: store.getError( 'scan' ),
		};
	}, [] );
	const { fetchScanStatus, pollRechecks, refreshData } =
		useDispatch( STORE_NAME );
	useEffect( () => {
		if ( completed ) {
			refreshData();
		}
	}, [ completed, refreshData ] );
	useEffect( () => {
		if ( ! active ) {
			return;
		}
		let stopped = false;
		let timer;
		const tick = async () => {
			try {
				await fetchScanStatus();
				if ( stopped ) {
					return;
				}
				await pollRechecks();
			} catch {
				// Network errors are surfaced by the store; keep the retry timer alive.
			} finally {
				if ( ! stopped ) {
					timer = setTimeout( tick, 5000 );
				}
			}
		};
		timer = setTimeout( tick, 5000 );
		return () => {
			stopped = true;
			clearTimeout( timer );
		};
	}, [ active, fetchScanStatus, pollRechecks, refreshData ] );
	return error ? (
		<Notice status="error" isDismissible={ false }>
			{ error }{ ' ' }
			<Button variant="link" onClick={ fetchScanStatus }>
				{ __( 'Refresh scan status', 'muri-link-tracker' ) }
			</Button>
		</Notice>
	) : null;
}
