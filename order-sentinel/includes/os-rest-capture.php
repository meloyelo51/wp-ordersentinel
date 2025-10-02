<?php
defined('ABSPATH') || exit;

if ( ! function_exists('ordersentinel_capture_rest') ) {

    /**
     * Robust status capture after dispatch.
     * @filter rest_post_dispatch
     */
    function ordersentinel_capture_rest( $result, $server, $request ) {
        global $wpdb;

        // Normalize into WP_REST_Response when possible
        $resp   = rest_ensure_response( $result );
        $status = 0;

        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            if ( is_array( $data ) && isset( $data['status'] ) ) {
                $status = (int) $data['status'];
            } elseif ( $resp instanceof WP_REST_Response ) {
                $status = (int) $resp->get_status();
            }
        } elseif ( $resp instanceof WP_REST_Response ) {
            $status = (int) $resp->get_status();
        }

        // Request info
        $route = '';
        $meth  = '';
        if ( $request instanceof WP_REST_Request ) {
            $route = $request->get_route() ?: '';
            $meth  = $request->get_method() ?: '';
        }
        if ( $route === '' ) {
            // Final fallback from server vars
            $route = isset($_SERVER['REQUEST_URI']) ? (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        }
        if ( $meth === '' ) {
            $meth = $_SERVER['REQUEST_METHOD'] ?? '';
        }
        // Normalize route like "/wp/v2/..."
        if ( $route !== '' && $route[0] !== '/' ) {
            $route = '/' . $route;
        }

        // Client IPs (respect "Trust proxies" setting)
        $settings = get_option('ordersentinel_settings', []);
        $trust_proxies = !empty($settings['trust_proxies']);

        $ip  = '';
        $ip4 = '';

        if ( $trust_proxies ) {
            // Use left-most X-Forwarded-For when present
            if ( !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
                $chain = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $cand = trim($chain[0]);
                if ( filter_var($cand, FILTER_VALIDATE_IP) ) {
                    $ip = $cand;
                }
            }
        }
        if ( $ip === '' && !empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        if ( $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ) {
            $ip4 = $ip;
        } elseif ( !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
            // Try to fish out any IPv4 from the forwarded chain
            foreach ( explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $piece ) {
                $p = trim($piece);
                if ( filter_var($p, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ) { $ip4 = $p; break; }
            }
        }

        // UA
        $ua = substr( (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255 );

        // Insert
        $table = $wpdb->prefix . 'ordersentinel_restlog';
        // Make sure the column order matches your schema: ip, ip_v4, ua, route, code, meth, ts
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `$table` (`ip`,`ip_v4`,`ua`,`route`,`code`,`meth`,`ts`)
                 VALUES (%s,%s,%s,%s,%d,%s,UTC_TIMESTAMP())",
                $ip, $ip4, $ua, $route, $status, $meth
            )
        );

        return $result; // must return original result to WP
    }

    // Hook late so we see final status
    add_filter('rest_post_dispatch', 'ordersentinel_capture_rest', 999, 3);
}
