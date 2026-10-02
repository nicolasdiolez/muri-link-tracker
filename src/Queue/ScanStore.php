<?php
/** Durable scan state and bounded job checkpoints. */
declare( strict_types=1 );

namespace MuriLinkTracker\Queue;

defined( 'ABSPATH' ) || exit;

class ScanStore {

	public const MAX_ATTEMPTS = 3;
	private readonly string $runs;
	private readonly string $jobs_table;

	public function __construct( private readonly \wpdb $wpdb ) {
		$this->runs       = $wpdb->prefix . 'mltr_scans';
		$this->jobs_table = $wpdb->prefix . 'mltr_scan_jobs';
	}

	/** Serialize database mutations, including reset, without holding a lock during HTTP. */
	public function exclusive( callable $callback ): mixed {
		$name = 'mltr_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $this->wpdb->prefix ), 0, 40 );
		$locked = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $name ) );
		$this->require_read_success();
		if ( '1' !== (string) $locked ) {
			throw new QueueBusyException( 'Another link tracker operation is running. Please try again.' );
		}
		try {
			return $callback();
		} finally {
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/** Read directly so a cancelled/reset run cannot remain active in another worker's cache. */
	public function current_run(): ?array {
		$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, 'mltr_active_scan_id' ) );
		$this->require_read_success();
		return is_string( $id ) ? $this->find_run( $id ) : null;
	}

	public function find_run( string $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', $this->runs, $id ), ARRAY_A );
		$this->require_read_success();
		return is_array( $row ) ? $row : null;
	}

	public function is_active( string $id ): bool {
		$run = $this->current_run();
		return null !== $run && $id === $run['id'] && 'running' === $run['status'];
	}

	public function manual_generation(): string {
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, 'mltr_queue_generation' ) );
		$this->require_read_success();
		return is_string( $value ) ? $value : 'initial';
	}

	public function invalidate_manual_checks(): void {
		$generation = wp_generate_uuid4();
		update_option( 'mltr_queue_generation', $generation, false );
		if ( $this->manual_generation() !== $generation ) {
			throw new \RuntimeException( 'The queue cancellation token could not be saved.' );
		}
	}

	public function create_run( string $scan_type, string $phase ): array {
		$run = array(
			'id'             => wp_generate_uuid4(),
			'scan_type'      => $scan_type,
			'status'         => 'running',
			'phase'          => $phase,
			'started_at'     => gmdate( 'Y-m-d H:i:s' ),
			'scan_cursor'    => 0,
			'check_cursor'   => 0,
			'planning_done'  => 0,
			'last_scan_date' => get_option( 'mltr_last_scan_date', '' ) ?: null,
		);
		if ( null !== $run['last_scan_date'] ) {
			$timestamp = strtotime( (string) $run['last_scan_date'] );
			$run['last_scan_date'] = false !== $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
		}
		$this->require_success( $this->wpdb->insert( $this->runs, $run ) );
		update_option( 'mltr_active_scan_id', $run['id'], false );
		if ( ( $this->current_run()['id'] ?? null ) !== $run['id'] ) {
			throw new \RuntimeException( 'The active scan could not be saved.' );
		}
		return $run;
	}

	public function update_run( string $id, array $data ): void {
		$allowed = array_flip( array( 'status', 'phase', 'finished_at', 'error_message', 'scan_cursor', 'check_cursor', 'planning_done' ) );
		$data = array_intersect_key( $data, $allowed );
		if ( $data ) {
			$this->require_success( $this->wpdb->update( $this->runs, $data, array( 'id' => $id ) ) );
		}
	}

	/** Unique batch key makes interrupted planning safe to repeat. */
	public function create_job( string $scan_id, string $phase, array $ids ): int {
		if ( ! $ids ) {
			throw new \InvalidArgumentException( 'Cannot create an empty scan job.' );
		}
		$ids = array_values( array_map( 'intval', $ids ) );
		$this->require_success( $this->wpdb->query( $this->wpdb->prepare(
			'INSERT INTO %i (scan_id, phase, batch_key, item_ids, item_count) VALUES (%s, %s, %d, %s, %d) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
			$this->jobs_table, $scan_id, $phase, $ids[0], wp_json_encode( $ids ), count( $ids )
		) ) );
		return (int) $this->wpdb->insert_id;
	}

	public function find_job( int $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->jobs_table, $id ), ARRAY_A );
		$this->require_read_success();
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['item_ids'] = json_decode( $row['item_ids'], true, 512, JSON_THROW_ON_ERROR );
		return $row;
	}

	/** Called under exclusive(), so claiming cannot race cancellation/reset. */
	public function claim_job( int $id, string $token ): ?array {
		$job = $this->find_job( $id );
		if ( null === $job || ! $this->is_active( $job['scan_id'] ) ) {
			return null;
		}
		$updated = $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE %i SET status = 'running', lease_token = %s, lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = %d AND status = 'pending'",
			$this->jobs_table, $token, $id
		) );
		$this->require_success( $updated );
		return 1 === $updated ? $this->find_job( $id ) : null;
	}

	public function owns_job( int $id, string $token ): bool {
		$job = $this->find_job( $id );
		return null !== $job && 'running' === $job['status'] && $token === $job['lease_token'] && $this->is_active( $job['scan_id'] );
	}

	public function checkpoint( int $id, string $token, int $offset, bool $complete = false ): void {
		$result = $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE %i SET completed_items = %d, status = %s, lease_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), error_message = NULL WHERE id = %d AND lease_token = %s AND status = 'running'",
			$this->jobs_table, $offset, $complete ? 'complete' : 'running', $id, $token
		) );
		$this->require_success( $result );
		if ( 1 !== $result ) {
			throw new \RuntimeException( 'The scan job no longer belongs to this worker.' );
		}
	}

	public function release_job( int $id, string $token ): void {
		$this->require_success( $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE %i SET status = 'pending', lease_token = NULL, lease_until = NULL WHERE id = %d AND lease_token = %s AND status = 'running'",
			$this->jobs_table, $id, $token
		) ) );
	}

	public function fail_job( int $id, string $token, string $message ): void {
		$this->require_success( $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE %i SET status = IF(attempts + 1 >= %d, 'failed', 'pending'), attempts = attempts + 1, error_message = %s, lease_token = NULL, lease_until = NULL WHERE id = %d AND lease_token = %s AND status = 'running'",
			$this->jobs_table, self::MAX_ATTEMPTS, substr( $message, 0, 2000 ), $id, $token
		) ) );
	}

	public function recover_expired( string $scan_id ): void {
		$this->require_success( $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE %i SET status = IF(attempts + 1 >= %d, 'failed', 'pending'), attempts = attempts + 1, error_message = 'The worker stopped before saving its checkpoint.', lease_token = NULL, lease_until = NULL WHERE scan_id = %s AND status = 'running' AND lease_until < UTC_TIMESTAMP()",
			$this->jobs_table, self::MAX_ATTEMPTS, $scan_id
		) ) );
	}

	/** Explicit resume starts a new bounded retry cycle; automatic retry stays bounded. */
	public function resume_jobs( string $scan_id ): void {
		$this->require_success( $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE %i SET status = 'pending', attempts = 0, lease_token = NULL, lease_until = NULL, error_message = NULL WHERE scan_id = %s AND status IN ('failed', 'running')",
			$this->jobs_table, $scan_id
		) ) );
	}

	public function pending_jobs( string $scan_id, int $limit = 100 ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT id, phase FROM %i WHERE scan_id = %s AND status = 'pending' ORDER BY id LIMIT %d",
			$this->jobs_table, $scan_id, $limit
		), ARRAY_A );
		$this->require_read_success();
		return $rows;
	}

	/** Indexed existence checks avoid re-summing every job after every completed URL. */
	public function work_state( string $scan_id, string $phase ): array {
		$failed = $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT 1 FROM %i WHERE scan_id = %s AND phase = %s AND status = 'failed' LIMIT 1",
			$this->jobs_table, $scan_id, $phase
		) );
		$this->require_read_success();
		$unfinished = $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT 1 FROM %i WHERE scan_id = %s AND phase = %s AND status IN ('pending', 'running') LIMIT 1",
			$this->jobs_table, $scan_id, $phase
		) );
		$this->require_read_success();
		return array( 'failed' => null !== $failed, 'unfinished' => null !== $unfinished );
	}

	/** Planning inserts and its cursor commit together, before anything is dispatched. */
	public function transaction( callable $callback ): mixed {
		$this->require_success( $this->wpdb->query( 'START TRANSACTION' ) );
		try {
			$result = $callback();
			$this->require_success( $this->wpdb->query( 'COMMIT' ) );
			return $result;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function aggregate( string $scan_id, string $phase ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT COALESCE(SUM(item_count), 0) total_items, COALESCE(SUM(completed_items), 0) processed, COALESCE(SUM(status = 'pending'), 0) pending, COALESCE(SUM(status = 'running'), 0) running, COALESCE(SUM(status = 'failed'), 0) failed, COUNT(*) total_jobs FROM %i WHERE scan_id = %s AND phase = %s",
			$this->jobs_table, $scan_id, $phase
		), ARRAY_A );
		$this->require_read_success();
		return array_map( 'intval', is_array( $row ) ? $row : array() ) + array( 'total_items' => 0, 'processed' => 0, 'pending' => 0, 'running' => 0, 'failed' => 0, 'total_jobs' => 0 );
	}

	/** ID cursor keeps planning bounded; inclusive delta boundary avoids same-second misses. */
	public function next_post_ids( array $run, array $types, int $limit ): array {
		if ( ! $types ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT ID FROM %i WHERE post_status = 'publish' AND post_type IN ($placeholders) AND ID > %d";
		$args = array( $this->wpdb->posts, ...$types, (int) $run['scan_cursor'] );
		if ( 'delta' === $run['scan_type'] && ! empty( $run['last_scan_date'] ) ) {
			$sql .= ' AND post_modified_gmt >= %s';
			$args[] = $run['last_scan_date'];
		}
		$sql .= ' ORDER BY ID ASC LIMIT %d';
		$args[] = $limit;
		$ids = $this->wpdb->get_col( $this->wpdb->prepare( $sql, ...$args ) );
		$this->require_read_success();
		return array_map( 'intval', $ids );
	}

	public function delete_runs(): void {
		$this->require_success( $this->wpdb->query( "DELETE FROM {$this->jobs_table}" ) );
		$this->require_success( $this->wpdb->query( "DELETE FROM {$this->runs}" ) );
		delete_option( 'mltr_active_scan_id' );
	}

	/** Retain the current run and recent diagnostics only. */
	public function prune(): void {
		$this->require_success( $this->wpdb->query( $this->wpdb->prepare(
			"DELETE j FROM %i j INNER JOIN %i r ON r.id = j.scan_id WHERE r.status <> 'running' AND r.started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) AND r.id <> %s",
			$this->jobs_table, $this->runs, (string) ( $this->current_run()['id'] ?? '' )
		) ) );
		$this->require_success( $this->wpdb->query( $this->wpdb->prepare(
			"DELETE FROM %i WHERE status <> 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) AND id <> %s",
			$this->runs, (string) ( $this->current_run()['id'] ?? '' )
		) ) );
	}

	private function require_success( mixed $result ): void {
		if ( false === $result ) {
			throw new \RuntimeException( 'The scan checkpoint could not be saved to the database.' );
		}
	}

	private function require_read_success(): void {
		if ( '' !== $this->wpdb->last_error ) {
			throw new \RuntimeException( 'The scan state could not be read from the database.' );
		}
	}
}
