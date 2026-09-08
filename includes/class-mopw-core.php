<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mopw_Core {

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

    public function handle_new_upload( $attachment_id ) {

        if ( ! Mopw_Settings::is_enabled() ) {
            return;
        }

        if ( ! Mopw_Settings::get( 'auto_optimize' ) ) {
            return;
        }

        if ( ! Mopw_Media_Handler::is_supported_image( $attachment_id ) ) {
            return;
        }

        if ( '1' === get_post_meta( $attachment_id, '_mopw_optimized', true ) ) {
            return;
        }

        Mopw_Queue::get_instance()->enqueue( $attachment_id );
    }

    public function handle_attachment_deleted( $attachment_id ) {

        // Backups now live in the protected uploads/mopw-backups/ folder
        // under a random filename (see Mopw_Optimizer::create_backup()),
        // recorded via '_mopw_backup_path' postmeta — not derived from
        // the live file's own path anymore, so we look it up directly
        // rather than guessing a "<file>.mopw-bak" suffix that no longer
        // matches how backups are actually named.
        $backup_path = get_post_meta( $attachment_id, '_mopw_backup_path', true );

        if ( ! $backup_path ) {
            return;
        }

        $backup_path = wp_normalize_path( $backup_path );

        if ( Mopw_Media_Handler::is_backup_path_safe( $backup_path ) && file_exists( $backup_path ) ) {
            wp_delete_file( $backup_path );
        }

    }
}