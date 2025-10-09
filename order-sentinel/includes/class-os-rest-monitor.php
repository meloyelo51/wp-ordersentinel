<?php
defined('ABSPATH') || exit;

if ( ! class_exists('OS_REST_Monitor') ) :

class OS_REST_Monitor {
    const VERSION = '1.0.37';

    public static function init_hooks() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'settings_register']);
    }

    /** Register options (array) with sanitizer */
    public static function settings_register() {
        register_setting('ordersentinel', 'ordersentinel_options', [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
            'default'           => [],
        ]);
    }

    /** Normalize booleans (esp. save_to_meta) */
    public static function sanitize_options( $opt ) {
        if ( ! is_array($opt) ) { $opt = []; }
        $opt['save_to_meta'] = ! empty($opt['save_to_meta']) ? 1 : 0;
        return $opt;
    }

    /** Add WooCommerce → REST Monitor (fallback Tools) */
    public static function admin_menu() {
        $cb    = [ __CLASS__, 'render_page' ];
        $title = 'REST Monitor';

        if ( class_exists( 'WooCommerce' ) ) {
            $cap = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
            add_submenu_page('woocommerce', $title, $title, $cap, 'ordersentinel-rest', $cb);
        } else {
            add_management_page($title, $title, 'manage_options', 'ordersentinel-rest', $cb);
        }
    }

    /** Main page with tabs */
    public static function render_page() {
        if ( ! current_user_can('manage_options') && ! current_user_can('manage_woocommerce') ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'order-sentinel' ) );
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $tabs = [
            'overview' => 'Overview',
            'log'      => 'Recent Requests',
            'settings' => 'Settings',
            'tools'    => 'Tools',
        ];

        echo '<div class="wrap"><h1>OrderSentinel — REST Monitor</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $active = $slug === $tab ? ' nav-tab nav-tab-active' : ' nav-tab';
            $url = add_query_arg(['page' => 'ordersentinel-rest', 'tab' => $slug], admin_url('admin.php'));
            echo '<a class="'.esc_attr($active).'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</h2><div id="osrm-tab">';

        switch ($tab) {
            case 'settings': self::render_settings(); break;
            case 'log':      self::render_log(); break;
            case 'tools':    self::render_tools(); break;
            default:         self::render_overview(); break;
        }

        echo '</div></div>';
    }

    public static function render_overview() { echo '<p>Overview placeholder.</p>'; }
    public static function render_log()      { echo '<p>Log placeholder.</p>'; }
    public static function render_tools()    { echo '<p>Tools placeholder.</p>'; }

    /** Settings tab with the “Store JSON on the order” checkbox that actually persists */
    public static function render_settings() {
        $opt  = get_option('ordersentinel_options', []);
        $save = ! empty($opt['save_to_meta']);

        echo '<form method="post" action="options.php">';
        settings_fields('ordersentinel');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Also save JSON into order meta?</th><td>';
        echo '<input type="hidden" name="ordersentinel_options[save_to_meta]" value="0" />';
        echo '<label><input type="checkbox" name="ordersentinel_options[save_to_meta]" value="1" '.($save ? 'checked="checked"' : '').' /> ';
        echo 'Store JSON on the order (optional)</label>';
        echo '</td></tr>';
        echo '</table>';

        submit_button();
        echo '</form>';
    }
}

OS_REST_Monitor::init_hooks();

endif;
