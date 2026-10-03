<?php
/**
 * Bounded HTTP checks, fenced against cancellation, reset and concurrent edits.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Queue;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Models\Enums\LinkStatus;
use MuriLinkTracker\Models\Link;
use MuriLinkTracker\Scanner\HttpChecker;
use MuriLinkTracker\Scanner\InternalLinkChecker;
use MuriLinkTracker\Scanner\UrlExclusions;

/** Checks URLs without allowing cancelled workers to persist results. */
class CheckJob {
	/**
	 * Durable scan state.
	 *
	 * @var ScanStore
	 */
	private readonly ScanStore $store;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param HttpChecker         $checker Safe HTTP link checker.
	 * @param InternalLinkChecker $internal_checker Checker for site-internal destinations.
	 * @param LinksRepository     $links_repo Links repo.
	 * @param ScanStore|null      $store Durable scan state repository.
	 */
	public function __construct(
		private readonly HttpChecker $checker,
		private readonly InternalLinkChecker $internal_checker,
		private readonly LinksRepository $links_repo,
		?ScanStore $store = null,
	) {
		global $wpdb;
		$this->store = $store ?? new ScanStore( $wpdb );
	}

	/**
	 * Process a bounded job while respecting cancellation and resource limits.
	 *
	 * @param int|string|array $job_id Persisted job identifier or legacy manual payload.
	 */
	public function process_batch( int|string|array $job_id ): void {
		if ( is_array( $job_id ) ) {
			$this->process_manual( $job_id );
			return;
		}
		if ( ! is_numeric( $job_id ) ) {
			return;
		}
		$job_id = (int) $job_id;
		$token  = wp_generate_uuid4();
		$job    = $this->store->exclusive( fn() => $this->store->claim_job( $job_id, $token ) );
		if ( null === $job || 'checking' !== $job['phase'] ) {
			return;
		}
		try {
			$count = count( $job['item_ids'] );
			for ( $offset = (int) $job['completed_items']; $offset < $count; ++$offset ) {
				if ( ! $this->store->owns_job( $job_id, $token ) ) {
					return;
				}
				$link = $this->links_repo->find( (int) $job['item_ids'][ $offset ] );
				// HTTP intentionally runs outside the mutation mutex.
				$result = null !== $link ? $this->check_link( $link ) : null;
				$saved  = $this->store->exclusive(
					function () use ( $job_id, $token, $link, $result, $offset, $count ): bool {
						if ( ! $this->store->owns_job( $job_id, $token ) ) {
								return false;
						}
						if ( null !== $link && null !== $result ) {
							$current = $this->links_repo->find( $link->id );
							if ( null !== $current && $current->url === $link->url && $current->is_external === $link->is_external ) {
								$this->persist_result( $link->id, $result );
							}
						}
						$this->store->checkpoint( $job_id, $token, $offset + 1, $offset + 1 === $count );
						return true;
					}
				);
				if ( ! $saved ) {
					return;
				}
				// Durable continuation even if a future planner creates a multi-item job.
				if ( $offset + 1 < $count ) {
					$this->store->exclusive( fn() => $this->store->release_job( $job_id, $token ) );
					SchedulerBootstrap::enqueue_job( $job_id, 'checking' );
					return;
				}
			}
		} catch ( \Throwable $error ) {
			$this->store->exclusive( fn() => $this->store->fail_job( $job_id, $token, $error->getMessage() ) );
			$failed = $this->store->find_job( $job_id );
			if ( null !== $failed && 'pending' === $failed['status'] ) {
				SchedulerBootstrap::enqueue_job( $job_id, 'checking', 30 * (int) $failed['attempts'] );
			}
		} finally {
			SchedulerBootstrap::enqueue_coordinator( $job['scan_id'] );
		}
	}

	/**
	 * Process a manual recheck fenced by its cancellation token.
	 *
	 * @param array $payload Manual check payload and cancellation token.
	 *
	 * @throws \Throwable When the guarded operation fails; the original error is propagated.
	 */
	private function process_manual( array $payload ): void {
		// Ignore legacy unscoped deliveries from before the durable queue migration.
		if ( ! isset( $payload['manual_id'], $payload['generation'] ) || $payload['generation'] !== $this->store->manual_generation() ) {
			return;
		}
		$link = $this->links_repo->find( (int) $payload['manual_id'] );
		if ( null === $link ) {
			return;
		}
		try {
			$result = $this->check_link( $link );
			$this->store->exclusive(
				function () use ( $payload, $link, $result ): void {
					$current = $this->links_repo->find( $link->id );
					if ( $payload['generation'] === $this->store->manual_generation() && null !== $current && $link->url === $current->url && $link->is_external === $current->is_external ) {
							$this->persist_result( $link->id, $result );
					}
				}
			);
		} catch ( \Throwable $error ) {
			SchedulerBootstrap::retry_manual( $payload );
			throw $error;
		}
	}

	/**
	 * Classify and check one link using the appropriate checker.
	 *
	 * @param Link $link Tracked link to check.
	 */
	private function check_link( Link $link ): array {
		$settings = get_option( 'mltr_settings', array() );
		if ( UrlExclusions::matches( $link->url, (array) ( $settings['excluded_urls'] ?? array() ) ) ) {
			return array(
				'http_status'      => 0,
				'status_category'  => LinkStatus::Skipped,
				'final_url'        => null,
				'response_time'    => 0,
				'redirect_count'   => 0,
				'redirect_chain'   => null,
				'is_redirect_loop' => false,
				'error'            => 'excluded_by_settings',
			);
		}
		$delay = max( 0, min( 5000, (int) ( $settings['http_request_delay'] ?? 300 ) ) );
		if ( $delay > 0 ) {
			usleep( $delay * 1000 );
		}
		return $link->is_external ? $this->checker->check( $link->url ) : $this->internal_checker->check( $link->url );
	}

	/**
	 * Save the HTTP result for a tracked link.
	 *
	 * @param int   $link_id Tracked link identifier.
	 * @param array $result Database write result or normalized HTTP result.
	 *
	 * @throws \RuntimeException When state validation or the database operation fails.
	 */
	private function persist_result( int $link_id, array $result ): void {
		$chain_json = null !== $result['redirect_chain'] ? wp_json_encode( $result['redirect_chain'] ) : null;
		if ( false === $chain_json ) {
			throw new \RuntimeException( 'The redirect chain could not be encoded.' );
		}
		$error = $result['error'];
		if ( $result['is_redirect_loop'] && null === $error ) {
			$error = 'redirect_loop';
		}
		if ( ! $this->links_repo->update_check_result( $link_id, $result['http_status'], $result['status_category'], $result['final_url'], $result['response_time'], $result['redirect_count'], $chain_json, $error ) ) {
			throw new \RuntimeException( 'The link check result could not be saved.' );
		}
		delete_transient( 'mltr_stats_cache' );
		do_action( 'mltr/check/link_checked', $link_id, $result );
	}
}
