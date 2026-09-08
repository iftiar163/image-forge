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
     * Request-level cache for the detected image engine, so we don't
     * re-instantiate Imagick / re-run queryFormats() on every single
     * call — this used to be called once per attachment PLUS once per
     * intermediate size, which is wasteful at bulk scale.
     *
     * @var string|null
     */
    private static $engine_cache = null;

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
        if ( null !== self::$engine_cache ) {
            return self::$engine_cache;
        }

        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            $imagick_formats = ( new Imagick() )->queryFormats( 'WEBP' );
            if ( ! empty( $imagick_formats ) ) {
                return self::$engine_cache = 'imagick';
            }
        }

        if ( extension_loaded( 'gd' ) && function_exists( 'imagewebp' ) ) {
            return self::$engine_cache = 'gd';
        }

        return self::$engine_cache = 'none';
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

    /**
     * Confirms a path is inside our protected backup directory
     * specifically (a stricter check than is_path_safe(), which only
     * confirms "somewhere under uploads"). Used before any read/delete
     * of a stored backup file.
     *
     * @param string $path
     * @return bool
     */
    public static function is_backup_path_safe( $path ) {
        $backup_dir = self::get_backup_dir();

        if ( '' === $backup_dir ) {
            return false;
        }

        $real_path = realpath( $path );
        $real_base = realpath( $backup_dir );

        if ( false === $real_path || false === $real_base ) {
            return false;
        }

        return 0 === strpos( $real_path . DIRECTORY_SEPARATOR, $real_base . DIRECTORY_SEPARATOR );
    }

    /**
     * Deletes every registered intermediate-size file listed in a
     * previously-saved attachment metadata array. Used before we
     * overwrite metadata with a freshly (re)generated set, so that
     * old-format/old-size files don't pile up as orphans on disk.
     *
     * Safe to call with empty/missing metadata — it just no-ops.
     *
     * @param string $base_dir Absolute directory the sizes live in (with trailing slash).
     * @param array  $metadata Attachment metadata (the 'sizes' key is what we need).
     */
    public static function delete_registered_sizes( $base_dir, $metadata ) {
        if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            return;
        }

        foreach ( $metadata['sizes'] as $size_data ) {
            if ( empty( $size_data['file'] ) ) {
                continue;
            }

            $path = $base_dir . $size_data['file'];

            if ( self::is_path_safe( $path ) && file_exists( $path ) ) {
                wp_delete_file( $path );
            }
        }
    }

    /**
     * Directory (inside uploads) where original-file backups are kept.
     * Kept out of the regular year/month upload folders and away from
     * predictable "same name as the live file" URLs — see is_backup_dir_protected().
     *
     * @return string Absolute path, no trailing slash. Empty string on failure.
     */
    public static function get_backup_dir() {
        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
            return '';
        }

        return trailingslashit( $upload_dir['basedir'] ) . 'mopw-backups';
    }

    /**
     * Ensures the backup directory exists and is protected from direct
     * web access (Apache via .htaccess, plus an index.php against
     * directory listing on any server). Idempotent — safe to call often.
     *
     * @return bool
     */
    public static function ensure_backup_dir_protected() {
        $dir = self::get_backup_dir();

        if ( '' === $dir ) {
            return false;
        }

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // "Require all denied" (Apache 2.4) with the 2.2 fallback covers
            // the vast majority of hosts; servers not using Apache at all
            // (e.g. Nginx) are unaffected by this file either way — those
            // hosts should instead rely on the unguessable directory name.
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }

        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        return true;
    }
}