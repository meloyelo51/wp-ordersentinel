<?php
/**
 * Plugin Name: OrderSentinel Diagnostics (MU)
 * Description: Minimal diagnostics MU plugin: injects a front-end scanner (head/footer), logs page snapshots via AJAX, and provides a Tools admin page.
 * Author: OrderSentinel
 * Version: 0.1.0
 */

if ( ! defined('ABSPATH') ) { exit; }

final class OS_Diagnostics {
    const OPT = 'ordersentinel_diag_options';
    const LOG = 'ordersentinel_diag_log';
    const NONCE = 'ordersentinel_diag_nonce';

    public static function init() {
        add_action('admin_menu', [__CLASS__,'admin_menu']);
        add_action('admin_init', [__CLASS__,'register_settings']);
        add_action('admin_post_ordersentinel_diag_clear', [__CLASS__,'handle_clear']);
        add_action('admin_bar_menu', [__CLASS__,'admin_bar'], 100);

        // AJAX sink
        add_action('wp_ajax_os_diag_beacon', [__CLASS__, 'ajax_beacon']);
        add_action('wp_ajax_nopriv_os_diag_beacon', [__CLASS__, 'ajax_beacon']);

        // Front-end injection
        add_action('wp', [__CLASS__, 'maybe_inject']);
    }

    /* ---------- settings ---------- */

    public static function defaults() {
        return [
            'enabled' => 1,
            'inject'  => 'footer', // header|footer
            'targets' => ['all'],  // all|front|wc_shop|wc_product|wc_cart|wc_checkout|wc_account
            'test_url'=> '',
            'keep'    => 50,       // max snapshots
        ];
    }

    public static function get_opt() {
        $opt = get_option(self::OPT, []);
        $def = self::defaults();
        if ( ! is_array($opt) ) $opt = [];
        $out = array_merge($def, $opt);
        // sanitize basics
        $out['enabled'] = empty($out['enabled']) ? 0 : 1;
        $out['inject']  = in_array($out['inject'], ['header','footer'], true) ? $out['inject'] : 'footer';
        $out['targets'] = is_array($out['targets']) ? array_values(array_unique(array_map('sanitize_key',$out['targets']))) : ['all'];
        $out['test_url']= esc_url_raw($out['test_url']);
        $out['keep']    = max(5, min(200, intval($out['keep'])));
        return $out;
    }

    public static function register_settings() {
        register_setting(self::OPT, self::OPT, [__CLASS__, 'sanitize']);
        add_settings_section('os_diag_main', 'Diagnostics', '__return_false', self::OPT);

        add_settings_field('enabled', 'Enable', function(){
            $o = self::get_opt();
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[enabled]" value="1" '.checked($o['enabled'],1,false).'> Enabled</label>';
        }, self::OPT, 'os_diag_main');

        add_settings_field('inject', 'Inject location', function(){
            $o = self::get_opt();
            echo '<label><select name="'.esc_attr(self::OPT).'[inject]">';
            foreach (['header'=>'Header (wp_head)','footer'=>'Footer (wp_footer)'] as $k=>$label) {
                echo '<option value="'.esc_attr($k).'" '.selected($o['inject'],$k,false).'>'.esc_html($label).'</option>';
            }
            echo '</select></label>';
        }, self::OPT, 'os_diag_main');

        add_settings_field('targets', 'Target pages', function(){
            $o = self::get_opt();
            $choices = [
                'all'        => 'All front-end pages',
                'front'      => 'Front page / Home',
                'wc_shop'    => 'Woo: Shop',
                'wc_product' => 'Woo: Single product',
                'wc_cart'    => 'Woo: Cart',
                'wc_checkout'=> 'Woo: Checkout',
                'wc_account' => 'Woo: My Account',
            ];
            echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:.5rem 1rem">';
            foreach ($choices as $k=>$label) {
                $checked = in_array($k, $o['targets'], true) ? 'checked' : '';
                echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[targets][]" value="'.esc_attr($k).'" '.$checked.'> '.esc_html($label).'</label>';
            }
            echo '</div>';
            echo '<p class="description">If “All” is checked, other filters are ignored.</p>';
        }, self::OPT, 'os_diag_main');

        add_settings_field('test_url', 'Custom test URL', function(){
            $o = self::get_opt();
            echo '<input type="url" class="regular-text" name="'.esc_attr(self::OPT).'[test_url]" value="'.esc_attr($o['test_url']).'"> ';
            if ($o['test_url']) {
                echo '<a class="button" target="_blank" href="'.esc_url($o['test_url']).'">Open</a>';
            }
        }, self::OPT, 'os_diag_main');

        add_settings_field('keep', 'Max snapshots to keep', function(){
            $o = self::get_opt();
            echo '<input type="number" min="5" max="200" name="'.esc_attr(self::OPT).'[keep]" value="'.esc_attr($o['keep']).'">';
        }, self::OPT, 'os_diag_main');
    }

