<?php
/**
 * Optimizer — orchestrates resize, convert/compress, metadata update.
 *
 * @package WebxperthubMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mopw_Optimizer {

	/**
	 * Process a single attachment: optional resize, convert or compress,
	 * update attachment metadata, mark as optimized.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{success:bool, error?:string}
	 */
	public static function process( $attachment_id ) {

		if ( ! Mopw_Settings::is_enabled() ) {
			return array( 'success' => false, 'error' => __( 'Plugin is disabled.', 'webxperthub-media-optimizer' ) );
		}

		if ( ! Mopw_Media_Handler::is_supported_image( $attachment_id ) ) {
			return array( 'success' => false, 'error' => __( 'Unsupported mime type.', 'webxperthub-media-optimizer' ) );
		}

		$skip = apply_filters( 'mopw_skip_optimization', false, $attachment_id );
		if ( $skip ) {
			return array( 'success' => false, 'error' => __( 'Skipped via filter.', 'webxperthub-media-optimizer' ) );
		}

		if ( '1' === get_post_meta( $attachment_id, '_mopw_optimized', true ) ) {
			return array( 'success' => false, 'error' => __( 'Already optimized.', 'webxperthub-media-optimizer' ) );
		}

		$source_path = Mopw_Media_Handler::get_file_path( $attachment_id );

		if ( ! $source_path ) {
			return array( 'success' => false, 'error' => __( 'Source file not found.', 'webxperthub-media-optimizer' ) );
		}

		// Large photos (20-40MP+) can blow past the default PHP memory_limit
		// inside Imagick/GD, causing a hard fatal that no try/catch inside
		// this request can stop. Ask WordPress for its "image" memory
		// ceiling (filterable via image_memory_limit) before touching any
		// image data, to make that fatal far less likely.
		wp_raise_memory_limit( 'image' );

		// Some hosts cap script execution independently of our own batch
		// time budget. 0 = unlimited; many hosts disable set_time_limit()
		// entirely in safe-mode-like configs, so this is best-effort and
		// silenced so it never throws a warning that derails processing.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$original_size = Mopw_Media_Handler::get_file_size( $source_path );

		// Always create a backup of the true original before any destructive
		// operation (resize or convert), when the user has requested it.
		if ( Mopw_Settings::get( 'keep_original' ) ) {
			$backup_result = self::create_backup( $attachment_id, $source_path );
			if ( ! $backup_result['success'] ) {
				return $backup_result;
			}
		}

		$args = apply_filters(
			'mopw_before_optimize_args',
			array(
				'format'  => Mopw_Settings::get( 'output_format' ),
				'quality' => Mopw_Settings::get( 'quality' ),
			),
			$attachment_id
		);

		if ( Mopw_Settings::get( 'resize_large_images' ) ) {
			$resize_result = self::maybe_resize( $source_path );
			if ( ! $resize_result['success'] ) {
				return $resize_result;
			}
		}

		if ( 'original' === $args['format'] ) {
			return self::compress_in_place( $attachment_id, $source_path, $args['quality'], $original_size );
		}

		return self::convert_and_replace( $attachment_id, $source_path, $args['format'], $args['quality'], $original_size );
	}

	/**
	 * Copies the true original into a protected, unpredictably-named
	 * backup location and records where it lives in postmeta.
	 *
	 * Previously this wrote "<file>.mopw-bak" next to the live file —
	 * directly web-accessible at a guessable URL, which defeated the
	 * point of stripping EXIF/GPS data from the public copy. Backups now
	 * live in an .htaccess-protected uploads/mopw-backups/ folder under
	 * a random, non-guessable filename.
	 *
	 * @param int    $attachment_id
	 * @param string $source_path
	 * @return array{success:bool, error?:string}
	 */
	private static function create_backup( $attachment_id, $source_path ) {

		// Reuse an existing backup if one's already recorded and present —
		// don't overwrite a true original with an already-optimized copy
		// on a re-run.
		$existing_backup = get_post_meta( $attachment_id, '_mopw_backup_path', true );
		if ( $existing_backup && file_exists( $existing_backup ) && Mopw_Media_Handler::is_backup_path_safe( $existing_backup ) ) {
			return array( 'success' => true );
		}

		if ( ! Mopw_Media_Handler::ensure_backup_dir_protected() ) {
			error_log( 'MOPW: Could not create/protect backup directory for attachment ' . $attachment_id );
			return array( 'success' => false, 'error' => __( 'Could not prepare backup directory.', 'webxperthub-media-optimizer' ) );
		}

		$backup_dir  = Mopw_Media_Handler::get_backup_dir();
		$ext         = pathinfo( $source_path, PATHINFO_EXTENSION );
		$random_name = $attachment_id . '-' . wp_generate_password( 20, false, false ) . ( $ext ? '.' . $ext : '' );
		$backup_path = wp_normalize_path( trailingslashit( $backup_dir ) . $random_name );

		$copy_result = @copy( $source_path, $backup_path );
		if ( ! $copy_result || ! file_exists( $backup_path ) ) {
			error_log( 'MOPW: Backup copy failed for ' . $attachment_id . '. Source: ' . $source_path . ', Backup: ' . $backup_path );
			return array( 'success' => false, 'error' => __( 'Could not create backup copy.', 'webxperthub-media-optimizer' ) );
		}

		// Record exactly where the backup lives, so restore_original()
		// never has to guess based on a filename that may change after
		// format conversion (e.g. photo.jpg -> photo.webp).
		$meta_result = update_post_meta( $attachment_id, '_mopw_backup_path', $backup_path );
		if ( ! $meta_result ) {
			error_log( 'MOPW: Failed to update backup path meta for ' . $attachment_id . '. Path: ' . $backup_path );
		}

		return array( 'success' => true );
	}

	/**
	 * Downscale the file in place if it exceeds configured max dimensions.
	 *
	 * @param string $source_path Absolute path.
	 * @return array{success:bool, error?:string}
	 */
	private static function maybe_resize( $source_path ) {
		$max_w = (int) Mopw_Settings::get( 'max_width' );
		$max_h = (int) Mopw_Settings::get( 'max_height' );

		$dimensions = @getimagesize( $source_path );
		if ( ! $dimensions ) {
			return array( 'success' => false, 'error' => __( 'Could not read dimensions for resize.', 'webxperthub-media-optimizer' ) );
		}

		list( $width, $height ) = $dimensions;

		if ( $width <= $max_w && $height <= $max_h ) {
			return array( 'success' => true );
		}

		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return array( 'success' => false, 'error' => $editor->get_error_message() );
		}

		$editor->resize( $max_w, $max_h, false );
		$saved = $editor->save( $source_path );

		if ( is_wp_error( $saved ) ) {
			return array( 'success' => false, 'error' => $saved->get_error_message() );
		}

		return array( 'success' => true );
	}

	/**
	 * Compress without changing format (re-encode in place).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_path   Absolute path.
	 * @param int    $quality       1-100.
	 * @param int    $original_size Bytes before optimization.
	 * @return array{success:bool, error?:string}
	 */
	private static function compress_in_place( $attachment_id, $source_path, $quality, $original_size ) {

		$ext = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
		$format_map = array(
			'jpg'  => 'jpeg',
			'jpeg' => 'jpeg',
			'png'  => 'png',
			'webp' => 'webp',
		);
		$target = isset( $format_map[ $ext ] ) ? $format_map[ $ext ] : $ext;

		$result = Mopw_Converter::convert(
			$source_path,
			$target,
			$quality,
			! Mopw_Settings::get( 'preserve_exif' )
		);

		if ( ! $result['success'] ) {
			return $result;
		}

		$final_path = ! empty( $result['path'] ) ? $result['path'] : $source_path;

		if ( $final_path !== $source_path && file_exists( $final_path ) ) {
			if ( ! @rename( $final_path, $source_path ) ) {
				@copy( $final_path, $source_path );
				wp_delete_file( $final_path );
			}
			$final_path = $source_path;
		}

		// Same filename/extension throughout, so the old intermediate
		// sizes are simply overwritten in place by wp_generate_attachment_metadata()
		// below — no orphaned files to clean up in the compress-only path.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $final_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return self::finalize( $attachment_id, $final_path, $original_size );
	}

	/**
	 * Convert to WebP/PNG, update attachment file + mime, regenerate sizes.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_path   Absolute path (may already be resized).
	 * @param string $format        'webp' or 'png'.
	 * @param int    $quality       1-100.
	 * @param int    $original_size Bytes of the true original (pre-resize).
	 * @return array{success:bool, error?:string}
	 */
	private static function convert_and_replace( $attachment_id, $source_path, $format, $quality, $original_size ) {

		$result = Mopw_Converter::convert( $source_path, $format, $quality, ! Mopw_Settings::get( 'preserve_exif' ) );

		if ( ! $result['success'] ) {
			return $result;
		}

		$new_path = $result['path'];
		$new_mime = ( 'webp' === $format ) ? 'image/webp' : 'image/png';

		$original_mime = get_post_mime_type( $attachment_id );
		update_post_meta( $attachment_id, '_mopw_original_mime', $original_mime );

		// The attachment is changing filename/extension (photo.jpg ->
		// photo.webp), so its OLD registered intermediate sizes
		// (photo-150x150.jpg etc.) will become orphaned once new ones are
		// generated under the new extension. Capture the old metadata now
		// so we can delete those old size files after the new ones exist.
		$old_metadata = wp_get_attachment_metadata( $attachment_id );
		$old_base_dir = trailingslashit( dirname( $source_path ) );

		update_attached_file( $attachment_id, $new_path );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $new_mime,
			)
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $new_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Re-encode intermediate sizes at the configured quality.
		self::optimize_intermediate_sizes( $attachment_id, $metadata, $format, $quality );

		// Now that the new-format sizes exist and metadata points to them,
		// it's safe to remove the old-format size files so they don't sit
		// around as orphaned disk usage forever.
		Mopw_Media_Handler::delete_registered_sizes( $old_base_dir, $old_metadata );

		if ( $new_path !== $source_path && file_exists( $source_path ) ) {
			wp_delete_file( $source_path );
		}

		return self::finalize( $attachment_id, $new_path, $original_size );
	}

	/**
	 * Re-encode intermediate size files at the plugin quality setting.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $metadata      Attachment metadata.
	 * @param string $format        Output format key.
	 * @param int    $quality       1-100.
	 */
	private static function optimize_intermediate_sizes( $attachment_id, $metadata, $format, $quality ) {
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return;
		}

		$base_dir = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );

		foreach ( $metadata['sizes'] as $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}

			$size_path = $base_dir . $size_data['file'];

			if ( ! Mopw_Media_Handler::is_path_safe( $size_path ) || ! file_exists( $size_path ) ) {
				continue;
			}

			$ext = strtolower( pathinfo( $size_path, PATHINFO_EXTENSION ) );
			$fmt = ( 'webp' === $ext ) ? 'webp' : ( ( 'png' === $ext ) ? 'png' : $format );

			$conv = Mopw_Converter::convert( $size_path, $fmt, $quality, ! Mopw_Settings::get( 'preserve_exif' ) );

			if ( ! empty( $conv['success'] ) && ! empty( $conv['path'] ) && $conv['path'] !== $size_path && file_exists( $conv['path'] ) ) {
				if ( ! @rename( $conv['path'], $size_path ) ) {
					@copy( $conv['path'], $size_path );
					wp_delete_file( $conv['path'] );
				}
			}
		}
	}

	/**
	 * Store optimization meta and fire action.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $final_path    Path to optimized file.
	 * @param int    $original_size Original byte size.
	 * @return array{success:bool}
	 */
	private static function finalize( $attachment_id, $final_path, $original_size ) {
		$new_size = Mopw_Media_Handler::get_file_size( $final_path );

		update_post_meta( $attachment_id, '_mopw_optimized', '1' );
		update_post_meta( $attachment_id, '_mopw_original_size', $original_size );
		update_post_meta( $attachment_id, '_mopw_new_size', $new_size );

		$saved_bytes = max( 0, $original_size - $new_size );
    	self::increment_lifetime_stats( 1, $saved_bytes );

		clean_post_cache( $attachment_id );
		wp_cache_delete( 'mopw_unoptimized_count', 'mopw' );

		do_action( 'mopw_after_optimize', $attachment_id, $original_size, $new_size );

		return array( 'success' => true );
	}

	private static function increment_lifetime_stats( $images_delta, $bytes_delta ) {
		global $wpdb;

		// Ensure both option rows exist first (autoloaded, since these
		// are read on every Bulk Optimize page load).
		if ( false === get_option( 'mopw_lifetime_images_optimized' ) ) {
			add_option( 'mopw_lifetime_images_optimized', 0 );
		}
		if ( false === get_option( 'mopw_lifetime_bytes_saved' ) ) {
			add_option( 'mopw_lifetime_bytes_saved', 0 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = 'mopw_lifetime_images_optimized'",
			$images_delta
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = 'mopw_lifetime_bytes_saved'",
			$bytes_delta
		) );

		wp_cache_delete( 'mopw_lifetime_images_optimized', 'options' );
		wp_cache_delete( 'mopw_lifetime_bytes_saved', 'options' );
	}

	/**
	 * Restores an attachment to its pre-optimization state using the
	 * backup file. Reverses everything convert_and_replace() did: file,
	 * mime type, and registered sizes — including cleaning up the
	 * optimized-format size files so they don't linger as orphans.
	 *
	 * @param int $attachment_id
	 * @return array{success:bool, error?:string}
	 */

	public static function restore_original( $attachment_id ) {

		if ( '1' !== get_post_meta( $attachment_id, '_mopw_optimized', true ) ) {
        	return array( 'success' => false, 'error' => __( 'This image was not optimized by this plugin.', 'webxperthub-media-optimizer' ) );
		}

		$current_path = Mopw_Media_Handler::get_file_path( $attachment_id );
		if ( ! $current_path ) {
			return array( 'success' => false, 'error' => __( 'Current file not found.', 'webxperthub-media-optimizer' ) );
		}

		$backup_path = wp_normalize_path( get_post_meta( $attachment_id, '_mopw_backup_path', true ) );

		if ( ! $backup_path ) {
			error_log( 'MOPW: Restore failed - no backup path meta for attachment ' . $attachment_id );
			return array( 'success' => false, 'error' => __( 'No backup file found for this image.', 'webxperthub-media-optimizer' ) );
		}

		if ( ! file_exists( $backup_path ) ) {
			error_log( 'MOPW: Restore failed - backup file does not exist: ' . $backup_path );
			return array( 'success' => false, 'error' => __( 'No backup file found for this image.', 'webxperthub-media-optimizer' ) );
		}

		if ( ! Mopw_Media_Handler::is_backup_path_safe( $backup_path ) ) {
			return array( 'success' => false, 'error' => __( 'Backup path failed safety check.', 'webxperthub-media-optimizer' ) );
		}

		// Capture the currently-registered (optimized-format) sizes so we
		// can delete them once the restore's fresh metadata is in place —
		// otherwise e.g. photo-150x150.webp lingers forever after restoring
		// back to photo-150x150.jpg.
		$old_metadata = wp_get_attachment_metadata( $attachment_id );
		$old_base_dir = trailingslashit( dirname( $current_path ) );

		$original_mime = get_post_meta( $attachment_id, '_mopw_original_mime', true );

		if ( $original_mime ) {
			// Format was converted — restore under the ORIGINAL extension
			// and mime type, since the current file has a different one.
			$ext_map = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/webp' => 'webp',
			);
			$original_ext  = isset( $ext_map[ $original_mime ] ) ? $ext_map[ $original_mime ] : 'jpg';
			$restored_path = Mopw_Media_Handler::build_converted_path( $current_path, $original_ext );

			if ( ! @copy( $backup_path, $restored_path ) ) {
				return array( 'success' => false, 'error' => __( 'Could not restore backup file.', 'webxperthub-media-optimizer' ) );
			}

			update_attached_file( $attachment_id, $restored_path );
			wp_update_post( array(
				'ID'             => $attachment_id,
				'post_mime_type' => $original_mime,
			) );

			if ( $restored_path !== $current_path && file_exists( $current_path ) ) {
				wp_delete_file( $current_path );
			}
		} else {
			// Compress-only — same path, same mime, just overwrite the
			// compressed bytes with the original backup bytes.
			$restored_path = $current_path;

			if ( ! @copy( $backup_path, $restored_path ) ) {
				return array( 'success' => false, 'error' => __( 'Could not restore backup file.', 'webxperthub-media-optimizer' ) );
			}
		}

		// Regenerate registered sizes from the restored file either way.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $restored_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Now that the restored-format sizes exist, remove the old
		// optimized-format size files left over from before the restore.
		if ( $original_mime ) {
			Mopw_Media_Handler::delete_registered_sizes( $old_base_dir, $old_metadata );
		}

		wp_delete_file( $backup_path );
		delete_post_meta( $attachment_id, '_mopw_optimized' );
		delete_post_meta( $attachment_id, '_mopw_original_size' );
		delete_post_meta( $attachment_id, '_mopw_new_size' );
		delete_post_meta( $attachment_id, '_mopw_original_mime' );
		delete_post_meta( $attachment_id, '_mopw_backup_path' );

		clean_post_cache( $attachment_id );
		wp_cache_delete( 'mopw_unoptimized_count', 'mopw' );

		// Decrement lifetime stats — this image's savings no longer exist
		// once restored, so lifetime totals shouldn't keep counting them.
		$reverted_original_size = (int) get_post_meta( $attachment_id, '_mopw_original_size', true );
		$reverted_new_size      = (int) get_post_meta( $attachment_id, '_mopw_new_size', true );
		$reverted_saved_bytes   = max( 0, $reverted_original_size - $reverted_new_size );

		self::increment_lifetime_stats( -1, -$reverted_saved_bytes );

		do_action( 'mopw_after_restore', $attachment_id );

		return array( 'success' => true );
	}

	/**
	 * Re-optimizes an already-optimized attachment using CURRENT settings.
	 *
	 * Internally, this restores the true original first (so we're never
	 * re-compressing an already-lossy WebP/PNG, which would compound
	 * quality loss), then runs a normal optimization pass on that clean
	 * original. Reuses restore_original() + process() rather than a
	 * separate code path, so any future fix to either automatically
	 * benefits re-optimization too.
	 *
	 * @param int $attachment_id
	 * @return array{success:bool, error?:string}
	 */
	public static function reoptimize( $attachment_id ) {

		if ( '1' !== get_post_meta( $attachment_id, '_mopw_optimized', true ) ) {
			return array( 'success' => false, 'error' => __( 'This image has not been optimized yet — use Bulk Optimize instead.', 'webxperthub-media-optimizer' ) );
		}

		$restore_result = self::restore_original( $attachment_id );

		if ( ! $restore_result['success'] ) {
			// If restore fails (e.g. the backup no longer exists), we
			// cannot safely re-optimize — doing so from the current,
			// already-lossy file would compound quality loss further,
			// so we stop here rather than proceeding on a bad foundation.
			return array(
				'success' => false,
				/* translators: %s is the underlying restore error message. */
				'error'   => sprintf( __( 'Could not re-optimize: %s', 'webxperthub-media-optimizer' ), $restore_result['error'] ),
			);
		}

		// restore_original() already cleared _mopw_optimized, so process()
		// runs exactly as it would on a fresh, never-optimized image.
		return self::process( $attachment_id );
	}
}
