<?php
defined('ABSPATH') || exit;

/**
 * OrderSentinel — baked-in bootstrap.
 * Loads core class, REST capture, and the Order metabox include.
 */
add_action('plugins_loaded', function () {
    $dir = __DIR__;

    $paths = array(
        $dir . '/class-os-rest-monitor.php',
        $dir . '/os-rest-capture.php',
        $dir . '/os-order-metabox.php',
    );

    foreach ($paths as $p) {
        if ( file_exists($p) ) {
            require_once $p;
        }
    }

    if ( class_exists('OS_REST_Monitor') && method_exists('OS_REST_Monitor','init') ) {
        OS_REST_Monitor::init();
    }
}, 1);

// Admin hooks for menus/settings
add_action('admin_menu', array('OS_REST_Monitor','admin_menu'));
add_action('admin_init', array('OS_REST_Monitor','settings_register'));
