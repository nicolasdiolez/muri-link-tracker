<?php
/**
 * Admin page registration and React asset enqueue.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level admin menu page and enqueues the React app.
 *
 * @since 1.0.0
 */
class AdminPage {

	/**
	 * Registers WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_menu', $this->add_menu_page( ... ) );
		add_action( 'admin_enqueue_scripts', $this->enqueue_assets( ... ) );
	}

	/**
	 * Adds the plugin menu page.
	 *
	 * @since 1.0.0
	 */
	private function add_menu_page(): void {
		add_menu_page(
			__( 'Muri Link Tracker', 'muri-link-tracker' ),
			__( 'Muri Link Tracker', 'muri-link-tracker' ),
			'manage_options',
			'muri-link-tracker',
			$this->render_page( ... ),
			'dashicons-admin-links',
			82
		);
	}

	/**
	 * Renders the admin page container for the React app.
	 *
	 * @since 1.0.0
	 */
	private function render_page(): void {
		echo '<div class="wrap"><div id="mltr-root"></div></div>';
	}

	/**
	 * Conditionally enqueues React build assets on the plugin page only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	private function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_muri-link-tracker' !== $hook_suffix ) {
			return;
		}

		$asset_file = MLTR_PLUGIN_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'mltr-admin',
			MLTR_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'mltr-admin', 'muri-link-tracker', MLTR_PLUGIN_DIR . 'languages' );

		if ( file_exists( MLTR_PLUGIN_DIR . 'build/index.css' ) ) {
			wp_enqueue_style(
				'mltr-admin',
				MLTR_PLUGIN_URL . 'build/index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_add_inline_script(
			'mltr-admin',
			'window.mltrData = ' . wp_json_encode(
				array(
					'restUrl'  => rest_url( 'muri-link-tracker/v1/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'adminUrl' => admin_url(),
					'version'  => MLTR_VERSION,
				)
			) . ';',
			'before'
		);
	}
}
