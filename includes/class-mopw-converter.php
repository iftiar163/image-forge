<?php
/**
 * Converter — handles format conversion (WebP/PNG) at the file level.
 *
 * Works purely with file paths, not attachment IDs. No hooks registered.
 *
 * @package WebxperthubMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mopw_Converter {

    /**
     * Converts a source image file to the target format.
     *
     * @param string $source_path   Absolute path to the source image.
     * @param string $target_format 'webp' or 'png'.
     * @param int    $quality       1-100.
     * @param bool   $strip_exif    Whether to strip metadata.
     * @return array {
     *     @type bool   $success
     *     @type string $path    Path to the new file (on success).
     *     @type string $error   Error message (on failure).
     * }
     */
    public static function convert( $source_path, $target_format, $quality, $strip_exif = true ) {

        if ( ! Mopw_Media_Handler::is_path_safe( $source_path ) ) {
            return array(
                'success' => false,
                'error'   => __( 'Source path failed safety check.', 'webxperthub-media-optimizer' ),
            );
        }

        $target_format = in_array( $target_format, array( 'webp', 'png' ), true ) ? $target_format : 'webp';
        $quality       = max( 1, min( 100, (int) $quality ) );
        $dest_path     = Mopw_Media_Handler::build_converted_path( $source_path, $target_format );

        $engine = Mopw_Media_Handler::get_image_engine();

        if ( 'imagick' === $engine ) {
            return self::convert_with_imagick( $source_path, $dest_path, $target_format, $quality, $strip_exif );
        }

        if ( 'gd' === $engine ) {
            return self::convert_with_gd( $source_path, $dest_path, $target_format, $quality, $strip_exif );
        }

        return array(
            'success' => false,
            'error'   => __( 'No supported image processing library found on this server.', 'webxperthub-media-optimizer' ),
        );
    }

    /**
     * Imagick conversion path — generally better compression/quality
     * than GD, so used whenever available.
     */
    private static function convert_with_imagick( $source_path, $dest_path, $target_format, $quality, $strip_exif ) {
    try {
        $image = new Imagick( $source_path );

        // Only animated sources (animated GIF/WebP) have more than one
        // frame. Coalescing/deconstructing a normal single-frame photo
        // is unnecessary and not what those methods are for — so we
        // gate this behind an actual frame count check.
        if ( $image->getNumberImages() > 1 ) {
            $image = $image->coalesceImages();
            $image = $image->deconstructImages();
        }

        $image->setImageFormat( strtoupper( $target_format ) );
        $image->setImageCompressionQuality( $quality );

        if ( $strip_exif ) {
            $image->stripImage();
        }

        $result = $image->writeImage( $dest_path );

        $image->clear();
        $image->destroy();

        if ( ! $result ) {
            return array(
                'success' => false,
                'error'   => __( 'Imagick failed to write the converted image.', 'webxperthub-media-optimizer' ),
            );
        }

        return array( 'success' => true, 'path' => $dest_path );

    } catch ( Exception $e ) {
        return array(
            'success' => false,
            // translators: %s is the underlying library error message.
            'error'   => sprintf( __( 'Imagick error: %s', 'webxperthub-media-optimizer' ), $e->getMessage() ),
        );
    }
}

    /**
     * GD conversion path — the universal fallback.
     */
    private static function convert_with_gd( $source_path, $dest_path, $target_format, $quality, $strip_exif ) {

        $image_info = @getimagesize( $source_path );

        if ( false === $image_info ) {
            return array(
                'success' => false,
                'error'   => __( 'Could not read image dimensions.', 'webxperthub-media-optimizer' ),
            );
        }

        $mime = $image_info['mime'];

        $source_image = self::create_gd_resource_from_file( $source_path, $mime );

        if ( ! $source_image ) {
            return array(
                'success' => false,
                'error'   => __( 'Unsupported source format for GD.', 'webxperthub-media-optimizer' ),
            );
        }

        // Preserve transparency for PNG sources so it doesn't turn black
        // on WebP output — GD defaults to black background otherwise.
        imagepalettetotruecolor( $source_image );
        imagealphablending( $source_image, true );
        imagesavealpha( $source_image, true );

        // GD has no direct "strip EXIF" call — EXIF data lives in the
        // JPEG file structure itself, and re-encoding via GD's image
        // functions naturally drops it, since GD never reads or
        // re-writes those APP1 segments in the first place.
        unset( $strip_exif ); // Intentionally unused in the GD path — see note above.

        $success = false;

        if ( 'webp' === $target_format && function_exists( 'imagewebp' ) ) {
            $success = imagewebp( $source_image, $dest_path, $quality );
        } elseif ( 'png' === $target_format ) {
            // GD's PNG quality scale is 0 (best) to 9 (most compressed) —
            // inverse of our 1-100 scale, so we convert it here.
            $png_quality = (int) round( ( 100 - $quality ) / 100 * 9 );
            $success     = imagepng( $source_image, $dest_path, $png_quality );
        }

        imagedestroy( $source_image );

        if ( ! $success ) {
            return array(
                'success' => false,
                'error'   => __( 'GD failed to write the converted image.', 'webxperthub-media-optimizer' ),
            );
        }

        return array( 'success' => true, 'path' => $dest_path );
    }

    /**
     * Creates a GD image resource from a file based on its real mime type.
     *
     * @param string $path
     * @param string $mime
     * @return \GdImage|resource|false
     */
    private static function create_gd_resource_from_file( $path, $mime ) {
        switch ( $mime ) {
            case 'image/jpeg':
                return @imagecreatefromjpeg( $path );
            case 'image/png':
                return @imagecreatefrompng( $path );
            case 'image/webp':
                return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
            default:
                return false;
        }
    }
}