<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'IMGFORGE_VERSION' ) ) {
    define( 'IMGFORGE_VERSION', '1.0.0' );
}

if ( ! defined( 'IMGFORGE_PLUGIN_DIR' ) ) {
    define( 'IMGFORGE_PLUGIN_DIR', plugin_dir_path( dirname( __DIR__ ) . '/image-forge.php' ) );
}

if ( ! defined( 'IMGFORGE_PLUGIN_URL' ) ) {
    define( 'IMGFORGE_PLUGIN_URL', plugin_dir_url( dirname( __DIR__ ) . '/image-forge.php' ) );
}

if ( ! defined( 'IMGFORGE_OPTION_KEY' ) ) {
    define( 'IMGFORGE_OPTION_KEY', 'imgforge_settings' );
}

class Imgforge_Admin {

    private static $instance = null;
    private $settings_hook;
    private $bulk_hook;

    const SETTINGS_SLUG = 'image-forge-settings';
    const BULK_SLUG      = 'image-forge-bulk-optimize';

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

        add_action( 'wp_ajax_imgforge_start_bulk', array( $this, 'ajax_start_bulk' ) );
        add_action( 'wp_ajax_imgforge_run_batch', array( $this, 'ajax_run_batch' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( IMGFORGE_PLUGIN_DIR . 'image-forge.php' ), array( $this, 'add_settings_link' ) );
    }

    public function add_menu() {
    $this->settings_hook = add_menu_page(
        __( 'Image Forge', 'image-forge' ),
        __( 'Image Forge', 'image-forge' ),
        'manage_options',
        self::SETTINGS_SLUG,
        array( $this, 'render_settings_page' ),
        'dashicons-images-alt2'
    );

    add_submenu_page(
        self::SETTINGS_SLUG,
        __( 'Settings', 'image-forge' ),
        __( 'Settings', 'image-forge' ),
        'manage_options',
        self::SETTINGS_SLUG,
        array( $this, 'render_settings_page' )
    );

    $this->bulk_hook = add_submenu_page(
        self::SETTINGS_SLUG,
        __( 'Bulk Optimize', 'image-forge' ),
        __( 'Bulk Optimize', 'image-forge' ),
        'manage_options',
        self::BULK_SLUG,
        array( $this, 'render_bulk_page' )
    );
}

