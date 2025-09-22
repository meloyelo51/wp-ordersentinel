<?php
/**
 * Plugin Name: OrderSentinel — Fraud/OSINT helper for WooCommerce orders
 * Description: Adds a bulk action to WooCommerce Orders to run lightweight OSINT on ordering IPs (RDAP/whois, geolocation, blacklist checks) and attach findings to orders. Defensive tool — use responsibly.
 * Version: 0.1.0
 * Author: Matt's Basement Arcade
 * Text Domain: order-sentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

class OS_Order_Sentinel {
const META_KEY   = '_ordersentinel_research';
const OPTION_KEY = 'ordersentinel_options';

public function __construct() {
add_action( 'init', array( $this, 'bootstrap' ) );
add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_action' ), 20, 1 );
add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
add_action( 'wp_ajax_ordersentinel_run_bulk_research', array( $this, 'ajax_run_research' ) );
add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
}

public function bootstrap() {
if ( ! class_exists( 'WooCommerce' ) ) {
return;
}
}

public function admin_assets( $hook ) {
if ( 'edit.php' !== $hook || empty( $_GET['post_type'] ) || 'shop_order' !== $_GET['post_type'] ) {
return;
}
wp_enqueue_script( 'ordersentinel-admin', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), '0.1', true );
wp_localize_script( 'ordersentinel-admin', 'OrderSentinel', array(
'ajax_url' => admin_url( 'admin-ajax.php' ),
'nonce'    => wp_create_nonce( 'ordersentinel-bulk' ),
) );
}

public function register_bulk_action( $bulk_actions ) {
$bulk_actions['ordersentinel_research'] = __( 'Run OSINT Research (OrderSentinel)', 'order-sentinel' );
return $bulk_actions;
}

public function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
if ( 'ordersentinel_research' !== $doaction ) {
return $redirect_to;
}
set_transient( 'ordersentinel_bulk_ids_' . get_current_user_id(), array_map( 'absint', $post_ids ), 60 );
return add_query_arg( 'ordersentinel_run', '1', $redirect_to );
}

public function ajax_run_research() {
if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'ordersentinel-bulk', 'nonce', false ) ) {
wp_send_json_error( 'permission' );
}
$user_id  = get_current_user_id();
$post_ids = get_transient( 'ordersentinel_bulk_ids_' . $user_id );
if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
wp_send_json_error( 'no_ids' );
}

$results = array();
foreach ( $post_ids as $order_id ) {
$order = wc_get_order( $order_id );
if ( ! $order ) {
continue;
}
$ip = $order->get_meta( '_customer_ip_address' );
if ( empty( $ip ) ) {
$ip = get_post_meta( $order_id, '_billing_ip', true );
}

$research = array(
'order_id'        => $order_id,
'ip'              => $ip ?: '(none)',
'billing_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
'billing_email'   => $order->get_billing_email(),
'billing_phone'   => $order->get_billing_phone(),
'billing_address' => $this->format_address( $order ),
'timestamp'       => current_time( 'mysql' ),
'lookups'         => array(),
);

if ( $ip ) {
$research['lookups']['rdap']      = $this->lookup_rdap( $ip );
$research['lookups']['geo']       = $this->lookup_ip_api( $ip );
$research['lookups']['abuseipdb'] = $this->lookup_abuseipdb( $ip );
}

update_post_meta( $order_id, self::META_KEY, $research );
$order->add_order_note( sprintf( 'OrderSentinel: research attached (IP: %s). See meta key %s.', $ip ?: 'n/a', self::META_KEY ), false );

$results[] = array( 'order_id' => $order_id, 'status' => 'ok' );
}

delete_transient( 'ordersentinel_bulk_ids_' . $user_id );
wp_send_json_success( $results );
}

protected function format_address( $order ) {
$parts = array_filter( array(
$order->get_billing_address_1(),
$order->get_billing_address_2(),
$order->get_billing_city(),
$order->get_billing_state(),
$order->get_billing_postcode(),
$order->get_billing_country(),
) );
return implode( ', ', $parts );
}

protected function lookup_rdap( $ip ) {
$url  = 'https://rdap.org/ip/' . rawurlencode( $ip );
$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
if ( is_wp_error( $resp ) ) {
return array( 'error' => $resp->get_error_message() );
}
$code = wp_remote_retrieve_response_code( $resp );
if ( 200 !== $code ) {
return array( 'error' => "RDAP response code $code" );
}
$body = wp_remote_retrieve_body( $resp );
$data = json_decode( $body, true );
return $data ? $data : array( 'raw' => $body );
}

protected function lookup_ip_api( $ip ) {
$url  = 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,regionName,city,isp,org,query,timezone,as,reverse,message';
$resp = wp_remote_get( $url, array( 'timeout' => 8 ) );
if ( is_wp_error( $resp ) ) {
return array( 'error' => $resp->get_error_message() );
}
$body = wp_remote_retrieve_body( $resp );
$data = json_decode( $body, true );
return $data ? $data : array( 'raw' => $body );
}

protected function lookup_abuseipdb( $ip ) {
$key = defined( 'ORDERSENTINEL_ABUSEIPDB_KEY' ) ? ORDERSENTINEL_ABUSEIPDB_KEY : apply_filters( 'ordersentinel_abuseipdb_key', '' );
if ( empty( $key ) ) {
return array( 'note' => 'No AbuseIPDB key configured' );
}
$url  = 'https://api.abuseipdb.com/api/v2/check?ipAddress=' . rawurlencode( $ip ) . '&maxAgeInDays=90';
$resp = wp_remote_get( $url, array(
'timeout' => 10,
'headers' => array(
'Key'    => $key,
'Accept' => 'application/json',
),
) );
if ( is_wp_error( $resp ) ) {
return array( 'error' => $resp->get_error_message() );
}
$body = wp_remote_retrieve_body( $resp );
$data = json_decode( $body, true );
return $data ? $data : array( 'raw' => $body );
}

public function maybe_show_notice() {
if ( isset( $_GET['ordersentinel_run'] ) && current_user_can( 'manage_woocommerce' ) ) {
printf(
'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
esc_html__( 'OrderSentinel: Research queued. Results will be attached to selected orders as private notes and meta.', 'order-sentinel' )
);
}
}
}

new OS_Order_Sentinel();
