<?php
/**
 * Core — wires real WordPress hooks into our queue/optimizer machinery.
 *
 * @package ImageForge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Imgforge_Core {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_attachment', array( $this, 'handle_new_upload' ) );
        add_action( 'delete_attachment', array( $this, 'handle_attachment_deleted' ) );
    }

    /**
     * Fires when a new attachment post is created. We don't optimize
     * here directly — we just queue it, so the upload request itself
     * stays fast and the actual work happens on the next cron tick
     * (or immediately, if WP-Cron fires on this same page load, which
     * is how wp_cron naturally works on most sites anyway).
     *
     * @param int $attachment_id
     */
    public function handle_new_upload( $attachment_id ) {

        if ( ! Imgforge_Settings::is_enabled() ) {
            return;
        }

        if ( ! Imgforge_Settings::get( 'auto_optimize' ) ) {
            return;
        }

        if ( ! Imgforge_Media_Handler::is_supported_image( $attachment_id ) ) {
            return;
        }

        // Some themes/plugins duplicate or re-import attachments that
        // may already carry our meta (e.g. after an XML content import
        // from a staging site). Don't re-queue something already done.
        if ( '1' === get_post_meta( $attachment_id, '_imgforge_optimized', true ) ) {
            return;
        }

        Imgforge_Queue::get_instance()->enqueue( $attachment_id );
    }

    /**
     * Fires when an attachment is permanently deleted. WordPress cleans
     * up the main file and registered sizes itself via
     * wp_delete_attachment() — but it has no knowledge of the
     * ".imgforge-bak" backup file we create when keep_original is on,
     * so we're responsible for removing that ourselves. Otherwise every
     * optimized-then-deleted image leaves an orphaned backup on disk
     * forever, silently eating storage.
     *
     * @param int $attachment_id
     */
    public function handle_attachment_deleted( $attachment_id ) {

        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path ) {
            return;
        }

        $backup_path = $file_path . '.imgforge-bak';

        if ( Imgforge_Media_Handler::is_path_safe( $backup_path ) && file_exists( $backup_path ) ) {
            @unlink( $backup_path );
        }

        // Our own postmeta rows are deleted automatically by WordPress
        // core's wp_delete_attachment() (it calls delete_post_meta for
        // every meta key tied to the post ID), so no manual cleanup
        // needed for _imgforge_optimized / _imgforge_original_size etc.
    }
}