    public function add_settings_link( $links ) {
        $url  = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
        $link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'image-forge' ) . '</a>';
        array_unshift( $links, $link );
        return $links;
    }

    public function enqueue_assets( $hook ) {
    if ( ! in_array( $hook, array( $this->settings_hook, $this->bulk_hook ), true ) ) {
        return;
    }

    wp_enqueue_style( 'imgforge-admin', IMGFORGE_PLUGIN_URL . 'admin/assets/css/admin.css', array(), IMGFORGE_VERSION );
    wp_enqueue_script( 'imgforge-admin', IMGFORGE_PLUGIN_URL . 'admin/assets/js/admin.js', array( 'jquery' ), IMGFORGE_VERSION, true );

    wp_localize_script( 'imgforge-admin', 'imgforgeAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'imgforge_bulk_nonce' ),
    ) );
}

    public function register_settings() {
        register_setting( 'imgforge_settings_group', IMGFORGE_OPTION_KEY, array( $this, 'sanitize_settings' ) );

        add_settings_section( 'imgforge_general', __( 'General', 'image-forge' ), '__return_empty_string', self::SETTINGS_SLUG );

        add_settings_field( 'enabled', __( 'Enable Plugin', 'image-forge' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'imgforge_general', array( 'key' => 'enabled' ) );
        add_settings_field( 'auto_optimize', __( 'Auto-Optimize on Upload', 'image-forge' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'imgforge_general', array( 'key' => 'auto_optimize' ) );

        add_settings_section( 'imgforge_compression', __( 'Compression', 'image-forge' ), '__return_empty_string', self::SETTINGS_SLUG );

        add_settings_field( 'output_format', __( 'Output Format', 'image-forge' ), array( $this, 'field_select_format' ), self::SETTINGS_SLUG, 'imgforge_compression' );
        add_settings_field( 'quality', __( 'Quality', 'image-forge' ), array( $this, 'field_quality_slider' ), self::SETTINGS_SLUG, 'imgforge_compression' );
        add_settings_field( 'keep_original', __( 'Keep Original as Backup', 'image-forge' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'imgforge_compression', array( 'key' => 'keep_original' ) );
        add_settings_field( 'preserve_exif', __( 'Preserve Image Metadata (EXIF)', 'image-forge' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'imgforge_compression', array( 'key' => 'preserve_exif' ) );

        add_settings_section( 'imgforge_resize', __( 'Resizing', 'image-forge' ), '__return_empty_string', self::SETTINGS_SLUG );

        add_settings_field( 'resize_large_images', __( 'Resize Oversized Uploads', 'image-forge' ), array( $this, 'field_checkbox' ), self::SETTINGS_SLUG, 'imgforge_resize', array( 'key' => 'resize_large_images' ) );
        add_settings_field( 'max_width', __( 'Max Width (px)', 'image-forge' ), array( $this, 'field_number' ), self::SETTINGS_SLUG, 'imgforge_resize', array( 'key' => 'max_width' ) );
        add_settings_field( 'max_height', __( 'Max Height (px)', 'image-forge' ), array( $this, 'field_number' ), self::SETTINGS_SLUG, 'imgforge_resize', array( 'key' => 'max_height' ) );

        add_settings_section( 'imgforge_performance', __( 'Performance', 'image-forge' ), '__return_empty_string', self::SETTINGS_SLUG );

        add_settings_field( 'batch_size', __( 'Images Per Batch', 'image-forge' ), array( $this, 'field_number' ), self::SETTINGS_SLUG, 'imgforge_performance', array( 'key' => 'batch_size' ) );
    }

    public function field_checkbox( $args ) {
        $key = $args['key'];
        $val = Imgforge_Settings::get( $key );
        printf(
            '<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s>',
            esc_attr( IMGFORGE_OPTION_KEY ),
            esc_attr( $key ),
            checked( $val, true, false )
        );
    }

    public function field_number( $args ) {
        $key = $args['key'];
        $val = absint( Imgforge_Settings::get( $key ) );

        printf(
            '<input type="number" name="%1$s[%2$s]" value="%3$s" min="1" style="width:100px;">',
            esc_attr( IMGFORGE_OPTION_KEY ),
            esc_attr( $key ),
            esc_attr( (string) $val )
        );
    }

    public function field_select_format() {
        $current = Imgforge_Settings::get( 'output_format' );
        $options = array(
            'webp'     => __( 'WebP (recommended)', 'image-forge' ),
            'png'      => __( 'PNG', 'image-forge' ),
            'original' => __( 'Keep Original Format (compress only)', 'image-forge' ),
        );

        echo '<select name="' . esc_attr( IMGFORGE_OPTION_KEY ) . '[output_format]">';
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
        $val = absint( Imgforge_Settings::get( 'quality' ) );

        printf(
            '<input type="range" min="1" max="100" name="%1$s[quality]" value="%2$s" oninput="this.nextElementSibling.innerText=this.value"> <output>%3$s</output>',
            esc_attr( IMGFORGE_OPTION_KEY ),
            esc_attr( (string) $val ),
            esc_html( (string) $val )
        );
        echo '<p class="description">' . esc_html__( '82 is a good balance of size vs. quality for most sites.', 'image-forge' ) . '</p>';
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
        $existing = Imgforge_Settings::get_all();
        $clean    = wp_parse_args( $clean, $existing );

        Imgforge_Settings::flush_cache();

        return $clean;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap imgforge-wrap">
            <h1><?php esc_html_e( 'Image Forge Settings', 'image-forge' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'imgforge_settings_group' );
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

        $pending = Imgforge_Queue::get_instance()->count_pending();
        ?>
        <div class="wrap imgforge-wrap">
            <h1><?php esc_html_e( 'Bulk Optimize', 'image-forge' ); ?></h1>
            <p><?php esc_html_e( 'Queue every un-optimized image in your Media Library for background processing.', 'image-forge' ); ?></p>

            <p>
                <?php
                printf(
                    /* translators: %d is the number of images currently queued and pending. */
                    esc_html__( 'Currently pending: %d', 'image-forge' ),
                    (int) $pending
                );
                ?>
            </p>

            <button type="button" id="imgforge-start-bulk" class="button button-primary">
                <?php esc_html_e( 'Start Bulk Optimize', 'image-forge' ); ?>
            </button>

            <div id="imgforge-progress-wrap" style="display:none; margin-top:20px;">
                <progress id="imgforge-progress-bar" value="0" max="100" style="width:100%;"></progress>
                <p id="imgforge-progress-text"></p>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: queues every un-optimized attachment. Called once, when
     * the user clicks "Start Bulk Optimize".
     */
    public function ajax_start_bulk() {
        check_ajax_referer( 'imgforge_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'image-forge' ) ), 403 );
        }

        $queued = Imgforge_Queue::get_instance()->enqueue_all_unoptimized();

        wp_send_json_success( array( 'queued' => $queued ) );
    }

    /**
     * AJAX: processes exactly one batch. Called repeatedly by JS
     * every ~2 seconds until 'remaining' reaches 0.
     */
    public function ajax_run_batch() {
        check_ajax_referer( 'imgforge_bulk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'image-forge' ) ), 403 );
        }

        $stats = Imgforge_Queue::get_instance()->process_batch();

        wp_send_json_success( $stats );
    }
}