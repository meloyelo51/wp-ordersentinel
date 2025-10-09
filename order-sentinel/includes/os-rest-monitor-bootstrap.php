<?php
defined('ABSPATH') || exit;

/** Bootstrap loader for OrderSentinel (baked-in). */
add_action('plugins_loaded', function () {
    $class_file   = __DIR__ . '/class-os-rest-monitor.php';
    $capture_file = __DIR__ . '/os-rest-capture.php';
    $metabox_file = __DIR__ . '/os-order-metabox.php';

    if ( file_exists($class_file) )   require_once $class_file;
    if ( file_exists($capture_file) ) require_once $capture_file;
    if ( file_exists($metabox_file) ) require_once $metabox_file;

    if ( class_exists('OS_REST_Monitor') && method_exists('OS_REST_Monitor','init') ) {
        OS_REST_Monitor::init();
    }
}, 1);

if ( class_exists('OS_REST_Monitor') ) {
    if ( method_exists('OS_REST_Monitor', 'admin_menu') ) {
        add_action('admin_menu', array('OS_REST_Monitor','admin_menu'));
    }
    if ( method_exists('OS_REST_Monitor', 'settings_register') ) {
        add_action('admin_init', array('OS_REST_Monitor','settings_register'));
    }
}

/**
 * /wp-admin/admin-post.php?action=ordersentinel_report_ip&ip=1.2.3.4&order_id=123&_wpnonce=...
 */
if ( ! function_exists('ordersentinel_handle_abuseipdb_report') ) {
    function ordersentinel_handle_abuseipdb_report() {
        if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Insufficient permissions.', 'order-sentinel') );
        }

        $ip       = isset($_REQUEST['ip']) ? sanitize_text_field( wp_unslash($_REQUEST['ip']) ) : '';
        $order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;
        $nonce    = isset($_REQUEST['_wpnonce']) ? sanitize_text_field( wp_unslash($_REQUEST['_wpnonce']) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'ordersentinel_report_ip' ) ) {
            wp_die( esc_html__('Bad nonce.', 'order-sentinel') );
        }

        $opts    = get_option('ordersentinel_options', array());
        $api_key = isset($opts['abuseipdb_key']) ? trim($opts['abuseipdb_key']) : '';
        $cats    = isset($opts['abuseipdb_categories']) ? trim($opts['abuseipdb_categories']) : '3,13';
        $tmpl    = ! empty($opts['abuseipdb_comment_tmpl'])
                     ? $opts['abuseipdb_comment_tmpl']
                     : 'Reported by OrderSentinel. Order #{order_id}.';

        $comment = strtr($tmpl, array(
            '{order_id}' => (string) $order_id,
            '{ip}'       => $ip,
            '{ts}'       => gmdate('c'),
            '{signals}'  => '',
        ));

        $ok=false; $msg='';
        if ( $api_key && filter_var($ip, FILTER_VALIDATE_IP) ) {
            $args = array(
                'headers' => array('Key' => $api_key, 'Accept' => 'application/json'),
                'body'    => array('ip' => $ip, 'categories' => $cats, 'comment' => $comment),
                'timeout' => 20,
            );
            $res = wp_remote_post('https://api.abuseipdb.com/api/v2/report', $args);
            if ( ! is_wp_error($res) ) {
                $code = wp_remote_retrieve_response_code($res);
                if ( $code >= 200 && $code < 300 ) { $ok=true; $msg=__('Reported to AbuseIPDB.', 'order-sentinel'); }
                else { $msg = sprintf(__('AbuseIPDB returned HTTP %d', 'order-sentinel'), $code); }
            } else {
                $msg = $res->get_error_message();
            }
        } else {
            $msg = __('Missing API key or invalid IP.', 'order-sentinel');
        }

        $redirect = admin_url('post.php');
        if ( $order_id ) $redirect = add_query_arg(array('post'=>$order_id,'action'=>'edit'), $redirect);
        $redirect = add_query_arg(array(
            'os_abuse_report' => $ok ? '1':'0',
            'os_msg'          => rawurlencode($msg),
        ), $redirect);

        wp_safe_redirect($redirect);
        exit;
    }
}
// (disabled) canonical handler lives in order-sentinel.php

/** Show success/error after redirect */
add_action('admin_notices', function () {
    if ( ! isset($_GET['os_abuse_report']) ) return;
    $ok  = $_GET['os_abuse_report'] === '1';
    $msg = isset($_GET['os_msg']) ? sanitize_text_field( wp_unslash($_GET['os_msg']) ) : '';
    echo '<div class="notice '.($ok?'notice-success':'notice-error').'"><p>';
    echo esc_html__('OrderSentinel:', 'order-sentinel').' '.esc_html($msg);
    echo '</p></div>';
});


add_action('admin_notices', function(){
    if ( ! empty($_GET['ordersentinel_notice']) ) {
        $cls = sanitize_key($_GET['ordersentinel_notice']) === 'success' ? 'success' : 'error';
        $msg = isset($_GET['ordersentinel_msg']) ? wp_kses_post(wp_unslash($_GET['ordersentinel_msg'])) : '';
        if ($msg) {
            echo '<div class="notice notice-' . esc_attr($cls) . ' is-dismissible"><p>' . $msg . '</p></div>';
        }
    }
});
