<?php
/**
 * Plugin Name: OrderSentinel — Fraud/OSINT helper for WooCommerce orders
 * Description: Bulk/one-click OSINT on WooCommerce orders (RDAP, geoloc, AbuseIPDB). Includes dashboard, CSV export, optional non-PII AbuseIPDB reporting, and GitHub updates.
 * Version: 1.0.37
 * Author: Matt's Basement Arcade
 * Text Domain: order-sentinel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class OS_Order_Sentinel {
	const META_KEY    = '_ordersentinel_research';
	const OPTION_KEY  = 'ordersentinel_options';
	const TABLE_SLUG  = 'ordersentinel';
	const DBV_OPTION  = 'ordersentinel_db_version';
	const DB_VERSION  = '1';

	/** Debug info for updater diagnostics */
	protected $last_updater_debug = array();

	public function __construct() {
		// Ensure table exists early for REST context
		add_action( 'rest_api_init', array( $this, 'maybe_migrate' ) );
		add_action( 'init', array( $this, 'bootstrap' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// Bulk + AJAX
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_action' ), 20, 1 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'wp_ajax_ordersentinel_run_bulk_research', array( $this, 'ajax_run_research' ) );
		add_action( 'wp_ajax_ordersentinel_run_single_research', array( $this, 'ajax_run_single_research' ) );

		// Notices
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );

		// UI
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Export & Report actions
		add_action( 'admin_post_ordersentinel_export', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_ordersentinel_report_ip', array( $this, 'handle_report_ip' ) );
		add_action( 'admin_post_ordersentinel_bulk_report', array( $this, 'handle_bulk_report' ) );

		// Reset report flags (per-row + bulk)
		add_action( 'admin_post_ordersentinel_reset_report', array( $this, 'handle_reset_report' ) );
		add_action( 'admin_post_ordersentinel_bulk_reset', array( $this, 'handle_bulk_reset' ) );

		// Rescan recent orders
		add_action( 'admin_post_ordersentinel_rescan_recent', array( $this, 'handle_rescan_recent' ) );

		// Force update check (GitHub updater)
		add_action( 'admin_post_ordersentinel_force_update_check', array( $this, 'handle_force_update_check' ) );

		// Upgrade-safe DB ensure + backfill
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );

		// GitHub updater
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
	}

	/** Activation/Uninstall */
	public static function activate() { self::create_or_upgrade_table(); }
	public static function uninstall() {
		$opts = get_option( self::OPTION_KEY, array() );
		if ( ! empty( $opts['drop_on_uninstall'] ) ) {
			global $wpdb; $wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/** Table helpers */
	protected static function table_name() { global $wpdb; return $wpdb->prefix . self::TABLE_SLUG; }
	protected static function create_or_upgrade_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			data LONGTEXT NULL,
			abuseipdb_reported TINYINT(1) NOT NULL DEFAULT 0,
			abuseipdb_report_id VARCHAR(64) NULL,
			abuseipdb_last_response LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY order_id (order_id),
			KEY ip (ip)
		) $charset_collate;";
		dbDelta( $sql );
		update_option( self::DBV_OPTION, self::DB_VERSION );
	}
	protected function table_exists() {
		global $wpdb; $table = self::table_name();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	public function maybe_migrate() {
		if ( ! $this->table_exists() || get_option( self::DBV_OPTION ) !== self::DB_VERSION ) {
			self::create_or_upgrade_table();
		}
		

	

if ( ! get_option( 'ordersentinel_backfilled' ) && class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
			$after_str = wp_date( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
			$args = array(
				'limit'      => 200,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'ids',
				'date_after' => $after_str,
			);
			$ids = wc_get_orders( $args );
			foreach ( $ids as $oid ) {
				if ( $this->row_exists_for_order( $oid ) ) { continue; }
				$r = get_post_meta( $oid, self::META_KEY, true );
				if ( is_array( $r ) ) { $this->save_research( $oid, $r ); }
			}
			update_option( 'ordersentinel_backfilled', time() );
		}
	}

	public function bootstrap() { if ( ! class_exists( 'WooCommerce' ) ) { return; } }

	public function admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_orders_list = ( 'edit.php' === $hook && ! empty( $_GET['post_type'] ) && 'shop_order' === $_GET['post_type'] );
		$is_order_edit  = ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen instanceof WP_Screen && 'shop_order' === $screen->post_type );
		$is_plugin_page = ( isset( $_GET['page'] ) && 'ordersentinel' === $_GET['page'] );
		if ( ! $is_orders_list && ! $is_order_edit && ! $is_plugin_page ) { return; }
		wp_enqueue_script( 'ordersentinel-admin', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), '0.2', true );
		wp_localize_script( 'ordersentinel-admin', 'OrderSentinel', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ordersentinel-bulk' ),
		) );
	}

	/** Bulk & AJAX plumbing */
	public function register_bulk_action( $bulk_actions ) { $bulk_actions['ordersentinel_research'] = __( 'Run OSINT Research (OrderSentinel)', 'order-sentinel' ); return $bulk_actions; }
	public function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( 'ordersentinel_research' !== $doaction ) { return $redirect_to; }
		set_transient( 'ordersentinel_bulk_ids_' . get_current_user_id(), array_map( 'absint', $post_ids ), 60 );
		return add_query_arg( 'ordersentinel_run', '1', $redirect_to );
	}
	public function ajax_run_research() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'ordersentinel-bulk', 'nonce', false ) ) { wp_send_json_error( 'permission' ); }
		$user_id  = get_current_user_id();
		$post_ids = get_transient( 'ordersentinel_bulk_ids_' . $user_id );
		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) { wp_send_json_error( 'no_ids' ); }
		$results = array();
		foreach ( $post_ids as $order_id ) {
			$res = $this->run_research_for_order( absint( $order_id ) );
			if ( $res ) { $results[] = array( 'order_id' => (int) $order_id, 'status' => 'ok' ); }
		}
		delete_transient( 'ordersentinel_bulk_ids_' . $user_id );
		wp_send_json_success( $results );
	}
	public function ajax_run_single_research() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'ordersentinel-bulk', 'nonce', false ) ) { wp_send_json_error( 'permission' ); }
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) { wp_send_json_error( 'no_order' ); }
		$res = $this->run_research_for_order( $order_id );
		if ( ! $res ) { wp_send_json_error( 'failed' ); }
		wp_send_json_success( array( 'order_id' => $order_id ) );
	}

	/** Core research */
	protected function run_research_for_order( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order ) { return false; }
		$ip = $order->get_meta( '_customer_ip_address' ); if ( empty( $ip ) ) { $ip = get_post_meta( $order_id, '_billing_ip', true ); }

		$research = array(
			'order_id'        => $order_id,
			'ip'              => $ip ?: '(none)',
			'billing_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email'   => $order->get_billing_email(),
			'billing_phone'   => $order->get_billing_phone(),
			'billing_address' => $this->format_address( $order ),
			'timestamp'       => current_time( 'mysql' ),
			'lookups'         => array(),
			'heuristics'      => array(),
			'payment_meta'    => $this->sniff_payment_meta( $order_id ),
		);
		if ( $ip ) {
			if ( $this->is_service_enabled( 'rdap', true ) ) { $research['lookups']['rdap'] = $this->lookup_rdap( $ip ); }
			if ( $this->is_service_enabled( 'ipapi', true ) ) { $research['lookups']['geo']  = $this->lookup_ip_api( $ip ); }
			if ( $this->is_service_enabled( 'abuseipdb', true ) ) { $research['lookups']['abuseipdb'] = $this->lookup_abuseipdb( $ip ); }
		}
		$addr2 = $order->get_billing_address_2();
		if ( $addr2 ) { $research['heuristics']['addr2_random'] = $this->detect_random_string( $addr2 ); }
		$research['heuristics']['email_domain'] = $this->email_domain( $order->get_billing_email() );
		$research['heuristics']['phone_digits'] = $this->digits_only( $order->get_billing_phone() );

		$this->save_research( $order_id, $research );

		$opts = $this->get_options();
		if ( ! empty( $opts['save_to_meta'] ) ) {
			update_post_meta( $order_id, self::META_KEY, $research );
			$order->add_order_note( sprintf( 'OrderSentinel: research attached (IP: %s). See meta key %s.', $ip ?: 'n/a', self::META_KEY ), false );
		}
		return $research;
	}

	/** Save to plugin table */
	protected function save_research( $order_id, $research ) {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'order_id'  => $order_id,
				'ip'        => substr( (string) ( $research['ip'] ?? '' ), 0, 45 ),
				'email'     => substr( (string) ( $research['billing_email'] ?? '' ), 0, 190 ),
				'phone'     => substr( (string) ( $research['billing_phone'] ?? '' ), 0, 50 ),
				'data'      => wp_json_encode( $research ),
				'created_at'=> $now,
				'updated_at'=> $now,
			),
			array( '%d','%s','%s','%s','%s','%s','%s' )
		);
	}
	protected function row_exists_for_order( $order_id ) {
		global $wpdb; $table = self::table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $order_id ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Payment/risk meta sniff (with explicit keys support) */
	protected function sniff_payment_meta( $order_id ) {
		$opts = $this->get_options();
		$keys_cfg = trim( (string) ( $opts['risk_meta_keys'] ?? '' ) );

		$out = array();
		if ( $keys_cfg !== '' ) {
			$keys = $this->parse_list( $keys_cfg );
			foreach ( $keys as $k ) {
				$val = get_post_meta( $order_id, $k, true );
				if ( '' === $val || null === $val ) { continue; }
				if ( is_array( $val ) || is_object( $val ) ) { $val = wp_json_encode( $val ); }
				$out[ $k ] = substr( (string) $val, 0, 256 );
				if ( count( $out ) >= 20 ) { break; }
			}
			return $out;
		}

		$keys = get_post_meta( $order_id );
		foreach ( $keys as $k => $arr ) {
			if ( count( $out ) >= 20 ) { break; }
			$lk = strtolower( $k );
			if ( false !== strpos( $lk, 'risk' ) || false !== strpos( $lk, 'stripe' ) || false !== strpos( $lk, 'wcpay' ) ) {
				$val = maybe_unserialize( $arr[0] ?? '' );
				if ( is_scalar( $val ) ) { $out[ $k ] = substr( (string) $val, 0, 256 ); }
			}
		}
		return $out;
	}

	/** Formatting & heuristics */
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
	protected function detect_random_string( $s ) {
		$s = preg_replace( '/\s+/', '', (string) $s );
		$len = strlen( $s ); if ( $len < 6 ) { return array( 'random_like' => false, 'len' => $len, 'entropy' => 0, 'value' => $s ); }
		$counts = array(); for ( $i = 0; $i < $len; $i++ ) { $ch = $s[ $i ]; $counts[ $ch ] = isset( $counts[ $ch ] ) ? $counts[ $ch ] + 1 : 1; }
		$entropy = 0.0; foreach ( $counts as $c ) { $p = $c / $len; $entropy += -$p * log( $p, 2 ); }
		$random_like = ( $len >= 8 && $entropy >= 3.0 );
		return array( 'random_like' => $random_like, 'len' => $len, 'entropy' => round( $entropy, 2 ), 'value' => $s );
	}
	protected function email_domain( $email ) { $email = (string) $email; if ( ! $email || false === strpos( $email, '@' ) ) { return ''; } return strtolower( substr( $email, strrpos( $email, '@' ) + 1 ) ); }
	protected function digits_only( $s ) { return preg_replace( '/\D+/', '', (string) $s ); }
	protected function parse_list( $s ) {
		$s = str_replace( array("\r\n", "\r"), "\n", (string) $s );
		$parts = preg_split( '/[\n,;|]+/', $s );
		$out = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( $p !== '' ) { $out[] = $p; }
		}
		return array_unique( $out );
	}

	/** Lookups */
	protected function lookup_rdap( $ip ) {
		$url  = 'https://rdap.org/ip/' . rawurlencode( $ip );
		$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) ) { return array( 'error' => $resp->get_error_message() ); }
		$code = wp_remote_retrieve_response_code( $resp ); if ( 200 !== $code ) { return array( 'error' => "RDAP response code $code" ); }
		$body = wp_remote_retrieve_body( $resp ); $data = json_decode( $body, true );
		return $data ? $data : array( 'raw' => $body );
	}
	protected function lookup_ip_api( $ip ) {
		$url  = 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,regionName,city,isp,org,query,timezone,as,reverse,message';
		$resp = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $resp ) ) { return array( 'error' => $resp->get_error_message() ); }
		$body = wp_remote_retrieve_body( $resp ); $data = json_decode( $body, true );
		return $data ? $data : array( 'raw' => $body );
	}
	protected function lookup_abuseipdb( $ip ) {
		$opts = $this->get_options();
		$key  = $opts['abuseipdb_key'];
		if ( empty( $key ) && defined( 'ORDERSENTINEL_ABUSEIPDB_KEY' ) ) { $key = ORDERSENTINEL_ABUSEIPDB_KEY; }
		if ( empty( $key ) ) { return array( 'note' => 'No AbuseIPDB key configured' ); }
		$url  = 'https://api.abuseipdb.com/api/v2/check?ipAddress=' . rawurlencode( $ip ) . '&maxAgeInDays=90';
		$resp = wp_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Key' => $key, 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $resp ) ) { return array( 'error' => $resp->get_error_message() ); }
		$body = wp_remote_retrieve_body( $resp ); $data = json_decode( $body, true );
		return $data ? $data : array( 'raw' => $body );
	}

	/** Notices */
	public function maybe_show_notice() {
		if ( isset( $_GET['ordersentinel_run'] ) && current_user_can( 'manage_woocommerce' ) ) {
			printf('<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html__( 'OrderSentinel: Research queued. Results will be stored in the plugin table.', 'order-sentinel' ));
		}
		if ( isset( $_GET['ordersentinel_msg'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( wp_unslash( $_GET['ordersentinel_msg'] ) ) . '</p></div>';
		}
	}

	/** Order Meta Box */
	public function add_order_metabox() {
		add_meta_box( 'ordersentinel_meta', __( 'OrderSentinel — Research', 'order-sentinel' ),
			array( $this, 'render_order_metabox' ), 'shop_order', 'side', 'high' );
	}
	public function render_order_metabox( $post ) {
		$order_id = $post->ID;
		$latest   = $this->get_latest_row_for_order( $order_id );
		$research = $latest ? json_decode( $latest->data, true ) : get_post_meta( $order_id, self::META_KEY, true );
		$ip       = is_array( $research ) ? ( $research['ip'] ?? '' ) : '';
		$geo      = is_array( $research ) ? ( $research['lookups']['geo'] ?? array() ) : array();
		$abuse    = is_array( $research ) ? ( $research['lookups']['abuseipdb']['data'] ?? array() ) : array();
		$heur     = is_array( $research ) ? ( $research['heuristics'] ?? array() ) : array();

		$email    = get_post_meta( $order_id, '_billing_email', true );
		$phone    = get_post_meta( $order_id, '_billing_phone', true );
		$address1 = get_post_meta( $order_id, '_billing_address_1', true );
		$address2 = get_post_meta( $order_id, '_billing_address_2', true );
		$city     = get_post_meta( $order_id, '_billing_city', true );
		$state    = get_post_meta( $order_id, '_billing_state', true );
		$postcode = get_post_meta( $order_id, '_billing_postcode', true );
		$country  = get_post_meta( $order_id, '_billing_country', true );

		$gq = function( $q ) { return esc_url( 'https://www.google.com/search?q=' . rawurlencode( $q ) ); };
		$phone_digits = $this->digits_only( $phone );
		$full_addr = trim( $address1 . ' ' . $address2 . ', ' . $city . ', ' . $state . ' ' . $postcode . ', ' . $country );

		echo '<div class="ordersentinel-box">';
		if ( $ip ) { printf( '<p><strong>IP:</strong> %s</p>', esc_html( $ip ) ); }
		if ( ! empty( $geo ) && ( $geo['status'] ?? '' ) === 'success' ) {
			printf( '<p><strong>Geo/ISP:</strong> %s, %s — ISP: %s — ASN: %s</p>',
				esc_html( $geo['city'] ?? '' ), esc_html( $geo['country'] ?? '' ),
				esc_html( $geo['isp'] ?? '' ), esc_html( $geo['as'] ?? '' ) );
		}
		if ( ! empty( $abuse ) ) {
			$score = isset( $abuse['abuseConfidenceScore'] ) ? intval( $abuse['abuseConfidenceScore'] ) : null;
			if ( null !== $score ) { printf( '<p><strong>AbuseIPDB:</strong> score %d, reports %s</p>', $score, esc_html( $abuse['totalReports'] ?? '?' ) ); }
		}
		if ( isset( $heur['addr2_random']['random_like'] ) && $heur['addr2_random']['random_like'] ) {
			printf( '<p style="color:#c00;"><strong>Signal:</strong> Address Line 2 looks random (len %d, H≈%s)</p>',
				intval( $heur['addr2_random']['len'] ), esc_html( $heur['addr2_random']['entropy'] ) );
		}
		echo '<hr /><p><strong>Quick OSINT links</strong></p>';
		if ( $email ) { printf( '<p>Email: <a href="%s" target="_blank" rel="noreferrer">Google "%s"</a></p>', $gq( '"' . $email . '"' ), esc_html( $email ) ); }
		if ( $phone_digits ) { printf( '<p>Phone: <a href="%s" target="_blank" rel="noreferrer">Google "%s"</a></p>', $gq( '"' . $phone_digits . '"' ), esc_html( $phone_digits ) ); }
		if ( $full_addr ) { printf( '<p>Address: <a href="%s" target="_blank" rel="noreferrer">Google "%s"</a></p>', $gq( '"' . $full_addr . '"' ), esc_html( $full_addr ) ); }
		$name = trim( get_post_meta( $order_id, '_billing_first_name', true ) . ' ' . get_post_meta( $order_id, '_billing_last_name', true ) );
		if ( $name ) { $q = $name . ' ' . $city . ' ' . $state; printf( '<p>Name: <a href="%s" target="_blank" rel="noreferrer">Google "%s"</a></p>', $gq( '"' . $q . '"' ), esc_html( $q ) ); }
		echo '<hr />';
		printf( '<p><button class="button button-primary" id="ordersentinel-rerun" data-order-id="%d">%s</button></p>', intval( $order_id ), esc_html__( 'Re-run OrderSentinel Research', 'order-sentinel' ) );
		echo '<p class="description">OrderSentinel stores results in its own table; saving into order meta is optional (Settings).</p>';
		echo '</div>';
	}

	/** Admin Menu: Dashboard & Settings */
	public function admin_menu() {
		add_submenu_page( 'woocommerce', __( 'OrderSentinel', 'order-sentinel' ), __( 'OrderSentinel', 'order-sentinel' ), 'manage_woocommerce', 'ordersentinel', array( $this, 'render_admin_page' ) );
	}

	/** Options */
	protected function get_options() {
		$defaults = array(
			'save_to_meta'         => 1,
			'drop_on_uninstall'    => 0,
			'enable'               => array( 'rdap' => 1, 'ipapi' => 1, 'abuseipdb' => 0 ),
			'abuseipdb_key'        => '',
			'abuseipdb_categories' => '3',
			'abuseipdb_comment_tmpl' => 'WooCommerce: suspected card testing/fraud. Order #{order_id}. Signals: {signals}. (Reported by OrderSentinel)',
			'window_days'          => 30,
			// Updater
			'github_repo'          => 'meloyelo51/wp-ordersentinel',
			'github_token'         => '',
			'update_channel'       => 'stable', // stable | beta
			// Risk keys mapping
			'risk_meta_keys'       => '',
		);
		$opts = get_option( self::OPTION_KEY, array() );
		$opts = wp_parse_args( $opts, $defaults );
		foreach ( array( 'rdap', 'ipapi', 'abuseipdb' ) as $k ) { $opts['enable'][ $k ] = empty( $opts['enable'][ $k ] ) ? 0 : 1; }
		return $opts;
	}
	protected function is_service_enabled( $key, $default = true ) { $opts = $this->get_options(); return isset( $opts['enable'][ $key ] ) ? (bool) $opts['enable'][ $key ] : (bool) $default; }
	public function register_settings() {
		register_setting( 'ordersentinel', self::OPTION_KEY );
		add_settings_section( 'ordersentinel_main', __( 'Services', 'order-sentinel' ), '__return_false', 'ordersentinel' );
		add_settings_field( 'ordersentinel_enable', __( 'Enable lookups', 'order-sentinel' ), array( $this, 'field_enable' ), 'ordersentinel', 'ordersentinel_main' );
		add_settings_field( 'ordersentinel_abuseipdb', __( 'AbuseIPDB API key', 'order-sentinel' ), array( $this, 'field_abuseipdb' ), 'ordersentinel', 'ordersentinel_main' );
		add_settings_field( 'ordersentinel_abuse_cats', __( 'AbuseIPDB categories (CSV)', 'order-sentinel' ), array( $this, 'field_abuse_cats' ), 'ordersentinel', 'ordersentinel_main' );
		add_settings_field( 'ordersentinel_abuse_comment', __( 'AbuseIPDB comment template', 'order-sentinel' ), array( $this, 'field_abuse_comment' ), 'ordersentinel', 'ordersentinel_main' );
		add_settings_field( 'ordersentinel_window', __( 'Report window (days)', 'order-sentinel' ), array( $this, 'field_window' ), 'ordersentinel', 'ordersentinel_main' );
		add_settings_field( 'ordersentinel_meta_toggle', __( 'Also save JSON into order meta?', 'order-sentinel' ), array( $this, 'field_meta_toggle' ), 'ordersentinel', 'ordersentinel_main' );
		add_settings_field( 'ordersentinel_uninstall', __( 'Drop table on uninstall?', 'order-sentinel' ), array( $this, 'field_uninstall' ), 'ordersentinel', 'ordersentinel_main' );

		// Updater fields
		add_settings_section( 'ordersentinel_upd', __( 'Updates (GitHub)', 'order-sentinel' ), '__return_false', 'ordersentinel' );
		add_settings_field( 'ordersentinel_repo', __( 'GitHub repo (owner/name)', 'order-sentinel' ), array( $this, 'field_repo' ), 'ordersentinel', 'ordersentinel_upd' );
		add_settings_field( 'ordersentinel_channel', __( 'Update channel', 'order-sentinel' ), array( $this, 'field_channel' ), 'ordersentinel', 'ordersentinel_upd' );
		add_settings_field( 'ordersentinel_token', __( 'GitHub token (optional)', 'order-sentinel' ), array( $this, 'field_token' ), 'ordersentinel', 'ordersentinel_upd' );

		// Risk mapping fields
		add_settings_section( 'ordersentinel_risk', __( 'Risk Fields', 'order-sentinel' ), '__return_false', 'ordersentinel' );
		add_settings_field( 'ordersentinel_risk_keys', __( 'Risk meta keys (newline/comma separated)', 'order-sentinel' ), array( $this, 'field_risk_keys' ), 'ordersentinel', 'ordersentinel_risk' );
	}
	public function field_enable() { $o = $this->get_options(); $chk = function($k)use($o){checked(1,$o['enable'][$k]);};
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable][rdap]" value="1" '; $chk('rdap'); echo '> RDAP</label><br />';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable][ipapi]" value="1" '; $chk('ipapi'); echo '> IP Geolocation (ip-api)</label><br />';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable][abuseipdb]" value="1" '; $chk('abuseipdb'); echo '> AbuseIPDB (check/report)</label>';
	}
	public function field_abuseipdb()  { $o=$this->get_options(); printf('<input type="text" class="regular-text" name="%s[abuseipdb_key]" value="%s" autocomplete="off" />', esc_attr(self::OPTION_KEY), esc_attr($o['abuseipdb_key'])); echo '<p class="description">Stored as a WordPress option.</p>'; }
	public function field_abuse_cats() { $o=$this->get_options(); printf('<input type="text" class="regular-text" name="%s[abuseipdb_categories]" value="%s" />', esc_attr(self::OPTION_KEY), esc_attr($o['abuseipdb_categories'])); echo ' <a href="https://www.abuseipdb.com/categories" target="_blank" rel="noreferrer">Category IDs</a>'; }
	public function field_abuse_comment(){ $o=$this->get_options(); printf('<textarea rows="4" style="width:600px;max-width:100%%" name="%s[abuseipdb_comment_tmpl]">%s</textarea>', esc_attr(self::OPTION_KEY), esc_textarea($o['abuseipdb_comment_tmpl'])); echo '<p class="description">Placeholders: {order_id}, {ip}, {ts}, {signals}. Avoid PII.</p>'; }
	public function field_window()     { $o=$this->get_options(); printf('<input type="number" min="1" max="365" name="%s[window_days]" value="%d" />', esc_attr(self::OPTION_KEY), intval($o['window_days'])); }
	public function field_meta_toggle(){ $o=$this->get_options(); printf('<label><input type="checkbox" name="%s[save_to_meta]" value="1" %s /> %s</label>', esc_attr(self::OPTION_KEY), checked(1,!empty($o['save_to_meta']),false), 'Store JSON on the order (optional)'); }
	public function field_uninstall()  { $o=$this->get_options(); printf('<label><input type="checkbox" name="%s[drop_on_uninstall]" value="1" %s /> %s</label>', esc_attr(self::OPTION_KEY), checked(1,!empty($o['drop_on_uninstall']),false), 'Drop plugin table when plugin is deleted'); }
	public function field_repo()       { $o=$this->get_options(); printf('<input type="text" class="regular-text" name="%s[github_repo]" value="%s" />', esc_attr(self::OPTION_KEY), esc_attr(trim($o['github_repo']))); echo '<p class="description">Example: <code>meloyelo51/wp-ordersentinel</code>. Repo must be public or token-accessible.</p>'; }
	public function field_channel()    { $o=$this->get_options(); $v = $o['update_channel']; ?>
		<select name="<?php echo esc_attr(self::OPTION_KEY); ?>[update_channel]">
			<option value="stable" <?php selected('stable',$v); ?>>Stable (latest non-prerelease)</option>
			<option value="beta"   <?php selected('beta',$v);   ?>>Beta (latest prerelease)</option>
		</select>
		<?php
	}
	public function field_token()      { $o=$this->get_options(); printf('<input type="password" class="regular-text" name="%s[github_token]" value="%s" autocomplete="off" />', esc_attr(self::OPTION_KEY), esc_attr($o['github_token'])); echo '<p class="description">Optional: GitHub token for higher rate limits or private repos.</p>'; }
	public function field_risk_keys()  { $o=$this->get_options(); printf('<textarea rows="4" style="width:600px;max-width:100%%" name="%s[risk_meta_keys]" placeholder="_stripe_risk_score\n_wcpay_charge_risk_level">%s</textarea>', esc_attr(self::OPTION_KEY), esc_textarea($o['risk_meta_keys'])); echo '<p class="description">One per line (or comma/semicolon). Leave empty to auto-detect keys containing <code>risk</code>, <code>stripe</code>, or <code>wcpay</code>.</p>'; }

	/** Admin page */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$opts = $this->get_options();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		echo '<div class="wrap"><h1>OrderSentinel</h1><h2 class="nav-tab-wrapper">';
		printf( '<a href="%s" class="nav-tab %s">Dashboard</a>', esc_url( admin_url( 'admin.php?page=ordersentinel&tab=dashboard' ) ), ( 'dashboard' === $tab ? 'nav-tab-active' : '' ) );
		printf( '<a href="%s" class="nav-tab %s">Settings</a>', esc_url( admin_url( 'admin.php?page=ordersentinel&tab=settings' ) ), ( 'settings' === $tab ? 'nav-tab-active' : '' ) );
		echo '</h2>';

		if ( 'settings' === $tab ) {
			echo '<form method="post" action="options.php">';
			settings_fields( 'ordersentinel' );
			do_settings_sections( 'ordersentinel' );
			submit_button();
			echo '</form>';

			// Updater diagnostics + force button
			echo '<hr /><h2>Updater tools</h2>';
			$rel = $this->fetch_github_release( trim($opts['github_repo']), $opts['update_channel'], $opts['github_token'] );
			$pkg_ok = ( is_array( $rel ) && ! empty( $rel['package'] ) ) ? 'OK' : 'MISSING';
			$dbg = $this->last_updater_debug;
			echo '<p><strong>Diagnostics</strong></p>';
			echo '<ul style="margin-left:1em;">';
			printf( '<li>Current version: <code>%s</code></li>', esc_html( $this->plugin_version() ) );
			printf( '<li>Channel: <code>%s</code> &middot; Repo: <code>%s</code></li>', esc_html( $opts['update_channel'] ), esc_html( trim($opts['github_repo']) ) );
			if ( $rel ) {
				printf( '<li>Remote version: <code>%s</code> &middot; Asset: <code>%s</code></li>', esc_html( $rel['version'] ), esc_html( $pkg_ok ) );
			} else {
				echo '<li>Remote release: <em>not found for this channel</em></li>';
			}
			if ( ! empty( $dbg ) ) {
				printf( '<li>HTTP: <code>%s</code> via <code>%s</code>%s</li>',
					isset($dbg['http']) ? esc_html($dbg['http']) : 'n/a',
					isset($dbg['endpoint']) ? esc_html($dbg['endpoint']) : 'n/a',
					!empty($dbg['err']) ? ' — <code>'.esc_html($dbg['err']).'</code>' : ''
				);
			}
			echo '</ul>';

			// Force update form
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_force_update_check' ) ) . '">';
			wp_nonce_field( 'ordersentinel_force_update_check' );
			submit_button( 'Check for updates now', 'secondary', 'ordersentinel_force_update_check_btn', false );
			echo ' <span class="description">Clears update cache and triggers immediate check.</span>';
			echo '</form>';

			echo '</div>';
			return;
		}

		// Dashboard
		$days      = max( 1, intval( $opts['window_days'] ) );
		$after_str = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$rows      = $this->get_recent_rows( $after_str, 200 );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_rescan_recent' ) ) . '" style="margin:10px 0;">';
		wp_nonce_field( 'ordersentinel_rescan_recent' );
		echo '<label>Rescan last <input type="number" min="1" max="500" name="n" value="50" /> orders</label> ';
		submit_button( 'Rescan', 'secondary', 'ordersentinel_rescan_btn', false );
		echo ' <span class="description">Re-runs research and stores fresh rows. Keep numbers modest.</span>';
		echo '</form>';

		$by_asn = array(); $by_isp = array(); $by_emaildom = array(); $by_phone = array(); $addr2_flags = array();
		foreach ( $rows as $row ) {
			$r = json_decode( $row->data, true ); if ( ! is_array( $r ) ) { continue; }
			$geo = $r['lookups']['geo'] ?? array();
			if ( ! empty( $geo['as'] ) )  { $by_asn[ $geo['as'] ]  = 1 + ( $by_asn[ $geo['as'] ]  ?? 0 ); }
			if ( ! empty( $geo['isp'] ) ) { $by_isp[ $geo['isp'] ] = 1 + ( $by_isp[ $geo['isp'] ] ?? 0 ); }
			$ed = $r['heuristics']['email_domain'] ?? ''; if ( $ed ) { $by_emaildom[ $ed ] = 1 + ( $by_emaildom[ $ed ] ?? 0 ); }
			$ph = $r['heuristics']['phone_digits'] ?? ''; if ( $ph ) { $by_phone[ $ph ] = 1 + ( $by_phone[ $ph ] ?? 0 ); }
			if ( ! empty( $r['heuristics']['addr2_random']['random_like'] ) ) { $addr2_flags[] = intval( $row->order_id ); }
		}

		echo '<h2>Suspicious clusters (last ' . intval( $days ) . " days)</h2>";
		$this->render_counts_table( 'By ASN', $by_asn );
		$this->render_counts_table( 'By ISP', $by_isp );
		$this->render_counts_table( 'By email domain', $by_emaildom );
		$this->render_counts_table( 'By phone digits', $by_phone );
		if ( ! empty( $addr2_flags ) ) { echo '<p><strong>Orders with random-looking Address Line 2:</strong> ' . implode( ', ', $addr2_flags ) . '</p>'; }

		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=ordersentinel_export' ), 'ordersentinel_export' );
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">Export CSV (recent research)</a></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_bulk_report' ) ) . '">';
		wp_nonce_field( 'ordersentinel_bulk_report' );
		echo '<h2>Recent research</h2>';
		echo '<table class="widefat striped"><thead><tr><th style="width:28px;"><input type="checkbox" onclick="jQuery(\'.ordersentinel-select\').prop(\'checked\', this.checked);" /></th><th>When</th><th>Order</th><th>IP</th><th>Geo/ISP</th><th>ASN</th><th>Email</th><th>Phone</th><th>Addr2</th><th>AbuseIPDB</th><th>Risk</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$r = json_decode( $row->data, true );
			$geo = $r['lookups']['geo'] ?? array();
			$ab  = $r['lookups']['abuseipdb']['data'] ?? array();
			$addr2_flag = ! empty( $r['heuristics']['addr2_random']['random_like'] );

			$risk_parts = array();
			if ( ! empty( $r['payment_meta'] ) ) {
				$opts = $this->get_options();
				$keys_cfg = trim( (string) ( $opts['risk_meta_keys'] ?? '' ) );
				if ( $keys_cfg !== '' ) {
					foreach ( $this->parse_list( $keys_cfg ) as $k ) {
						if ( isset( $r['payment_meta'][ $k ] ) ) { $risk_parts[] = $k . ': ' . $r['payment_meta'][ $k ]; }
					}
				} else {
					foreach ( $r['payment_meta'] as $k => $v ) {
						if ( stripos( $k, 'risk' ) !== false ) { $risk_parts[] = $k . ': ' . $v; }
						if ( count( $risk_parts ) >= 2 ) { break; }
					}
				}
			}
			$risk = implode( ' | ', array_slice( $risk_parts, 0, 3 ) );

			$can_report = ( ! $row->abuseipdb_reported && ! empty( $row->ip ) );
			$actions = array();
			if ( $can_report ) {
				$actions[] = '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ordersentinel_report_ip&order_id=' . intval( $row->order_id ) ), 'ordersentinel_report_ip' ) ) . '">Report</a>';
			} else {
				$actions[] = '<span style="color:#2d7;">Reported</span>';
				$actions[] = '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ordersentinel_reset_report&order_id=' . intval( $row->order_id ) ), 'ordersentinel_reset_report' ) ) . '">Reset</a>';
			}

			printf(
				'<tr><td>%s</td><td>%s</td><td><a href="%s">#%d</a></td><td>%s</td><td>%s, %s — %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s%% / %s</td><td>%s</td><td>%s</td></tr>',
				( ! $row->abuseipdb_reported && $row->ip ) ? '<input type="checkbox" class="ordersentinel-select" name="selected[]" value="' . intval( $row->order_id ) . '" />' : '',
				esc_html( $row->created_at ),
				esc_url( get_edit_post_link( intval( $row->order_id ) ) ),
				intval( $row->order_id ),
				esc_html( $row->ip ),
				esc_html( $geo['city'] ?? '' ), esc_html( $geo['country'] ?? '' ), esc_html( $geo['isp'] ?? '' ),
				esc_html( $geo['as'] ?? '' ),
				esc_html( $r['billing_email'] ?? '' ),
				esc_html( $r['billing_phone'] ?? '' ),
				$addr2_flag ? '<span style="color:#c00;">random-like</span>' : '',
				isset( $ab['abuseConfidenceScore'] ) ? intval( $ab['abuseConfidenceScore'] ) : '',
				isset( $ab['totalReports'] ) ? intval( $ab['totalReports'] ) : '',
				$risk ? esc_html( $risk ) : '',
				implode( ' ', $actions )
			);
		}
		if ( empty( $rows ) ) { echo '<tr><td colspan="12">No research stored yet. Run research via Bulk action or re-run on an order.</td></tr>'; }
		echo '</tbody></table>';

		submit_button( 'Report selected to AbuseIPDB', 'secondary', 'ordersentinel_bulk_report_btn', false );
		echo ' ';
		echo '<button type="submit" class="button" formaction="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_bulk_reset' ) ) . '">' . esc_html__( 'Reset report state for selected', 'order-sentinel' ) . '</button>';

		echo '</form>';
		echo '</div>';
	}

	/** DB access */
	protected function get_latest_row_for_order( $order_id ) {
		global $wpdb; $table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	protected function get_recent_rows( $after_str, $limit = 200 ) {
		global $wpdb; $table = self::table_name();
		$limit = max( 1, intval( $limit ) );
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY id DESC LIMIT {$limit}", $after_str ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}

	protected function render_counts_table( $label, $counts ) {
		arsort( $counts );
		echo '<h3>' . esc_html( $label ) . '</h3>';
		if ( empty( $counts ) ) { echo '<p><em>None</em></p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Value</th><th>Count</th></tr></thead><tbody>';
		foreach ( $counts as $k => $v ) { printf( '<tr><td>%s</td><td>%d</td></tr>', esc_html( $k ), intval( $v ) ); }
		echo '</tbody></table>';
	}

	/** Export CSV */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ordersentinel_export' ) ) { wp_die( 'Not allowed' ); }
		$opts      = $this->get_options();
		$days      = max( 1, intval( $opts['window_days'] ) );
		$after_str = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$rows      = $this->get_recent_rows( $after_str, 10000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ordersentinel-export.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'created_at','order_id','ip','email','phone','abuse_score','abuse_reports','addr2_random','timestamp' ) );
		foreach ( $rows as $row ) {
			$r = json_decode( $row->data, true );
			$ab  = $r['lookups']['abuseipdb']['data'] ?? array();
			fputcsv( $out, array(
				$row->created_at,
				$row->order_id,
				$row->ip,
				$r['billing_email'] ?? '',
				$r['billing_phone'] ?? '',
				isset( $ab['abuseConfidenceScore'] ) ? intval( $ab['abuseConfidenceScore'] ) : '',
				isset( $ab['totalReports'] ) ? intval( $ab['totalReports'] ) : '',
				! empty( $r['heuristics']['addr2_random']['random_like'] ) ? '1' : '0',
				$r['timestamp'] ?? '',
			) );
		}
		fclose( $out ); exit;
	}

	/** Per-IP report */
	public function handle_report_ip() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ordersentinel_report_ip' ) ) { wp_die( 'Not allowed' ); }
		$order_id = absint( $_GET['order_id'] ?? 0 ); if ( ! $order_id ) { wp_die( 'No order' ); }
		$row = $this->get_latest_row_for_order( $order_id ); if ( ! $row || empty( $row->ip ) ) { wp_die( 'No IP found' ); }
		$res = $this->report_ip_to_abuseipdb( $order_id, $row );
		$msg = $res['ok'] ? 'Reported to AbuseIPDB.' : 'Report failed: ' . $res['err'];
		wp_safe_redirect( add_query_arg( 'ordersentinel_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=ordersentinel' ) ) ); exit;
	}

	/** Bulk report */
	public function handle_bulk_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_bulk_report' ) ) { wp_die( 'Not allowed' ); }
		$selected = array_map( 'absint', (array) ( $_POST['selected'] ?? array() ) );
		$selected = array_filter( array_unique( $selected ) );
		$ok = 0; $fail = 0;
		foreach ( $selected as $order_id ) {
			$row = $this->get_latest_row_for_order( $order_id );
			if ( ! $row || empty( $row->ip ) || $row->abuseipdb_reported ) { continue; }
			$res = $this->report_ip_to_abuseipdb( $order_id, $row );
			if ( $res['ok'] ) { $ok++; } else { $fail++; }
		}
		$msg = "Bulk report finished. OK: $ok" . ( $fail ? ", Failed: $fail" : '' );
		wp_safe_redirect( add_query_arg( 'ordersentinel_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=ordersentinel' ) ) ); exit;
	}

	/** Reset report (per-row) */
	public function handle_reset_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ordersentinel_reset_report' ) ) { wp_die( 'Not allowed' ); }
		$order_id = absint( $_GET['order_id'] ?? 0 ); if ( ! $order_id ) { wp_die( 'No order' ); }
		$this->reset_report_for_order( $order_id );
		wp_safe_redirect( add_query_arg( 'ordersentinel_msg', rawurlencode( 'Report state reset for order #' . $order_id ), admin_url( 'admin.php?page=ordersentinel' ) ) ); exit;
	}
	protected function reset_report_for_order( $order_id ) {
		global $wpdb;
		$row = $this->get_latest_row_for_order( $order_id ); if ( ! $row ) { return; }
		$wpdb->update(
			self::table_name(),
			array( 'abuseipdb_reported' => 0, 'abuseipdb_report_id' => null, 'abuseipdb_last_response' => null, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $row->id ),
			array( '%d','%s','%s','%s' ),
			array( '%d' )
		);
	}

	/** Bulk reset */
	public function handle_bulk_reset() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_bulk_report' ) ) { wp_die( 'Not allowed' ); }
		$selected = array_map( 'absint', (array) ( $_POST['selected'] ?? array() ) );
		$selected = array_filter( array_unique( $selected ) );
		foreach ( $selected as $order_id ) { $this->reset_report_for_order( $order_id ); }
		wp_safe_redirect( add_query_arg( 'ordersentinel_msg', rawurlencode( 'Reset done for selected orders.' ), admin_url( 'admin.php?page=ordersentinel' ) ) ); exit;
	}

	/** Rescan last N orders */
	public function handle_rescan_recent() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_rescan_recent' ) ) { wp_die( 'Not allowed' ); }
		$n = isset( $_POST['n'] ) ? max( 1, min( 500, absint( $_POST['n'] ) ) ) : 50;
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_orders' ) ) { wp_die( 'WooCommerce not available' ); }
		$args = array( 'limit' => $n, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' );
		$ids = wc_get_orders( $args );
		@set_time_limit( 0 );
		$count = 0;
		foreach ( $ids as $oid ) {
			$this->run_research_for_order( absint( $oid ) );
			$count++;
		}
		wp_safe_redirect( add_query_arg( 'ordersentinel_msg', rawurlencode( "Rescanned $count orders." ), admin_url( 'admin.php?page=ordersentinel' ) ) ); exit;
	}

	/** Force update check (clear transient + trigger fetch) */
	public function handle_force_update_check() {
		if ( ! current_user_can( 'update_plugins' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_force_update_check' ) ) { wp_die( 'Not allowed' ); }
		delete_site_transient( 'update_plugins' );
		delete_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		wp_update_plugins();
		wp_safe_redirect( add_query_arg( 'ordersentinel_msg', rawurlencode( 'Update check forced. Visit Plugins page to see results.' ), admin_url( 'admin.php?page=ordersentinel&tab=settings' ) ) ); exit;
	}

	/** Report helper */
	protected function report_ip_to_abuseipdb( $order_id, $row ) {
		$opts = $this->get_options();
		$key  = $opts['abuseipdb_key']; if ( empty( $key ) ) { return array( 'ok' => false, 'err' => 'No API key' ); }
		$data = json_decode( (string) $row->data, true );
		$signals = array();
		if ( ! empty( $data['heuristics']['addr2_random']['random_like'] ) ) { $signals[] = 'random addr2'; }
		$abuse_score = $data['lookups']['abuseipdb']['data']['abuseConfidenceScore'] ?? null;
		if ( null !== $abuse_score ) { $signals[] = 'score=' . intval( $abuse_score ); }
		$signals_str = implode( '; ', $signals );

		$comment_tmpl = $opts['abuseipdb_comment_tmpl'];
		$comment = strtr( $comment_tmpl, array(
			'{order_id}' => (string) $order_id,
			'{ip}'       => (string) $row->ip,
			'{ts}'       => esc_html( $row->created_at ),
			'{signals}'  => $signals_str,
		) );
		$comment = wp_strip_all_tags( $comment );

		$args = array(
			'timeout' => 12,
			'headers' => array( 'Key' => $key, 'Accept' => 'application/json' ),
			'body'    => array(
				'ip'         => $row->ip,
				'categories' => $opts['abuseipdb_categories'],
				'comment'    => $comment,
			),
		);
		$resp = wp_remote_post( 'https://api.abuseipdb.com/api/v2/report', $args );
		if ( is_wp_error( $resp ) ) { return array( 'ok' => false, 'err' => $resp->get_error_message() ); }
		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$ok   = ( $code >= 200 && $code < 300 );
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'abuseipdb_reported'      => $ok ? 1 : 0,
				'abuseipdb_last_response' => $body,
				'updated_at'              => current_time( 'mysql' ),
			),
			array( 'id' => $row->id ),
			array( '%d','%s','%s' ),
			array( '%d' )
		);
		return $ok ? array( 'ok' => true ) : array( 'ok' => false, 'err' => "HTTP $code" );
	}

	/** GitHub updater (release asset preferred) */
	public function filter_update_plugins( $transient ) {
		if ( empty( $transient ) || empty( $transient->checked ) ) { return $transient; }
		$opts   = $this->get_options();
		$repo   = trim( (string) $opts['github_repo'] );
		if ( '' === $repo ) { return $transient; }

		$current_version = $this->plugin_version();
		$rel = $this->fetch_github_release( $repo, $opts['update_channel'], $opts['github_token'] );
		if ( ! $rel || empty( $rel['version'] ) || empty( $rel['package'] ) ) { return $transient; }

		if ( version_compare( $rel['version'], $current_version, '>' ) ) {
			$plugin_file = plugin_basename( __FILE__ );
			$slug = dirname( $plugin_file ); // 'order-sentinel'
			$icon_svg = plugins_url( 'assets/icon.svg', __FILE__ );
			$update = (object) array(
				'slug'        => $slug,
				'plugin'      => $plugin_file,
				'new_version' => $rel['version'],
				'url'         => $rel['url'],
				'package'     => $rel['package'],
				'tested'      => $rel['tested'],
				'requires'    => $rel['requires'],
				// Show a nice icon in Plugins/Updates UI
				'icons'       => array( 'svg' => $icon_svg ),
			);
			$transient->response[ $plugin_file ] = $update;
		}
		return $transient;
	}
	public function filter_plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) { return $res; }
		$plugin_file = plugin_basename( __FILE__ );
		$slug = dirname( $plugin_file );
		if ( empty( $args->slug ) || $args->slug !== $slug ) { return $res; }

		$opts = $this->get_options();
		$repo = trim( (string) $opts['github_repo'] );
		if ( '' === $repo ) { return $res; }
		$rel = $this->fetch_github_release( $repo, $opts['update_channel'], $opts['github_token'] );
		if ( ! $rel ) { return $res; }

		$icon_svg = plugins_url( 'assets/icon.svg', __FILE__ );
		$info = (object) array(
			'name'          => 'OrderSentinel',
			'slug'          => $slug,
			'version'       => $rel['version'],
			'author'        => '<a href="https://mattsbasementarcade.com">Matt\'s Basement Arcade</a>',
			'homepage'      => $rel['url'],
			'download_link' => $rel['package'],
			'sections'      => array(
				'description' => 'Bulk/one-click OSINT on WooCommerce orders.',
				'changelog'   => wp_kses_post( $rel['changelog'] ),
						// Icons and optional banner for the modal
			'icons'         => array( 'svg' => $icon_svg ),
),
		);
		return $info;
	}
	protected function plugin_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$pd = get_plugin_data( __FILE__, false, false );
		return isset( $pd['Version'] ) ? $pd['Version'] : '0.0.0';
	}

	/**
	 * Robust GitHub release fetcher with diagnostics.
	 * - Stable: prefers /releases/latest (non-prerelease), fallbacks to /releases list
	 * - Beta: picks newest prerelease from list
	 * Populates $this->last_updater_debug with endpoint/http/error.
	 */
	protected function fetch_github_release( $repo, $channel, $token = '' ) {
		$repo = trim( (string) $repo );
		if ( '' === $repo ) { return null; }
		// Encode owner/repo parts separately to avoid turning '/' into '%2F'
		list( $owner, $name ) = array_pad( explode( '/', $repo, 2 ), 2, '' );
		if ( '' === $owner || '' === $name ) { return null; }
$base = 'https://api.github.com/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $name );
		$headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'OrderSentinel-Updater' );
		if ( $token ) { $headers['Authorization'] = 'token ' . $token; }
		$this->last_updater_debug = array();

		$try_endpoints = array();
		if ( 'beta' === $channel ) {
			$try_endpoints[] = $base . '/releases';
		} else {
			$try_endpoints[] = $base . '/releases/latest';
			$try_endpoints[] = $base . '/releases';
		}

		foreach ( $try_endpoints as $ep ) {
			$resp = wp_remote_get( $ep, array( 'timeout' => 12, 'headers' => $headers ) );
			if ( is_wp_error( $resp ) ) {
				$this->last_updater_debug = array( 'endpoint' => $ep, 'http' => 'wp_error', 'err' => $resp->get_error_message() );
				continue;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$body = wp_remote_retrieve_body( $resp );
			$this->last_updater_debug = array( 'endpoint' => $ep, 'http' => (string) $code, 'err' => '' );

			if ( 200 !== $code ) { continue; }

			// Parse per endpoint shape
			$data = json_decode( $body, true );
			if ( 'beta' === $channel ) {
				if ( ! is_array( $data ) ) { continue; }
				$candidate = null;

				// Prefer highest semantic version among prereleases; fallback to most recent by published_at
				$best_version = null;
				foreach ( $data as $rel ) {
					if ( ! empty( $rel['draft'] ) || empty( $rel['prerelease'] ) ) { continue; }
					$tag = isset( $rel['tag_name'] ) ? (string) $rel['tag_name'] : '';
					$v = ltrim( $tag, 'vV' );
					if ( $best_version === null || version_compare( $v, $best_version, '>' ) ) {
						$best_version = $v;
						$candidate = $rel;
					}
				}
				if ( ! $candidate ) { continue; }
				return $this->shape_release( $candidate, $repo );
			} else {
				// Stable via /releases/latest
				if ( strpos( $ep, '/releases/latest' ) !== false ) {
					if ( ! is_array( $data ) || ! empty( $data['prerelease'] ) ) {
						// odd, fall through
					} else {
						return $this->shape_release( $data, $repo );
					}
				}
				// Stable via list: pick highest semver among non-prerelease
				if ( strpos( $ep, '/releases' ) !== false && is_array( $data ) ) {
					$candidate = null; $best_version = null;
					foreach ( $data as $rel ) {
						if ( ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) { continue; }
						$tag = isset( $rel['tag_name'] ) ? (string) $rel['tag_name'] : '';
						$v = ltrim( $tag, 'vV' );
						if ( $best_version === null || version_compare( $v, $best_version, '>' ) ) {
							$best_version = $v;
							$candidate = $rel;
						}
					}
					if ( $candidate ) { return $this->shape_release( $candidate, $repo ); }
				}
			}
		}
		return null;
	}

	protected function shape_release( $rel, $repo ) {
		$version = ltrim( (string) ( $rel['tag_name'] ?? '' ), 'vV' );
		$url     = (string) ( $rel['html_url'] ?? 'https://github.com/' . $repo );
		$package = '';
		if ( ! empty( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
			foreach ( $rel['assets'] as $asset ) {
				$name = (string) ( $asset['name'] ?? '' );
				if ( preg_match( '/(OrderSentinel|order-sentinel).+\.zip$/i', $name ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}
		// Fallback to zipball (works for detection; install may fail if top folder name mismatches)
		if ( '' === $package && ! empty( $rel['zipball_url'] ) ) {
			$package = (string) $rel['zipball_url'];
		}
		return array(
			'version'   => $version ?: null,
			'url'       => $url,
			'package'   => $package,
			'requires'  => '5.8',
			'tested'    => '6.6',
			'changelog' => isset( $rel['body'] ) ? wpautop( esc_html( $rel['body'] ) ) : '',
		);
	}
}

// -- end class OS_Order_Sentinel --

// Bootstrap main plugin instance early.
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'OS_Order_Sentinel' ) ) { return; }
    if ( ! isset( $GLOBALS['ordersentinel'] ) ) {
        $GLOBALS['ordersentinel'] = new OS_Order_Sentinel();
    }
}, 1 );

/**
 * OrderSentinel — baked-in REST monitor loader (admin only)
 * Loads includes/os-rest-monitor-bootstrap.php which wires the UI.
 */
add_action( 'plugins_loaded', function () {
    if ( ! is_admin() ) { return; }
    $bootstrap = plugin_dir_path( __FILE__ ) . 'includes/os-rest-monitor-bootstrap.php';
    if ( file_exists( $bootstrap ) ) {
        include_once $bootstrap;
    } else {
        // Optional breadcrumb for debugging:
        // error_log('OrderSentinel: bootstrap not found: ' . $bootstrap );
    }
}, 9 );


/**
 * OrderSentinel — baked-in REST monitor loader
 * (generated)
 */
if ( ! defined('ABSPATH') ) { exit; }
require_once __DIR__ . '/includes/os-rest-monitor-bootstrap.php';


require_once __DIR__ . '/includes/admin/class-os-abuseipdb-actions.php';
