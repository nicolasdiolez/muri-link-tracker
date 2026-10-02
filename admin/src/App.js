/**
 * App component — Root of the Muri Link Tracker admin interface.
 *
 * @package
 * @since   1.0.0
 */

import { useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button, Modal, SnackbarList } from '@wordpress/components';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import Dashboard from './components/Dashboard';
import LinkTable from './components/LinkTable';
import LinkEditModal from './components/LinkEditModal';
import SettingsPanel from './components/SettingsPanel';
import DataSync from './components/DataSync';
import { viewForFilter } from './utils/link-view';
import ErrorBoundary from './components/ErrorBoundary';
import { STORE_NAME } from './store';

const TABS = [
	{ name: 'dashboard', title: __( 'Dashboard', 'muri-link-tracker' ) },
	{ name: 'links', title: __( 'Links', 'muri-link-tracker' ) },
	{ name: 'settings', title: __( 'Settings', 'muri-link-tracker' ) },
];

const App = () => {
	const [ editLinkId, setEditLinkId ] = useState( null );
	const { fetchLinks, fetchStats, setLinksView } = useDispatch( STORE_NAME );
	const view = useSelect(
		( select ) => select( STORE_NAME ).getLinksView(),
		[]
	);
	const [ activeTab, setActiveTab ] = useState( 'dashboard' );
	const [ settingsDirty, setSettingsDirty ] = useState( false );
	const [ pendingTab, setPendingTab ] = useState( null );
	const navigate = ( name ) => {
		if ( name === activeTab ) {
			return;
		}
		if ( settingsDirty ) {
			setPendingTab( name );
			return;
		}
		setActiveTab( name );
	};
	const filterLinks = ( params ) => {
		setLinksView( viewForFilter( view, params ) );
		navigate( 'links' );
	};

	const notices = useSelect(
		( select ) => select( noticesStore ).getNotices(),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );
	const snackbarNotices = notices.filter( ( n ) => n.type === 'snackbar' );

	const handleEditLink = useCallback( ( id ) => {
		setEditLinkId( id );
	}, [] );

	const handleCloseModal = useCallback(
		( shouldRefresh ) => {
			setEditLinkId( null );
			if ( shouldRefresh ) {
				fetchLinks();
				fetchStats();
			}
		},
		[ fetchLinks, fetchStats ]
	);

	return (
		<ErrorBoundary>
			<div className="mltr-app">
				<h1>{ __( 'Muri Link Tracker', 'muri-link-tracker' ) }</h1>

				<DataSync />
				<nav
					className="mltr-tabs"
					aria-label={ __(
						'Link tracker sections',
						'muri-link-tracker'
					) }
				>
					{ TABS.map( ( tab ) => (
						<Button
							key={ tab.name }
							variant={
								activeTab === tab.name ? 'primary' : 'secondary'
							}
							aria-current={
								activeTab === tab.name ? 'page' : undefined
							}
							onClick={ () => navigate( tab.name ) }
						>
							{ tab.title }
						</Button>
					) ) }
				</nav>
				<section
					aria-label={
						TABS.find( ( tab ) => tab.name === activeTab ).title
					}
				>
					{ activeTab === 'dashboard' && (
						<Dashboard onFilterLinks={ filterLinks } />
					) }
					{ activeTab === 'links' && (
						<LinkTable onEditLink={ handleEditLink } />
					) }
					{ activeTab === 'settings' && (
						<SettingsPanel onDirtyChange={ setSettingsDirty } />
					) }
				</section>

				{ pendingTab && (
					<Modal
						title={ __(
							'Discard unsaved settings?',
							'muri-link-tracker'
						) }
						onRequestClose={ () => setPendingTab( null ) }
					>
						<p>
							{ __(
								'Your changes have not been saved.',
								'muri-link-tracker'
							) }
						</p>
						<Button
							variant="secondary"
							onClick={ () => setPendingTab( null ) }
						>
							{ __( 'Keep editing', 'muri-link-tracker' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => {
								setSettingsDirty( false );
								setActiveTab( pendingTab );
								setPendingTab( null );
							} }
						>
							{ __( 'Discard changes', 'muri-link-tracker' ) }
						</Button>
					</Modal>
				) }

				{ editLinkId && (
					<LinkEditModal
						linkId={ editLinkId }
						onClose={ handleCloseModal }
					/>
				) }

				<SnackbarList
					notices={ snackbarNotices }
					onRemove={ removeNotice }
					className="mltr-snackbar-list"
				/>
			</div>
		</ErrorBoundary>
	);
};

export default App;