    public static function sanitize($in) {
        $o = self::get_opt();
        $o['enabled'] = empty($in['enabled']) ? 0 : 1;
        $o['inject']  = isset($in['inject']) && in_array($in['inject'], ['header','footer'], true) ? $in['inject'] : 'footer';
        $o['targets'] = [];
        if (!empty($in['targets']) && is_array($in['targets'])) {
            foreach ($in['targets'] as $t) {
                $t = sanitize_key($t);
                if ($t) { $o['targets'][] = $t; }
            }
        }
        if (empty($o['targets'])) { $o['targets'] = ['all']; }
        $o['test_url'] = isset($in['test_url']) ? esc_url_raw($in['test_url']) : '';
        $o['keep'] = max(5, min(200, intval($in['keep'] ?? 50)));
        return $o;
    }

    /* ---------- admin UI ---------- */

    public static function admin_menu() {
        add_submenu_page(
            'tools.php',
            'OrderSentinel Diagnostics',
            'OrderSentinel Diagnostics',
            'manage_options',
            'ordersentinel_diag',
            [__CLASS__, 'render_admin']
        );
    }

    public static function admin_bar($bar) {
        if ( ! current_user_can('manage_options') ) return;
        $bar->add_node([
            'id'    => 'ordersentinel_diag',
            'title' => 'Diagnostics',
            'href'  => admin_url('tools.php?page=ordersentinel_diag'),
            'meta'  => ['title' => 'OrderSentinel Diagnostics']
        ]);
    }

