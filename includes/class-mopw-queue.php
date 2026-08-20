<?php
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

    public function enqueue( $attachment_id ) {
        global $wpdb;

        $table       = $this->table_name();
        $attachment_id = absint( $attachment_id );
        $cache_key   = 'mopw_queue_exists_' . $attachment_id;

        $exists = wp_cache_get( $cache_key, 'mopw' );
        if ( false === $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $exists = $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM ' . esc_sql( $table ) . " WHERE attachment_id = %d AND status IN ('pending','processing')",
                $attachment_id
            ) );
            wp_cache_set( $cache_key, $exists, 'mopw', 30 );
        }

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
            wp_cache_delete( $cache_key, 'mopw' );
        }

        return false !== $inserted;
    }

    public function process_batch() {
        global $wpdb;
        $table = $this->table_name();

        $time_budget_seconds = (float) apply_filters( 'mopw_batch_time_budget', 20 );
        $start_time          = microtime( true );

        $max_rows_to_fetch = max( 1, (int) Mopw_Settings::get( 'batch_size' ) );
        $cache_key         = 'mopw_pending_rows_' . $max_rows_to_fetch;

        $rows = wp_cache_get( $cache_key, 'mopw' );
        if ( false === $rows ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, attachment_id FROM ' . esc_sql( $table ) . " WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
                $max_rows_to_fetch
            ) );
            wp_cache_set( $cache_key, $rows, 'mopw', 10 );
        }

        $processed = 0;
        $failed    = 0;

        foreach ( $rows as $row ) {

            if ( ( microtime( true ) - $start_time ) >= $time_budget_seconds ) {
                break;
            }

            $this->mark_status( $row->id, 'processing' );

            $result = Mopw_Optimizer::process( (int) $row->attachment_id );

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

    private function mark_failed( $row_id, $error_message ) {
        global $wpdb;
        $table = $this->table_name();
        $row_id = absint( $row_id );

        $attempts = wp_cache_get( 'mopw_attempts_' . $row_id, 'mopw' );
        if ( false === $attempts ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $attempts = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT attempts FROM ' . esc_sql( $table ) . ' WHERE id = %d',
                $row_id
            ) );
            wp_cache_set( 'mopw_attempts_' . $row_id, $attempts, 'mopw', 30 );
        }

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
        wp_cache_delete( 'mopw_attempts_' . $row_id, 'mopw' );
    }

    private function mark_status( $row_id, $status ) {
        global $wpdb;
        $row_id = absint( $row_id );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $this->table_name(),
            array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $row_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        wp_cache_delete( 'mopw_pending_count', 'mopw' );
    }

    public function count_pending() {
        global $wpdb;

        $cache_key = 'mopw_pending_count';
        $count     = wp_cache_get( $cache_key, 'mopw' );

        if ( false === $count ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $count = (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM ' . esc_sql( $this->table_name() ) . " WHERE status = 'pending'"
            );
            wp_cache_set( $cache_key, $count, 'mopw', 30 );
        }

        return $count;
    }

    public function enqueue_all_unoptimized() {
        $query = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => (array) Mopw_Settings::get( 'allowed_mime_types' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => '_mopw_optimized',
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
        return $wpdb->prefix . MOPW_QUEUE_TABLE;
    }
}