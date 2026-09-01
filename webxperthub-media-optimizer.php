<?php

/**
 * Plugin Name:       Webxperthub Media Optimizer
 * Plugin URI:        https://wordpress.org/plugins/webxperthub-media-optimizer
 * Description:       Automatically optimizes, compresses, and converts images to WebP on upload, with background bulk processing for your existing media library.
 * Version:           1.0.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Iftiar Hossain
 * Author URI:        https://iftiarhossain.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webxperthub-media-optimizer
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('MOPW_VERSION', '1.0.1');
define('MOPW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MOPW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MOPW_OPTION_KEY', 'mopw_settings');
define('MOPW_QUEUE_TABLE', 'mopw_queue');

require_once MOPW_PLUGIN_DIR . 'includes/class-mopw-settings.php';
require_once MOPW_PLUGIN_DIR . 'includes/class-mopw-media-handler.php';
require_once MOPW_PLUGIN_DIR . 'includes/class-mopw-converter.php';
require_once MOPW_PLUGIN_DIR . 'includes/class-mopw-optimizer.php';
require_once MOPW_PLUGIN_DIR . 'includes/class-mopw-queue.php';
require_once MOPW_PLUGIN_DIR . 'includes/class-mopw-core.php';
require_once MOPW_PLUGIN_DIR . 'admin/class-mopw-admin.php';

register_activation_hook(__FILE__, 'mopw_activate');
register_deactivation_hook(__FILE__, 'mopw_deactivate');

function mopw_activate($network_wide)
{

    $default = [
        'enabled'            => true,
        'auto_optimize'      => true,
        'output_format'      => 'webp',
        'quality'            => 82,
        'keep_original'      => true,
        'max_width'          => 2560,
        'max_height'         => 2560,
        'batch_size'         => 5,
    ];

    if (is_multisite() && $network_wide) {
        $sites = get_sites(['number' => 0, 'fields' => 'ids']);
        foreach ($sites as $site_id) {
            switch_to_blog($site_id);
            mopw_install_site($default);
            restore_current_blog();
        }
    } else {
        mopw_install_site($default);
    }
}

function mopw_install_site($defaults)
{
    if (false === get_option(MOPW_OPTION_KEY)) {
        update_option(MOPW_OPTION_KEY, $defaults);
    }

    global $wpdb;
    $table_name      = $wpdb->prefix . MOPW_QUEUE_TABLE;
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
        KEY status (status),
        KEY status_attachment (status, attachment_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function mopw_deactivate()
{
    // Clear every scheduled occurrence of our cron hook.
    $timestamp = wp_next_scheduled( 'mopw_process_queue' );
    while ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'mopw_process_queue' );
        $timestamp = wp_next_scheduled( 'mopw_process_queue' );
    }
    wp_clear_scheduled_hook( 'mopw_process_queue' );
}

add_action('plugins_loaded', 'mopw_init');

function mopw_init()
{
    Mopw_Queue::get_instance();
    Mopw_Core::get_instance();

    if (is_admin()) {
        Mopw_Admin::get_instance();
    }
}
