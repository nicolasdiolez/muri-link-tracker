<?php
/**
 * Resumable extraction jobs with durable checkpoints and bounded retries.
 *
 * @package MuriLinkTracker
 */

declare( strict_types=1 );

namespace MuriLinkTracker\Queue;

defined( 'ABSPATH' ) || exit;

use MuriLinkTracker\Database\InstancesRepository;
use MuriLinkTracker\Database\LinksRepository;
use MuriLinkTracker\Models\Enums\LinkType;
use MuriLinkTracker\Models\LinkInstance;
use MuriLinkTracker\Scanner\LinkClassifier;
use MuriLinkTracker\Scanner\LinkExtractor;

/** Extracts post links with resumable checkpoints. */
class ScanJob {
	/**
	 * Durable scan state.
	 *
	 * @var ScanStore
	 */
	private readonly ScanStore $store;

	/**
	 * Initialize the service dependencies.
	 *
	 * @param LinkExtractor       $extractor Post link extractor.
	 * @param LinksRepository     $links_repo Links repo.
	 * @param InstancesRepository $instances_repo Instances repo.
	 * @param ScanStore|null      $store Durable scan state repository.
	 */
	public function __construct(
		private readonly LinkExtractor $extractor,
		private readonly LinksRepository $links_repo,
		private readonly InstancesRepository $instances_repo,
		?ScanStore $store = null,
	) {
		global $wpdb;
		$this->store = $store ?? new ScanStore( $wpdb );
	}

	/**
	 * Process a bounded job while respecting cancellation and resource limits.
	 *
	 * @param int|string $job_id Persisted job identifier or legacy manual payload.
	 */
	public function process_batch( int|string $job_id ): void {
		// Pre-upgrade transient deliveries cannot be attributed to a durable run.
		if ( ! is_numeric( $job_id ) ) {
			return;
		}
		$job_id = (int) $job_id;
		$token  = wp_generate_uuid4();
		$job    = $this->store->exclusive( fn() => $this->store->claim_job( $job_id, $token ) );
		if ( null === $job || 'scanning' !== $job['phase'] ) {
			return;
		}
		$started        = microtime( true );
		$settings       = get_option( 'mltr_settings', array() );
		$previous_cache = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );
		try {
			$count = count( $job['item_ids'] );
			for ( $offset = (int) $job['completed_items']; $offset < $count; ++$offset ) {
				$processed = $this->store->exclusive(
					function () use ( $job_id, $token, $job, $offset, $count, $settings ): bool {
						if ( ! $this->store->owns_job( $job_id, $token ) ) {
								return false;
						}
						$post = get_post( $job['item_ids'][ $offset ] );
						if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
							$this->process_post( $post, $settings );
						} else {
							$this->instances_repo->delete_by_post( (int) $job['item_ids'][ $offset ] );
						}
						$this->store->checkpoint( $job_id, $token, $offset + 1, $offset + 1 === $count );
						return true;
					}
				);
				if ( ! $processed ) {
					return;
				}
				if ( $offset + 1 < $count && ! $this->has_resources( $started ) ) {
					$this->store->exclusive( fn() => $this->store->release_job( $job_id, $token ) );
					SchedulerBootstrap::enqueue_job( $job_id, 'scanning' );
					return;
				}
			}
			delete_transient( 'mltr_stats_cache' );
		} catch ( \Throwable $error ) {
			$this->store->exclusive( fn() => $this->store->fail_job( $job_id, $token, $error->getMessage() ) );
			$failed = $this->store->find_job( $job_id );
			if ( null !== $failed && 'pending' === $failed['status'] ) {
				SchedulerBootstrap::enqueue_job( $job_id, 'scanning', 30 * (int) $failed['attempts'] );
			}
		} finally {
			wp_suspend_cache_addition( $previous_cache );
			SchedulerBootstrap::enqueue_coordinator( $job['scan_id'] );
		}
	}

	/**
	 * Check whether this worker has time and memory remaining.
	 *
	 * @param float $started Worker start time in seconds.
	 */
	private function has_resources( float $started ): bool {
		$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return ( $memory <= 0 || memory_get_usage( true ) < $memory * 0.8 ) && microtime( true ) - $started < 20;
	}

	/**
	 * Extract one post and replace its inventory occurrences.
	 *
	 * @param \WP_Post $post Source WordPress post.
	 * @param array    $settings Plugin scan settings.
	 */
	private function process_post( \WP_Post $post, array $settings ): void {
		$affected_ids = array_fill_keys( array_map( static fn( LinkInstance $instance ): int => $instance->link_id, $this->instances_repo->find_by_post( $post->ID ) ), true );
		$extracted    = $this->extractor->extract_from_post( $post, $settings );

		if ( empty( $extracted ) ) {
			// No links found: clean up any old instances.
			$this->instances_repo->sync_for_post( $post->ID, array() );
			$this->refresh_classifications( array_keys( $affected_ids ) );
			return;
		}

		$instances_data = array();

		foreach ( $extracted as $url_data ) {
			// Insert or get the link record.
			$link_id = $this->links_repo->insert_or_get(
				$url_data['url'],
				$url_data['url_hash'],
				LinkType::External === $url_data['type'],
				$url_data['is_affiliate'],
				$url_data['affiliate_network']
			);

			if ( 0 === $link_id ) {
				continue;
			}
			$affected_ids[ $link_id ] = true;

			// Build instance records for each occurrence.
			foreach ( $url_data['instances'] as $instance ) {
				$scan_result = $instance['scan_result'];
				$rel_flags   = $instance['rel_flags'];

				$instances_data[] = array(
					'link_id'       => $link_id,
					'post_id'       => $post->ID,
					'source_type'   => $scan_result->source_type,
					'anchor_text'   => $scan_result->anchor_text,
					'rel_nofollow'  => $rel_flags['rel_nofollow'],
					'rel_sponsored' => $rel_flags['rel_sponsored'],
					'rel_ugc'       => $rel_flags['rel_ugc'],
					'is_dofollow'   => $rel_flags['is_dofollow'],
					'link_position' => $scan_result->link_position,
					'block_name'    => $scan_result->block_name,
				);
			}
		}

		// Atomically replace all instances for this post.
		$this->instances_repo->sync_for_post( $post->ID, $instances_data );
		$this->refresh_classifications( array_keys( $affected_ids ) );

		/**
		 * Fires after a post has been fully processed by the scanner.
		 *
		 * @since 1.0.0
		 *
		 * @param int $post_id The processed post ID.
		 */
		do_action( 'mltr/scan/post_processed', $post->ID );
	}

	/**
	 * Recompute after replacement, including URLs whose last sponsored occurrence disappeared.
	 *
	 * @param array $ids Record identifiers for this bounded batch.
	 */
	private function refresh_classifications( array $ids ): void {
		if ( ! $ids ) {
			return;
		}
		$classifier = new LinkClassifier();
		foreach ( $this->links_repo->find_by_ids( $ids ) as $link ) {
			// The group hint may contain rel=sponsored; this argument must describe only the URL.
			$affiliate = $classifier->detect_affiliate( $link->url );
			$this->links_repo->refresh_classification( $link->id, LinkType::External === $classifier->classify_type( $link->url ), $affiliate['is_affiliate'], $affiliate['network'] );
		}
	}
}
