<?php
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

        if ( '1' === get_post_meta( $attachment_id, '_imgforge_optimized', true ) ) {
            return;
        }

        Imgforge_Queue::get_instance()->enqueue( $attachment_id );
    }

    public function handle_attachment_deleted( $attachment_id ) {

        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path ) {
            return;
        }

        $backup_path = $file_path . '.imgforge-bak';

        if ( Imgforge_Media_Handler::is_path_safe( $backup_path ) && file_exists( $backup_path ) ) {
            wp_delete_file( $backup_path );
        }

    }
}