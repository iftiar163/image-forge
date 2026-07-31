<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

function imgforge_uninstall_site() {
    global $wpdb;

    delete_option( 'imgforge_settings' );

    $table_name = $wpdb->prefix . 'imgforge_queue';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $table_name ) );
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