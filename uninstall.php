<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the settings option and drops the custom queue table.
 * Multisite-aware: loops every site on the network so no subsite
 * is left with orphaned data.
 *
 * Note: .imgforge-bak backup files created when "Keep Original as
 * Backup" was enabled are intentionally NOT deleted here — scanning
 * an entire uploads directory tree on uninstall risks a timeout on
 * large media libraries. See readme.txt FAQ for manual cleanup steps.
 *
 * @package ImageForge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Per-site cleanup: delete the settings option and drop the queue
 * table for whichever site is currently "active" (via switch_to_blog
 * in the multisite loop below, or the main site on single-site installs).
 */
function imgforge_uninstall_site() {
    global $wpdb;

    delete_option( 'imgforge_settings' );

    $table_name = $wpdb->prefix . 'imgforge_queue';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
}

if ( is_multisite() ) {
    $imgforge_sites = get_sites( array(
        'number' => 0,
        'fields' => 'ids',
    ) );

    foreach ( $imgforge_sites as $imgforge_site_id ) {
        switch_to_blog( $imgforge_site_id );
        imgforge_uninstall_site();
        restore_current_blog();
    }
} else {
    imgforge_uninstall_site();
}