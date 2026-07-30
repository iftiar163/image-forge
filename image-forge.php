<?php
/**
 * Plugin Name:       Image Forge
 * Plugin URI:        https://example.com/image-forge
 * Description:       Automatically optimizes, compresses, and converts images to WebP on upload, with background bulk processing for your existing media library.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Iftiar Hossain
 * Author URI:        https://iftiarhossain.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-forge
 * Domain Path:       /languages
 *
 * @package ImageForge
 */

defined('ABSPATH') || exit;

define( 'IMGFORGE_VERSION', '1.0.0' );
define( 'IMGFORGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMGFORGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IMGFORGE_OPTION_KEY', 'imgforge_settings' );
define( 'IMGFORGE_QUEUE_TABLE', 'imgforge_queue' );

require_once IMGFORGE_PLUGIN_DIR . 'includes/class-imgforge-settings.php';
require_once IMGFORGE_PLUGIN_DIR . 'includes/class-imgforge-media-handler.php';
require_once IMGFORGE_PLUGIN_DIR . 'includes/class-imgforge-converter.php';
require_once IMGFORGE_PLUGIN_DIR . 'includes/class-imgforge-optimizer.php';
require_once IMGFORGE_PLUGIN_DIR . 'includes/class-imgforge-queue.php';
require_once IMGFORGE_PLUGIN_DIR . 'includes/class-imgforge-core.php';
require_once IMGFORGE_PLUGIN_DIR . 'admin/class-imgforge-admin.php';

register_activation_hook( __FILE__, 'imgforge_activate' );
register_deactivation_hook( __FILE__, 'imgforge_deactivate' );

function imgforge_activate( $network_wide ) {

    $default = [
        'enabled'            => true,
        'auto_optimize'      => true,
        'output_format'      => 'webp', // webp | png | original
        'quality'            => 82,
        'keep_original'      => true,
        'max_width'          => 2560,
        'max_height'         => 2560,
        'batch_size'         => 5,
    ];

    if( is_multisite() && $network_wide ) {
        $sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
        foreach( $sites as $site_id ) {
            switch_to_blog( $site_id );
            imgforge_install_site( $default );
            restore_current_blog();
        }
    } else {
            imgforge_install_site( $default );
        }
  
}

function imgforge_install_site( $defaults ) {
    if ( false === get_option( IMGFORGE_OPTION_KEY ) ) {
        update_option( IMGFORGE_OPTION_KEY, $defaults );
    }

    global $wpdb;
    $table_name      = $wpdb->prefix . IMGFORGE_QUEUE_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        attachment_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY attachment_id (attachment_id),
        KEY status (status)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function imgforge_deactivate() {
    $timestamps = wp_next_scheduled( 'imgforge_process_queue' );
    if( $timestamps) {
        wp_unschedule_event( $timestamps, 'imgforge_process_queue' );
    }
}

add_action( 'plugins_loaded', 'imgforge_init' );

function imgforge_init() {

}