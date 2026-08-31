<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'MOPW_VERSION' ) ) {
    define( 'MOPW_VERSION', '1.0.0' );
}

if ( ! defined( 'MOPW_PLUGIN_DIR' ) ) {
    define( 'MOPW_PLUGIN_DIR', plugin_dir_path( dirname( __DIR__ ) . '/webxperthub-media-optimizer.php' ) );
}

if ( ! defined( 'MOPW_PLUGIN_URL' ) ) {
    define( 'MOPW_PLUGIN_URL', plugin_dir_url( dirname( __DIR__ ) . '/webxperthub-media-optimizer.php' ) );
}

if ( ! defined( 'MOPW_OPTION_KEY' ) ) {
    define( 'MOPW_OPTION_KEY', 'mopw_settings' );
}

class Mopw_Admin {

    private static $instance = null;
    private $settings_hook;
    private $bulk_hook;

    const SETTINGS_SLUG = 'webxperthub-media-optimizer-settings';
    const BULK_SLUG      = 'webxperthub-media-optimizer-bulk-optimize';

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

        add_filter( 'plugin_action_links_' . plugin_basename( MOPW_PLUGIN_DIR . 'webxperthub-media-optimizer.php' ), array( $this, 'add_settings_link' ) );
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
    }

    public function add_settings_link( $links ) {
        $url  = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
        $link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'webxperthub-media-optimizer' ) . '</a>';
        array_unshift( $links, $link );
        return $links;
    }

    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( $this->settings_hook, $this->bulk_hook ), true ) ) {
            return;
        }

        wp_enqueue_style( 'mopw-admin', MOPW_PLUGIN_URL . 'admin/assets/css/admin.css', array(), MOPW_VERSION );
        wp_enqueue_script( 'mopw-admin', MOPW_PLUGIN_URL . 'admin/assets/js/admin.js', array( 'jquery' ), MOPW_VERSION, true );

        wp_localize_script( 'mopw-admin', 'mopwAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mopw_bulk_nonce' ),
        ) );
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
            'webp'     => __( 'WebP (recommended)', 'webxperthub-media-optimizer' ),
            'png'      => __( 'PNG', 'webxperthub-media-optimizer' ),
            'original' => __( 'Keep Original Format (compress only)', 'webxperthub-media-optimizer' ),
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

        $valid_formats         = array( 'webp', 'png', 'original' );
        $clean['output_format'] = in_array( $input['output_format'] ?? '', $valid_formats, true )
            ? $input['output_format']
            : 'webp';

        $clean['quality']    = max( 1, min( 100, (int) ( $input['quality'] ?? 82 ) ) );
        $clean['max_width']  = max( 100, (int) ( $input['max_width'] ?? 2560 ) );
        $clean['max_height'] = max( 100, (int) ( $input['max_height'] ?? 2560 ) );
        $clean['batch_size'] = max( 1, min( 500, (int) ( $input['batch_size'] ?? 20 ) ) );
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

        $pending = Mopw_Queue::get_instance()->count_pending();
        $queue = Mopw_Queue::get_instance();
        $pending = $queue->count_pending();
        $unoptimized = $queue->count_unoptimized();

        ?>
        <div class="wrap mopw-wrap">
            <h1><?php esc_html_e( 'Bulk Optimize', 'webxperthub-media-optimizer' ); ?></h1>
            <p><?php esc_html_e( 'Queue every un-optimized image in your Media Library for background processing.', 'webxperthub-media-optimizer' ); ?></p>

            <p>
                <?php if ( $pending > 0 ) : ?>
                    <?php
                    printf(
                        /* translators: %d is the number of images currently in the processing queue. */
                        esc_html__( 'Currently processing: %d images remaining in queue.', 'webxperthub-media-optimizer' ),
                        (int) $pending
                    );
                    ?>
                <?php else : ?>
                    <?php
                    printf(
                        /* translators: %d is the number of un-optimized images found in the Media Library. */
                        esc_html__( '%d images in your Media Library have not been optimized yet.', 'webxperthub-media-optimizer' ),
                        (int) $unoptimized
                    );
                    ?>
                <?php endif; ?>
            </p>

            <button type="button" id="mopw-start-bulk" class="button button-primary">
                <?php esc_html_e( 'Start Bulk Optimize', 'webxperthub-media-optimizer' ); ?>
            </button>

            <div id="mopw-progress-wrap" style="display:none; margin-top:20px;">
                <progress id="mopw-progress-bar" value="0" max="100" style="width:100%;"></progress>
                <p id="mopw-progress-text"></p>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: queues every un-optimized attachment. Called once, when
     * the user clicks "Start Bulk Optimize".
     */
    public function ajax_start_bulk() {
        check_ajax_referer( 'mopw_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
        }

        $queued = Mopw_Queue::get_instance()->enqueue_all_unoptimized();

        wp_send_json_success( array( 'queued' => $queued ) );
    }

    /**
     * AJAX: processes exactly one batch. Called repeatedly by JS
     * every ~2 seconds until 'remaining' reaches 0.
     */
    public function ajax_run_batch() {
        check_ajax_referer( 'mopw_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'webxperthub-media-optimizer' ) ), 403 );
        }

        $stats = Mopw_Queue::get_instance()->process_batch();

        wp_send_json_success( $stats );
    }
}