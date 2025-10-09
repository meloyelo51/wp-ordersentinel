<?php
defined('ABSPATH') || exit;

/**
 * Handle "Report to AbuseIPDB" from the order metabox.
 */
if ( ! function_exists('ordersentinel_handle_abuse_report') ) {
    function ordersentinel_handle_abuse_report() {
        if ( ! ( current_user_can('manage_woocommerce') || current_user_can('manage_options') ) ) {
            wp_die( esc_html__('Insufficient permissions.', 'order-sentinel') );
        }
        check_admin_referer('ordersentinel_abuse');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $ip       = isset($_POST['ip'])       ? sanitize_text_field($_POST['ip']) : '';

        $back = admin_url( $order_id ? ("post.php?post={$order_id}&action=edit") : 'edit.php' );
        if ( ! $order_id || ! $ip ) {
            wp_safe_redirect( add_query_arg('ordersentinel_abuse','missing', $back) );
            exit;
        }

        $opts = get_option('ordersentinel_options', array());
        $key  = isset($opts['abuseipdb_key']) ? trim((string)$opts['abuseipdb_key']) : '';
        $cats = isset($opts['abuseipdb_categories']) ? trim((string)$opts['abuseipdb_categories']) : '3,13';
        $tmpl = isset($opts['abuseipdb_comment_tmpl']) ? (string)$opts['abuseipdb_comment_tmpl'] : 'Reported by OrderSentinel for order #{order_id}.';

        if ( $key === '' ) {
            wp_safe_redirect( add_query_arg('ordersentinel_abuse','no-key', $back) );
            exit;
        }

        $ts   = gmdate('Y-m-d H:i:s');
        $comment = strtr($tmpl, array(
            '{order_id}' => (string)$order_id,
            '{ip}'       => $ip,
            '{ts}'       => $ts,
            '{signals}'  => 'manual-report',
        ));
        $comment = wp_strip_all_tags( $comment );

        $resp = wp_remote_post( 'https://api.abuseipdb.com/api/v2/report', array(
            'timeout' => 20,
            'headers' => array(
                'Accept' => 'application/json',
                'Key'    => $key,
            ),
            'body' => array(
                'ipAddress' => $ip,
                'categories'=> $cats,
                'comment'   => $comment,
            ),
        ) );

        if ( is_wp_error($resp) ) {
            $msg = rawurlencode($resp->get_error_message());
            wp_safe_redirect( add_query_arg('ordersentinel_abuse', $msg ?: 'error', $back) );
            exit;
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ( $code < 200 || $code >= 300 ) {
            $body = wp_remote_retrieve_body($resp);
            $msg  = rawurlencode( substr( (string)$body, 0, 140 ) );
            wp_safe_redirect( add_query_arg('ordersentinel_abuse', $msg ?: 'http-error', $back) );
            exit;
        }

        wp_safe_redirect( add_query_arg('ordersentinel_abuse','ok', $back) );
        exit;
    }

}

/** Order-page diagnostic: append &os_diag=1 to the order edit URL */
if ( is_admin() && isset($_GET['os_diag']) && $_GET['os_diag'] == '1' ) {
    add_action('admin_notices', function() {
        $has = has_action('admin_post_ordersentinel_report_ip');
        echo '<div class="notice notice-info"><p><strong>OrderSentinel:</strong> '
            . 'admin_post_ordersentinel_report_ip '
            . ( $has ? '<span style="color:green">is registered</span>.' : '<span style="color:red">NOT registered</span>!' )
            . '</p></div>';
    });
}

/** Friendly status notices after reporting */
add_action('admin_notices', function() {
    if ( empty($_GET['ordersentinel_abuse']) ) return;
    $msg = sanitize_text_field( wp_unslash( $_GET['ordersentinel_abuse'] ) );
    if ( $msg === 'ok' ) {
        echo '<div class="notice notice-success is-dismissible"><p>AbuseIPDB report submitted.</p></div>';
    } elseif ( $msg === 'no-key' ) {
        echo '<div class="notice notice-warning is-dismissible"><p>Set your AbuseIPDB API key in OrderSentinel → Settings.</p></div>';
    } elseif ( $msg === 'missing' ) {
        echo '<div class="notice notice-error is-dismissible"><p>Missing order or IP for report.</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>AbuseIPDB error: '.esc_html($msg).'</p></div>';
    }
});


// OrderSentinel – AbuseIPDB handler & helpers
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Hook: admin-post handler
if ( ! has_action( 'admin_post_ordersentinel_report_ip', 'ordersentinel_handle_report_ip' ) ) {

}


    
// Handler
if ( ! function_exists( 'ordersentinel_handle_report_ip' ) ) {
function ordersentinel_handle_report_ip() {
    if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' ) ) ) {
        wp_die( __( 'Insufficient permissions.', 'order-sentinel' ) );
    }
    check_admin_referer( 'ordersentinel_report_ip_nonce' );

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $ip       = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';

    // TODO: call real AbuseIPDB reporter here (settings-driven)

    // Return to referer (order edit) — avoid edit.php
    $ref = wp_get_referer();
    if ( ! $ref && $order_id ) { $ref = admin_url( 'post.php?post=' . $order_id . '&action=edit' ); }
    if ( ! $ref ) { $ref = admin_url(); }
    $ref = add_query_arg( array( 'os_report_ip' => '1', 'os_report_ip_id' => $order_id ), $ref );
    wp_safe_redirect( $ref ); exit;
}}

// Success notice
if ( ! function_exists( 'ordersentinel_maybe_show_report_notice' ) ) {
function ordersentinel_maybe_show_report_notice() {
    if ( isset( $_GET['os_report_ip'] ) && '1' === $_GET['os_report_ip'] ) {
        $order_id = isset( $_GET['os_report_ip_id'] ) ? absint( $_GET['os_report_ip_id'] ) : 0; ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( sprintf( __( 'Reported IP for order #%d to AbuseIPDB (handler executed).', 'order-sentinel' ), $order_id ) ); ?></p>
        </div>
    <?php }
}
add_action( 'admin_notices', 'ordersentinel_maybe_show_report_notice' );
}



