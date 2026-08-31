<?php
/**
 * Uninstall — remove options, queue table, and optimization post meta.
 *
 * @package WebxperthubMediaOptimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function mopw_uninstall_site() {
	global $wpdb;

	delete_option( 'mopw_settings' );

	$table_name = $wpdb->prefix . 'mopw_queue';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' );

	// Remove optimization flags and size meta from attachments.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_mopw_optimized', '_mopw_original_size', '_mopw_new_size')"
	);

	// Clear any leftover transients.
	delete_transient( 'mopw_batch_lock' );
}

if ( is_multisite() ) {
	$mopw_sites = get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	);

	foreach ( $mopw_sites as $mopw_site_id ) {
		switch_to_blog( $mopw_site_id );
		mopw_uninstall_site();
		restore_current_blog();
	}
} else {
	mopw_uninstall_site();
}
