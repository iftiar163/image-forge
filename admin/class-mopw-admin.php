<?php
/**
 * Admin UI — settings page, bulk optimize screen, AJAX handlers.
 *
 * @package WebxperthubMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mopw_Admin {

	private static $instance = null;
	private $settings_hook;
	private $bulk_hook;
	private $failed_hook;

	const SETTINGS_SLUG = 'webxperthub-media-optimizer-settings';
	const BULK_SLUG     = 'webxperthub-media-optimizer-bulk-optimize';
	const FAILED_SLUG = 'webxperthub-media-optimizer-failed';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_mopw_start_bulk', array( $this, 'ajax_start_bulk' ) );
		add_action( 'wp_ajax_mopw_run_batch', array( $this, 'ajax_run_batch' ) );
		add_action( 'wp_ajax_mopw_cancel_bulk', array( $this, 'ajax_cancel_bulk' ) );

		add_filter( 'media_row_actions', array( $this, 'add_restore_row_action'), 10, 2 );
		add_action( 'wp_ajax_mopw_restore_original', array( $this, 'ajax_restore_original' ) );
		add_action( 'wp_ajax_mopw_reoptimize', array( $this, 'ajax_reoptimize' ) );
		
		add_filter(
			'plugin_action_links_' . plugin_basename( MOPW_PLUGIN_DIR . 'webxperthub-media-optimizer.php' ),
			array( $this, 'add_settings_link' )
		);

		add_filter( 'manage_media_columns', array( $this, 'add_media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'manage_upload_sortable_columns', array( $this, 'add_sortable_media_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_media_column_sorting' ) );

		add_action( 'wp_ajax_mopw_retry_failed', array( $this, 'ajax_retry_failed' ) );
		add_action( 'wp_ajax_mopw_dismiss_failed', array( $this, 'ajax_dismiss_failed' ) );
	}

	public function ajax_retry_failed() {
		check_ajax_referer( 'mopw_bulk_nonce', 'nonce' );

		if( ! current_user_can( 'manage_options' ) ){
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
		}

		$row_id = isset( $_POST['row_id'] ) ? absint( $_POST[ 'row_id' ] ) : 0;
		$ok     = Mopw_Queue::get_instance()->retry_failed_row( $row_id );

		if( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not retry this image.', 'webxperthub-media-optimizer' ) ) );
		}

		wp_send_json_success();
	}

	public function ajax_dismiss_failed() {
		check_admin_referer('mopw_bulk_nonce', 'nonce');

		if( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
		}

		$row_id = isset( $_POST['row_id'] ) ? absint( $_POST['row_id'] ) : 0;
		$ok     = Mopw_Queue::get_instance()->dismiss_failed_row( $row_id );

		if( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not dismiss this entry.', 'webxperthub-media-optimizer' ) ) );
		}

		wp_send_json_success();
	}



	/**
	 * Registers our custom "Optimization" column in the Media Library
	 * list view, placed right after the default "Author" column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */

	public function add_media_columns( $columns ) {
		$columns['mopw_savings'] = __( 'Optimization', 'webxperthub-media-optimizer' );
		return $columns;
	}

	/**
	 * Renders the content of our column for each attachment row.
	 *
	 * @param string $column_name Current column being rendered.
	 * @param int    $attachment_id
	 */

	public function render_media_column( $column_name, $attachment_id ) {

		if( 'mopw_savings' !== $column_name ) {
			return;
		}

		if( '1' !== get_post_meta( $attachment_id, '_mopw_optimized', true ) ) {
			echo '<span class="mopw-column-not-optimized">' . esc_html__( 'Not optimized', 'webxperthub-media-optimizer' ) . '</span>';
        	return;
		}

		$original_size = (int) get_post_meta( $attachment_id, '_mopw_original_size', true );
		$new_size      = (int) get_post_meta( $attachment_id, '_mopw_new_size', true );

		if( $original_size <= 0 || $new_size <= 0 ) {
			 echo '<span class="mopw-column-not-optimized">' . esc_html__( '—', 'webxperthub-media-optimizer' ) . '</span>';
            return;
		}

		$saved_bytes   = $original_size - $new_size;
		$saved_percent = round( ( $saved_bytes / $original_size ) * 100 );

		printf(
			'<div class="mopw-savings-cell">
				<span class="mopw-savings-percent">-%1$s%%</span>
				<span class="mopw-savings-detail">%2$s → %3$s</span>
			</div>',
			esc_html( $saved_percent ),
			esc_html( Mopw_Media_Handler::format_bytes( $original_size ) ),
			esc_html( Mopw_Media_Handler::format_bytes( $new_size ) )
		);
	}

	/**
	 * Marks our column as sortable. WordPress passes the value we set
	 * here ('mopw_savings') through as $_GET['orderby'] when clicked.
	 *
	 * @param array $columns
	 * @return array
	 */

	public function add_sortable_media_columns( $columns ) {
		$columns['mopw_savings'] = 'mopw_savings';
		return $columns;
	}

	public function handle_media_column_sorting( $query ) {

		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'mopw_savings' !== $query->get( 'orderby' ) ) {
			return;
		}

		$meta_key = apply_filters( 'mopw_savings_sort_meta_key', '_mopw_new_size' );

		$query->set( 'meta_key', $meta_key );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Adds a "Restore Original" link to the Media Library list view row actions for optimized images.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    The attachment post.
	 * @return array
	 */

	public function add_restore_row_action( $actions, $post ) {

		if( '1' !== get_post_meta( $post->ID, '_mopw_optimized', true ) ) {
			return $actions;
		}

		$restore_nonce    = wp_create_nonce( 'mopw_restore_' . $post->ID );
		$reoptimize_nonce = wp_create_nonce( 'mopw_reoptimize_' . $post->ID );

		$actions['mopw_restore'] = sprintf(
			'<a href="#" class="mopw-restore-link" data-attachment-id="%1$d" data-nonce="%2$s">%3$s</a>',
			(int) $post->ID,
			esc_attr( $restore_nonce ),
			esc_html__( 'Restore Original', 'webxperthub-media-optimizer' )
    	);

		$actions['mopw_reoptimize'] = sprintf(
			'<a href="#" class="mopw-reoptimize-link" data-attachment-id="%1$d" data-nonce="%2$s">%3$s</a>',
			(int) $post->ID,
			esc_attr( $reoptimize_nonce ),
			esc_html__( 'Re-optimize', 'webxperthub-media-optimizer' )
		);

		return $actions;
	}

	/**
	 * AJAX: restores a single attachment to its pre-optimization state.
	 */
	public function ajax_restore_original() {

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		check_ajax_referer( 'mopw_restore_' . $attachment_id, 'nonce' );

		if( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
		}

		$result = Mopw_Optimizer::restore_original( $attachment_id );

		if( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success( array( 'message' => __( 'Original image restored successfully.', 'webxperthub-media-optimizer' ) ) );

	}

	/**
	 * AJAX: re-optimizes a single attachment using current settings.
	 */
	public function ajax_reoptimize() {

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		check_ajax_referer( 'mopw_reoptimize_' . $attachment_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
		}

		$result = Mopw_Optimizer::reoptimize( $attachment_id );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success( array( 'message' => __( 'Image re-optimized successfully.', 'webxperthub-media-optimizer' ) ) );
	}

	/**
	 * AJAX: cancels the in-progress bulk run.
	 */
	public function ajax_cancel_bulk() {
		check_ajax_referer( 'mopw_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
		}

		$cancelled = Mopw_Queue::get_instance()->cancel_all_pending();
		Mopw_Queue::get_instance()->end_run();

		wp_send_json_success( array( 'cancelled' => $cancelled ) );
	}

	public function add_menu() {
		$this->settings_hook = add_menu_page(
			__( 'Webxperthub Media Optimizer', 'webxperthub-media-optimizer' ),
			__( 'Media Optimizer', 'webxperthub-media-optimizer' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-images-alt2'
		);

		add_submenu_page(
			self::SETTINGS_SLUG,
			__( 'Settings', 'webxperthub-media-optimizer' ),
			__( 'Settings', 'webxperthub-media-optimizer' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);

		$this->bulk_hook = add_submenu_page(
			self::SETTINGS_SLUG,
			__( 'Bulk Optimize', 'webxperthub-media-optimizer' ),
			__( 'Bulk Optimize', 'webxperthub-media-optimizer' ),
			'manage_options',
			self::BULK_SLUG,
			array( $this, 'render_bulk_page' )
		);

		$this->failed_hook = add_submenu_page(
			self::SETTINGS_SLUG,
			__( 'Failed Images', 'webxperthub-media-optimizer' ),
			__( 'Failed Images', 'webxperthub-media-optimizer' ),
			'manage_options',
			self::FAILED_SLUG,
			array( $this, 'render_failed_page' )
		);
	}

	public function render_failed_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failed_rows = Mopw_Queue::get_instance()->get_failed_rows();
		?>
		<div class="wrap mopw-wrap">
			<h1><?php esc_html_e( 'Failed Images', 'webxperthub-media-optimizer' ); ?></h1>

			<?php if ( empty( $failed_rows ) ) : ?>
				<p><?php esc_html_e( 'No failed images — everything processed successfully.', 'webxperthub-media-optimizer' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d is the number of images that failed optimization. */
						esc_html__( '%d image(s) could not be optimized after multiple attempts.', 'webxperthub-media-optimizer' ),
						count( $failed_rows )
					);
					?>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:50px;"></th>
							<th><?php esc_html_e( 'Image', 'webxperthub-media-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Error', 'webxperthub-media-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'webxperthub-media-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Last Attempt', 'webxperthub-media-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'webxperthub-media-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $failed_rows as $row ) : ?>
							<tr data-row-id="<?php echo esc_attr( $row['id'] ); ?>">
								<td>
									<?php if ( $row['thumbnail_url'] ) : ?>
										<img src="<?php echo esc_url( $row['thumbnail_url'] ); ?>" width="40" height="40" alt="">
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
								</td>
								<td><?php echo esc_html( $row['error_message'] ); ?></td>
								<td><?php echo esc_html( $row['attempts'] ); ?></td>
								<td><?php echo esc_html( $row['updated_at'] ); ?></td>
								<td>
									<button type="button" class="button mopw-retry-failed" data-row-id="<?php echo esc_attr( $row['id'] ); ?>">
										<?php esc_html_e( 'Retry', 'webxperthub-media-optimizer' ); ?>
									</button>
									<button type="button" class="button mopw-dismiss-failed" data-row-id="<?php echo esc_attr( $row['id'] ); ?>">
										<?php esc_html_e( 'Dismiss', 'webxperthub-media-optimizer' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function add_settings_link( $links ) {
		$url  = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'webxperthub-media-optimizer' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	public function enqueue_assets( $hook ) {
		
		$our_pages = array( $this->settings_hook, $this->bulk_hook, $this->failed_hook, 'upload.php' );

		if( ! in_array( $hook, $our_pages, true ) ) {
			return;
		}

		wp_enqueue_style( 'mopw-admin', MOPW_PLUGIN_URL . 'admin/assets/css/admin.css', array(), MOPW_VERSION );
		wp_enqueue_script( 'mopw-admin', MOPW_PLUGIN_URL . 'admin/assets/js/admin.js', array( 'jquery' ), MOPW_VERSION, true );

		$unoptimized_count = ( $hook === $this->bulk_hook )
			? Mopw_Queue::get_instance()->count_unoptimized()
			: 0;

		$queue = Mopw_Queue::get_instance();

		wp_localize_script(
			'mopw-admin',
			'mopwAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'mopw_bulk_nonce' ),
				'unoptimizedCount' => Mopw_Queue::get_instance()->count_unoptimized(),
				'runActive'        => $queue->is_run_active(),
				'runTotal'         => $queue->get_run_total(),
				'i18n'             => array(
					'noImages'       => __( 'No images to optimize — your media library is already up to date.', 'webxperthub-media-optimizer' ),
					'somethingWrong' => __( 'Something went wrong.', 'webxperthub-media-optimizer' ),
					'couldNotReach'  => __( 'Could not reach the server. Please try again.', 'webxperthub-media-optimizer' ),
					'queued'         => __( 'Queued %d images. Processing…', 'webxperthub-media-optimizer' ),
					'processed'      => __( 'Processed %1$d of %2$d (%3$d failed this batch)', 'webxperthub-media-optimizer' ),
					'done'           => __( 'Done! %d images processed.', 'webxperthub-media-optimizer' ),
					'retrying'       => __( 'Lost connection during processing. Retrying…', 'webxperthub-media-optimizer' ),
					'cancelConfirm'  => __( 'Stop the current optimization run? Images already processed will keep their optimized version — only remaining images will be skipped.', 'webxperthub-media-optimizer' ),
					'cancelling'     => __( 'Cancelling…', 'webxperthub-media-optimizer' ),
					'cancelled'      => __( 'Cancelled. Already-optimized images were kept; the rest were skipped.', 'webxperthub-media-optimizer' ),
					'cancelFailed'   => __( 'Could not cancel — please try again.', 'webxperthub-media-optimizer' ),
					'startLabel'     => __( 'Start Bulk Optimize', 'webxperthub-media-optimizer' ),
					'cancelLabel'    => __( 'Cancel', 'webxperthub-media-optimizer' ),
				),
			)
		);
	}

	public function register_settings() {
		register_setting( 'mopw_settings_group', MOPW_OPTION_KEY, array( $this, 'sanitize_settings' ) );

		add_settings_section( 'mopw_general', __( 'General', 'webxperthub-media-optimizer' ), '__return_empty_string', self::SETTINGS_SLUG );

		add_settings_field( 'enabled', __( 'Enable Plugin', 'webxperthub-media-optimizer' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'mopw_general', array( 'key' => 'enabled' ) );
		add_settings_field( 'auto_optimize', __( 'Auto-Optimize on Upload', 'webxperthub-media-optimizer' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'mopw_general', array( 'key' => 'auto_optimize' ) );

		add_settings_section( 'mopw_compression', __( 'Compression', 'webxperthub-media-optimizer' ), '__return_empty_string', self::SETTINGS_SLUG );

		add_settings_field( 'output_format', __( 'Output Format', 'webxperthub-media-optimizer' ), array( $this, 'field_select_format' ), self::SETTINGS_SLUG, 'mopw_compression' );
		add_settings_field( 'quality', __( 'Quality', 'webxperthub-media-optimizer' ), array( $this, 'field_quality_slider' ), self::SETTINGS_SLUG, 'mopw_compression' );
		add_settings_field( 'keep_original', __( 'Keep Original as Backup', 'webxperthub-media-optimizer' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'mopw_compression', array( 'key' => 'keep_original' ) );
		add_settings_field( 'preserve_exif', __( 'Preserve Image Metadata (EXIF)', 'webxperthub-media-optimizer' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'mopw_compression', array( 'key' => 'preserve_exif' ) );

		add_settings_section( 'mopw_resize', __( 'Resizing', 'webxperthub-media-optimizer' ), '__return_empty_string', self::SETTINGS_SLUG );

		add_settings_field( 'resize_large_images', __( 'Resize Oversized Uploads', 'webxperthub-media-optimizer' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'mopw_resize', array( 'key' => 'resize_large_images' ) );
		add_settings_field( 'max_width', __( 'Max Width (px)', 'webxperthub-media-optimizer' ), array( $this, 'field_number' ), self::SETTINGS_SLUG, 'mopw_resize', array( 'key' => 'max_width' ) );
		add_settings_field( 'max_height', __( 'Max Height (px)', 'webxperthub-media-optimizer' ), array( $this, 'field_number' ), self::SETTINGS_SLUG, 'mopw_resize', array( 'key' => 'max_height' ) );

		add_settings_section( 'mopw_performance', __( 'Performance', 'webxperthub-media-optimizer' ), '__return_empty_string', self::SETTINGS_SLUG );

		add_settings_field( 'batch_size', __( 'Images Per Batch', 'webxperthub-media-optimizer' ), array( $this, 'field_number' ), self::SETTINGS_SLUG, 'mopw_performance', array( 'key' => 'batch_size' ) );
	}

	public function field_checkbox( $args ) {
		$key = $args['key'];
		$val = Mopw_Settings::get( $key );
		printf(
			'<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s>',
			esc_attr( MOPW_OPTION_KEY ),
			esc_attr( $key ),
			checked( $val, true, false )
		);
	}

	public function field_number( $args ) {
		$key = $args['key'];
		$val = absint( Mopw_Settings::get( $key ) );

		printf(
			'<input type="number" name="%1$s[%2$s]" value="%3$s" min="1" style="width:100px;">',
			esc_attr( MOPW_OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( (string) $val )
		);
	}

	public function field_select_format() {
		$current = Mopw_Settings::get( 'output_format' );
		$options = array(
			'original' => __( 'Keep Original Format (compress only, recommended)', 'webxperthub-media-optimizer' ),
			'webp'     => __( 'Convert to WebP', 'webxperthub-media-optimizer' ),
			'png'      => __( 'Convert to PNG', 'webxperthub-media-optimizer' ),
		);

		echo '<select name="' . esc_attr( MOPW_OPTION_KEY ) . '[output_format]">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Converting format changes the file extension/URL. Any content that already links directly to the old file (rather than through WordPress\'s own image tags) may break. "Keep Original Format" avoids this.', 'webxperthub-media-optimizer' ) . '</p>';
	}

	public function field_quality_slider() {
		$val = absint( Mopw_Settings::get( 'quality' ) );

		printf(
			'<input type="range" min="1" max="100" name="%1$s[quality]" value="%2$s" oninput="this.nextElementSibling.innerText=this.value"> <output>%3$s</output>',
			esc_attr( MOPW_OPTION_KEY ),
			esc_attr( (string) $val ),
			esc_html( (string) $val )
		);
		echo '<p class="description">' . esc_html__( '82 is a good balance of size vs. quality for most sites.', 'webxperthub-media-optimizer' ) . '</p>';
	}

	public function sanitize_settings( $input ) {
		$clean = array();

		$clean['enabled']             = ! empty( $input['enabled'] );
		$clean['auto_optimize']       = ! empty( $input['auto_optimize'] );
		$clean['keep_original']       = ! empty( $input['keep_original'] );
		$clean['preserve_exif']       = ! empty( $input['preserve_exif'] );
		$clean['resize_large_images'] = ! empty( $input['resize_large_images'] );

		$valid_formats          = array( 'webp', 'png', 'original' );
		$clean['output_format'] = in_array( $input['output_format'] ?? '', $valid_formats, true )
			? $input['output_format']
			: 'original';

		$clean['quality']    = max( 1, min( 100, (int) ( $input['quality'] ?? 82 ) ) );
		$clean['max_width']  = max( 100, (int) ( $input['max_width'] ?? 2560 ) );
		$clean['max_height'] = max( 100, (int) ( $input['max_height'] ?? 2560 ) );
		$clean['batch_size'] = max( 1, min( 50, (int) ( $input['batch_size'] ?? 5 ) ) );

		$existing = Mopw_Settings::get_all();
		$clean    = wp_parse_args( $clean, $existing );

		Mopw_Settings::flush_cache();

		return $clean;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap mopw-wrap">
			<h1><?php esc_html_e( 'Webxperthub Media Optimizer Settings', 'webxperthub-media-optimizer' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'mopw_settings_group' );
				do_settings_sections( self::SETTINGS_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_bulk_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $queue        = Mopw_Queue::get_instance();
    $pending      = $queue->count_pending();
    $unoptimized  = $queue->count_unoptimized();
    $run_active   = $queue->is_run_active();
    $run_total    = $queue->get_run_total();
	$lifetime      = $queue->get_lifetime_stats();
    ?>
    <div class="wrap mopw-wrap">
        <h1><?php esc_html_e( 'Bulk Optimize', 'webxperthub-media-optimizer' ); ?></h1>
		<?php if ( $lifetime['images'] > 0 ) : ?>
            <div class="mopw-lifetime-stats">
                <div class="mopw-stat-box">
                    <span class="mopw-stat-number"><?php echo esc_html( number_format_i18n( $lifetime['images'] ) ); ?></span>
                    <span class="mopw-stat-label"><?php esc_html_e( 'Images Optimized', 'webxperthub-media-optimizer' ); ?></span>
                </div>
                <div class="mopw-stat-box">
                    <span class="mopw-stat-number"><?php echo esc_html( Mopw_Media_Handler::format_bytes( $lifetime['bytes'] ) ); ?></span>
                    <span class="mopw-stat-label"><?php esc_html_e( 'Total Space Saved', 'webxperthub-media-optimizer' ); ?></span>
                </div>
            </div>
        <?php endif; ?>
        <p><?php esc_html_e( 'Queue every un-optimized image in your Media Library for background processing.', 'webxperthub-media-optimizer' ); ?></p>

        <p id="mopw-status-text">
            <?php if ( $pending > 0 ) : ?>
                <?php
                printf(
                    esc_html__( 'Currently processing: %d images remaining in queue.', 'webxperthub-media-optimizer' ),
                    (int) $pending
                );
                ?>
            <?php else : ?>
                <?php
                printf(
                    esc_html__( '%d images in your Media Library have not been optimized yet.', 'webxperthub-media-optimizer' ),
                    (int) $unoptimized
                );
                ?>
            <?php endif; ?>
        </p>

        <button type="button" id="mopw-start-bulk" class="button button-primary" <?php disabled( $run_active ); ?>>
            <?php esc_html_e( 'Start Bulk Optimize', 'webxperthub-media-optimizer' ); ?>
        </button>

        <button type="button" id="mopw-cancel-bulk" class="button" style="<?php echo $run_active ? '' : 'display:none;'; ?>">
            <?php esc_html_e( 'Cancel', 'webxperthub-media-optimizer' ); ?>
        </button>

        <div id="mopw-progress-wrap" style="<?php echo $run_active ? '' : 'display:none;'; ?> margin-top:20px;">
            <progress id="mopw-progress-bar" value="<?php echo (int) ( $run_total - $pending ); ?>" max="<?php echo (int) $run_total; ?>" style="width:100%;"></progress>
            <p id="mopw-progress-text">
                <?php
                if ( $run_active ) {
                    printf(
                        /* translators: 1: completed count, 2: total count */
                        esc_html__( 'Resuming: %1$d of %2$d processed so far…', 'webxperthub-media-optimizer' ),
                        (int) ( $run_total - $pending ),
                        (int) $run_total
                    );
                }
                ?>
            </p>
        </div>
    </div>
    <?php
	}

	/**
	 * AJAX: queues every un-optimized attachment.
	 */
	public function ajax_start_bulk() {
		check_ajax_referer( 'mopw_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
		}

		$queue  = Mopw_Queue::get_instance();
		$queued = $queue->enqueue_all_unoptimized();

		if ( $queued > 0 ) {
			$queue->start_run( $queued );
		}

		wp_send_json_success( array( 'queued' => $queued ) );
	}

	/**
	 * AJAX: processes exactly one batch.
	 */
	public function ajax_run_batch() {
		check_ajax_referer( 'mopw_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
		}

		$queue = Mopw_Queue::get_instance();
		$stats = $queue->process_batch();

		if ( $stats['remaining'] <= 0 ) {
			$queue->end_run();
		}

		wp_send_json_success( $stats );
	}
}
