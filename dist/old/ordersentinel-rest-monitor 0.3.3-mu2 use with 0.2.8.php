<?php
/**
 * Plugin Name: (MU) OrderSentinel — REST Monitor
 * Description: Standalone REST logger (safe while main plugin is refactored). Auto-creates table, logs requests, adds admin page + CSV export.
 * Author: Matt's Basement Arcade
 * Version: 0.3.3-mu2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'OS_REST_Monitor' ) ) {

	class OS_REST_Monitor {
		private $tbl;
		private $opt_key = 'ordersentinel_options';
		private static $req_times = array();

		public function __construct() {
			global $wpdb;
			$this->tbl = $wpdb->prefix . 'ordersentinel_restlog';

			// Create/maintain table in both admin + REST contexts
			add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
			add_action( 'rest_api_init', array( $this, 'maybe_migrate' ) );

			// Capture REST timings
			add_filter( 'rest_request_before_callbacks', array( $this, 'rest_before' ), 10, 3 );
			add_filter( 'rest_request_after_callbacks',  array( $this, 'rest_after'  ), 10, 3 );

			// Legacy Woo API marker (best-effort)
			add_action( 'woocommerce_api_request', array( $this, 'mark_legacy_wc_api' ), 10, 1 );

			// Admin UI + actions
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_post_ordersentinel_purge_rest',      array( $this, 'handle_purge_rest' ) );
			add_action( 'admin_post_ordersentinel_export_rest_csv', array( $this, 'handle_export_csv' ) );
			add_action( 'admin_init', array( $this, 'maybe_handle_settings_post' ) );
		}

		/* ---------------- Options ---------------- */

		private function get_options() {
			$defaults = array(
				'rest_monitor_enable' => 1,
				'rest_threshold_hour' => 200,
				'rest_retention_days' => 7,
				'rest_trust_proxies'  => 0,
			);
			$o = get_option( $this->opt_key, array() );
			foreach ( $defaults as $k => $v ) { if ( ! isset( $o[ $k ] ) ) { $o[ $k ] = $v; } }
			return $o;
		}
		private function update_options( $new ) {
			$o = get_option( $this->opt_key, array() );
			update_option( $this->opt_key, array_merge( $o, $new ) );
		}

		/* ---------------- DB ---------------- */

		public function maybe_migrate() {
			global $wpdb;
			$charset = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$this->tbl} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ts DATETIME NOT NULL,
				ip VARCHAR(45) NOT NULL DEFAULT '',
				method VARCHAR(10) NOT NULL DEFAULT '',
				route VARCHAR(191) NOT NULL DEFAULT '',
				status SMALLINT NOT NULL DEFAULT 0,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				ua VARCHAR(191) NOT NULL DEFAULT '',
				ref VARCHAR(191) NOT NULL DEFAULT '',
				took_ms INT NOT NULL DEFAULT 0,
				flags VARCHAR(50) NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				KEY ts (ts),
				KEY ip (ip),
				KEY route (route)
			) $charset;";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			// Retention purge
			$days = max( 1, intval( $this->get_options()['rest_retention_days'] ) );
			$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tbl} WHERE ts < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		private function ensure_table_exists() {
			global $wpdb;
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tbl ) ) === $this->tbl );
			if ( $exists ) { return true; }
			$this->maybe_migrate();
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tbl ) ) === $this->tbl );
			return $exists;
		}

		/* ---------------- REST capture ---------------- */

		public function rest_before( $response, $handler, $request ) {
			if ( empty( $this->get_options()['rest_monitor_enable'] ) ) { return $response; }
			self::$req_times[ spl_object_hash( $request ) ] = microtime( true );
			return $response;
		}

		public function rest_after( $response, $handler, $request ) {
			if ( empty( $this->get_options()['rest_monitor_enable'] ) ) { return $response; }
			if ( ! $this->ensure_table_exists() ) { return $response; }

			$key    = spl_object_hash( $request );
			$start  = isset( self::$req_times[ $key ] ) ? self::$req_times[ $key ] : microtime( true );
			unset( self::$req_times[ $key ] );
			$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );

			$trust = ! empty( $this->get_options()['rest_trust_proxies'] );
			$ip    = $this->client_ip( $request, $trust );
			$ua    = $this->truncate( $request->get_header( 'user-agent' ), 190 );
			$ref   = $this->truncate( $request->get_header( 'referer' ), 190 );
			$st    = ( $response instanceof WP_REST_Response ) ? (int) $response->get_status() : ( is_wp_error( $response ) ? 500 : 200 );
			$route = $request->get_route();
			$method= strtoupper( $request->get_method() );
			$uid   = get_current_user_id();

			global $wpdb;
			$wpdb->insert( $this->tbl, array(
				'ts'      => current_time( 'mysql', 1 ),
				'ip'      => $ip,
				'method'  => $method,
				'route'   => $this->truncate( $route, 190 ),
				'status'  => $st,
				'user_id' => $uid,
				'ua'      => $ua ?: '',
				'ref'     => $ref ?: '',
				'took_ms' => $elapsed,
				'flags'   => '',
			), array( '%s','%s','%s','%s','%d','%d','%s','%s','%d','%s' ) );

			return $response;
		}

		public function mark_legacy_wc_api( $endpoint ) {
			if ( ! $this->ensure_table_exists() ) { return; }
			global $wpdb;
			$ip = $this->client_ip( null, false );
			$wpdb->insert( $this->tbl, array(
				'ts'      => current_time( 'mysql', 1 ),
				'ip'      => $ip,
				'method'  => 'GET',
				'route'   => $this->truncate( '/wc-api/' . ltrim( (string) $endpoint, '/' ), 190 ),
				'status'  => 0,
				'user_id' => get_current_user_id(),
				'ua'      => $this->truncate( $_SERVER['HTTP_USER_AGENT'] ?? '', 190 ),
				'ref'     => $this->truncate( $_SERVER['HTTP_REFERER'] ?? '', 190 ),
				'took_ms' => 0,
				'flags'   => 'wc_legacy',
			) );
		}

		private function client_ip( $request = null, $trust_proxies = false ) {
			$xff = $request instanceof WP_REST_Request ? $request->get_header( 'x-forwarded-for' ) : ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' );
			if ( $trust_proxies && $xff ) {
				$first = trim( explode( ',', $xff )[0] );
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) { return $first; }
			}
			$ra = $request instanceof WP_REST_Request ? $request->get_header( 'x-real-ip' ) : ( $_SERVER['HTTP_X_REAL_IP'] ?? '' );
			if ( $trust_proxies && $ra && filter_var( $ra, FILTER_VALIDATE_IP ) ) { return $ra; }
			$ip = $_SERVER['REMOTE_ADDR'] ?? '';
			return $ip ?: '0.0.0.0';
		}
		private function truncate( $s, $n ) { $s = (string) $s; return ( strlen( $s ) > $n ) ? substr( $s, 0, $n ) : $s; }

		/* ---------------- Admin UI ---------------- */

		public function admin_menu() {
			$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
			$cap    = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
			add_submenu_page(
				$parent,
				'OrderSentinel REST',
				'OrderSentinel REST',
				$cap,
				'ordersentinel-rest',
				array( $this, 'render_page' )
			);
		}

		public function render_page() {
			if ( ! current_user_can( class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options' ) ) { return; }
			$opts = $this->get_options();
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

			echo '<div class="wrap"><h1>OrderSentinel — REST Monitor</h1>';
			echo '<h2 class="nav-tab-wrapper">';
			printf('<a href="%s" class="nav-tab %s">Dashboard</a>', esc_url( admin_url('admin.php?page=ordersentinel-rest&tab=dashboard') ), $tab==='dashboard'?'nav-tab-active':'' );
			printf('<a href="%s" class="nav-tab %s">Settings</a>',  esc_url( admin_url('admin.php?page=ordersentinel-rest&tab=settings') ),  $tab==='settings' ?'nav-tab-active':'' );
			echo '</h2>';

			if ( 'settings' === $tab ) {
				echo '<form method="post">';
				wp_nonce_field( 'ordersentinel_rest_save' );
				echo '<table class="form-table"><tbody>';
				echo '<tr><th>Enable REST logging</th><td><label><input type="checkbox" name="rest_monitor_enable" value="1" '.checked(1,!empty($opts['rest_monitor_enable']),false).' /> Log REST API requests</label></td></tr>';
				echo '<tr><th>Flag threshold (per IP, per hr)</th><td><input type="number" name="rest_threshold_hour" min="10" max="100000" value="'.intval($opts['rest_threshold_hour']).'" /></td></tr>';
				echo '<tr><th>Retention (days)</th><td><input type="number" name="rest_retention_days" min="1" max="365" value="'.intval($opts['rest_retention_days']).'" /></td></tr>';
				echo '<tr><th>Trust proxies</th><td><label><input type="checkbox" name="rest_trust_proxies" value="1" '.checked(1,!empty($opts['rest_trust_proxies']),false).' /> Use first IP in <code>X-Forwarded-For</code></label></td></tr>';
				echo '</tbody></table>';
				submit_button( 'Save settings' );
				echo '</form>';

				echo '<hr /><form method="post" action="'.esc_url( admin_url('admin-post.php?action=ordersentinel_purge_rest') ).'">';
				wp_nonce_field( 'ordersentinel_purge_rest' );
				submit_button( 'Purge logs older than retention now', 'secondary', 'purge_now', false );
				echo '</form>';

				echo '<hr /><form method="post" action="'.esc_url( wp_nonce_url( admin_url('admin-post.php?action=ordersentinel_export_rest_csv&days=7'), 'ordersentinel_export_rest_csv' ) ).'">';
				submit_button( 'Export REST logs CSV (last 7 days)', 'secondary', 'export_csv', false );
				echo '</form>';

				echo '</div>';
				return;
			}

			$probe = $this->probe_rest();
			echo '<h2>REST status</h2>';
			printf('<p>GET <code>/wp-json/</code>: <strong>%s</strong>%s</p>',
				$probe['code'] ? intval($probe['code']) : 'n/a',
				$probe['msg'] ? ' — ' . esc_html($probe['msg']) : ''
			);

			list($topIps, $topRoutes, $suspicious) = $this->summaries();
			echo '<div class="metabox-holder" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">';
			$this->render_counts_card('Top IPs (24h)', $topIps, 'ip');
			$this->render_counts_card('Top Routes (24h)', $topRoutes, 'route');
			$this->render_counts_card('Suspicious IPs (last hr)', $suspicious, 'ip', true);
			echo '</div>';

			echo '</div>';
		}

		private function probe_rest() {
			$resp = wp_remote_get( home_url( '/wp-json/' ), array( 'timeout' => 8 ) );
			if ( is_wp_error( $resp ) ) { return array( 'code' => 0, 'msg' => $resp->get_error_message() ); }
			$code = wp_remote_retrieve_response_code( $resp );
			$body = wp_remote_retrieve_body( $resp );
			$msg  = '';
			if ( $body ) {
				$j = json_decode( $body, true );
				if ( is_array( $j ) && isset( $j['message'] ) ) { $msg = $j['message']; }
			}
			return array( 'code' => $code, 'msg' => $msg );
		}

		private function summaries() {
			global $wpdb;
			$now = time();
			$cut24 = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
			$rows1 = $wpdb->get_results( $wpdb->prepare( "SELECT ip, COUNT(*) c FROM {$this->tbl} WHERE ts >= %s GROUP BY ip ORDER BY c DESC LIMIT 20", $cut24 ) );
			$rows2 = $wpdb->get_results( $wpdb->prepare( "SELECT route, COUNT(*) c FROM {$this->tbl} WHERE ts >= %s GROUP BY route ORDER BY c DESC LIMIT 20", $cut24 ) );

			$opts = $this->get_options();
			$cut1h = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
			$th = max( 10, intval( $opts['rest_threshold_hour'] ) );
			$rows3 = $wpdb->get_results( $wpdb->prepare( "SELECT ip, COUNT(*) c FROM {$this->tbl} WHERE ts >= %s GROUP BY ip HAVING c >= %d ORDER BY c DESC LIMIT 50", $cut1h, $th ) );

			$topIps = array(); foreach ( (array) $rows1 as $r ) { $topIps[ $r->ip ] = (int) $r->c; }
			$topRoutes = array(); foreach ( (array) $rows2 as $r ) { $topRoutes[ $r->route ] = (int) $r->c; }
			$suspicious = array(); foreach ( (array) $rows3 as $r ) { $suspicious[ $r->ip ] = (int) $r->c; }
			return array( $topIps, $topRoutes, $suspicious );
		}

		private function render_counts_card( $title, $counts, $label, $highlight=false ) {
			echo '<div class="postbox"><h2 class="hndle" style="padding:8px 12px;">'.esc_html($title).'</h2><div class="inside"><table class="widefat striped"><thead><tr><th>'.esc_html(ucfirst($label)).'</th><th>Count</th></tr></thead><tbody>';
			if ( empty( $counts ) ) { echo '<tr><td colspan="2"><em>None</em></td></tr>'; }
			foreach ( $counts as $k => $v ) {
				printf('<tr><td>%s</td><td%s>%d</td></tr>', esc_html($k), $highlight?' style="color:#c00;font-weight:600;"':'', intval($v));
			}
			echo '</tbody></table></div></div>';
		}

		/* ---------------- Admin actions ---------------- */

		public function handle_purge_rest() {
			if ( ! current_user_can( class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_purge_rest' ) ) { wp_die('Not allowed'); }
			$this->maybe_migrate();
			$days = max( 1, intval( $this->get_options()['rest_retention_days'] ) );
			global $wpdb;
			$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tbl} WHERE ts < %s", $cut ) );
			wp_safe_redirect( admin_url( 'admin.php?page=ordersentinel-rest&tab=settings' ) ); exit;
		}

		public function handle_export_csv() {
			if ( ! current_user_can( class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options' ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'ordersentinel_export_rest_csv' ) ) { wp_die('Not allowed'); }
			if ( ! $this->ensure_table_exists() ) { wp_die('No table'); }
			global $wpdb;
			$days = isset( $_GET['days'] ) ? max( 1, min( 365, intval( $_GET['days'] ) ) ) : 7;
			$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ts, ip, method, route, status, user_id, ua, ref, took_ms, flags FROM {$this->tbl} WHERE ts >= %s ORDER BY id DESC LIMIT 50000", $cut ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=ordersentinel-rest-' . $days . 'd.csv' );
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, array( 'ts','ip','method','route','status','user_id','ua','ref','took_ms','flags' ) );
			foreach ( (array) $rows as $r ) { fputcsv( $out, $r ); }
			fclose( $out ); exit;
		}

		/* ---------------- Settings POST ---------------- */

		public function maybe_handle_settings_post() {
			if ( isset($_POST['rest_monitor_enable']) || isset($_POST['rest_threshold_hour']) || isset($_POST['rest_retention_days']) || isset($_POST['rest_trust_proxies']) ) {
				check_admin_referer( 'ordersentinel_rest_save' );
				$this->update_options( array(
					'rest_monitor_enable' => empty($_POST['rest_monitor_enable']) ? 0 : 1,
					'rest_threshold_hour' => max(10, intval($_POST['rest_threshold_hour'] ?? 200)),
					'rest_retention_days' => max(1, intval($_POST['rest_retention_days'] ?? 7)),
					'rest_trust_proxies'  => empty($_POST['rest_trust_proxies']) ? 0 : 1,
				) );
				add_action( 'admin_notices', function() {
					echo '<div class="notice notice-success is-dismissible"><p>OrderSentinel REST settings saved.</p></div>';
				} );
			}
		}
	}

	// Bootstrap (instantiate once)
	add_action( 'plugins_loaded', function() {
		if ( is_admin() && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			if ( ! isset( $GLOBALS['ordersentinel_rest_mu'] ) || ! ( $GLOBALS['ordersentinel_rest_mu'] instanceof OS_REST_Monitor ) ) {
				$GLOBALS['ordersentinel_rest_mu'] = new OS_REST_Monitor();
			}
		}
	}, 9 );

}
