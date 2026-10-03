/**
 * ScanPanel — Scan controls and progress indicator.
 *
 * @package
 * @since   1.0.0
 */

import { useCallback, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Button,
	// This is the confirmation dialog export available in the supported WP components.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

const ScanPanel = () => {
	const scanStatus = useSelect(
		( select ) => select( STORE_NAME ).getScanStatus(),
		[]
	);
	const scanLoading = useSelect(
		( select ) => select( STORE_NAME ).isLoading( 'scan' ),
		[]
	);
	const { startScan, cancelScan, resumeScan, resetScan } =
		useDispatch( STORE_NAME );

	const isRunning = scanStatus?.status === 'running';

	const handleStart = useCallback(
		( type ) => () => startScan( type ),
		[ startScan ]
	);

	const [ isResetConfirmOpen, setResetConfirmOpen ] = useState( false );

	const handleReset = () => {
		setResetConfirmOpen( true );
	};

	const handleResetConfirm = () => {
		setResetConfirmOpen( false );
		resetScan();
	};

	const handleResetCancel = () => {
		setResetConfirmOpen( false );
	};

	const phase = scanStatus?.phase ?? 'scanning';

	let progress = 0;
	if ( isRunning ) {
		if ( phase === 'checking' ) {
			if ( scanStatus.total_links > 0 ) {
				progress = Math.round(
					( scanStatus.checked_links / scanStatus.total_links ) * 100
				);
			}
		} else if ( scanStatus.total_posts > 0 ) {
			progress = Math.round(
				( scanStatus.scanned_posts / scanStatus.total_posts ) * 100
			);
		}
	}

	return (
		<div className="mltr-scan-panel">
			<div className="mltr-scan-panel__controls">
				{ ! isRunning ? (
					<>
						<Button
							variant="primary"
							onClick={ handleStart( 'full' ) }
							isBusy={ scanLoading }
							disabled={ scanLoading }
						>
							{ __( 'Scan all content', 'muri-link-tracker' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ handleStart( 'delta' ) }
							isBusy={ scanLoading }
							disabled={ scanLoading }
							className="mltr-scan-panel__delta-btn"
						>
							{ __(
								'Scan changed content',
								'muri-link-tracker'
							) }
						</Button>
						{ scanStatus?.status === 'cancelled' && (
							<Button
								variant="primary"
								onClick={ resumeScan }
								isBusy={ scanLoading }
								disabled={ scanLoading }
							>
								{ __( 'Resume Scan', 'muri-link-tracker' ) }
							</Button>
						) }
						<Button
							variant="secondary"
							isDestructive
							onClick={ handleReset }
							isBusy={ scanLoading }
							disabled={ scanLoading }
						>
							{ __( 'Reset Data', 'muri-link-tracker' ) }
						</Button>
					</>
				) : (
					<Button
						variant="secondary"
						isDestructive
						disabled={ scanLoading }
						isBusy={ scanLoading }
						onClick={ cancelScan }
					>
						{ __( 'Cancel Scan', 'muri-link-tracker' ) }
					</Button>
				) }
			</div>

			{ isRunning && (
				<div
					className="mltr-scan-panel__progress"
					aria-label={ __(
						'Scan progress details',
						'muri-link-tracker'
					) }
				>
					<div
						className="mltr-progress-bar"
						role="progressbar"
						aria-valuenow={ progress }
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
						aria-label={ __(
							'Scan progress',
							'muri-link-tracker'
						) }
					>
						<div
							className="mltr-progress-bar__fill"
							style={ { width: `${ progress }%` } }
						/>
					</div>
					<span
						className="mltr-scan-panel__status"
						role="status"
						aria-live="polite"
					>
						{ phase === 'checking'
							? sprintf(
									/* translators: 1: checked links, 2: total links, 3: progress percentage. */
									__(
										'Checking links: %1$d / %2$d (%3$d%%)',
										'muri-link-tracker'
									),
									scanStatus.checked_links,
									scanStatus.total_links,
									progress
							  )
							: sprintf(
									/* translators: 1: scanned posts, 2: total posts, 3: progress percentage. */
									__(
										'Scanning posts: %1$d / %2$d (%3$d%%)',
										'muri-link-tracker'
									),
									scanStatus.scanned_posts,
									scanStatus.total_posts,
									progress
							  ) }
					</span>
				</div>
			) }

			{ scanStatus?.status === 'complete' && (
				<p className="mltr-scan-panel__complete" role="status">
					{ sprintf(
						/* translators: 1: total links, 2: ok count, 3: broken count, 4: redirects, 5: errors. */
						__(
							'Scan complete — %1$d links checked: %2$d OK, %3$d redirects, %4$d broken, %5$d errors.',
							'muri-link-tracker'
						),
						scanStatus.total_links || 0,
						scanStatus.ok_count || 0,
						scanStatus.redirect_count || 0,
						scanStatus.broken_count || 0,
						( scanStatus.error_count || 0 ) +
							( scanStatus.timeout_count || 0 )
					) }
					<a
						href="https://wordpress.org/support/plugin/muri-link-tracker/reviews/#new-post"
						target="_blank"
						rel="noopener noreferrer"
						className="mltr-scan-panel__review-link"
					>
						{ __(
							'Enjoying the plugin? Please leave a 5-star review!',
							'muri-link-tracker'
						) }
					</a>
				</p>
			) }

			{ ! isRunning && (
				<p className="mltr-scan-panel__help">
					{ __(
						'Delta Scan only checks posts modified since the last successful scan.',
						'muri-link-tracker'
					) }
				</p>
			) }
			{ isResetConfirmOpen && (
				<ConfirmDialog
					onConfirm={ handleResetConfirm }
					onCancel={ handleResetCancel }
				>
					{ __(
						'Are you sure you want to reset all scan data? This will clear all links found so far.',
						'muri-link-tracker'
					) }
				</ConfirmDialog>
			) }
		</div>
	);
};

export default ScanPanel;
