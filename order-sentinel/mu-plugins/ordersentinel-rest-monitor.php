<?php
/**
 * Plugin Name: (MU) OrderSentinel — REST Monitor
 * Description: REST + search logger for OrderSentinel. IPv4-friendly, optional bot verification, light rate-limits. Tools include Backup & Purge and Repair truncated IPs. Creates table early to avoid races.
 * Author: Matt's Basement Arcade
 * Version: 0.3.11-mu10
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'OS_REST_Monitor' ) ) :
class OS_REST_Monitor {
	private $tbl;
	private $opt_key = 'ordersentinel_options';
	private static $req_times = array();

	public function __construct() {
		global $wpdb;
		$this->tbl = $wpdb->prefix . 'ordersentinel_restlog';

		$this->maybe_migrate();

		add_action( 'muplugins_loaded', array( $this, 'maybe_migrate' ), 0 );
		add_action( 'init',            array( $this, 'maybe_migrate' ), 0 );
		add_action( 'admin_init',      array( $this, 'maybe_migrate' ) );
		add_action( 'rest_api_init',   array( $this, 'maybe_migrate' ) );

		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_before' ), 9, 3 );
		add_filter( 'rest_request_after_callbacks',  array( $this, 'rest_after'  ), 10, 3 );

		add_action( 'template_redirect', array( $this, 'maybe_log_and_limit_search' ), 1 );
		add_action( 'woocommerce_api_request', array( $this, 'mark_legacy_wc_api' ), 10, 1 );

		// NEW: debug routes for IP/headers
		add_action( 'rest_api_init', array( $this, 'register_debug_routes' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_ordersentinel_purge_rest',        array( $this, 'handle_purge_rest' ) );
		add_action( 'admin_post_ordersentinel_export_rest_csv',   array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_ordersentinel_backup_purge_now',  array( $this, 'handle_backup_purge_now' ) );
		add_action( 'admin_post_ordersentinel_repair_trunc_ips',  array( $this, 'handle_repair_truncated_ips' ) );
	}

	private function get_options() {
		$defaults = array(
			'rest_monitor_enable' => 1,
			'rest_threshold_hour' => 200,
			'rest_retention_days' => 7,
			'rest_trust_proxies'  => 1, // you run behind Cloudflare
			'rate_rest_per_min'   => 300,
			'rate_search_per_min' => 60,
			'bot_dns_verify'      => 0,
		);
		$o = get_option( $this->opt_key, array() );
		foreach ( $defaults as $k => $v ) { if ( ! isset( $o[ $k ] ) ) { $o[ $k ] = $v; } }
		return $o;
	}
	private function update_options( $new ) {
		$o = get_option( $this->opt_key, array() );
		update_option( $this->opt_key, array_merge( $o, $new ) );
	}

	public function maybe_migrate() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->tbl} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			ip_ver TINYINT NOT NULL DEFAULT 0,
			ip_v4 VARCHAR(15) NOT NULL DEFAULT '',
			method VARCHAR(10) NOT NULL DEFAULT '',
			route VARCHAR(191) NOT NULL DEFAULT '',
			status SMALLINT NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ua VARCHAR(191) NOT NULL DEFAULT '',
			ref VARCHAR(191) NOT NULL DEFAULT '',
			took_ms INT NOT NULL DEFAULT 0,
			flags VARCHAR(120) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY ts (ts),
			KEY ip (ip),
			KEY ip_v4 (ip_v4),
			KEY route (route)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$this->tbl}", 0 );
		if ( is_array( $cols ) ) {
			if ( ! in_array( 'ip_ver', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE {$this->tbl} ADD COLUMN ip_ver TINYINT NOT NULL DEFAULT 0 AFTER ip" ); // phpcs:ignore
			}
			if ( ! in_array( 'ip_v4', $cols, true ) ) {
				$wpdb->query( "ALTER TABLE {$this->tbl} ADD COLUMN ip_v4 VARCHAR(15) NOT NULL DEFAULT '' AFTER ip_ver" ); // phpcs:ignore
				$wpdb->query( "ALTER TABLE {$this->tbl} ADD KEY ip_v4 (ip_v4)" ); // phpcs:ignore
			}
		}

		$days = max( 1, intval( $this->get_options()['rest_retention_days'] ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tbl} WHERE ts < %s", $cut ) ); // phpcs:ignore
	}
	private function ensure_table_exists() {
		global $wpdb;
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tbl ) ) === $this->tbl );
		if ( $exists ) { return true; }
		$this->maybe_migrate();
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tbl ) ) === $this->tbl );
	}

	private function rate_key( $scope, $ip ) { return 'osrl:' . $scope . ':' . $ip . ':' . gmdate( 'YmdHi' ); }
	private function bump_and_check( $scope, $ip, $limit, $ttl = 70 ) {
		if ( $limit <= 0 ) { return array( 0, false ); }
		$key = $this->rate_key( $scope, $ip );
		$val = false;

		if ( function_exists( 'wp_cache_add' ) && function_exists( 'wp_cache_incr' ) ) {
			if ( false === wp_cache_add( $key, 0, '', $ttl ) ) { $val = wp_cache_incr( $key, 1 ); }
			else { $val = 1; }
		} else {
			$val = get_transient( $key );
			$val = ( false === $val ) ? 0 : $val;
			$val++;
			set_transient( $key, $val, $ttl );
		}
		return array( (int) $val, ( $val > $limit ) );
	}

	public function rest_before( $response, $handler, $request ) {
		$opts = $this->get_options();
		if ( empty( $opts['rest_monitor_enable'] ) ) { return $response; }

		$trust  = ! empty( $opts['rest_trust_proxies'] );
		$ipmeta = $this->choose_client_ip( $request, $trust );
		$ip     = $ipmeta['ip'];

		if ( current_user_can( 'manage_woocommerce' ) ) { return $response; }
		if ( $this->is_verified_search_bot( $ip, $this->header( $request, 'user-agent' ), ! empty( $opts['bot_dns_verify'] ) ) ) { return $response; }

		list( $count, $hit ) = $this->bump_and_check( 'rest', $ip, intval( $opts['rate_rest_per_min'] ) );
		if ( $hit ) {
			return new WP_Error( 'ordersentinel_rate_limited', __( 'Too many requests. Please slow down.', 'order-sentinel' ), array( 'status' => 429 ) );
		}

		self::$req_times[ spl_object_hash( $request ) ] = microtime( true );
		return $response;
	}
	public function rest_after( $response, $handler, $request ) {
		$opts = $this->get_options();
		if ( empty( $opts['rest_monitor_enable'] ) ) { return $response; }
		if ( ! $this->ensure_table_exists() ) { return $response; }

		$key    = spl_object_hash( $request );
		$start  = isset( self::$req_times[ $key ] ) ? self::$req_times[ $key ] : microtime( true );
		unset( self::$req_times[ $key ] );
		$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );

		$trust  = ! empty( $opts['rest_trust_proxies'] );
		$ipmeta = $this->choose_client_ip( $request, $trust );
		$ip     = $ipmeta['ip'];
		$ipver  = (int) $ipmeta['ver'];
		$ipv4   = $ipmeta['ip4'];
		$ua     = $this->truncate( $request->get_header( 'user-agent' ), 190 );
		$ref    = $this->truncate( $request->get_header( 'referer' ), 190 );
		$st     = ( $response instanceof WP_REST_Response ) ? (int) $response->get_status() : ( is_wp_error( $response ) ? 500 : 200 );
		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );
		$uid    = get_current_user_id();

		$flags  = 'src=' . $ipmeta['src'] . ';v=' . ( $ipver ?: ( strpos( $ip, ':' ) !== false ? 6 : 4 ) );

		global $wpdb;
		$wpdb->insert( $this->tbl, array(
			'ts'      => current_time( 'mysql', 1 ),
			'ip'      => $ip,
			'ip_ver'  => $ipver,
			'ip_v4'   => $ipv4,
			'method'  => $method,
			'route'   => $this->truncate( $route, 190 ),
			'status'  => $st,
			'user_id' => $uid,
			'ua'      => $ua ?: '',
			'ref'     => $ref ?: '',
			'took_ms' => $elapsed,
			'flags'   => $flags,
		), array( '%s','%s','%d','%s','%s','%s','%d','%d','%s','%s','%d','%s' ) );

		return $response;
	}

	public function maybe_log_and_limit_search() {
		if ( ! is_search() ) { return; }
		$opts = $this->get_options();

		$trust  = ! empty( $opts['rest_trust_proxies'] );
		$ipmeta = $this->choose_client_ip( null, $trust );
		$ip     = $ipmeta['ip'];
		$ua     = $this->truncate( $_SERVER['HTTP_USER_AGENT'] ?? '', 190 );

		if ( current_user_can( 'manage_woocommerce' ) ) { return; }
		if ( $this->is_verified_search_bot( $ip, $ua, ! empty( $opts['bot_dns_verify'] ) ) ) { return; }

		list( $count, $hit ) = $this->bump_and_check( 'search', $ip, intval( $opts['rate_search_per_min'] ) );
		if ( $hit ) {
			status_header( 429 );
			header( 'Retry-After: 60' );
			wp_die( __( 'Too many searches. Please try again in a minute.', 'order-sentinel' ), 429 );
		}

		if ( ! $this->ensure_table_exists() ) { return; }
		$q   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		global $wpdb;
		$wpdb->insert( $this->tbl, array(
			'ts'      => current_time( 'mysql', 1 ),
			'ip'      => $ipmeta['ip'],
			'ip_ver'  => (int) $ipmeta['ver'],
			'ip_v4'   => $ipmeta['ip4'],
			'method'  => 'GET',
			'route'   => $this->truncate( '/?s=' . $q, 190 ),
			'status'  => 200,
			'user_id' => get_current_user_id(),
			'ua'      => $ua,
			'ref'     => $this->truncate( $_SERVER['HTTP_REFERER'] ?? '', 190 ),
			'took_ms' => 0,
			'flags'   => 'search',
		), array( '%s','%s','%d','%s','%s','%s','%d','%d','%s','%s','%d','%s' ) );
	}

	public function mark_legacy_wc_api( $endpoint ) {
		if ( ! $this->ensure_table_exists() ) { return; }
		$ipmeta = $this->choose_client_ip( null, ! empty( $this->get_options()['rest_trust_proxies'] ) );
		$flags  = 'src=' . $ipmeta['src'] . ';v=' . ( $ipmeta['ver'] ?: ( strpos( $ipmeta['ip'], ':' ) !== false ? 6 : 4 ) ) . ';wc_legacy';
		global $wpdb;
		$wpdb->insert( $this->tbl, array(
			'ts'      => current_time( 'mysql', 1 ),
			'ip'      => $ipmeta['ip'],
			'ip_ver'  => (int) $ipmeta['ver'],
			'ip_v4'   => $ipmeta['ip4'],
			'method'  => 'GET',
			'route'   => $this->truncate( '/wc-api/' . ltrim( (string) $endpoint, '/' ), 190 ),
			'status'  => 0,
			'user_id' => get_current_user_id(),
			'ua'      => $this->truncate( $_SERVER['HTTP_USER_AGENT'] ?? '', 190 ),
			'ref'     => $this->truncate( $_SERVER['HTTP_REFERER'] ?? '', 190 ),
			'took_ms' => 0,
			'flags'   => $flags,
		), array( '%s','%s','%d','%s','%s','%s','%d','%d','%s','%s','%d','%s' ) );
	}

	/* ------------ NEW: Debug routes ------------ */
	public function register_debug_routes() {
		register_rest_route(
			'ordersentinel/v1',
			'/ping',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'rest_ping' ),
				'permission_callback' => '__return_true',
			)
		);
	}
	public function rest_ping( WP_REST_Request $r ) {
		$opts  = $this->get_options();
		$trust = ! empty( $opts['rest_trust_proxies'] );
		$ipm   = $this->choose_client_ip( $r, $trust );

		return rest_ensure_response( array(
			'trust_proxies' => (bool) $trust,
			'ip'            => $ipm['ip'],
			'ip_ver'        => (int) $ipm['ver'],
			'ip_v4'         => $ipm['ip4'],
			'source'        => $ipm['src'], // ra, cf, tci, xff
			'ua'            => $this->truncate( $r->get_header( 'user-agent' ), 190 ),
			'headers'       => array(
				'cf-connecting-ip' => $this->header( $r, 'cf-connecting-ip' ),
				'true-client-ip'   => $this->header( $r, 'true-client-ip' ),
				'x-forwarded-for'  => $this->header( $r, 'x-forwarded-for' ),
				'remote_addr'      => $_SERVER['REMOTE_ADDR'] ?? '',
			),
		) );
	}

	/* ------------ IP helpers ------------ */
	private function choose_client_ip( $request = null, $trust_proxies = false ) {
		$src = 'ra';
		$ip  = $this->server_or_header( $request, 'REMOTE_ADDR', '' );
		$ip4 = $this->to_ipv4_mapped( $ip );
		$ver = $this->ip_version( $ip );

		if ( $trust_proxies ) {
			$cf = $this->header( $request, 'cf-connecting-ip' );
			if ( $this->is_valid_public_ip( $cf ) ) { return array( 'ip' => $cf, 'ver' => $this->ip_version( $cf ), 'ip4' => $this->to_ipv4_mapped( $cf ), 'src' => 'cf' ); }
			$tci = $this->header( $request, 'true-client-ip' );
			if ( $this->is_valid_public_ip( $tci ) ) { return array( 'ip' => $tci, 'ver' => $this->ip_version( $tci ), 'ip4' => $this->to_ipv4_mapped( $tci ), 'src' => 'tci' ); }
			$xff = $this->header( $request, 'x-forwarded-for' );
			if ( $xff ) {
				$list = array_map( 'trim', explode( ',', $xff ) );
				foreach ( $list as $cand ) { if ( $this->is_valid_public_ip( $cand, true ) ) { return array( 'ip' => $cand, 'ver' => 4, 'ip4' => $cand, 'src' => 'xff' ); } }
				foreach ( $list as $cand ) { if ( $this->is_valid_public_ip( $cand, false ) ) { return array( 'ip' => $cand, 'ver' => $this->ip_version( $cand ), 'ip4' => $this->to_ipv4_mapped( $cand ), 'src' => 'xff' ); } }
			}
		}
		return array( 'ip' => $ip, 'ver' => $ver, 'ip4' => $ip4, 'src' => $src );
	}
	private function ip_version( $ip ) { if ( ! $ip ) { return 0; } return ( strpos( $ip, ':' ) !== false ) ? 6 : 4; }
	private function to_ipv4_mapped( $ip ) {
		if ( ! $ip ) { return ''; }
		if ( strpos( $ip, ':' ) !== false && preg_match( '/::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m ) ) { return $m[1]; }
		return ( $this->ip_version( $ip ) === 4 ) ? $ip : '';
	}
	private function is_valid_public_ip( $ip, $ipv4_only = false ) {
		if ( ! $ip ) { return false; }
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( $ipv4_only ) { return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags ); }
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, $flags );
	}
	private function header( $request, $name ) {
		if ( $request instanceof WP_REST_Request ) { $v = $request->get_header( $name ); if ( $v ) { return $v; } }
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return $_SERVER[ $key ] ?? '';
	}
	private function server_or_header( $request, $server_key, $header_name ) {
		if ( $header_name && $request instanceof WP_REST_Request ) {
			$h = $request->get_header( $header_name );
			if ( $h ) { return $h; }
		}
		return $_SERVER[ $server_key ] ?? '';
	}
	private function truncate( $s, $n ) { $s = (string) $s; return ( strlen( $s ) > $n ) ? substr( $s, 0, $n ) : $s; }

	/* ------------ Bot verification ------------ */
	private function is_verified_search_bot( $ip, $ua, $verify_dns = false ) {
		if ( ! $ua ) { return false; }
		$ua_l = strtolower( $ua );
		$is_goog = ( strpos( $ua_l, 'googlebot' ) !== false );
		$is_bing = ( strpos( $ua_l, 'bingbot' ) ) !== false;
		if ( ! $is_goog && ! $is_bing ) { return false; }
		if ( ! $verify_dns ) { return true; }

		$cache_key = 'osbot:' . md5( $ip . '|' . ( $is_goog ? 'g' : 'b' ) );
		$cached = get_transient( $cache_key );
		if ( $cached === '1' ) { return true; }
		if ( $cached === '0' ) { return false; }

		$host = @gethostbyaddr( $ip );
		if ( ! $host || $host === $ip ) { set_transient( $cache_key, '0', DAY_IN_SECONDS ); return false; }

		$ends = function( $h, $needle ) { $n = strlen( $needle ); if ( $n === 0 ) return true; return substr( $h, -$n ) === $needle; };

		if ( $is_goog ) {
			$ok = ( $ends( $host, '.googlebot.com' ) || $ends( $host, '.google.com' ) || $ends( $host, '.googleusercontent.com' ) );
			if ( $ok ) {
				$ip2 = @gethostbyname( $host );
				if ( empty( $ip2 ) || $ip2 !== $ip ) { set_transient( $cache_key, '0', DAY_IN_SECONDS ); return false; }
				set_transient( $cache_key, '1', DAY_IN_SECONDS ); return true;
			}
			set_transient( $cache_key, '0', DAY_IN_SECONDS ); return false;
		}
		$ok = ( $ends( $host, '.search.msn.com' ) || $ends( $host, '.bing.com' ) );
		if ( $ok ) {
			$ip2 = @gethostbyname( $host );
			if ( empty( $ip2 ) || $ip2 !== $ip ) { set_transient( $cache_key, '0', DAY_IN_SECONDS ); return false; }
			set_transient( $cache_key, '1', DAY_IN_SECONDS ); return true;
		}
		set_transient( $cache_key, '0', DAY_IN_SECONDS ); return false;
	}

	/* ------------ Admin UI ------------ */
	public function admin_menu() {
		$page_parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
		add_submenu_page(
			$page_parent,
			'OrderSentinel REST',
			'OrderSentinel REST',
			'manage_woocommerce',
			'ordersentinel-rest',
			array( $this, 'render_page' )
		);
	}
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$opts = $this->get_options();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

		echo '<div class="wrap"><h1>OrderSentinel — REST Monitor (MU)</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		printf( '<a href="%s" class="nav-tab %s">Dashboard</a>', esc_url( admin_url( 'admin.php?page=ordersentinel-rest&tab=dashboard' ) ), $tab === 'dashboard' ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">Settings</a>',  esc_url( admin_url( 'admin.php?page=ordersentinel-rest&tab=settings' ) ),  $tab === 'settings'  ? 'nav-tab-active' : '' );
		printf( '<a href="%s" class="nav-tab %s">Tools</a>',     esc_url( admin_url( 'admin.php?page=ordersentinel-rest&tab=tools' ) ),     $tab === 'tools'     ? 'nav-tab-active' : '' );
		echo '</h2>';

		if ( 'settings' === $tab ) {
			echo '<form method="post">';
			wp_nonce_field( 'ordersentinel_rest_save' );
			echo '<table class="form-table"><tbody>';
			echo '<tr><th>Enable REST logging</th><td><label><input type="checkbox" name="rest_monitor_enable" value="1" ' . checked( 1, ! empty( $opts['rest_monitor_enable'] ), false ) . ' /> Log REST API requests</label></td></tr>';
			echo '<tr><th>Flag threshold (per IP, per hr)</th><td><input type="number" name="rest_threshold_hour" min="10" max="100000" value="' . intval( $opts['rest_threshold_hour'] ) . '" /></td></tr>';
			echo '<tr><th>Retention (days)</th><td><input type="number" name="rest_retention_days" min="1" max="365" value="' . intval( $opts['rest_retention_days'] ) . '" /></td></tr>';
			echo '<tr><th>Trust proxies</th><td><label><input type="checkbox" name="rest_trust_proxies" value="1" ' . checked( 1, ! empty( $opts['rest_trust_proxies'] ), false ) . ' /> Honor <code>CF-Connecting-IP</code>, <code>True-Client-IP</code>, <code>X-Forwarded-For</code></label></td></tr>';
			echo '<tr><th>Rate limit (REST/min)</th><td><input type="number" name="rate_rest_per_min" min="10" max="100000" value="' . intval( $opts['rate_rest_per_min'] ) . '" /></td></tr>';
			echo '<tr><th>Rate limit (search/min)</th><td><input type="number" name="rate_search_per_min" min="10" max="100000" value="' . intval( $opts['rate_search_per_min'] ) . '" /></td></tr>';
			echo '<tr><th>Verify search engines via DNS</th><td><label><input type="checkbox" name="bot_dns_verify" value="1" ' . checked( 1, ! empty( $opts['bot_dns_verify'] ), false ) . ' /> Verify Googlebot/Bingbot via reverse + forward DNS</label></td></tr>';
			echo '</tbody></table>';
			submit_button( 'Save settings' );
			echo '</form>';

			echo '<div class="notice notice-info" style="margin-top:16px;"><p><strong>Help:</strong> <em>Trust proxies</em> reads CF-Connecting-IP / True-Client-IP / X-Forwarded-For so you see the real client IP (recommended behind Cloudflare). <em>Rate limits</em> are per-IP per-minute; exceeding returns HTTP 429. <em>Verify search engines</em> confirms Googlebot/Bingbot via DNS and exempts verified bots from rate limits.</p></div>';

			echo '<hr /><form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_purge_rest' ) ) . '">';
			wp_nonce_field( 'ordersentinel_purge_rest' );
			submit_button( 'Purge logs older than retention now', 'secondary', 'purge_now', false );
			echo '</form>';

			echo '<hr /><form method="post" action="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ordersentinel_export_rest_csv&days=7' ), 'ordersentinel_export_rest_csv' ) ) . '">';
			submit_button( 'Export REST logs CSV (last 7 days)', 'secondary', 'export_csv', false );
			echo '</form>';

			echo '</div>';
			return;
		}

		if ( 'tools' === $tab ) {
			echo '<h2>Tools</h2>';
			if ( isset($_GET['repaired_simple']) || isset($_GET['repaired_heur']) || isset($_GET['repaired_v6pref']) || isset($_GET['repaired_v6any']) || isset($_GET['repaired_v6glob']) ) {
				$si = intval($_GET['repaired_simple'] ?? 0);
				$hi = intval($_GET['repaired_heur'] ?? 0);
				$v6p = intval($_GET['repaired_v6pref'] ?? 0);
				$v6a = intval($_GET['repaired_v6any'] ?? 0);
				$v6g = intval($_GET['repaired_v6glob'] ?? 0);
				echo '<div class="notice notice-success is-dismissible"><p>Repair complete. Simple: ' . $si . ' • Heuristic: ' . $hi . ' • IPv6-prefix (same UA): ' . $v6p . ' • IPv6-prefix (any UA ±60s): ' . $v6a . ' • IPv6-prefix (global 24h): ' . $v6g . '.</p></div>';
			}
			echo '<p>Backup and maintenance tools for the REST/search log.</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_backup_purge_now' ) ) . '">';
			wp_nonce_field( 'ordersentinel_backup_purge_now' );
			submit_button( 'Backup & Purge NOW (export all rows to CSV, then truncate table)', 'secondary', 'backup_purge_now', false );
			echo '</form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=ordersentinel_repair_trunc_ips' ) ) . '" style="margin-top:12px;">';
			wp_nonce_field( 'ordersentinel_repair_trunc_ips' );
			submit_button( 'Repair truncated IPs', 'secondary', 'repair_trunc_ips', false );
			echo '</form>';

			echo '</div>';
			return;
		}

		$probe = $this->probe_rest();
		echo '<h2>REST status</h2>';
		printf(
			'<p>GET <code>/wp-json/</code>: <strong>%s</strong>%s</p>',
			$probe['code'] ? intval( $probe['code'] ) : 'n/a',
			$probe['msg'] ? ' — ' . esc_html( $probe['msg'] ) : ''
		);

		list( $topIps24, $topRoutes, $topV4s, $suspicious, $topIps10m ) = $this->summaries();
		echo '<div class="metabox-holder" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">';
		$this->render_counts_card( 'Top IPs (24h)', $topIps24, 'ip' );
		$this->render_counts_card( 'Top IPs (10m)', $topIps10m, 'ip' );
		$this->render_counts_card( 'Top IPv4s (24h)', $topV4s, 'ip_v4' );
		$this->render_counts_card( 'Top Routes (24h)', $topRoutes, 'route' );
		$this->render_counts_card( 'Suspicious IPs (last hr)', $suspicious, 'ip', true );
		echo '</div>';

		// NEW: Recent 25
		$recent = $this->recent_rows( 25 );
		echo '<div class="postbox" style="margin-top:12px;"><h2 class="hndle" style="padding:8px 12px;">Recent requests (last 25)</h2><div class="inside">';
		echo '<table class="widefat striped"><thead><tr><th>ts</th><th>ip</th><th>ip_v4</th><th>method</th><th>route</th><th>status</th><th>ua</th></tr></thead><tbody>';
		if ( empty( $recent ) ) { echo '<tr><td colspan="7"><em>None</em></td></tr>'; }
		foreach ( $recent as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%d</td><td><span title="%s">%s</span></td></tr>',
				esc_html( $r['ts'] ),
				esc_html( $r['ip'] ),
				esc_html( $r['ip_v4'] ),
				esc_html( $r['method'] ),
				esc_html( $r['route'] ),
				intval( $r['status'] ),
				esc_attr( $r['ua'] ),
				esc_html( ( strlen( $r['ua'] ) > 48 ) ? ( substr( $r['ua'], 0, 48 ) . '…' ) : $r['ua'] )
			);
		}
		echo '</tbody></table></div></div>';

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
		$now    = time();
		$cut24  = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
		$cut10m = gmdate( 'Y-m-d H:i:s', $now - 600 );

		$rows1 = $wpdb->get_results( $wpdb->prepare(
			"SELECT CASE WHEN ip REGEXP '^[0-9]{1,4}$' AND ip_v4 <> '' THEN ip_v4 ELSE ip END AS label, COUNT(*) c
			 FROM {$this->tbl} WHERE ts >= %s
			 GROUP BY label ORDER BY c DESC LIMIT 20",
			$cut24
		) );
		$rows2 = $wpdb->get_results( $wpdb->prepare(
			"SELECT route, COUNT(*) c FROM {$this->tbl} WHERE ts >= %s GROUP BY route ORDER BY c DESC LIMIT 20",
			$cut24
		) );
		$rows4 = $wpdb->get_results( $wpdb->prepare(
			"SELECT ip_v4, COUNT(*) c FROM {$this->tbl} WHERE ts >= %s AND ip_v4 <> '' GROUP BY ip_v4 ORDER BY c DESC LIMIT 20",
			$cut24
		) );

		$opts  = $this->get_options();
		$cut1h = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$th    = max( 10, intval( $opts['rest_threshold_hour'] ) );
		$rows3 = $wpdb->get_results( $wpdb->prepare(
			"SELECT CASE WHEN ip REGEXP '^[0-9]{1,4}$' AND ip_v4 <> '' THEN ip_v4 ELSE ip END AS label, COUNT(*) c
			 FROM {$this->tbl} WHERE ts >= %s
			 GROUP BY label HAVING c >= %d ORDER BY c DESC LIMIT 50",
			$cut1h, $th
		) );

		$rows5 = $wpdb->get_results( $wpdb->prepare(
			"SELECT CASE WHEN ip REGEXP '^[0-9]{1,4}$' AND ip_v4 <> '' THEN ip_v4 ELSE ip END AS label, COUNT(*) c
			 FROM {$this->tbl} WHERE ts >= %s
			 GROUP BY label ORDER BY c DESC LIMIT 20",
			$cut10m
		) );

		$topIps24 = array();   foreach ( (array) $rows1 as $r ) { $topIps24[ $r->label ]   = (int) $r->c; }
		$topRoutes = array();  foreach ( (array) $rows2 as $r ) { $topRoutes[ $r->route ]  = (int) $r->c; }
		$topV4s = array();     foreach ( (array) $rows4 as $r ) { $topV4s[ $r->ip_v4 ]     = (int) $r->c; }
		$suspicious = array(); foreach ( (array) $rows3 as $r ) { $suspicious[ $r->label ] = (int) $r->c; }
		$topIps10m = array();  foreach ( (array) $rows5 as $r ) { $topIps10m[ $r->label ]  = (int) $r->c; }

		return array( $topIps24, $topRoutes, $topV4s, $suspicious, $topIps10m );
	}
	private function recent_rows( $limit = 25 ) {
		global $wpdb;
		$limit = max(1, min(200, intval($limit)));
		return (array) $wpdb->get_results( "SELECT ts, ip, ip_v4, method, route, status, ua FROM {$this->tbl} ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
	}
	private function render_counts_card( $title, $counts, $label, $highlight = false ) {
		echo '<div class="postbox"><h2 class="hndle" style="padding:8px 12px;">' . esc_html( $title ) . '</h2><div class="inside"><table class="widefat striped"><thead><tr><th>' . esc_html( ucfirst( $label ) ) . '</th><th>Count</th></tr></thead><tbody>';
		if ( empty( $counts ) ) { echo '<tr><td colspan="2"><em>None</em></td></tr>'; }
		foreach ( $counts as $k => $v ) {
			printf( '<tr><td>%s</td><td%s>%d</td></tr>', esc_html( $k ), $highlight ? ' style="color:#c00;font-weight:600;"' : '', intval( $v ) );
		}
		echo '</tbody></table></div></div>';
	}

	public function handle_purge_rest() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_purge_rest' ) ) { wp_die( 'Not allowed' ); }
		$this->maybe_migrate();
		$days = max( 1, intval( $this->get_options()['rest_retention_days'] ) );
		global $wpdb;
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tbl} WHERE ts < %s", $cut ) );
		wp_safe_redirect( admin_url( 'admin.php?page=ordersentinel-rest&tab=settings' ) ); exit;
	}
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'ordersentinel_export_rest_csv' ) ) { wp_die( 'Not allowed' ); }
		if ( ! $this->ensure_table_exists() ) { wp_die( 'No table' ); }
		global $wpdb;
		$days = isset( $_GET['days'] ) ? max( 1, min( 365, intval( $_GET['days'] ) ) ) : 7;
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ts, ip, ip_ver, ip_v4, method, route, status, user_id, ua, ref, took_ms, flags FROM {$this->tbl} WHERE ts >= %s ORDER BY id DESC LIMIT 50000", $cut ), ARRAY_A ); // phpcs:ignore
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ordersentinel-rest-' . $days . 'd.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ts','ip','ip_ver','ip_v4','method','route','status','user_id','ua','ref','took_ms','flags' ) );
		foreach ( (array) $rows as $r ) { fputcsv( $out, $r ); }
		fclose( $out ); exit;
	}

	public function handle_backup_purge_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_backup_purge_now' ) ) { wp_die( 'Not allowed' ); }
		if ( ! $this->ensure_table_exists() ) { wp_die( 'No table' ); }
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT ts, ip, ip_ver, ip_v4, method, route, status, user_id, ua, ref, took_ms, flags FROM {$this->tbl} ORDER BY id ASC", ARRAY_A );
		$upload = wp_upload_dir();
		$dir = trailingslashit( $upload['basedir'] ) . 'ordersentinel';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$file = $dir . '/restlog-' . gmdate( 'Ymd-His' ) . '.csv';
		$out = fopen( $file, 'w' );
		if ( $out ) {
			fputcsv( $out, array( 'ts','ip','ip_ver','ip_v4','method','route','status','user_id','ua','ref','took_ms','flags' ) );
			foreach ( (array) $rows as $r ) { fputcsv( $out, $r ); }
			fclose( $out );
		}
		$wpdb->query( "TRUNCATE TABLE {$this->tbl}" ); // phpcs:ignore
		wp_safe_redirect( admin_url( 'admin.php?page=ordersentinel-rest&tab=tools' ) ); exit;
	}

	public function handle_repair_truncated_ips() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ordersentinel_repair_trunc_ips' ) ) { wp_die( 'Not allowed' ); }
		if ( ! $this->ensure_table_exists() ) { wp_die( 'No table' ); }
		global $wpdb;

		// 1) Promote ip_v4 when ip is 1–4 digits.
		$simple = $wpdb->query( "UPDATE {$this->tbl} SET ip = ip_v4, ip_ver = 4, flags = CONCAT(flags, IF(flags LIKE '%repair_ip%', '', ';repair_ip')) WHERE ip REGEXP '^[0-9]{1,4}$' AND ip_v4 <> ''" ); // phpcs:ignore
		if ( ! is_int( $simple ) ) { $simple = 0; }

		// 2) Heuristic: same UA within ±3s that has a full IP.
		$trunc = $wpdb->get_results( "SELECT id, ts, ua FROM {$this->tbl} WHERE ip REGEXP '^[0-9]{1,4}$'", ARRAY_A );
		$heur  = 0;
		if ( $trunc ) {
			foreach ( $trunc as $row ) {
				$id = intval( $row['id'] );
				$ts = $row['ts'];
				$ua = $row['ua'];

				$cand = $wpdb->get_row( $wpdb->prepare(
					"SELECT ip, ip_ver, ip_v4
					 FROM {$this->tbl}
					 WHERE id <> %d AND ua = %s
					   AND ABS(TIMESTAMPDIFF(SECOND, ts, %s)) <= 3
					   AND ip <> '' AND ip NOT REGEXP '^[0-9]{1,4}$'
					 ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, %s)) ASC
					 LIMIT 1",
					$id, $ua, $ts, $ts
				), ARRAY_A );

				if ( $cand && ! empty( $cand['ip'] ) ) {
					$wpdb->update(
						$this->tbl,
						array(
							'ip'     => (string) $cand['ip'],
							'ip_ver' => intval( $cand['ip_ver'] ),
							'ip_v4'  => (string) $cand['ip_v4'],
							'flags'  => $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(flags, IF(flags LIKE '%heur_repair%', '', ';heur_repair')) FROM {$this->tbl} WHERE id = %d", $id ) ),
						),
						array( 'id' => $id ),
						array( '%s','%d','%s','%s' ),
						array( '%d' )
					);
					$heur++;
				}
			}
		}

		// 3) IPv6-prefix pass (same UA): for ip like '2600', copy nearest '2600:%' within ±10s.
		$v6pref = 0;
		$trunc4 = $wpdb->get_results( "SELECT id, ts, ua, ip FROM {$this->tbl} WHERE ip REGEXP '^[0-9]{4}$'", ARRAY_A );
		if ( $trunc4 ) {
			foreach ( $trunc4 as $row ) {
				$id   = intval( $row['id'] );
				$ts   = $row['ts'];
				$ua   = $row['ua'];
				$pref = $row['ip'];

				$cand = $wpdb->get_row( $wpdb->prepare(
					"SELECT ip, ip_ver, ip_v4
					 FROM {$this->tbl}
					 WHERE id <> %d AND ua = %s
					   AND ABS(TIMESTAMPDIFF(SECOND, ts, %s)) <= 10
					   AND ip LIKE %s
					   AND ip NOT REGEXP '^[0-9]{1,4}$'
					 ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, %s)) ASC
					 LIMIT 1",
					$id, $ua, $ts, $pref . ':%', $ts
				), ARRAY_A );

				if ( $cand && ! empty( $cand['ip'] ) ) {
					$wpdb->update(
						$this->tbl,
						array(
							'ip'     => (string) $cand['ip'],
							'ip_ver' => intval( $cand['ip_ver'] ),
							'ip_v4'  => (string) $cand['ip_v4'],
							'flags'  => $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(flags, IF(flags LIKE '%v6pref_repair%', '', ';v6pref_repair')) FROM {$this->tbl} WHERE id = %d", $id ) ),
						),
						array( 'id' => $id ),
						array( '%s','%d','%s','%s' ),
						array( '%d' )
					);
					$v6pref++;
				}
			}
		}

		// 4) IPv6-prefix (any UA ±60s).
		$v6any = 0;
		$still = $wpdb->get_results( "SELECT id, ts, ip FROM {$this->tbl} WHERE ip REGEXP '^[0-9]{4}$'", ARRAY_A );
		if ( $still ) {
			foreach ( $still as $row ) {
				$id   = intval( $row['id'] );
				$ts   = $row['ts'];
				$pref = $row['ip'];

				$cand = $wpdb->get_row( $wpdb->prepare(
					"SELECT ip, ip_ver, ip_v4
					 FROM {$this->tbl}
					 WHERE id <> %d
					   AND ABS(TIMESTAMPDIFF(SECOND, ts, %s)) <= 60
					   AND ip LIKE %s
					   AND ip NOT REGEXP '^[0-9]{1,4}$'
					 ORDER BY ABS(TIMESTAMPDIFF(SECOND, ts, %s)) ASC
					 LIMIT 1",
					$id, $ts, $pref . ':%', $ts
				), ARRAY_A );

				if ( $cand && ! empty( $cand['ip'] ) ) {
					$wpdb->update(
						$this->tbl,
						array(
							'ip'     => (string) $cand['ip'],
							'ip_ver' => intval( $cand['ip_ver'] ),
							'ip_v4'  => (string) $cand['ip_v4'],
							'flags'  => $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(flags, IF(flags LIKE '%v6any_repair%', '', ';v6any_repair')) FROM {$this->tbl} WHERE id = %d", $id ) ),
						),
						array( 'id' => $id ),
						array( '%s','%d','%s','%s' ),
						array( '%d' )
					);
					$v6any++;
				}
			}
		}

		// 5) IPv6-prefix (global, last 24h) — last resort.
		$v6glob = 0;
		$still = $wpdb->get_results( "SELECT id, ip FROM {$this->tbl} WHERE ip REGEXP '^[0-9]{4}$'", ARRAY_A );
		if ( $still ) {
			$cut24 = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			foreach ( $still as $row ) {
				$id   = intval( $row['id'] );
				$pref = $row['ip'];

				$cand = $wpdb->get_row( $wpdb->prepare(
					"SELECT ip, ip_ver, ip_v4
					 FROM {$this->tbl}
					 WHERE ts >= %s
					   AND ip LIKE %s
					   AND ip NOT REGEXP '^[0-9]{1,4}$'
					 ORDER BY id DESC
					 LIMIT 1",
					$cut24, $pref . ':%'
				), ARRAY_A );

				if ( $cand && ! empty( $cand['ip'] ) ) {
					$wpdb->update(
						$this->tbl,
						array(
							'ip'     => (string) $cand['ip'],
							'ip_ver' => intval( $cand['ip_ver'] ),
							'ip_v4'  => (string) $cand['ip_v4'],
							'flags'  => $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(flags, IF(flags LIKE '%v6glob_repair%', '', ';v6glob_repair')) FROM {$this->tbl} WHERE id = %d", $id ) ),
						),
						array( 'id' => $id ),
						array( '%s','%d','%s','%s' ),
						array( '%d' )
					);
					$v6glob++;
				}
			}
		}

		$url = add_query_arg(
			array(
				'page'             => 'ordersentinel-rest',
				'tab'              => 'tools',
				'repaired_simple'  => $simple,
				'repaired_heur'    => $heur,
				'repaired_v6pref'  => $v6pref,
				'repaired_v6any'   => $v6any,
				'repaired_v6glob'  => $v6glob,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url ); exit;
	}
}
endif;

$GLOBALS['ordersentinel_rest_mu'] = isset( $GLOBALS['ordersentinel_rest_mu'] ) && ( $GLOBALS['ordersentinel_rest_mu'] instanceof OS_REST_Monitor )
	? $GLOBALS['ordersentinel_rest_mu']
	: new OS_REST_Monitor();
