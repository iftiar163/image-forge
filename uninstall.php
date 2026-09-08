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

	// Delete backup files BEFORE the postmeta that points to them is
	// removed below — otherwise '_mopw_backup_path' is gone and every
	// backup in uploads/mopw-backups/ becomes permanently orphaned,
	// undiscoverable disk usage with no UI path to clean it up.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$backup_paths = $wpdb->get_col(
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_mopw_backup_path'"
	);

	foreach ( (array) $backup_paths as $backup_path ) {
		$backup_path = wp_normalize_path( $backup_path );
		if ( $backup_path && file_exists( $backup_path ) ) {
			wp_delete_file( $backup_path );
		}
	}

	// Remove the whole backup directory (and its .htaccess/index.php
	// guards) now that it should be empty.
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
		$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'mopw-backups';
		if ( is_dir( $backup_dir ) ) {
			foreach ( array( 'index.php', '.htaccess' ) as $guard_file ) {
				$guard_path = trailingslashit( $backup_dir ) . $guard_file;
				if ( file_exists( $guard_path ) ) {
					wp_delete_file( $guard_path );
				}
			}
			@rmdir( $backup_dir ); // Only succeeds if empty — safe no-op otherwise.
		}
	}

	delete_option( 'mopw_settings' );
	delete_option( 'mopw_bulk_run_total' );
	delete_option( 'mopw_bulk_run_active' );

	$table_name = $wpdb->prefix . 'mopw_queue';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table_name ) . '`' );

	// Remove optimization flags and size meta from attachments.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_mopw_optimized', '_mopw_original_size', '_mopw_new_size', '_mopw_original_mime', '_mopw_backup_path')"
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
