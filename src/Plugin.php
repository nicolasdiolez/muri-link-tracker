<?php
/**
 * Main plugin bootstrap class.
 *
 * @package MuriLinkTracker
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace MuriLinkTracker;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Admin\AdminPage;
use MuriLinkTracker\Admin\ReviewNotice;
use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Database\Migrator;
use MuriLinkTracker\Database\QueryBuilder;
use MuriLinkTracker\Queue\BatchOrchestrator;
use MuriLinkTracker\Queue\CheckJob;
use MuriLinkTracker\Queue\ScanJob;
use MuriLinkTracker\Queue\SchedulerBootstrap;
use MuriLinkTracker\Queue\ScanStore;
use MuriLinkTracker\REST\CsvExporter;
use MuriLinkTracker\REST\LinksController;
use MuriLinkTracker\REST\ScanController;
use MuriLinkTracker\REST\SettingsController;
use MuriLinkTracker\Scanner\BlockParser;
use MuriLinkTracker\Scanner\ContentParser;
use MuriLinkTracker\Scanner\HttpChecker;
use MuriLinkTracker\Scanner\InternalLinkChecker;
use MuriLinkTracker\Scanner\LinkClassifier;
use MuriLinkTracker\Scanner\LinkExtractor;
use MuriLinkTracker\Scanner\LinkHtmlEditor;

/**
 * Singleton that boots the plugin and registers all hooks.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Lazily-instantiated links repository (shared between REST and Queue layers).
	 *
	 * @since 1.0.0
	 * @var LinksRepository|null
	 */
	private ?LinksRepository $links_repo = null;

	/**
	 * Lazily-instantiated instances repository (shared between REST and Queue layers).
	 *
	 * @since 1.0.0
	 * @var InstancesRepository|null
	 */
	private ?InstancesRepository $instances_repo = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Registers all plugin hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		( new Migrator() )->maybe_migrate();
		// Queue system must register on ALL requests (not just admin)
		// because Action Scheduler processes actions via WP-Cron/frontend.
		$this->register_queue();

		// REST API must register on ALL requests for wp-json availability.
		$this->register_rest_api();

		if ( \is_admin() ) {
			$admin_page = new AdminPage();
			$admin_page->register();

			$review_notice = new ReviewNotice();
			$review_notice->register();
		}
	}

	/**
	 * Registers REST API controllers.
	 *
	 * @since 1.0.0
	 */
	private function register_rest_api(): void {
		global $wpdb;

		$links_repo     = $this->get_links_repo();
		$instances_repo = $this->get_instances_repo();
		$query_builder  = new QueryBuilder( $wpdb );
		$orchestrator   = new BatchOrchestrator( $links_repo, $instances_repo );
		$html_editor    = new LinkHtmlEditor( $wpdb );
		$csv_exporter   = new CsvExporter();

		$links_ctrl    = new LinksController( $query_builder, $links_repo, $instances_repo, $html_editor, $csv_exporter );
		$scan_ctrl     = new ScanController( $orchestrator );
		$settings_ctrl = new SettingsController();

		\add_action( 'rest_api_init', $links_ctrl->register_routes( ... ) );
		\add_action( 'rest_api_init', $scan_ctrl->register_routes( ... ) );
		\add_action( 'rest_api_init', $settings_ctrl->register_routes( ... ) );
	}

	/**
	 * Returns the shared LinksRepository instance (lazy singleton).
	 *
	 * One instance is shared by both the REST layer and the Queue layer.
	 *
	 * @since 1.0.0
	 *
	 * @return LinksRepository
	 */
	private function get_links_repo(): LinksRepository {
		if ( null === $this->links_repo ) {
			global $wpdb;
			$this->links_repo = new LinksRepository( $wpdb );
		}
		return $this->links_repo;
	}

	/**
	 * Returns the shared InstancesRepository instance (lazy singleton).
	 *
	 * @since 1.0.0
	 *
	 * @return InstancesRepository
	 */
	private function get_instances_repo(): InstancesRepository {
		if ( null === $this->instances_repo ) {
			global $wpdb;
			$this->instances_repo = new InstancesRepository( $wpdb );
		}
		return $this->instances_repo;
	}

	/**
	 * Registers the background processing queue system.
	 *
	 * Wires up the scanner, checker, repositories, and Action Scheduler jobs.
	 *
	 * @since 1.0.0
	 */
	private function register_queue(): void {
		if ( ! SchedulerBootstrap::is_available() ) {
			return;
		}

		$links_repo     = $this->get_links_repo();
		$instances_repo = $this->get_instances_repo();

		// Scanner pipeline.
		$content_parser = new ContentParser();
		$block_parser   = new BlockParser( $content_parser );
		$classifier     = new LinkClassifier();
		$extractor      = new LinkExtractor( $content_parser, $block_parser, $classifier );

		// HTTP checker.
		$settings          = \get_option( 'mltr_settings', array() );
		$timeout           = (int) ( $settings['check_timeout'] ?? 15 );
		$http_checker      = new HttpChecker( $timeout );
		$internal_checker  = new InternalLinkChecker( http_checker: new HttpChecker( 5 ) );
		global $wpdb;
		$store = new ScanStore( $wpdb );

		// Jobs.
		$scan_job  = new ScanJob( $extractor, $links_repo, $instances_repo, $store );
		$check_job = new CheckJob( $http_checker, $internal_checker, $links_repo, $store );

		// Hook jobs to Action Scheduler actions.
		\add_action( SchedulerBootstrap::SCAN_BATCH_HOOK, array( $scan_job, 'process_batch' ) );
		\add_action( SchedulerBootstrap::CHECK_BATCH_HOOK, array( $check_job, 'process_batch' ) );

		// Orchestrator for daily recheck and batch tracking.
		$orchestrator = new BatchOrchestrator( $links_repo, $instances_repo, $store );
		\add_action( SchedulerBootstrap::RECHECK_DAILY_HOOK, array( $orchestrator, 'recheck_stale_links' ) );
		\add_action( SchedulerBootstrap::COORDINATOR_HOOK, array( $orchestrator, 'advance' ) );
		\add_action( SchedulerBootstrap::WATCHDOG_HOOK, array( $orchestrator, 'watchdog' ) );
		\add_action( SchedulerBootstrap::CLEANUP_HOOK, array( $orchestrator, 'cleanup' ) );
		\add_action( 'action_scheduler_init', array( SchedulerBootstrap::class, 'ensure_recurring_actions' ) );
		\add_action( 'action_scheduler_ensure_recurring_actions', array( SchedulerBootstrap::class, 'ensure_recurring_actions' ) );
	}

}
