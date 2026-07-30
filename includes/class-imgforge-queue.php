<?php
/**
 * Queue — custom table + background batch processor.
 *
 * Two responsibilities: (1) manage rows in the imgforge_queue table,
 * (2) process a small batch per invocation, whether triggered by
 * wp_cron or by an admin-triggered AJAX call.
 *
 * @package ImageForge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Imgforge_Queue {

    private static $instance = null;

    const CRON_HOOK = 'imgforge_process_queue';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'imgforge_five_minutes', self::CRON_HOOK );
        }

        add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
    }

    /**
     * wp_cron only ships with hourly/twicedaily/daily out of the box.
     * We need something much more frequent for near-real-time bulk
     * processing, so we register a custom 5-minute interval.
     *
     * @param array $schedules
     * @return array
     */
    public function register_cron_interval( $schedules ) {
        $schedules['imgforge_five_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 Minutes (Image Forge)', 'image-forge' ),
        );
        return $schedules;
    }

    /**
     * Adds an attachment to the queue, ignoring duplicates.
     *
     * @param int $attachment_id
     * @return bool
     */
    public function enqueue( $attachment_id ) {
        global $wpdb;
        $table = $this->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d AND status IN ('pending','processing')",
            $attachment_id
        ) );

        if ( $exists ) {
            return false; // Already queued, don't duplicate.
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

        return false !== $inserted;
    }

    /**
     * Processes one batch of pending rows. Called by wp_cron on its
     * schedule, AND callable directly from the Admin AJAX handler for
     * responsive bulk-optimize UI (same method, two triggers).
     *
     * @return array Stats about this batch run, used by the AJAX response.
     */
    public function process_batch() {
        global $wpdb;
        $table      = $this->table_name();
        $batch_size = max( 1, (int) Imgforge_Settings::get( 'batch_size' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, attachment_id FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
            $batch_size
        ) );

        $processed = 0;
        $failed    = 0;

        foreach ( $rows as $row ) {
            $this->mark_status( $row->id, 'processing' );

            $result = Imgforge_Optimizer::process( (int) $row->attachment_id );

            if ( $result['success'] ) {
                $this->mark_status( $row->id, 'done' );
                $processed++;
            } else {
                $this->mark_failed( $row->id, $result['error'] );
                $failed++;
            }
        }

        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'remaining' => $this->count_pending(),
        );
    }

    /**
     * Marks a row's attempt count and error, and re-queues it as
     * pending unless it's already failed 3 times — permanent failures
     * (corrupt files, unsupported formats) shouldn't retry forever.
     */
    private function mark_failed( $row_id, $error_message ) {
        global $wpdb;
        $table = $this->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $attempts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT attempts FROM {$table} WHERE id = %d", $row_id
        ) );

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
    }

    private function mark_status( $row_id, $status ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $this->table_name(),
            array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $row_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * How many images are still waiting — used by the admin progress bar.
     *
     * @return int
     */
    public function count_pending() {
        global $wpdb;

        $cache_key = 'imgforge_pending_count';
        $count     = wp_cache_get( $cache_key, 'imgforge' );

        if ( false === $count ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name()} WHERE status = 'pending'"
            );
            wp_cache_set( $cache_key, $count, 'imgforge', 30 ); // short TTL, this changes fast during a batch run.
        }

        return $count;
    }

    /**
     * Bulk-enqueues every image-library attachment that hasn't been
     * optimized yet. Called from the Admin "Bulk Optimize" button.
     *
     * @return int Number of images newly queued.
     */
    public function enqueue_all_unoptimized() {
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => (array) Imgforge_Settings::get( 'allowed_mime_types' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => '_imgforge_optimized',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ) );

        $count = 0;
        foreach ( $query->posts as $attachment_id ) {
            if ( $this->enqueue( $attachment_id ) ) {
                $count++;
            }
        }

        return $count;
    }

    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . IMGFORGE_QUEUE_TABLE;
    }
}