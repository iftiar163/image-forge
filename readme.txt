=== Image Forge ===
Contributors:      yourwporgusername
Tags:              images, optimize, compress, webp, performance
Requires at least: 6.3
Tested up to:      6.6
Requires PHP:      7.4
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically compress and convert your images to WebP on upload, with safe background bulk processing for your existing media library.

== Description ==

Image Forge optimizes your WordPress media library without slowing down your site or your server. It automatically compresses new uploads and converts them to WebP, and lets you bulk-process your entire existing media library safely in the background.

= Key Features =

* Automatic optimization on upload — no manual steps required
* Converts images to WebP (or PNG, or compress-only with original format kept)
* Typically reduces file size by 40-60% with no noticeable quality loss
* Background queue processing — bulk-optimize thousands of images without server timeouts
* Optional resizing of oversized uploads to a configurable maximum width/height
* Keeps a backup of your original files by default
* Fully multisite compatible
* Uses your server's native Imagick or GD library — no external API, no data ever leaves your server
* Developer-friendly filter hooks for custom workflows

= How It Works =

When Image Forge is enabled, every new image uploaded to your Media Library is automatically queued for optimization. A background process (WP-Cron) picks up queued images in small batches, so your server is never overloaded even if hundreds of images are queued at once.

Use the **Bulk Optimize** screen under Image Forge in your admin menu to queue your entire existing media library and watch a live progress bar as images are processed.

= For Developers =

Skip optimization for specific attachments:

`
add_filter( 'imgforge_skip_optimization', function( $skip, $attachment_id ) {
    if ( get_post_meta( $attachment_id, '_never_optimize', true ) ) {
        return true;
    }
    return $skip;
}, 10, 2 );
`

Override format/quality for specific attachments:

`
add_filter( 'imgforge_before_optimize_args', function( $args, $attachment_id ) {
    if ( has_term( 'print-quality', 'category', $attachment_id ) ) {
        $args['quality'] = 95;
    }
    return $args;
}, 10, 2 );
`

Run custom logic after an image is optimized:

`
add_action( 'imgforge_after_optimize', function( $attachment_id, $original_size, $new_size ) {
    error_log( "Attachment {$attachment_id} shrank from {$original_size} to {$new_size} bytes." );
}, 10, 3 );
`

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/, or install directly through the Plugins screen in your WordPress admin.
2. Activate the plugin.
3. Go to Image Forge → Settings to configure quality, output format, and resizing options.
4. Optionally, go to Image Forge → Bulk Optimize to process your existing media library.

== Frequently Asked Questions ==

= Will this plugin send my images to an external server? =

No. Image Forge uses your server's own Imagick or GD library to process images locally. Nothing is ever uploaded to a third-party service.

= What happens to my original images? =

By default, Image Forge keeps a backup copy of your original file (with an `.imgforge-bak` extension) alongside the optimized version. You can disable this in Settings if you don't need it.

= Does deleting the plugin remove my backup files? =

No. Uninstalling Image Forge removes its settings and internal processing queue, but does not scan your uploads folder for `.imgforge-bak` files, to avoid a slow or timed-out uninstall on large media libraries. If you want to remove them, you can safely delete any file ending in `.imgforge-bak` from your uploads folder.

= Will this slow down my server when bulk-optimizing thousands of images? =

No. Bulk optimization uses a background queue processed in small batches (configurable under Settings → Performance), so your server only ever processes a few images at a time, never all at once.

= Is this compatible with Multisite? =

Yes. Image Forge can be activated network-wide, and each site maintains its own independent settings and processing queue.

= What image formats are supported? =

JPEG and PNG sources are supported. Output can be WebP, PNG, or the original format (compression only, no format change).

== Screenshots ==

1. Settings page — configure format, quality, resizing, and performance options
2. Bulk Optimize screen with live progress bar

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release