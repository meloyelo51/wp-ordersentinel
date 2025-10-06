<?php
defined('ABSPATH') || exit;

/**
 * Bootstrap loader for OrderSentinel REST monitor (baked-in).
 */
add_action('plugins_loaded', function () {
    $class_file   = __DIR__ . '/class-os-rest-monitor.php';
    $capture_file = __DIR__ . '/os-rest-capture.php';

    if ( file_exists( $class_file ) ) {
        require_once $class_file;
    }
    if ( file_exists( $capture_file ) ) {
        require_once $capture_file;
    }
    if ( class_exists('OS_REST_Monitor') && method_exists('OS_REST_Monitor','init') ) {
        OS_REST_Monitor::init();
    }
}, 1);

add_action('admin_menu', array('OS_REST_Monitor','admin_menu'));
add_action('admin_init', array('OS_REST_Monitor','settings_register'));
// --- Ensure checkboxes exist as '0' on read (prevents defaults from re-checking) ---
if ( ! function_exists('ordersentinel_fill_missing_checkboxes') ) {
    function ordersentinel_fill_missing_checkboxes( $v ) {
        if ( ! is_array( $v ) ) { $v = array(); }
        $keys = array(
            'save_to_meta','store_json','json_on_order',
            'trust_proxies','verify_search_dns',
            'rest_log_enabled','throttle_rest','throttle_search',
            'drop_on_uninstall'
        );
        foreach ( $keys as $k ) {
            if ( ! array_key_exists( $k, $v ) ) { $v[$k] = '0'; }
        }
        return $v;
    }
}
foreach ( array('ordersentinel_options','ordersentinel','order_sentinel','order_sentinel_options') as $__name ) {
    add_filter( "option_{\$__name}", 'ordersentinel_fill_missing_checkboxes', 5, 1 );
}
// --- Normalize checkboxes on save (unchecked => '0') ---
if ( ! function_exists('ordersentinel_normalize_checkboxes') ) {
    function ordersentinel_normalize_checkboxes( $v ) {
        if ( ! is_array( $v ) ) { return $v; }
        $keys = array(
            'save_to_meta','store_json','json_on_order',
            'trust_proxies','verify_search_dns',
            'rest_log_enabled','throttle_rest','throttle_search',
            'drop_on_uninstall'
        );
        foreach ( $keys as $k ) {
            if ( array_key_exists( $k, $v ) ) {
                $v[$k] = ( isset($v[$k]) && (string)$v[$k] === '1' ) ? '1' : '0';
            }
        }
        return $v;
    }
}
foreach ( array('ordersentinel_options','ordersentinel','order_sentinel','order_sentinel_options') as $__name ) {
    add_filter( "pre_update_option_{\$__name}", 'ordersentinel_normalize_checkboxes', 5, 1 );
    add_filter( "sanitize_option_{\$name}",    'ordersentinel_normalize_checkboxes', 5, 1 );
    add_filter( "sanitize_option_{\$__name}",  'ordersentinel_normalize_checkboxes', 5, 1 );
}


// === OrderSentinel: checkbox normalization === ORDERSENTINEL_NORMALIZE_V1
if ( ! function_exists('ordersentinel__normalize_checkboxes_v1') ) {
    function ordersentinel__normalize_checkboxes_v1( $v ) {
        if ( ! is_array( $v ) ) { return $v; }
        $keys = array(
            'save_to_meta','store_json','json_on_order',
            'trust_proxies','verify_search_dns',
            'rest_log_enabled','throttle_rest','throttle_search',
            'drop_on_uninstall'
        );
        foreach ( $keys as $k ) {
            $v[$k] = ( isset($v[$k]) && (string)$v[$k] === '1' ) ? '1' : '0';
        }
        return $v;
    }
}
if ( ! function_exists('ordersentinel__read_fill_checkboxes_v1') ) {
    function ordersentinel__read_fill_checkboxes_v1( $v ) {
        if ( ! is_array( $v ) ) { $v = array(); }
        foreach ( array(
            'save_to_meta','store_json','json_on_order',
            'trust_proxies','verify_search_dns',
            'rest_log_enabled','throttle_rest','throttle_search',
            'drop_on_uninstall'
        ) as $k ) {
            if ( ! array_key_exists( $k, $v ) ) { $v[$k] = '0'; }
        }
        return $v;
    }
}
foreach ( array('ordersentinel_options','ordersentinel','order_sentinel','order_sentinel_options') as $__name ) {
    add_filter( "pre_update_option_${__name}", 'ordersentinel__normalize_checkboxes_v1', 5, 1 );
    add_filter( "sanitize_option_${__name}",   'ordersentinel__normalize_checkboxes_v1', 5, 1 );
    add_filter( "option_${__name}",            'ordersentinel__read_fill_checkboxes_v1', 5, 1 );
}
// === /OrderSentinel: checkbox normalization ===

