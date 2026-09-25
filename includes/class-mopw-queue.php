<?php
/**
 * Background queue — stores pending attachment IDs and processes batches.
 *
 * @package WebxperthubMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mopw_Queue {

	private static $instance = null;

	const CRON_HOOK = 'mopw_process_queue';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'mopw_five_minutes', self::CRON_HOOK );
		}
	}

	public function register_cron_interval( $schedules ) {
		$schedules['mopw_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes (Webxperthub Media Optimizer)', 'webxperthub-media-optimizer' ),
		);
		return $schedules;
	}

	/**
	 * Add an attachment to the queue if not already pending/processing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if a new row was inserted.
	 */
	public function enqueue( $attachment_id ) {
		global $wpdb;

		$table         = $this->table_name();
		$attachment_id = absint( $attachment_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE attachment_id = %d AND status IN ('pending','processing') LIMIT 1",
				$attachment_id
			)
		);

		if ( $exists ) {
			return false;
		}

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'attachment_id' => $attachment_id,
				'status'        => 'pending',
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false !== $inserted ) {
			wp_cache_delete( 'mopw_pending_count', 'mopw' );
		}

		return false !== $inserted;
	}

	/**
	 * Claim and process a batch of pending rows.
	 * Uses a short-lived transient lock to avoid concurrent runners.
	 *
	 * @return array{processed:int, failed:int, remaining:int}
	 */
	public function process_batch() {
		global $wpdb;
		$table = $this->table_name();

		$lock_key = 'mopw_batch_lock';
		if ( get_transient( $lock_key ) ) {
			return array(
				'processed' => 0,
				'failed'    => 0,
				'remaining' => $this->count_pending(),
			);
		}
		set_transient( $lock_key, 1, 60 );

		// Recover rows stuck in 'processing'. A PHP fatal (out-of-memory,
		// a segfaulting Imagick call, a host killing a long-running
		// request) can kill this whole process between "claim" and
		// "mark done/failed" below, leaving a row stuck at 'processing'
		// forever — invisible to count_pending() and never retried.
		// Anything still 'processing' after a generous stale window is
		// almost certainly an orphan from a crashed run, so put it back
		// in the queue.
		$stale_minutes = (int) apply_filters( 'mopw_stale_processing_minutes', 5 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', updated_at = %s
				WHERE status = 'processing' AND updated_at < %s",
				current_time( 'mysql' ),
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $stale_minutes * MINUTE_IN_SECONDS ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			)
		);

		$time_budget_seconds = (float) apply_filters( 'mopw_batch_time_budget', 20 );
		$start_time          = microtime( true );

		$max_rows = max( 1, (int) Mopw_Settings::get( 'batch_size' ) );

		// Claim rows: mark pending -> processing in one statement.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', updated_at = %s
				WHERE status = 'pending'
				ORDER BY id ASC
				LIMIT %d",
				current_time( 'mysql' ),
				$max_rows
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id FROM {$table} WHERE status = 'processing' ORDER BY id ASC LIMIT %d",
				$max_rows
			)
		);

		$processed = 0;
		$failed    = 0;

		foreach ( (array) $rows as $row ) {

			if ( ( microtime( true ) - $start_time ) >= $time_budget_seconds ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'status'     => 'pending',
						'updated_at' => current_time( 'mysql' ),
					),
					array(
						'id'     => $row->id,
						'status' => 'processing',
					),
					array( '%s', '%s' ),
					array( '%d', '%s' )
				);
				continue;
			}

			$result = Mopw_Optimizer::process( (int) $row->attachment_id );

			if ( ! empty( $result['success'] ) ) {
				$this->mark_status( $row->id, 'done' );
				$processed++;
			} else {
				$error = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error.', 'webxperthub-media-optimizer' );
				$this->mark_failed( $row->id, $error );
				$failed++;
			}
		}

		delete_transient( $lock_key );
		wp_cache_delete( 'mopw_pending_count', 'mopw' );

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			'remaining' => $this->count_pending(),
		);
	}

	/**
	 * @param int    $row_id        Queue row ID.
	 * @param string $error_message Error text.
	 */
	private function mark_failed( $row_id, $error_message ) {
		global $wpdb;
		$table  = $this->table_name();
		$row_id = absint( $row_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attempts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$table} WHERE id = %d",
				$row_id
			)
		);

		$new_status = ( $attempts + 1 >= 3 ) ? 'error' : 'pending';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array(
				'status'        => $new_status,
				'attempts'      => $attempts + 1,
				'error_message' => sanitize_text_field( $error_message ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $row_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		wp_cache_delete( 'mopw_pending_count', 'mopw' );
	}

	/**
	 * @param int    $row_id Queue row ID.
	 * @param string $status New status.
	 */
	private function mark_status( $row_id, $status ) {
		global $wpdb;
		$row_id = absint( $row_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$this->table_name(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $row_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_cache_delete( 'mopw_pending_count', 'mopw' );
	}

	/**
	 * @return int
	 */
	public function count_pending() {
		global $wpdb;

		$cache_key = 'mopw_pending_count';
		$count     = wp_cache_get( $cache_key, 'mopw' );

		if ( false === $count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table_name()} WHERE status = 'pending'"
			);
			wp_cache_set( $cache_key, $count, 'mopw', 15 );
		}

		return (int) $count;
	}

	/**
	 * Enqueue all unoptimized attachments in chunks to avoid memory exhaustion.
	 *
	 * @param int $chunk_size Number of IDs per WP_Query page.
	 * @return int Number of newly enqueued items.
	 */
	public function enqueue_all_unoptimized( $chunk_size = 200 ) {
		$chunk_size = max( 50, min( 500, (int) $chunk_size ) );
		$total      = 0;

		// Cursor (ID > $last_id) instead of OFFSET pagination. OFFSET
		// forces MySQL to walk and discard every prior row on each page,
		// which gets very slow on large media libraries (tens of
		// thousands of attachments) — exactly the scale this bulk tool
		// targets. An indexed "ID > last seen" WHERE clause stays fast
		// regardless of how many pages precede it.
		$last_id = 0;

		do {
			// WP_Query has no native "ID > X" param, so apply it via a
			// filter scoped to just this one query instance (added right
			// before the query and removed right after).
			$where_cb = function ( $where ) use ( $last_id ) {
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $last_id );
			};
			add_filter( 'posts_where', $where_cb );

			$query = new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'post_mime_type'         => (array) Mopw_Settings::get( 'allowed_mime_types' ),
					'posts_per_page'         => $chunk_size,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_mopw_optimized',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);

			remove_filter( 'posts_where', $where_cb );

			$ids = $query->posts;
			foreach ( $ids as $attachment_id ) {
				if ( $this->enqueue( $attachment_id ) ) {
					$total++;
				}
				$last_id = max( $last_id, (int) $attachment_id );
			}

			$fetched = count( $ids );

		} while ( $fetched === $chunk_size );

		wp_cache_delete( 'mopw_pending_count', 'mopw' );
		wp_cache_delete( 'mopw_unoptimized_count', 'mopw' );

		return $total;
	}

	/**
	 * @return string
	 */
	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . MOPW_QUEUE_TABLE;
	}

	/**
	 * Count Media Library attachments that haven't been optimized yet.
	 *
	 * @return int
	 */
	public function count_unoptimized() {
		$cache_key = 'mopw_unoptimized_count';
		$count     = wp_cache_get( $cache_key, 'mopw' );

		if ( false !== $count ) {
			return (int) $count;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => (array) Mopw_Settings::get( 'allowed_mime_types' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_mopw_optimized',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$count = (int) $query->found_posts;
		wp_cache_set( $cache_key, $count, 'mopw', 30 );

		return $count;
	}

	/**
	 * Cancel all pending/processing rows.
	 *
	 * @return int Number of rows cancelled.
	 */
	public function cancel_all_pending() {
		global $wpdb;
		$table = $this->table_name();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled', updated_at = %s WHERE status IN ('pending','processing')",
				$now
			)
		);

		wp_cache_delete( 'mopw_pending_count', 'mopw' );
		delete_transient( 'mopw_batch_lock' );

		return (int) $affected;
	}

	/**
	 * Records the total image count for the run currently starting, so
	 * the progress bar can compute a percentage even after a page reload
	 * — without this, we'd only ever know "how many are left," never
	 * "out of how many," once client-side JS state is lost.
	 */
	public function start_run( $total ) {
		update_option( 'mopw_bulk_run_total', (int) $total, false );
		update_option( 'mopw_bulk_run_active', 1, false );
	}

	public function end_run() {
		delete_option( 'mopw_bulk_run_total' );
		delete_option( 'mopw_bulk_run_active' );
	}

	public function is_run_active() {
		return (bool) get_option( 'mopw_bulk_run_active', false ) && $this->count_pending() > 0;
	}

	public function get_run_total() {
		return (int) get_option( 'mopw_bulk_run_total', 0 );
	}

	/**
	 * Returns every row currently in 'error' status, joined with basic
	 * attachment info, for display on the Failed Images admin screen.
	 *
	 * @return array List of objects: {id, attachment_id, error_message,
	 *               updated_at, title, thumbnail_url}
	 */

	public function get_failed_rows() {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			'SELECT id, attachment_id, error_message, attempts, updated_at FROM ' . esc_sql( $table ) . " WHERE status = 'error' ORDER BY updated_at DESC"
		);

		$results = array();

		foreach ( $rows as $row ) {
			$attachment_id = $row->attachment_id;
			$post		  = get_post( $attachment_id );

			if( !$post ) {
				continue;
			}

			$results[] = array(
				'id'             => (int) $row->id,
				'attachment_id'  => $attachment_id,
				'title'          => get_the_title( $attachment_id ),
				'thumbnail_url'  => wp_get_attachment_image_url( $attachment_id, array( 40, 40 ) ),
				'edit_url'       => get_edit_post_link( $attachment_id ),
				'error_message'  => $row->error_message,
				'attempts'       => (int) $row->attempts,
				'updated_at'     => $row->updated_at,
			);
		}

		return $results;
	}

	/**
	 * Resets a single failed row back to 'pending' so the next batch
	 * picks it up and retries — resets its attempt counter too, since
	 * the user explicitly asked for a fresh try.
	 *
	 * @param int $row_id
	 * @return bool
	 */

	public function retry_failed_row( $row_id ) {
		global $wpdb;
		$row_id = absint( $row_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			$this->table_name(),
			array(
				'status'        => 'pending',
				'attempts'      => 0,
				'error_message' => null,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $row_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		wp_cache_delete( 'mopw_pending_count', 'mopw' );

		return false !== $updated;
	}

	/**
	 * Permanently removes a failed row from the queue without retrying —
	 * useful for images the user has decided aren't worth optimizing
	 * (e.g. a corrupt file they plan to re-upload manually instead).
	 *
	 * @param int $row_id
	 * @return bool
	 */
	public function dismiss_failed_row( $row_id ) {
		global $wpdb;
		$row_id = absint( $row_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete( $this->table_name(), array( 'id' => $row_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Returns lifetime totals: images optimized and bytes saved, across
	 * the entire history of this plugin's use on this site — not just
	 * the current queue state.
	 *
	 * @return array{images:int, bytes:int}
	 */
	public function get_lifetime_stats() {
		return array(
			'images' => (int) get_option( 'mopw_lifetime_images_optimized', 0 ),
			'bytes'  => (int) get_option( 'mopw_lifetime_bytes_saved', 0 ),
		);
	}
}
