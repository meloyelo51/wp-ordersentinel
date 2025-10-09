<?php
defined('ABSPATH') || exit;

/**
 * Combined OrderSentinel metabox for WooCommerce orders.
 * - Always registers (no gating).
 * - Shows IP + X-Forwarded-For, quick OSINT links.
 * - Two jump links (REST Monitor / Tools).
 * - A working "Report to AbuseIPDB" form that posts to admin-post.php.
 */
if ( ! function_exists('ordersentinel_register_combined_metabox') ) {
    function ordersentinel_register_combined_metabox() {
        // Hide any legacy box if present
        add_action('add_meta_boxes', function() {
            remove_meta_box('ordersentinel_research',      'shop_order', 'side');
            remove_meta_box('ordersentinel_research',      'shop_order', 'normal');
            remove_meta_box('ordersentinel_researchorders','shop_order', 'side');
            remove_meta_box('ordersentinel_researchorders','shop_order', 'normal');
        }, 99);

        add_meta_box(
            'ordersentinel_metabox',
            'OrderSentinel',
            'ordersentinel_render_combined_metabox',
            'shop_order',
            'side',
            'high'
        );
    }
    add_action('add_meta_boxes', 'ordersentinel_register_combined_metabox', 10);
    add_action('add_meta_boxes_shop_order', 'ordersentinel_register_combined_metabox', 10);
}

if ( ! function_exists('ordersentinel_render_combined_metabox') ) {
    function ordersentinel_render_combined_metabox( $post ) {
        $order_id = absint($post->ID);

        // IPs from order meta
        $ip  = get_post_meta($order_id, '_customer_ip_address', true);
        $xff = get_post_meta($order_id, '_x_forwarded_for', true);
        if (!is_string($ip))  { $ip  = ''; }
        if (!is_string($xff)) { $xff = ''; }

        // Basic order details for OSINT links
        $email = get_post_meta($order_id, '_billing_email', true);
        $phone = get_post_meta($order_id, '_billing_phone', true);
        $name  = trim( get_post_meta($order_id, '_billing_first_name', true) . ' ' . get_post_meta($order_id, '_billing_last_name', true) );
        $addr  = trim( implode(' ', array_filter([
            get_post_meta($order_id, '_billing_address_1', true),
            get_post_meta($order_id, '_billing_address_2', true),
            get_post_meta($order_id, '_billing_city', true),
            get_post_meta($order_id, '_billing_state', true),
            get_post_meta($order_id, '_billing_postcode', true),
            get_post_meta($order_id, '_billing_country', true),
        ])));

        $esc = 'esc_html';
        ?>
        <div class="inside">
            <p><strong>IP:</strong><br><?php echo $esc($ip ?: '—'); ?></p>
            <p><strong>X-Forwarded-For:</strong><br><?php echo $esc($xff ?: 'empty'); ?></p>

            <p style="margin-top:8px">
                <a class="button" href="<?php echo esc_url( add_query_arg( array(
                    'page' => 'ordersentinel-rest',
                    'tab'  => 'overview',
                    'ip'   => $ip,
                ), admin_url('admin.php') ) ); ?>">Open REST Monitor</a>

                <a class="button" href="<?php echo esc_url( add_query_arg( array(
                    'page'  => 'ordersentinel',
                    'tab'   => 'tools',
                    'ip'    => $ip,
                    'focus' => 'ip'
                ), admin_url('admin.php') ) ); ?>">Lookup IP (Tools)</a>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-top:6px">
                <input type="hidden" name="action"   value="ordersentinel_report_ip" />
                <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>" />
                <input type="hidden" name="ip"       value="<?php echo esc_attr($ip); ?>" />
                <?php wp_nonce_field( 'ordersentinel_report_ip' ); ?>
                <?php echo function_exists('ordersentinel_render_abuseipdb_button')
    ? ordersentinel_render_abuseipdb_button( (int)$order_id, isset($ip)? $ip : (isset($client_ip)? $client_ip : ''), 'Report to AbuseIPDB' )
    : ''; ?>
            </form>

            <hr />
            <p><strong>Quick OSINT links</strong></p>
            <ul style="margin-left:16px; list-style:disc;">
                <?php if ($email): ?>
                    <li>Email: <a href="<?php echo esc_url('https://www.google.com/search?q='.rawurlencode($email)); ?>" target="_blank" rel="noreferrer">Google "<?php echo $esc($email); ?>"</a></li>
                <?php endif; ?>
                <?php if ($phone): ?>
                    <li>Phone: <a href="<?php echo esc_url('https://www.google.com/search?q='.rawurlencode($phone)); ?>" target="_blank" rel="noreferrer">Google "<?php echo $esc($phone); ?>"</a></li>
                <?php endif; ?>
                <?php if ($addr): ?>
                    <li>Address: <a href="<?php echo esc_url('https://www.google.com/search?q='.rawurlencode($addr)); ?>" target="_blank" rel="noreferrer">Google "<?php echo $esc($addr); ?>"</a></li>
                <?php endif; ?>
                <?php if ($name): ?>
                    <li>Name: <a href="<?php echo esc_url('https://www.google.com/search?q='.rawurlencode($name)); ?>" target="_blank" rel="noreferrer">Google "<?php echo $esc($name); ?>"</a></li>
                <?php endif; ?>
            </ul>

            <p class="description" style="margin-top:8px;">
                OrderSentinel stores results in its own table; saving into order meta is optional (Settings).
            </p>
        </div>
        <?php
    }
}
