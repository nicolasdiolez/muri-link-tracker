<?php
/** Run with wp eval-file tests/integration/queue.php on a DISPOSABLE local install. */
if ( 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Integration tests require a disposable local WordPress install.' );
}
use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Queue\BatchOrchestrator;
use MuriLinkTracker\Queue\ScanStore;
use MuriLinkTracker\Queue\SchedulerBootstrap;

global $wpdb;
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
};
$links = new LinksRepository( $wpdb );
$instances = new InstancesRepository( $wpdb );
$store = new ScanStore( $wpdb );
$orchestrator = new BatchOrchestrator( $links, $instances, $store );
$original_settings = get_option( 'mltr_settings', array() );
$post_ids = array();
$requests = 0;
$http = static function ( $pre, $args, $url ) use ( &$requests ) {
	++$requests;
	return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => str_contains( $url, '/missing' ) ? 404 : 200, 'message' => 'Test' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $http, 10, 3 );
try {
	$orchestrator->reset();
	update_option( 'mltr_settings', array_merge( $original_settings, array( 'batch_size' => 10, 'http_request_delay' => 0, 'scan_post_types' => array( 'post' ), 'excluded_urls' => array( 'https://example.com/excluded*' ) ) ) );
	for ( $i = 0; $i < 23; ++$i ) {
		$post_ids[] = wp_insert_post( array( 'post_title' => 'MLTR queue fixture ' . $i, 'post_status' => 'publish', 'post_content' => '<p><a href="https://example.com/queue-' . ( $i % 4 ) . '">fixture</a><a href="https://example.com/missing">missing</a><a href="https://example.com/excluded-item">excluded</a></p>' ) );
	}
	$orchestrator->start_scan();
	$id = $orchestrator->get_status()['scan_id'];
	$before = $store->find_run( $id );
	for ( $i = 0; $i < 3; ++$i ) { $orchestrator->get_status(); }
	$assert( $before === $store->find_run( $id ) && 0 === $store->aggregate( $id, 'scanning' )['total_jobs'], 'Status reads must not advance a scan.' );
	$busy = false;
	try { $orchestrator->start_scan(); } catch ( RuntimeException $error ) { $busy = true; }
	$assert( $busy, 'Concurrent starts must be refused.' );
	// Process actual Action Scheduler deliveries. No status polling in this loop.
	for ( $i = 0; $i < 20; ++$i ) {
		ActionScheduler_QueueRunner::instance()->run( 'MLTR integration' );
		if ( 'running' !== $store->find_run( $id )['status'] ) { break; }
	}
	$status = $orchestrator->get_status();
	$assert( 'complete' === $status['status'], 'A scan must complete without an open admin tab: ' . wp_json_encode( $status ) );
	$assert( $store->aggregate( $id, 'scanning' )['total_jobs'] >= 3, 'Configured batch size must produce multiple jobs.' );
	$assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mltr_links WHERE url LIKE '%excluded%'" ), 'Excluded URLs must not enter the inventory.' );
	$assert( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mltr_links WHERE url='https://example.com/missing' AND http_status=404" ), 'HTTP status must be persisted.' );
	$assert( 0 === $status['pending_jobs'] && 0 === $status['running_jobs'], 'Completed scan must have no unfinished jobs.' );
	// A fresh delta scan should extract same-second edits safely but skip fresh HTTP results.
	$prior_requests = $requests;
	$orchestrator->start_scan( 'delta' );
	$delta = $store->current_run()['id'];
	for ( $i = 0; $i < 20; ++$i ) {
		ActionScheduler_QueueRunner::instance()->run( 'MLTR integration delta' );
		if ( 'running' !== $store->find_run( $delta )['status'] ) { break; }
	}
	$assert( 'complete' === $store->find_run( $delta )['status'], 'Delta scan must finish.' );
	$assert( $prior_requests === $requests, 'Delta must not check already-fresh URLs.' );
	// A claimed job is revoked by cancellation and given a fresh token on resume.
	$orchestrator->start_scan();
	$run = $store->current_run();
	$job_id = $store->create_job( $run['id'], 'scanning', array( $post_ids[0] ) );
	$store->exclusive( fn() => $store->claim_job( $job_id, 'old-worker' ) );
	$assert( $store->owns_job( $job_id, 'old-worker' ), 'Worker must initially own its job.' );
	$orchestrator->cancel();
	$assert( ! $store->owns_job( $job_id, 'old-worker' ), 'Cancellation must revoke worker ownership.' );
	$assert( $orchestrator->resume(), 'Cancelled run must resume.' );
	$assert( ! $store->owns_job( $job_id, 'old-worker' ), 'Old worker must not revive on resume.' );
	for ( $attempt = 0; $attempt < 3; ++$attempt ) {
		$token = 'retry-' . $attempt;
		$store->exclusive( fn() => $store->claim_job( $job_id, $token ) );
		$store->exclusive( fn() => $store->fail_job( $job_id, $token, 'Injected failure' ) );
	}
	$orchestrator->advance( $run['id'] );
	$assert( 'error' === $orchestrator->get_status()['status'], 'Three failures must stop with an error, never success.' );
	$assert( $orchestrator->resume(), 'An explicit resume must retry failed jobs.' );
	$generation = $store->manual_generation();
	$orchestrator->reset();
	$assert( $generation !== $store->manual_generation(), 'Reset must invalidate delayed manual checks.' );
	$assert( ! $store->owns_job( $job_id, 'old-worker' ), 'Reset must fence late workers.' );
	$assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mltr_links" ), 'Reset must clear inventory.' );
	$assert( false !== as_next_scheduled_action( SchedulerBootstrap::RECHECK_DAILY_HOOK, array(), SchedulerBootstrap::GROUP ), 'Reset must preserve recurring maintenance.' );
	// REST permissions and start/resume response shape with real WP dispatch.
	wp_set_current_user( 0 );
	$assert( 401 === rest_do_request( '/muri-link-tracker/v1/scan/status' )->get_status(), 'Anonymous status access must be refused.' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );
	delete_transient( 'mltr_rate_limit_start' );
	$request = new WP_REST_Request( 'POST', '/muri-link-tracker/v1/scan/start' );
	$request->set_param( 'scan_type', 'full' );
	$response = rest_do_request( $request );
	$assert( 200 === $response->get_status() && 'running' === $response->get_data()['status'], 'Start endpoint must return a flat scan status.' );
	echo "Queue integration: {$checks} assertions passed.\n";
} finally {
	$orchestrator->reset();
	foreach ( $post_ids as $post_id ) { wp_delete_post( $post_id, true ); }
	update_option( 'mltr_settings', $original_settings );
	remove_filter( 'pre_http_request', $http, 10 );
}
