<?php
defined('ABSPATH') || exit;

/**
 * Combined OrderSentinel metabox for WooCommerce orders.
 * Always registers. Hides any legacy "OrderSentinel — Research*" boxes.
 */
if ( ! function_exists('ordersentinel_register_combined_metabox') ) {
    function ordersentinel_register_combined_metabox() {
        // Force-remove legacy boxes very late so we win priority order.
        add_action('add_meta_boxes', function() {
            remove_meta_box('ordersentinel_research',       'shop_order', 'side');
            remove_meta_box('ordersentinel_research',       'shop_order', 'normal');
            remove_meta_box('ordersentinel_researchorders', 'shop_order', 'side');
            remove_meta_box('ordersentinel_researchorders', 'shop_order', 'normal');

            // Defensive removal by title match.
            global $wp_meta_boxes;
            if ( isset($wp_meta_boxes['shop_order']) && is_array($wp_meta_boxes['shop_order']) ) {
                foreach (array('side','normal','advanced') as $ctx) {
                    foreach (array('high','core','default','low') as $prio) {
                        if (empty($wp_meta_boxes['shop_order'][$ctx][$prio])) continue;
                        foreach ($wp_meta_boxes['shop_order'][$ctx][$prio] as $id => $box) {
                            if ( isset($box['title']) && is_string($box['title']) && stripos($box['title'], 'OrderSentinel — Research') !== false ) {
                                unset($wp_meta_boxes['shop_order'][$ctx][$prio][$id]);
                            }
                        }
                    }
                }
            }
        }, 9999);

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
        $ip       = get_post_meta($order_id, '_customer_ip_address', true);
        $xff      = get_post_meta($order_id, '_x_forwarded_for', true);

        $safe = function($v){ return esc_html((string)$v); };
        $mk_admin = function($args){ return esc_url(add_query_arg($args, admin_url('admin.php'))); };

        $rest_monitor_url = $mk_admin(array('page'=>'ordersentinel-rest','tab'=>'overview','ip'=>$ip ?: ''));
        $tools_ip_lookup  = $mk_admin(array('page'=>'ordersentinel','tab'=>'tools','ip'=>$ip ?: ''));

        echo '<div class="ordersentinel-box">';
        echo '<p><strong>Order ID:</strong> '.$safe($order_id).'</p>';
        echo '<p><strong>Customer IP:</strong> '.($ip ? $safe($ip) : '<em>unknown</em>').'</p>';
        echo '<p><strong>X-Forwarded-For:</strong> '.($xff ? $safe($xff) : '<em>empty</em>').'</p>';

        echo '<p style="margin-top:8px">';
        echo '<a class="button" href="'.$rest_monitor_url.'">Open REST Monitor</a> ';
        echo '<a class="button" href="'.$tools_ip_lookup.'">Lookup IP</a>';
        echo '</p>';

        global $wpdb;
        $tbl = $wpdb->prefix . 'ordersentinel_restlog';
        $has_tbl = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s", $tbl
        ) );
        if ( $has_tbl && $ip ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ts, code, meth, route FROM `$tbl` WHERE ip = %s ORDER BY ts DESC LIMIT 5", $ip
            ) );
            if ( $rows ) {
                echo '<details style="margin-top:8px"><summary><strong>Latest REST hits for this IP</strong></summary>';
                echo '<table class="widefat striped" style="margin-top:6px"><thead><tr><th>Time (UTC)</th><th>Code</th><th>Method</th><th>Route</th></tr></thead><tbody>';
                foreach ( $rows as $r ) {
                    echo '<tr><td>'.esc_html($r->ts).'</td><td>'.esc_html($r->code).'</td><td>'.esc_html($r->meth).'</td><td>'.esc_html($r->route).'</td></tr>';
                }
                echo '</tbody></table></details>';
            }
        }

        echo '<p style="margin-top:8px;color:#666"><small>If you don’t see this, open “Screen Options” and enable <em>OrderSentinel</em>. The legacy “OrderSentinel — Research” panel is hidden by the plugin.</small></p>';
        echo '</div>';
    }
}
