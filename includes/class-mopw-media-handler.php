<?php
/**
 * Media Handler — shared file/path/mime helpers.
 *
 * Pure, stateless helpers used by the Optimizer and Converter classes.
 * No hooks are registered here — this class does nothing on its own.
 *
 * @package WebxperthubMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mopw_Media_Handler {

    /**
     * Returns the absolute file path for an attachment, or false if
     * it doesn't exist on disk or isn't a real image attachment.
     *
     * @param int $attachment_id
     * @return string|false
     */
    public static function get_file_path( $attachment_id ) {
        $path = get_attached_file( $attachment_id );

        if ( ! $path || ! file_exists( $path ) ) {
            return false;
        }

        if ( ! self::is_path_safe( $path ) ) {
            return false;
        }

        return $path;
    }

    /**
     * Confirms a path is actually inside the uploads directory.
     * This is a defense-in-depth check — never trust a file path
     * derived from post meta without verifying it first.
     *
     * @param string $path
     * @return bool
     */
    public static function is_path_safe( $path ) {
        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) ) {
            return false;
        }

        $real_path = realpath( $path );
        $real_base = realpath( $upload_dir['basedir'] );

        if ( false === $real_path || false === $real_base ) {
            return false;
        }

        // strpos check ensures $real_path is truly *inside* $real_base,
        // not just sharing a text prefix (e.g. /uploads-evil vs /uploads).
        return 0 === strpos( $real_path . DIRECTORY_SEPARATOR, $real_base . DIRECTORY_SEPARATOR );
    }

    /**
     * Whether this attachment's mime type is one we're allowed to touch,
     * per the allowed_mime_types setting.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function is_supported_image( $attachment_id ) {
        $mime = get_post_mime_type( $attachment_id );

        if ( ! $mime ) {
            return false;
        }

        $allowed = (array) Mopw_Settings::get( 'allowed_mime_types' );

        return in_array( $mime, $allowed, true );
    }

    /**
     * File size in bytes, or 0 if unreadable.
     *
     * @param string $path
     * @return int
     */
    public static function get_file_size( $path ) {
        if ( ! self::is_path_safe( $path ) ) {
            return 0;
        }

        $size = @filesize( $path );

        return is_int( $size ) ? $size : 0;
    }

    /**
     * Builds the destination path for a converted file by swapping
     * the extension, e.g. photo.jpg -> photo.webp
     *
     * @param string $path
     * @param string $new_extension e.g. 'webp'
     * @return string
     */
    public static function build_converted_path( $path, $new_extension ) {
        $info = pathinfo( $path );
        return $info['dirname'] . '/' . $info['filename'] . '.' . ltrim( $new_extension, '.' );
    }

    /**
     * Detects which image library is available on this server.
     * Imagick generally produces better WebP quality/compression than GD,
     * so we prefer it when present, but GD is nearly universal so it's
     * our safe fallback.
     *
     * @return string 'imagick' | 'gd' | 'none'
     */
    public static function get_image_engine() {
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            $imagick_formats = ( new Imagick() )->queryFormats( 'WEBP' );
            if ( ! empty( $imagick_formats ) ) {
                return 'imagick';
            }
        }

        if ( extension_loaded( 'gd' ) && function_exists( 'imagewebp' ) ) {
            return 'gd';
        }

        return 'none';
    }

    /**
     * Human-readable file size, e.g. "1.2 MB" — used in admin UI.
     *
     * @param int $bytes
     * @return string
     */
    public static function format_bytes( $bytes ) {
        if ( $bytes >= 1048576 ) {
            return round( $bytes / 1048576, 2 ) . ' MB';
        }

        if ( $bytes >= 1024 ) {
            return round( $bytes / 1024, 2 ) . ' KB';
        }

        return $bytes . ' B';
    }
}