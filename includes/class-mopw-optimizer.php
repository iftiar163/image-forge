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

		$original_size = Mopw_Media_Handler::get_file_size( $source_path );

		// Always create a backup of the true original before any destructive
		// operation (resize or convert), when the user has requested it.
		if ( Mopw_Settings::get( 'keep_original' ) ) {
			$backup_path = $source_path . '.mopw-bak';
			if ( ! file_exists( $backup_path ) ) {
				if ( ! @copy( $source_path, $backup_path ) ) {
					return array( 'success' => false, 'error' => __( 'Could not create backup copy.', 'webxperthub-media-optimizer' ) );
				}
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

		clean_post_cache( $attachment_id );
		wp_cache_delete( 'mopw_unoptimized_count', 'mopw' );

		do_action( 'mopw_after_optimize', $attachment_id, $original_size, $new_size );

		return array( 'success' => true );
	}
}