    public static function render_admin() {
        if ( ! current_user_can('manage_options') ) { wp_die('Not allowed'); }
        $o = self::get_opt();
        $log = get_option(self::LOG, []);
        if (!is_array($log)) $log = [];
        $nonce_clear = wp_create_nonce(self::NONCE);
        echo '<div class="wrap"><h1>OrderSentinel Diagnostics</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPT);
        do_settings_sections(self::OPT);
        submit_button('Save settings');
        echo '</form>';

        echo '<hr><h2>Recent snapshots</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="ordersentinel_diag_clear">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce_clear).'">';
        submit_button('Clear snapshots', 'delete', 'submit', false);
        echo '</form>';

        if ($log) {
            echo '<table class="widefat striped"><thead><tr><th>When</th><th>URL</th><th>Matches</th><th>Notes</th></tr></thead><tbody>';
            foreach (array_reverse($log) as $row) {
                $when = esc_html($row['when'] ?? '');
                $url  = esc_url($row['url'] ?? '');
                $m    = isset($row['matches']) && is_array($row['matches']) ? implode(', ', array_map('esc_html',$row['matches'])) : '';
                $notes= esc_html($row['notes'] ?? '');
                echo "<tr><td>{$when}</td><td><a href=\"{$url}\" target=\"_blank\">{$url}</a></td><td>{$m}</td><td>{$notes}</td></tr>";
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No snapshots captured yet.</p>';
        }
        echo '</div>';
    }

    public static function handle_clear() {
        if ( ! current_user_can('manage_options') ) { wp_die('Not allowed'); }
        check_admin_referer(self::NONCE);
        delete_option(self::LOG);
        wp_safe_redirect( admin_url('tools.php?page=ordersentinel_diag') );
        exit;
    }

    /* ---------- front-end injection ---------- */

    public static function maybe_inject() {
        $o = self::get_opt();
        if ( ! $o['enabled'] ) return;
        if ( is_admin() ) return;

        if ( ! self::page_matches($o['targets']) ) return;

        $cb = $o['inject'] === 'header' ? 'wp_head' : 'wp_footer';
        add_action($cb, [__CLASS__, 'print_script']);
    }

    protected static function page_matches($targets) {
        if (in_array('all', $targets, true)) return true;

        // front page / home
        if ( in_array('front', $targets, true) && (is_front_page() || is_home()) ) return true;

        // WooCommerce conditionals if available
        if ( function_exists('is_shop') && in_array('wc_shop', $targets, true) && is_shop() ) return true;
        if ( function_exists('is_product') && in_array('wc_product', $targets, true) && is_product() ) return true;
        if ( function_exists('is_cart') && in_array('wc_cart', $targets, true) && is_cart() ) return true;
        if ( function_exists('is_checkout') && in_array('wc_checkout', $targets, true) && is_checkout() ) return true;
        if ( function_exists('is_account_page') && in_array('wc_account', $targets, true) && is_account_page() ) return true;

        return false;
    }

    public static function print_script() {
        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce(self::NONCE);
        $o = self::get_opt();
        ?>
<script>
(function(){
  try {
    var cfg = {
      ajax: <?php echo json_encode($ajax); ?>,
      nonce: <?php echo json_encode($nonce); ?>,
      when: (new Date()).toISOString(),
      loc: <?php echo json_encode($o['inject']); ?>
    };

    // Collect lightweight DOM diagnostics
    var matches = [];
    function has(q){ try { return document.querySelector(q) ? true : false; } catch(e){ return false; } }
    function count(q){ try { return document.querySelectorAll(q).length; } catch(e){ return 0; } }

    // Generic forms
    var forms = count('form');
    if (forms) matches.push('forms:'+forms);

    // Woo checkout/cart forms & place-order button
    if (has('form.checkout')) matches.push('form.checkout');
    if (has('form[name="checkout"]')) matches.push('form[name=checkout]');
    if (has('button[name="woocommerce_checkout_place_order"]')) matches.push('place_order_btn');

    // CF7 forms
    if (has('.wpcf7 form')) matches.push('cf7');

    // Payment buttons / iframes (best-effort)
    if (has('apple-pay-button, .apple-pay-button')) matches.push('applepay');
    if (has('iframe[src*="paypal.com"], [data-funding-source="venmo"], [data-funding-source="paypal"]')) matches.push('paypal/venmo');
    if (has('[id*="google-pay"], [aria-label*="Google Pay"], [data-testid*="google-pay"]')) matches.push('gpay');
    if (has('iframe[src*="stripe.com"], .stripe-payment, .wc-stripe-elements-field')) matches.push('stripe');

    // OrderSentinel honeypot presence
    if (has('.ordersentinel-hp-wrap')) matches.push('os_hp_wrap');
    if (has('input[name="ordersentinel_hp_nonce"]')) matches.push('os_hp_nonce');
    if (has('input[id^="os_hp_"]')) matches.push('os_hp_input');

    // Send beacon (no blocking)
    var payload = new FormData();
    payload.append('action', 'os_diag_beacon');
    payload.append('nonce', cfg.nonce);
    payload.append('url', location.href);
    payload.append('when', cfg.when);
    payload.append('matches', JSON.stringify(matches));
    payload.append('notes', 'loc='+cfg.loc);

    // Prefer sendBeacon; fall back to fetch
    if (navigator.sendBeacon) {
      var u = cfg.ajax;
      var d = new URLSearchParams();
      d.append('action','os_diag_beacon');
      d.append('nonce', cfg.nonce);
      d.append('url', location.href);
      d.append('when', cfg.when);
      d.append('matches', JSON.stringify(matches));
      d.append('notes', 'loc='+cfg.loc);
      navigator.sendBeacon(u, d);
    } else {
      fetch(cfg.ajax, { method:'POST', body: payload, credentials:'same-origin' }).catch(function(){});
    }
    // Also log to console for immediate visibility
    console.log('[OS Diag]', { url: location.href, matches: matches, when: cfg.when, loc: cfg.loc });
  } catch(e) { /* swallow */ }
})();
</script>
        <?php
    }

    /* ---------- AJAX sink ---------- */

    public static function ajax_beacon() {
        // write-only endpoint, public
        $nonce = $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce($nonce, self::NONCE) ) {
            wp_send_json_success(['ok'=>false,'err'=>'bad_nonce']); // keep silent-but-success to avoid noise
        }
        $url = esc_url_raw($_POST['url'] ?? '');
        $when= sanitize_text_field($_POST['when'] ?? current_time('mysql'));
        $m   = json_decode(strval($_POST['matches'] ?? '[]'), true);
        $m   = is_array($m) ? array_values(array_filter(array_map('sanitize_text_field',$m))) : [];
        $notes = sanitize_text_field($_POST['notes'] ?? '');

        $log = get_option(self::LOG, []);
        if ( ! is_array($log) ) $log = [];
        $log[] = [
            'when'    => $when,
            'url'     => $url,
            'matches' => $m,
            'notes'   => $notes,
        ];
        $keep = self::get_opt()['keep'];
        if (count($log) > $keep) {
            $log = array_slice($log, -1 * $keep);
        }
        update_option(self::LOG, $log, false);
        wp_send_json_success(['ok'=>true,'kept'=>count($log)]);
    }
}

OS_Diagnostics::init();
