<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Imgforge_Optimizer {

    public static function process( $attachment_id ) {

        if ( ! Imgforge_Settings::is_enabled() ) {
            return array( 'success' => false, 'error' => __( 'Plugin is disabled.', 'image-forge' ) );
        }

        if ( ! Imgforge_Media_Handler::is_supported_image( $attachment_id ) ) {
            return array( 'success' => false, 'error' => __( 'Unsupported mime type.', 'image-forge' ) );
        }

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

    private static function maybe_resize( $source_path ) {
        $max_w = (int) Imgforge_Settings::get( 'max_width' );
        $max_h = (int) Imgforge_Settings::get( 'max_height' );

        $dimensions = @getimagesize( $source_path );
        if ( ! $dimensions ) {
            return array( 'success' => false, 'error' => __( 'Could not read dimensions for resize.', 'image-forge' ) );
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

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata( $attachment_id, $source_path );
    wp_update_attachment_metadata( $attachment_id, $metadata );

    return self::finalize( $attachment_id, $source_path, $original_size );
}

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

        update_attached_file( $attachment_id, $new_path );
        wp_update_post( array(
            'ID'             => $attachment_id,
            'post_mime_type' => $new_mime,
        ) );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $new_path );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        if ( $new_path !== $source_path ) {
            wp_delete_file( $source_path );
        }

        return self::finalize( $attachment_id, $new_path, $original_size );
    }

    private static function finalize( $attachment_id, $final_path, $original_size ) {
        $new_size = Imgforge_Media_Handler::get_file_size( $final_path );

        update_post_meta( $attachment_id, '_imgforge_optimized', '1' );
        update_post_meta( $attachment_id, '_imgforge_original_size', $original_size );
        update_post_meta( $attachment_id, '_imgforge_new_size', $new_size );

        do_action( 'imgforge_after_optimize', $attachment_id, $original_size, $new_size );

        return array( 'success' => true );
    }
}