<?php
/**
 * Plugin Name: (MU) OrderSentinel — WooCommerce Remote Order Importer
 * Description: Import orders from a remote WooCommerce site via REST (read-only keys). Saves a lightweight snapshot into wp_ordersentinel_orders for offline analysis.
 * Author: Matt's Basement Arcade
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'OS_Import_Tool' ) ) :
class OS_Import_Tool {
	private $tbl;

	public function __construct() {
		global $wpdb;
		$this->tbl = $wpdb->prefix . 'ordersentinel_orders';
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
		add_action( 'admin_post_ordersentinel_import_orders', array( $this, 'handle_import' ) );
	}

	public function admin_menu() {
		$page_parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
		add_submenu_page(
			$page_parent,
			'OrderSentinel Import',
			'OrderSentinel Import',
			'manage_woocommerce',
			'ordersentinel-import',
			array( $this, 'render_page' )
		);
	}

	public function maybe_migrate() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$this->tbl} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(40) NOT NULL DEFAULT '',
			total DECIMAL(18,6) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			ua VARCHAR(191) NOT NULL DEFAULT '',
			gateway VARCHAR(191) NOT NULL DEFAULT '',
			meta_json LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY ts (ts),
			UNIQUE KEY order_id (order_id)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		echo '<div class="wrap"><h1>OrderSentinel — Import from Remote Woo</h1>';
		echo '<p>Read-only import of orders from another WooCommerce site via REST API.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_import_orders' ) ) . '">';
		wp_nonce_field( 'ordersentinel_import_orders' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="remote_url">Remote Site URL</label></th><td><input type="url" id="remote_url" name="remote_url" class="regular-text" placeholder="https://example.com" required /></td></tr>';
		echo '<tr><th><label for="ck">Consumer Key</label></th><td><input type="text" id="ck" name="ck" class="regular-text code" required /></td></tr>';
		echo '<tr><th><label for="cs">Consumer Secret</label></th><td><input type="text" id="cs" name="cs" class="regular-text code" required /></td></tr>';
		echo '<tr><th><label for="after">From (UTC)</label></th><td><input type="datetime-local" id="after" name="after" /> <small>Optional</small></td></tr>';
		echo '<tr><th><label for="before">To (UTC)</label></th><td><input type="datetime-local" id="before" name="before" /> <small>Optional</small></td></tr>';
		echo '<tr><th><label for="limit">Per page</label></th><td><input type="number" id="limit" name="limit" min="1" max="100" value="100" /> <small>Woo max is typically 100</small></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Import Now' );
		echo '</form>';
		echo '<p><em>Note:</em> Requires Woo REST enabled on the remote site and read permission for orders.</p>';
		echo '</div>';
	}

	public function handle_import() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_import_orders' ) ) { wp_die( 'Not allowed' ); }
		$remote = trim( (string) ( $_POST['remote_url'] ?? '' ) );
		$ck     = trim( (string) ( $_POST['ck'] ?? '' ) );
		$cs     = trim( (string) ( $_POST['cs'] ?? '' ) );
		$after  = (string) ( $_POST['after'] ?? '' );
		$before = (string) ( $_POST['before'] ?? '' );
		$limit  = max( 1, min( 100, intval( $_POST['limit'] ?? 100 ) ) );

		if ( ! $remote || ! $ck || ! $cs ) { wp_die( 'Missing fields' ); }

		$this->maybe_migrate();

		$page = 1; $imported = 0; $errors = 0;
		do {
			$url = trailingslashit( $remote ) . 'wp-json/wc/v3/orders';
			$args = array(
				'per_page' => $limit,
				'page'     => $page,
				'orderby'  => 'date',
				'order'    => 'asc',
				'status'   => 'any',
				'consumer_key'    => $ck,
				'consumer_secret' => $cs,
			);
			if ( $after )  { $args['after']  = gmdate( 'c', strtotime( $after ) ); }
			if ( $before ) { $args['before'] = gmdate( 'c', strtotime( $before ) ); }

			$req_url = add_query_arg( $args, $url );
			$resp = wp_remote_get( $req_url, array( 'timeout' => 20, 'headers' => array( 'Accept' => 'application/json' ) ) );
			if ( is_wp_error( $resp ) ) { $errors++; break; }
			$code = wp_remote_retrieve_response_code( $resp );
			if ( $code < 200 || $code >= 300 ) { $errors++; break; }

			$list = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $list ) || empty( $list ) ) { break; }

			global $wpdb;
			foreach ( $list as $o ) {
				$order_id = intval( $o['id'] ?? 0 );
				if ( ! $order_id ) { continue; }
				$ts       = ! empty( $o['date_created_gmt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $o['date_created_gmt'] ) ) : current_time( 'mysql', 1 );
				$status   = (string) ( $o['status'] ?? '' );
				$total    = (string) ( $o['total'] ?? '0' );
				$currency = (string) ( $o['currency'] ?? '' );
				$email    = (string) ( $o['billing']['email'] ?? '' );
				$gateway  = (string) ( $o['payment_method_title'] ?? '' );

				// Common meta keys for IP/UA (varies by gateway/setup)
				$meta_map = array();
				if ( isset( $o['meta_data'] ) && is_array( $o['meta_data'] ) ) {
					foreach ( $o['meta_data'] as $m ) {
						if ( ! isset( $m['key'] ) ) continue;
						$k = (string) $m['key'];
						$v = isset( $m['value'] ) ? ( is_scalar( $m['value'] ) ? (string) $m['value'] : wp_json_encode( $m['value'] ) ) : '';
						$meta_map[ $k ] = $v;
					}
				}
				$ip = $meta_map['_customer_ip_address'] ?? '';
				$ua = $meta_map['_customer_user_agent'] ?? '';

				$wpdb->replace( $this->tbl, array(
					'ts'        => $ts,
					'order_id'  => $order_id,
					'status'    => $status,
					'total'     => $total,
					'currency'  => $currency,
					'email'     => $email,
					'ip'        => $ip,
					'ua'        => $ua,
					'gateway'   => $gateway,
					'meta_json' => wp_json_encode( array( 'meta' => $meta_map ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				), array( '%s','%d','%s','%f','%s','%s','%s','%s','%s','%s' ) );

				$imported++;
			}

			$page++;
		} while ( true );

		wp_safe_redirect( add_query_arg( array( 'page' => 'ordersentinel-import', 'imported' => $imported, 'errors' => $errors ), admin_url( 'admin.php' ) ) ); exit;
	}
}
endif;

add_action( 'plugins_loaded', function() {
	if ( is_admin() && current_user_can( 'manage_woocommerce' ) && class_exists( 'OS_Import_Tool' ) ) {
		if ( ! isset( $GLOBALS['ordersentinel_import_mu'] ) || ! ( $GLOBALS['ordersentinel_import_mu'] instanceof OS_Import_Tool ) ) {
			$GLOBALS['ordersentinel_import_mu'] = new OS_Import_Tool();
		}
	}
}, 9);
