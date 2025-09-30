<?php
defined('ABSPATH') || exit;

/**
 * Bootstrap the baked-in REST Monitor.
 *
 * We intentionally instantiate on plugins_loaded so WC + other deps are ready.
 */
add_action('plugins_loaded', function () {
    // Options with sane defaults (keep keys in sync with MU version if you’re migrating data).
    $default = array(
        'window_minutes'        => 1440,
        'trust_proxies'         => 0,
        'verify_search_bots'    => 0,
        'rate_limit_rest_per_m' => 0,
        'rate_limit_search_per_m'=> 0,
        'allowlist'             => '',
    );
    $opt = get_option('ordersentinel_rest_options', array());
    if (!is_array($opt)) { $opt = array(); }
    $opt = array_merge($default, $opt);

    // Load the baked class if not loaded yet.
    if (!class_exists('OS_REST_Monitor_Plugin', false)) {
        require_once __DIR__ . '/class-os-rest-monitor.php';
    }
    // Instantiate. Constructor hooks everything (admin menu, logging, REST hooks, etc).
    if (class_exists('OS_REST_Monitor_Plugin', false)) {
        $GLOBALS['ordersentinel_rest_monitor'] = new OS_REST_Monitor_Plugin($opt);
    }
});
