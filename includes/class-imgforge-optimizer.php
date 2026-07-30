<?php
/**
 * Optimizer — orchestrates optimizing a single attachment.
 *
 * Ties together Settings, Media_Handler, and Converter to actually
 * process one attachment ID end-to-end. No hooks registered here —
 * Core and Queue call into this; this class doesn't call itself.
 *
 * @package ImageForge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Imgforge_Optimizer {

    /**
     * Optimizes a single attachment: resize if needed, convert format,
     * regenerate registered sizes, update metadata, optionally back up
     * the original.
     *
     * @param int $attachment_id
     * @return array {
     *     @type bool   $success
     *     @type string $error   Present only on failure.
     * }
     */
    public static function process( $attachment_id ) {

        if ( ! Imgforge_Settings::is_enabled() ) {
            return array( 'success' => false, 'error' => __( 'Plugin is disabled.', 'image-forge' ) );
        }

        if ( ! Imgforge_Media_Handler::is_supported_image( $attachment_id ) ) {
            return array( 'success' => false, 'error' => __( 'Unsupported mime type.', 'image-forge' ) );
        }

        // Let other plugins veto optimization for specific attachments
        // (e.g. a client's "never touch these" media folder plugin).
        $skip = apply_filters( 'imgforge_skip_optimization', false, $attachment_id );
        if ( $skip ) {
            return array( 'success' => false, 'error' => __( 'Skipped via filter.', 'image-forge' ) );
        }

        if ( '1' === get_post_meta( $attachment_id, '_imgforge_optimized', true ) ) {
            return array( 'success' => false, 'error' => __( 'Already optimized.', 'image-forge' ) );
        }

        $source_path = Imgforge_Media_Handler::get_file_path( $attachment_id );

        if ( ! $source_path ) {
            return array( 'success' => false, 'error' => __( 'Source file not found.', 'image-forge' ) );
        }

        $original_size = Imgforge_Media_Handler::get_file_size( $source_path );

        // Allow per-attachment overrides of quality/format via filter,
        // e.g. a developer forcing PNG for a specific post type.
        $args = apply_filters( 'imgforge_before_optimize_args', array(
            'format'  => Imgforge_Settings::get( 'output_format' ),
            'quality' => Imgforge_Settings::get( 'quality' ),
        ), $attachment_id );

        if ( Imgforge_Settings::get( 'resize_large_images' ) ) {
            $resize_result = self::maybe_resize( $source_path );
            if ( ! $resize_result['success'] ) {
                return $resize_result;
            }
        }

        if ( 'original' === $args['format'] ) {
            // User just wants compression, not format conversion.
            return self::compress_in_place( $attachment_id, $source_path, $args['quality'], $original_size );
        }

        return self::convert_and_replace( $attachment_id, $source_path, $args['format'], $args['quality'], $original_size );
    }

    /**
     * Downscales the source file in place if it exceeds max dimensions.
     * Uses WordPress's own image editor abstraction (not Converter),
     * since resizing-in-place is a core WP capability we shouldn't
     * reinvent, and image_editor already handles GD/Imagick internally.
     */
    private static function maybe_resize( $source_path ) {
        $max_w = (int) Imgforge_Settings::get( 'max_width' );
        $max_h = (int) Imgforge_Settings::get( 'max_height' );

        $dimensions = @getimagesize( $source_path );
        if ( ! $dimensions ) {
            return array( 'success' => false, 'error' => __( 'Could not read dimensions for resize.', 'image-forge' ) );
        }

        list( $width, $height ) = $dimensions;

        if ( $width <= $max_w && $height <= $max_h ) {
            return array( 'success' => true ); // No resize needed.
        }

        $editor = wp_get_image_editor( $source_path );

        if ( is_wp_error( $editor ) ) {
            return array( 'success' => false, 'error' => $editor->get_error_message() );
        }

        $editor->resize( $max_w, $max_h, false ); // false = preserve aspect ratio, no crop.
        $saved = $editor->save( $source_path );

        if ( is_wp_error( $saved ) ) {
            return array( 'success' => false, 'error' => $saved->get_error_message() );
        }

        return array( 'success' => true );
    }

    /**
     * "output_format = original" path: just re-compress without
     * changing the file extension or mime type.
     */
    private static function compress_in_place( $attachment_id, $source_path, $quality, $original_size ) {

    $result = Imgforge_Converter::convert(
        $source_path,
        pathinfo( $source_path, PATHINFO_EXTENSION ),
        $quality,
        ! Imgforge_Settings::get( 'preserve_exif' )
    );

    if ( ! $result['success'] ) {
        return $result;
    }

    // Even though the format didn't change, registered sizes (thumbnail,
    // medium, large) were generated from the OLD, uncompressed original.
    // Regenerating here re-creates them from the now-compressed file,
    // so file-size savings apply consistently across every size, not
    // just the main uploaded image.
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata( $attachment_id, $source_path );
    wp_update_attachment_metadata( $attachment_id, $metadata );

    return self::finalize( $attachment_id, $source_path, $original_size );
}

    /**
     * Full conversion path: new format, regenerate all registered
     * image sizes, update metadata + mime type, optionally back up
     * the original file first.
     */
    private static function convert_and_replace( $attachment_id, $source_path, $format, $quality, $original_size ) {

        if ( Imgforge_Settings::get( 'keep_original' ) ) {
            $backup_path = $source_path . '.imgforge-bak';
            if ( ! @copy( $source_path, $backup_path ) ) {
                return array( 'success' => false, 'error' => __( 'Could not create backup copy.', 'image-forge' ) );
            }
        }

        $result = Imgforge_Converter::convert( $source_path, $format, $quality, ! Imgforge_Settings::get( 'preserve_exif' ) );

        if ( ! $result['success'] ) {
            return $result;
        }

        $new_path = $result['path'];
        $new_mime = 'webp' === $format ? 'image/webp' : 'image/png';

        // Point the attachment at the new file and mime type.
        update_attached_file( $attachment_id, $new_path );
        wp_update_post( array(
            'ID'             => $attachment_id,
            'post_mime_type' => $new_mime,
        ) );

        // Regenerate thumbnail/medium/large sizes FROM THE NEW FILE.
        // This is the step most plugins get wrong — skip it and every
        // srcset entry in existing posts silently breaks.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $new_path );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // Remove the old-format original + its old-format registered
        // sizes now that everything points at the new file, UNLESS
        // keep_original is on (in which case we already backed it up
        // above and can safely delete the "live" old copy here too).
        if ( $new_path !== $source_path ) {
            @unlink( $source_path );
        }

        return self::finalize( $attachment_id, $new_path, $original_size );
    }

    /**
     * Shared final bookkeeping: mark as optimized, store size savings
     * for the admin stats display, fire an action for other plugins.
     */
    private static function finalize( $attachment_id, $final_path, $original_size ) {
        $new_size = Imgforge_Media_Handler::get_file_size( $final_path );

        update_post_meta( $attachment_id, '_imgforge_optimized', '1' );
        update_post_meta( $attachment_id, '_imgforge_original_size', $original_size );
        update_post_meta( $attachment_id, '_imgforge_new_size', $new_size );

        /**
         * Fires after an attachment has been successfully optimized.
         *
         * @param int $attachment_id
         * @param int $original_size Bytes.
         * @param int $new_size      Bytes.
         */
        do_action( 'imgforge_after_optimize', $attachment_id, $original_size, $new_size );

        return array( 'success' => true );
    }
}