=== Webxperthub Media Optimizer ===
Contributors:      iftiarhossain
Tags:              images, optimize, compress, webp, performance
Requires at least: 6.3
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        1.1.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically compress and convert your images to WebP on upload, with safe background bulk processing for your existing media library.

== Description ==

Webxperthub Media Optimizer optimizes your WordPress media library without slowing down your site or your server. It automatically compresses new uploads and converts them to WebP, and lets you bulk-process your entire existing media library safely in the background.

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

When Webxperthub Media Optimizer is enabled, every new image uploaded to your Media Library is automatically queued for optimization. A background process (WP-Cron) picks up queued images in small batches, so your server is never overloaded even if hundreds of images are queued at once.

Use the **Bulk Optimize** screen under Webxperthub Media Optimizer in your admin menu to queue your entire existing media library and watch a live progress bar as images are processed.

= For Developers =

Skip optimization for specific attachments:

`
add_filter( 'mopw_skip_optimization', function( $skip, $attachment_id ) {
    if ( get_post_meta( $attachment_id, '_never_optimize', true ) ) {
        return true;
    }
    return $skip;
}, 10, 2 );
`

Override format/quality for specific attachments:

`
add_filter( 'mopw_before_optimize_args', function( $args, $attachment_id ) {
    if ( has_term( 'print-quality', 'category', $attachment_id ) ) {
        $args['quality'] = 95;
    }
    return $args;
}, 10, 2 );
`

Run custom logic after an image is optimized:

`
add_action( 'mopw_after_optimize', function( $attachment_id, $original_size, $new_size ) {
    error_log( "Attachment {$attachment_id} shrank from {$original_size} to {$new_size} bytes." );
}, 10, 3 );
`

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/, or install directly through the Plugins screen in your WordPress admin.
2. Activate the plugin.
3. Go to Webxperthub Media Optimizer → Settings to configure quality, output format, and resizing options.
4. Optionally, go to Webxperthub Media Optimizer → Bulk Optimize to process your existing media library.

== Frequently Asked Questions ==

= Will this plugin send my images to an external server? =

No. Webxperthub Media Optimizer uses your server's own Imagick or GD library to process images locally. Nothing is ever uploaded to a third-party service.

= What happens to my original images? =

By default, Webxperthub Media Optimizer keeps a backup copy of your original file in a protected `uploads/mopw-backups/` folder (not web-accessible, and not guessable from the live file's URL) alongside the optimized version. You can disable this in Settings if you don't need it.

= Does deleting the plugin remove my backup files? =

Yes. Uninstalling Webxperthub Media Optimizer removes every backup file it created, along with its settings and internal processing queue.

= Will converting to WebP break links to my images elsewhere? =

If you choose to convert format (WebP/PNG), the file's extension changes — so any content that links directly to the old file's URL (rather than through WordPress's own image blocks/shortcodes, which are updated automatically) may break. This is why the default output mode is "Keep Original Format" (compress only, same filename). Switch to WebP/PNG conversion only if you understand this trade-off.

= Will this slow down my server when bulk-optimizing thousands of images? =

No. Bulk optimization uses a background queue processed in small batches (configurable under Settings → Performance), so your server only ever processes a few images at a time, never all at once.

= Is this compatible with Multisite? =

Yes. Webxperthub Media Optimizer can be activated network-wide, and each site maintains its own independent settings and processing queue.

= What image formats are supported? =

JPEG and PNG sources are supported. Output can be WebP, PNG, or the original format (compression only, no format change).

== Screenshots ==

1. Settings page — configure format, quality, resizing, and performance options
2. Bulk Optimize screen with live progress bar

== Changelog ==

= 1.1.0 =
* Default output format changed to "Keep Original Format" (compress-only) — converting to WebP/PNG changes the file's URL and could break existing links to it, so that behavior is now opt-in rather than default.
* Original-file backups moved to a protected, non-guessable `uploads/mopw-backups/` folder instead of a predictable `<file>.mopw-bak` name next to the live file (which was directly web-accessible).
* Fixed: old-format/old-size thumbnail files were left behind (orphaned) after converting an image's format or restoring an original — they are now cleaned up.
* Fixed: uninstalling now removes backup files and internal run-state options that were previously left behind.
* Fixed: a queue item could get stuck indefinitely if PHP crashed mid-optimization (e.g. out-of-memory on a very large photo); stuck items are now automatically recovered and retried.
* Fixed: large images are now processed with a raised memory limit to reduce the chance of that crash happening in the first place.
* Fixed: a JavaScript error could silently break the "Restore Original" link in the Media Library while a bulk run was in progress.
* Performance: bulk-queuing your whole media library now uses indexed cursor-based pagination instead of OFFSET, which is significantly faster on large libraries.
* Performance: image-processing engine detection (Imagick/GD) is now cached per request instead of being re-run for every image size.

= 1.0.1 =
* Improved compatibility and polish for the initial public release
* Refined WordPress.org plugin metadata and release documentation
* Updated upgrade notice and public listing details for a cleaner submission package

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.1.0 =
Important fixes: safer default (no more broken image links on format conversion), protected backup storage, cleanup of orphaned files, and a stuck-queue-item recovery fix. Recommended for all users.

= 1.0.1 =
Compatibility and publishing polish for the initial public release.