<?php
defined('ABSPATH') || exit;

if ( ! class_exists( 'OS_REST_Monitor', false ) ) :

class OS_REST_Monitor {

    const VERSION = '1.0.0';

    /** Option key */
    const OPT = 'ordersentinel_rest_settings';

    /** Hook bootstrap */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybe_create_or_upgrade_table' ] );

        // REST request enforcement happens BEFORE dispatch
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'pre_dispatch_enforce' ], 0, 3 );
        // Logging happens AFTER dispatch
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'log_rest' ], 999, 3 );
    }

    /** Menu under WooCommerce (fallback Tools) */
    public static function admin_menu() {
        $cb    = [ __CLASS__, 'render_page' ];
        $title = 'REST Monitor';

        if ( class_exists( 'WooCommerce' ) ) {
            $cap = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
            add_submenu_page( 'woocommerce', $title, $title, $cap, 'ordersentinel-rest', $cb );
            return;
        }
        add_management_page( $title, $title, 'manage_options', 'ordersentinel-rest', $cb );
    }

    /** Settings (with sane defaults) */
    private static function settings() {
        $defaults = [
            'log_lines'           => 50,
            'default_window'      => 600,   // seconds (10 min)
            'trust_proxies'       => 0,
            'rate_rest_per_min'   => 0,     // 0 = disabled
            'rate_search_per_min' => 0,     // placeholder (non-REST search)
            'verify_search_dns'   => 0,
            'enforce_mode'        => 'monitor', // monitor | throttle | block
            'allowlist'           => [],
            'blocklist'           => [],
        ];
        $opt = get_option( self::OPT );
        if ( ! is_array( $opt ) ) $opt = [];
        // Normalize arrays
        foreach ( ['allowlist','blocklist'] as $k ) {
            if ( empty( $opt[$k] ) ) { $opt[$k] = []; }
            if ( is_string( $opt[$k] ) ) { $opt[$k] = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $opt[$k] ) ) ); }
        }
        return wp_parse_args( $opt, $defaults );
    }

    private static function save_settings_from_post() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( empty( $_POST['osrm_save_settings'] ) || ! check_admin_referer( 'osrm_settings' ) ) return;

        $s = self::settings();
        $s['log_lines']           = max( 10, absint( $_POST['log_lines'] ?? $s['log_lines'] ) );
        $s['default_window']      = max( 10, absint( $_POST['default_window'] ?? $s['default_window'] ) );
        $s['trust_proxies']       = isset( $_POST['trust_proxies'] ) ? 1 : 0;
        $s['rate_rest_per_min']   = max( 0, absint( $_POST['rate_rest_per_min'] ?? 0 ) );
        $s['rate_search_per_min'] = max( 0, absint( $_POST['rate_search_per_min'] ?? 0 ) );
        $s['verify_search_dns']   = isset( $_POST['verify_search_dns'] ) ? 1 : 0;
        $mode                     = sanitize_key( $_POST['enforce_mode'] ?? 'monitor' );
        $s['enforce_mode']        = in_array( $mode, ['monitor','throttle','block'], true ) ? $mode : 'monitor';

        $allow = trim( (string) ( $_POST['allowlist'] ?? '' ) );
        $block = trim( (string) ( $_POST['blocklist'] ?? '' ) );
        $s['allowlist'] = $allow ? array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $allow ) ) ) : [];
        $s['blocklist'] = $block ? array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $block ) ) ) : [];

        update_option( self::OPT, $s, false );
        add_settings_error( 'osrm', 'saved', 'Settings saved.', 'updated' );
    }

    /** Table name */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ordersentinel_restlog';
    }

    /** Create/upgrade DB schema and add any missing columns/indexes */
    public static function maybe_create_or_upgrade_table() {
        global $wpdb;
        $tbl = self::table();
        $charset = $wpdb->get_charset_collate();

        // Create if missing (dbDelta-friendly)
        $sql = "CREATE TABLE IF NOT EXISTS `$tbl` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            ip_v4 VARCHAR(15) NOT NULL DEFAULT '',
            ua TEXT NULL,
            route VARCHAR(191) NOT NULL DEFAULT '',
            code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            meth VARCHAR(8) NOT NULL DEFAULT '',
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            bytes INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY ts (ts),
            KEY ip (ip),
            KEY ip_v4 (ip_v4),
            KEY route (route)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Ensure required columns exist (idempotent)
        $need = [
            'ip' => "ALTER TABLE `$tbl` ADD COLUMN ip VARCHAR(45) NOT NULL DEFAULT ''",
            'ip_v4' => "ALTER TABLE `$tbl` ADD COLUMN ip_v4 VARCHAR(15) NOT NULL DEFAULT ''",
            'ua' => "ALTER TABLE `$tbl` ADD COLUMN ua TEXT NULL",
            'route' => "ALTER TABLE `$tbl` ADD COLUMN route VARCHAR(191) NOT NULL DEFAULT ''",
            'code' => "ALTER TABLE `$tbl` ADD COLUMN code SMALLINT UNSIGNED NOT NULL DEFAULT 0",
            'meth' => "ALTER TABLE `$tbl` ADD COLUMN meth VARCHAR(8) NOT NULL DEFAULT ''",
            'duration_ms' => "ALTER TABLE `$tbl` ADD COLUMN duration_ms INT UNSIGNED NOT NULL DEFAULT 0",
            'bytes' => "ALTER TABLE `$tbl` ADD COLUMN bytes INT UNSIGNED NOT NULL DEFAULT 0",
        ];
        foreach ( $need as $col => $alter ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$tbl` LIKE %s", $col ) );
            if ( ! $exists ) {
                $wpdb->query( $alter ); // phpcs:ignore
            }
        }
    }

    /** Determine client IP (with optional proxy header trust) */
    private static function client_ip( $trust_proxies ) {
        $candidates = [];
        if ( $trust_proxies ) {
            // Cloudflare
            if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
            }
            // Generic proxies
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                // First public IP in the list
                $parts = array_map( 'trim', explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
                foreach ( $parts as $p ) {
                    if ( filter_var( $p, FILTER_VALIDATE_IP ) ) { $candidates[] = $p; }
                }
            }
            if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
                $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
            }
        }
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $candidates[] = $_SERVER['REMOTE_ADDR'];
        }
        foreach ( $candidates as $ip ) {
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }

    /** Extract embedded IPv4 (e.g. ::ffff:1.2.3.4) */
    private static function embedded_ipv4( $ip ) {
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) return $ip;
        if ( strpos( $ip, ':' ) !== false ) {
            if ( preg_match( '/(\d{1,3}(?:\.\d{1,3}){3})$/', $ip, $m ) ) return $m[1];
        }
        return '';
    }

    /** Rate limiting and allow/deny enforcement (REST only) */
    public static function pre_dispatch_enforce( $result, $server, $request ) {
        if ( is_wp_error( $result ) ) return $result;

        $s   = self::settings();
        $ip  = self::client_ip( (bool) $s['trust_proxies'] );
        $v4  = self::embedded_ipv4( $ip );
        $route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        $meth  = method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '';

        // Allow/deny lists
        if ( ! empty( $s['allowlist'] ) && in_array( $ip, $s['allowlist'], true ) ) {
            return $result;
        }
        if ( ! empty( $s['blocklist'] ) ) {
            if ( in_array( $ip, $s['blocklist'], true ) || ( $v4 && in_array( $v4, $s['blocklist'], true ) ) ) {
                if ( $s['enforce_mode'] === 'block' ) {
                    return new WP_Error( 'ordersentinel_blocked', 'Blocked by OrderSentinel.', [ 'status' => 403 ] );
                }
            }
        }

        // Rate limit (REST per-minute)
        $limit = (int) $s['rate_rest_per_min'];
        if ( $limit > 0 && in_array( $s['enforce_mode'], ['throttle','block'], true ) ) {
            global $wpdb;
            $tbl = self::table();
            $since = gmdate( 'Y-m-d H:i:s', time() - 60 );
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `$tbl` WHERE ts >= %s AND (ip = %s OR ip_v4 = %s)",
                $since, $ip, $v4
            ) );
            if ( $count >= $limit ) {
                return new WP_Error( 'ordersentinel_rate_limited', 'Too many requests.', [ 'status' => 429 ] );
            }
        }

        return $result;
    }

    /** Log REST traffic after dispatch */
    public static function log_rest( $result, $server, $request ) {
        $s   = self::settings();
        $ip  = self::client_ip( (bool) $s['trust_proxies'] );
        $v4  = self::embedded_ipv4( $ip );
        $ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 65535 ) : '';
        $route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        $meth  = method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '';
        $code  = 0;

        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            if ( is_array( $data ) && ! empty( $data['status'] ) && is_numeric( $data['status'] ) ) {
                $code = (int) $data['status'];
            }
        } elseif ( is_object( $result ) && method_exists( $result, 'get_status' ) ) {
            $code = (int) $result->get_status();
        }

        self::insert_log_row( [
            'ip'   => $ip,
            'ip_v4'=> $v4,
            'ua'   => $ua,
            'route'=> $route,
            'code' => $code,
            'meth' => $meth,
        ] );

        return $result;
    }

    /** Insert row safely */
    private static function insert_log_row( $row ) {
        global $wpdb;
        $tbl = self::table();
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO `$tbl` (`ip`,`ip_v4`,`ua`,`route`,`code`,`meth`,`ts`)
             VALUES (%s,%s,%s,%s,%d,%s,UTC_TIMESTAMP())",
            (string)$row['ip'], (string)$row['ip_v4'], (string)$row['ua'],
            (string)$row['route'], (int)$row['code'], (string)$row['meth']
        ) );
    }

    /** Render admin page with tabs */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'order-sentinel' ) );
        }

        self::maybe_create_or_upgrade_table();

        $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore
        $tabs = [ 'overview' => 'Overview', 'tools' => 'Tools', 'settings' => 'Settings' ];
        $base = admin_url( ( class_exists( 'WooCommerce' ) ? 'admin.php?page=ordersentinel-rest' : 'tools.php?page=ordersentinel-rest' ) );

        echo '<div class="wrap"><h1>REST Monitor</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $k => $label ) {
            $url   = esc_url( $base . '&tab=' . $k );
            $class = 'nav-tab' . ( $tab === $k ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $class ) . '" href="' . $url . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        settings_errors( 'osrm' );

        echo '<div style="margin-top:12px">';
        switch ( $tab ) {
            case 'tools':
                self::handle_tools_actions();
                self::render_tools();
                break;

            case 'settings':
                self::save_settings_from_post();
                self::render_settings();
                break;

            case 'overview':
            default:
                self::render_overview();
                break;
        }
        echo '</div></div>';
    }

    /** Timeframe helpers */
    private static function current_window_seconds() {
        $s = self::settings();
        $n = isset( $_GET['q_n'] ) ? absint( $_GET['q_n'] ) : 0;      // phpcs:ignore
        $u = isset( $_GET['q_unit'] ) ? sanitize_key( $_GET['q_unit'] ) : ''; // phpcs:ignore
        if ( $n && in_array( $u, ['sec','min','hour','day'], true ) ) {
            switch ( $u ) {
                case 'sec':  return max(10, $n);
                case 'min':  return max(10, $n*60);
                case 'hour': return max(10, $n*3600);
                case 'day':  return max(10, $n*86400);
            }
        }
        return (int) $s['default_window'];
    }
    private static function window_label( $secs ) {
        if ( $secs % 86400 === 0 ) return sprintf( 'Last %d day(s)', $secs/86400 );
        if ( $secs % 3600  === 0 ) return sprintf( 'Last %d hour(s)', $secs/3600 );
        if ( $secs % 60    === 0 ) return sprintf( 'Last %d minute(s)', $secs/60 );
        return sprintf( 'Last %d second(s)', $secs );
    }

    /** Overview UI */
    private static function render_overview() {
        global $wpdb;
        $tbl  = self::table();
        $s    = self::settings();
        $secs = self::current_window_seconds();
        $since = gmdate( 'Y-m-d H:i:s', time() - $secs );
        $label = esc_html( self::window_label( $secs ) );

        // timeframe picker inline
        $base = remove_query_arg( ['q_n','q_unit'] );
        echo '<form method="get" style="margin:8px 0 16px;display:flex;gap:8px;align-items:center">';
        foreach ( ['page','tab'] as $keep ) {
            if ( isset($_GET[$keep]) ) { // phpcs:ignore
                echo '<input type="hidden" name="'.esc_attr($keep).'" value="'.esc_attr( sanitize_text_field( $_GET[$keep] ) ).'"/>'; // phpcs:ignore
            }
        }
        $n = isset($_GET['q_n']) ? absint($_GET['q_n']) : max(1, (int)round($secs/60));
        $u = isset($_GET['q_unit']) ? sanitize_key($_GET['q_unit']) : 'min';
        echo '<label>Window:</label>';
        echo '<input type="number" min="1" name="q_n" value="'.esc_attr($n).'" style="width:80px" />';
        echo '<select name="q_unit">';
        foreach ( ['sec'=>'seconds','min'=>'minutes','hour'=>'hours','day'=>'days'] as $ku=>$lu ) {
            $sel = $u===$ku ? ' selected' : '';
            echo '<option value="'.esc_attr($ku).'"'.$sel.'>'.esc_html($lu).'</option>';
        }
        echo '</select>';
        echo '<button class="button">Apply</button>';
        echo '</form>';

        // Suspicious first (above Top Routes)
        self::render_suspicious_card( $since, $label, $s );

        // Top IPs (IPv6/any)
        $top_ips = $wpdb->get_results( $wpdb->prepare(
            "SELECT ip, COUNT(*) as c FROM `$tbl` WHERE ts >= %s GROUP BY ip ORDER BY c DESC LIMIT 10",
            $since
        ), ARRAY_A );
        echo self::card_open( 'Top IPs ('. $label .')' );
        self::simple_table( ['Ip','Count'], $top_ips );
        echo self::card_close();

        // Top IPv4s
        $top_v4 = $wpdb->get_results( $wpdb->prepare(
            "SELECT ip_v4 as ip_v4, COUNT(*) as c FROM `$tbl` WHERE ts >= %s AND ip_v4 <> '' GROUP BY ip_v4 ORDER BY c DESC LIMIT 10",
            $since
        ), ARRAY_A );
        echo self::card_open( 'Top IPv4s ('. $label .')' );
        self::simple_table( ['Ip_v4','Count'], $top_v4 );
        echo self::card_close();

        // Top Routes (below suspicious)
        $top_routes = $wpdb->get_results( $wpdb->prepare(
            "SELECT route, COUNT(*) as c FROM `$tbl` WHERE ts >= %s GROUP BY route ORDER BY c DESC LIMIT 10",
            $since
        ), ARRAY_A );
        echo self::card_open( 'Top Routes ('. $label .')' );
        self::simple_table( ['Route','Count'], $top_routes );
        echo self::card_close();

        // Recent requests with Status column
        $recent = $wpdb->get_results( "SELECT ts, ip, ip_v4, meth, code, route, LEFT(ua,120) ua FROM `$tbl` ORDER BY ts DESC LIMIT ". (int)$s['log_lines'], ARRAY_A );
        echo self::card_open( 'Recent Requests (showing '. (int)$s['log_lines'] .')' );
        self::simple_table( ['Time (UTC)','IP','IPv4','Method','Status','Route','UA'], $recent, function($r){
            return [
                esc_html( $r['ts'] ),
                esc_html( $r['ip'] ),
                esc_html( $r['ip_v4'] ),
                esc_html( $r['meth'] ),
                esc_html( (string)$r['code'] ),
                esc_html( $r['route'] ),
                esc_html( $r['ua'] ),
            ];
        } );
        echo self::card_close();
    }

    /** Suspicious card (rate thresholds) */
    private static function render_suspicious_card( $since, $label, $s ) {
        global $wpdb;
        $tbl = self::table();
        $limit = max( 10, (int)$s['rate_rest_per_min'] ?: 60 );
        // Find IPs over threshold within window
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ip, ip_v4, COUNT(*) c
             FROM `$tbl`
             WHERE ts >= %s
             GROUP BY ip, ip_v4
             HAVING c >= %d
             ORDER BY c DESC LIMIT 20",
             $since, $limit
        ), ARRAY_A );
        echo self::card_open( 'Suspicious Activity ('. $label .'; threshold ≥ '. (int)$limit .'/min)' );
        if ( empty( $rows ) ) {
            echo '<p>No suspicious activity in this window.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>IP</th><th>IPv4</th><th>Count</th><th>Action</th></tr></thead><tbody>';
            foreach ( $rows as $r ) {
                $ip  = $r['ip'];
                $v4  = $r['ip_v4'];
                $act = wp_nonce_url( add_query_arg( ['osrm_action'=>'ban','ip'=>$ip], menu_page_url( 'ordersentinel-rest', false ) ), 'osrm_tools' );
                echo '<tr>';
                echo '<td>'.esc_html($ip).'</td><td>'.esc_html($v4).'</td><td>'.esc_html($r['c']).'</td>';
                echo '<td><a class="button button-small" href="'.esc_url($act).'">Ban</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo self::card_close();
    }

    /** Tools actions: repair, backup+purge, import/replace blacklist */
    private static function handle_tools_actions() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Ban from suspicious table
        if ( isset($_GET['osrm_action']) && $_GET['osrm_action']==='ban' && check_admin_referer('osrm_tools') && !empty($_GET['ip']) ) { // phpcs:ignore
            $ip = sanitize_text_field( wp_unslash( $_GET['ip'] ) );
            $s  = self::settings();
            if ( ! in_array( $ip, $s['blocklist'], true ) ) {
                $s['blocklist'][] = $ip;
                update_option( self::OPT, $s, false );
                add_settings_error( 'osrm', 'banned', 'IP added to blacklist.', 'updated' );
            }
        }

        // Repair truncated IPs
        if ( ! empty($_POST['osrm_repair_trunc']) && check_admin_referer('osrm_tools') ) { // phpcs:ignore
            $fixed = self::repair_truncated_ips();
            add_settings_error( 'osrm', 'repaired', sprintf( 'Repair complete. Fixed %d row(s).', (int)$fixed ), 'updated' );
        }

        // Backup & purge
        if ( ! empty($_POST['osrm_backup_purge']) && check_admin_referer('osrm_tools') ) { // phpcs:ignore
            $file = self::backup_to_uploads();
            self::purge_all();
            add_settings_error( 'osrm', 'purged', 'Backup saved to ' . esc_html( basename( $file ) ) . ' and logs purged.', 'updated' );
        }

        // Import/replace blacklist
        if ( ! empty($_POST['osrm_import_blacklist']) && check_admin_referer('osrm_tools') ) { // phpcs:ignore
            $replace = ! empty( $_POST['replace_blacklist'] );
            $raw = trim( (string) ( $_POST['blacklist_blob'] ?? '' ) );
            $list = $raw ? array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $raw ) ) ) : [];
            $s = self::settings();
            if ( $replace ) {
                $s['blocklist'] = $list;
            } else {
                $s['blocklist'] = array_values( array_unique( array_merge( $s['blocklist'], $list ) ) );
            }
            update_option( self::OPT, $s, false );
            add_settings_error( 'osrm', 'imported', sprintf( 'Blacklist %s. Now %d entries.', $replace?'replaced':'merged', count($s['blocklist']) ), 'updated' );
        }
    }

    private static function render_tools() {
        echo self::card_open('Maintenance & Import / Export');

        echo '<form method="post" style="margin-bottom:16px">';
        wp_nonce_field('osrm_tools');
        echo '<h3>Repair truncated IPs</h3>';
        echo '<p>Fix rows where <code>ip</code> is a short digit (e.g. <code>2600</code>) by mapping to the most common full IPv6 that starts with that prefix.</p>';
        echo '<button class="button" name="osrm_repair_trunc" value="1">Repair now</button>';
        echo '</form>';

        echo '<form method="post" style="margin-bottom:16px">';
        wp_nonce_field('osrm_tools');
        echo '<h3>Backup & Purge</h3>';
        echo '<p>Exports all rows to <code>wp-content/uploads/ordersentinel/</code> with a timestamped filename, then purges the table.</p>';
        echo '<button class="button button-primary" name="osrm_backup_purge" value="1">Backup & Purge</button>';
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field('osrm_tools');
        echo '<h3>Import / Replace Blacklist</h3>';
        echo '<p>Paste IPs (one per line). Choose merge or replace.</p>';
        echo '<p><label><input type="checkbox" name="replace_blacklist" value="1"> Replace instead of merge</label></p>';
        echo '<p><textarea name="blacklist_blob" rows="8" style="width:100%"></textarea></p>';
        echo '<p><button class="button" name="osrm_import_blacklist" value="1">Apply</button></p>';
        echo '</form>';

        echo self::card_close();
    }

    private static function render_settings() {
        $s = self::settings();

        echo '<form method="post">';
        wp_nonce_field( 'osrm_settings' );
        echo self::card_open('Display & Window');
        echo '<p><label>Default time window (seconds): <input type="number" min="10" name="default_window" value="'. esc_attr($s['default_window']) .'" /></label></p>';
        echo '<p><label>Recent log lines: <input type="number" min="10" name="log_lines" value="'. esc_attr($s['log_lines']) .'" /></label></p>';
        echo self::card_close();

        echo self::card_open('Proxies & Rate Limits');
        echo '<p><label><input type="checkbox" name="trust_proxies" value="1" '. checked( $s['trust_proxies'], 1, false ) .'/> Trust proxy headers (CF-Connecting-IP / X-Forwarded-For / X-Real-IP)</label></p>';
        echo '<p><label>REST rate limit (requests per minute; 0=disabled): <input type="number" min="0" name="rate_rest_per_min" value="'. esc_attr($s['rate_rest_per_min']) .'" /></label></p>';
        echo '<p><label>Search rate limit (per minute; placeholder): <input type="number" min="0" name="rate_search_per_min" value="'. esc_attr($s['rate_search_per_min']) .'" /></label> ';
        echo '<label style="margin-left:12px"><input type="checkbox" name="verify_search_dns" value="1" '. checked( $s['verify_search_dns'], 1, false ) .'/> Verify search bots via DNS</label></p>';
        echo '<p><label>Enforcement: <select name="enforce_mode">';
        foreach ( ['monitor'=>'Monitor only','throttle'=>'Throttle (429)','block'=>'Hard block (403)'] as $k=>$lab ) {
            echo '<option value="'.esc_attr($k).'" '.selected($s['enforce_mode'],$k,false).'>'.esc_html($lab).'</option>';
        }
        echo '</select></label></p>';
        echo self::card_close();

        echo self::card_open('Allowlist / Blacklist');
        echo '<p><strong>Allowlist</strong> (one per line; bypasses limits):</p>';
        echo '<p><textarea name="allowlist" rows="5" style="width:100%">'. esc_textarea( implode("\n",$s['allowlist']) ) .'</textarea></p>';
        echo '<p><strong>Blacklist</strong> (one per line; blocked when enforcement is "block"):</p>';
        echo '<p><textarea name="blocklist" rows="5" style="width:100%">'. esc_textarea( implode("\n",$s['blocklist']) ) .'</textarea></p>';
        echo self::card_close();

        echo '<p><button class="button button-primary" name="osrm_save_settings" value="1">Save settings</button></p>';
        echo '</form>';
    }

    /** Helpers: UI cards & tables */
    private static function card_open( $title ) {
        return '<div class="postbox" style="padding:0;margin:0 0 16px;"><h2 class="hndle" style="padding:12px 16px;margin:0;border-bottom:1px solid #ddd;">'
            . esc_html( $title ) . '</h2><div class="inside" style="padding:12px 16px;">';
    }
    private static function card_close() {
        return '</div></div>';
    }
    private static function simple_table( $heads, $rows, $row_cb = null ) {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ( $heads as $h ) echo '<th>'.esc_html($h).'</th>';
        echo '</tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="'.count($heads).'"><em>No data</em></td></tr>';
        } else {
            foreach ( $rows as $r ) {
                echo '<tr>';
                if ( $row_cb ) {
                    $cells = $row_cb( $r );
                    foreach ( $cells as $c ) echo '<td>'.$c.'</td>';
                } else {
                    foreach ( $r as $v ) echo '<td>'.esc_html( (string)$v ).'</td>';
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }

    /** Tools: repair truncated IPs like "2600" -> best matching "2600:..." */
    private static function repair_truncated_ips() {
        global $wpdb;
        $tbl = self::table();
        $shorts = $wpdb->get_col( "SELECT DISTINCT ip FROM `$tbl` WHERE ip REGEXP '^[0-9]{1,3}$'" );
        $fixed = 0;
        foreach ( $shorts as $short ) {
            $full = $wpdb->get_var( $wpdb->prepare(
                "SELECT ip FROM `$tbl` WHERE ip LIKE %s AND LENGTH(ip) > 8 GROUP BY ip ORDER BY COUNT(*) DESC LIMIT 1",
                $wpdb->esc_like($short).':%'
            ) );
            if ( $full ) {
                $wpdb->query( $wpdb->prepare( "UPDATE `$tbl` SET ip = %s WHERE ip = %s", $full, $short ) );
                $fixed += $wpdb->rows_affected;
            }
        }
        return (int) $fixed;
    }

    /** Tools: backup everything to uploads and return filepath */
    private static function backup_to_uploads() {
        global $wpdb;
        $tbl = self::table();
        $uploads = wp_upload_dir();
        $dir = trailingslashit( $uploads['basedir'] ) . 'ordersentinel';
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        $file = $dir . '/restlog-' . gmdate('Ymd-His') . '.csv';
        $fh = fopen( $file, 'w' );
        if ( $fh ) {
            fputcsv( $fh, ['id','ts','ip','ip_v4','ua','route','Status','meth','duration_ms','bytes'] );
            $q = "SELECT id,ts,ip,ip_v4,ua,route,code,meth,duration_ms,bytes FROM `$tbl` ORDER BY ts DESC";
            $wpdb->query( 'SET SESSION sql_big_selects=1' ); // best-effort
            $rows = $wpdb->get_results( $q, ARRAY_N );
            if ( $rows ) {
                foreach ( $rows as $row ) fputcsv( $fh, $row );
            }
            fclose( $fh );
        }
        return $file;
    }

    /** Tools: purge table */
    private static function purge_all() {
        global $wpdb;
        $tbl = self::table();
        $wpdb->query( "TRUNCATE TABLE `$tbl`" );
    }
}

endif;
