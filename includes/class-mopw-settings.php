<?php
/**
 * Settings helper — single source of truth for all plugin options.
 *
 * @package MediaOptimizerByWebxperthub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mopw_Settings {

    /**
     * Request-level cache so we only merge defaults once per request,
     * no matter how many times get() is called (e.g. inside a bulk loop).
     *
     * @var array|null
     */
    private static $cache = null;

    /**
     * Hardcoded defaults. Adding a new setting later just means adding
     * a key here — existing installs get it automatically via
     * wp_parse_args() without needing a database migration.
     *
     * @var array
     */
    private static $defaults = array(
        'enabled'              => true,   // Master on/off switch
        'auto_optimize'        => true,   // Optimize automatically on upload
        'output_format'        => 'webp', // webp | png | original
        'quality'              => 82,     // 1-100, compression quality
        'keep_original'        => true,   // Keep the original file as backup
        'resize_large_images'  => true,   // Downscale oversized uploads
        'max_width'            => 2560,   // px, only used if resize_large_images
        'max_height'           => 2560,   // px, only used if resize_large_images
        'convert_existing'     => false,  // Bulk tool default: also convert non-optimized older images
        'batch_size'           => 5,      // Images processed per cron tick
        'allowed_mime_types'   => array( 'image/jpeg', 'image/png' ),
        'preserve_exif'        => false,  // Strip EXIF by default (saves space, privacy-friendly)
    );

    /**
     * Get a single setting value.
     *
     * @param string $key Setting key.
     * @return mixed|null Value, or null if the key doesn't exist at all.
     */
    public static function get( $key ) {
        self::maybe_load();
        return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
    }

    /**
     * Get every setting at once — used by the Admin class when
     * rendering the settings form.
     *
     * @return array
     */
    public static function get_all() {
        self::maybe_load();
        return self::$cache;
    }

    /**
     * Convenience boolean check, mirrors Postmediaweb_Settings::is_enabled().
     *
     * @return bool
     */
    public static function is_enabled() {
        return (bool) self::get( 'enabled' );
    }

    /**
     * Populates the cache on first access only.
     */
    private static function maybe_load() {
        if ( null !== self::$cache ) {
            return;
        }

        $saved       = get_option( MOPW_OPTION_KEY, array() );
        self::$cache = wp_parse_args( $saved, self::$defaults );
    }

    /**
     * Forces a cache refresh. Call this right after update_option()
     * inside the same request (e.g. after saving settings via AJAX)
     * so subsequent get() calls in that same request see fresh data.
     */
    public static function flush_cache() {
        self::$cache = null;
    }
}