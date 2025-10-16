<?php
defined('ABSPATH') || exit;

/**
 * OrderSentinel Honeypot — Slice 1
 * - Settings page (basic/global)
 * - Injection stubs for Woo checkout, core auth/comment, CF7 global
 * - Log-only on trip (no block/redirect yet)
 * - Express/offsite gateways untouched (validation only when our field.nonce exist)
 */
if ( ! class_exists( 'OS_Honeypot' ) ) {
class OS_Honeypot {
    const OPT_KEY   = 'ordersentinel_honeypot_options';
    const NONCE_KEY = 'ordersentinel_hp_nonce';
    const FIELD_PREFIX = 'os_hp_';

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 60);
        $self = new self();
        add_action('admin_menu', [$self,'admin_menu'], 60);
        add_action('admin_init', [$self,'register_settings']);
        add_action('init',       [$self,'maybe_hook_integrations']);
    }

    // ---------- Settings ----------
    public static function defaults() {
        return [
            'enabled'       => 1,
            'css_hide'      => 1,
            'required_look' => 0,
            'fields'        => [
                ['key' => 'company_fax', 'label' => 'Company Fax', 'places' => ['checkout','login','register','comments','cf7'], 'css_hide'=>1, 'required_look'=>0],
            ],
            'action'     => 'log',   // future: log|ban|404|403|custom|stall
            'custom_url' => '',
            'gateways'   => [],      // future per-gateway include
        ];
    }
    public static function get_opts() {
        $o = get_option(self::OPT_KEY);
        if ( ! is_array($o) ) $o = [];
        return wp_parse_args($o, self::defaults());
    }

    public static function admin_menu() {
        // Preferred: under WooCommerce
        add_submenu_page(
            'woocommerce',
            __('OrderSentinel Honeypot','order-sentinel'),
            __('Honeypot','order-sentinel'),
            'manage_woocommerce',
            'ordersentinel_honeypot',
            [__CLASS__, 'render_admin']
        );
        // Fallback: under Tools
        add_submenu_page(
            'tools.php',
            __('OrderSentinel Honeypot','order-sentinel'),
            __('Honeypot (OrderSentinel)','order-sentinel'),
            'manage_options',
            'ordersentinel_honeypot',
            [__CLASS__, 'render_admin']
        );
    }

    public function register_settings() {
        register_setting(self::OPT_KEY, self::OPT_KEY, [
            'type'              => 'array',
            'show_in_rest'      => false,
            'sanitize_callback' => [$this,'sanitize'],
            'default'           => self::defaults(),
        ]);
        add_settings_section('os_hp_main', __('Honeypot Settings','order-sentinel'), function(){
            echo '<p>'.esc_html__('Configure honeypot fields and behavior.','order-sentinel').'</p>';
        }, self::OPT_KEY);

        add_settings_field('enabled', __('Enable Honeypots','order-sentinel'), function(){
            $o = self::get_opts();
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_KEY).'[enabled]" value="1" '.checked(!empty($o['enabled']),true,false).' /> '.__('Enabled','order-sentinel').'</label>';
        }, self::OPT_KEY, 'os_hp_main');

        add_settings_field('css_hide', __('CSS Hide (global)','order-sentinel'), function(){
            $o = self::get_opts();
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_KEY).'[css_hide]" value="1" '.checked(!empty($o['css_hide']),true,false).' /> '.__('Hide fields via CSS','order-sentinel').'</label>';
        }, self::OPT_KEY, 'os_hp_main');

        add_settings_field('required_look', __('Required Look (global)','order-sentinel'), function(){
            $o = self::get_opts();
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_KEY).'[required_look]" value="1" '.checked(!empty($o['required_look']),true,false).' /> '.__('Show * (visual only)','order-sentinel').'</label>';
        }, self::OPT_KEY, 'os_hp_main');

        add_settings_field('fields', __('Fields (starter)','order-sentinel'), function(){
            $o = self::get_opts();
            $fields = isset($o['fields']) && is_array($o['fields']) ? $o['fields'] : [];
            echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Placements</th></tr></thead><tbody>';
            foreach ($fields as $i=>$f) {
                $key = esc_attr($f['key']);
                $lab = esc_attr($f['label']);
                $places = isset($f['places']) && is_array($f['places']) ? $f['places'] : [];
                $chk = function($p) use($places){ return in_array($p,$places,true) ? 'checked' : ''; };
                echo '<tr><td><input name="'.self::OPT_KEY.'[fields]['.$i.'][key]" value="'.$key.'" class="regular-text"></td>';
                echo '<td><input name="'.self::OPT_KEY.'[fields]['.$i.'][label]" value="'.$lab.'" class="regular-text"></td>';
                echo '<td>';
                foreach (['checkout','login','register','comments','cf7','shortcode'] as $p) {
                    echo '<label style="margin-right:8px"><input type="checkbox" name="'.self::OPT_KEY.'[fields]['.$i.'][places][]" value="'.$p.'" '. $chk($p) .'> '.$p.'</label>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p><em>'.esc_html__('Slice 1 has a single starter row; add/remove controls come later.','order-sentinel').'</em></p>';
        }, self::OPT_KEY, 'os_hp_main');

        add_settings_field('action', __('Default Action on Trip','order-sentinel'), function(){
            $o = self::get_opts();
            $opts = ['log'=>'Log only','ban'=>'Ban IP','404'=>'Fake 404','403'=>'Fake 403','custom'=>'Redirect URL','stall'=>'Stall page'];
            echo '<select name="'.esc_attr(self::OPT_KEY).'[action]">';
            foreach ($opts as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($o['action'],$k,false).'>'.esc_html($v).'</option>';
            echo '</select> ';
            echo '<input placeholder="https://example.com/..." class="regular-text" name="'.esc_attr(self::OPT_KEY).'[custom_url]" value="'.esc_attr($o['custom_url']).'">';
        }, self::OPT_KEY, 'os_hp_main');
    }

    public function sanitize($in) {
        if ( ! is_array($in) ) $in = [];
        $out = self::defaults();
        $out['enabled']       = empty($in['enabled']) ? 0 : 1;
        $out['css_hide']      = empty($in['css_hide']) ? 0 : 1;
        $out['required_look'] = empty($in['required_look']) ? 0 : 1;
        $out['action']        = isset($in['action']) ? sanitize_text_field($in['action']) : 'log';
        $out['custom_url']    = isset($in['custom_url']) ? esc_url_raw($in['custom_url']) : '';
        $fields = [];
        if ( isset($in['fields']) && is_array($in['fields']) ) {
            foreach ($in['fields'] as $f) {
                $k = isset($f['key']) ? sanitize_key($f['key']) : '';
                $l = isset($f['label']) ? sanitize_text_field($f['label']) : '';
                if ( ! $k ) continue;
                $places = [];
                if ( ! empty($f['places']) && is_array($f['places']) ) {
                    foreach ($f['places'] as $p) { $places.append(sanitize_key($p)); }
                }
                $fields[] = [
                    'key' => $k,
                    'label' => $l ?: ucfirst(str_replace('_',' ',$k)),
                    'places' => array_values(array_unique($places)),
                    'css_hide' => !empty($f['css_hide']) ? 1 : 1, // default on
                    'required_look' => !empty($f['required_look']) ? 1 : 0,
                ];
            }
        }
        if ( empty($fields) ) $fields = self::defaults()['fields'];
        $out['fields'] = $fields;
        return $out;
    }

    public function render_admin() {
        if ( ! current_user_can('manage_options') ) { wp_die('Not allowed'); }
        echo '<div class="wrap"><h1>'.esc_html__('OrderSentinel — Honeypot','order-sentinel').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPT_KEY);
        do_settings_sections(self::OPT_KEY);
        submit_button();
        echo '</form></div>';
    }

    // ---------- Integration ----------
    public function maybe_hook_integrations() {
        $o = self::get_opts();
        if ( empty($o['enabled']) ) return;

        // enqueue CSS (correct base file for plugins_url)
        add_action('wp_enqueue_scripts', function() {
            wp_enqueue_style('ordersentinel-honeypot', plugins_url('assets/honeypot.css', dirname(__FILE__) . '/../order-sentinel.php'), [], null);
        });

        // Woo checkout inject . validate
        if ( class_exists('WooCommerce') ) {
            add_action('woocommerce_after_order_notes', [$this,'render_for_checkout'], 50);
            add_action('woocommerce_checkout_process', [$this,'validate_checkout'], 0);
        }

        // Core: login/register/comment
        add_action('login_form',        [$this,'render_for_login']);
        add_filter('authenticate',      [$this,'validate_login'], 30, 3);
        add_action('register_form',     [$this,'render_for_register']);
        add_filter('registration_errors', [$this,'validate_register'], 30, 3);
        add_action('comment_form_after_fields', [$this,'render_for_comments']);
        add_filter('preprocess_comment', [$this,'validate_comment']);

        // CF7 global inject . validate
        if ( function_exists('wpcf7_add_form_tag') ) {
            add_filter('wpcf7_form_elements',     [$this,'cf7_inject'], 50, 1);
            add_action('wpcf7_before_send_mail',  [$this,'cf7_validate'], 5, 3);
        }

        // Shortcode (optional)
        add_shortcode('ordersentinel_honeypot', [$this,'shortcode_field']);
    }

    private function field_html($key, $label, $required_look = false) {
        $name = self::FIELD_PREFIX . $key;
        $label_txt = esc_html($label . ($required_look ? ' *' : ''));
        $req = $required_look ? ' aria-required="true"' : '';
        // include the NONCE input INSIDE the same wrapper so it's submitted with the form
        $nonce = wp_create_nonce(self::NONCE_KEY);
        return '<span class="ordersentinel-hp-wrap">' .
               '<label class="ordersentinel-hp-label" for="'.esc_attr($name).'">'.$label_txt.'</label>' .
               '<input type="hidden" name="'.esc_attr(self::NONCE_KEY).'" value="'.esc_attr($nonce).'" />' .
               '<input type="text" name="'.esc_attr($name).'" id="'.esc_attr($name).'" '.$req.' value="" />' .
               '</span>';
    }
    private function any_tripped($src) {
        $opts = self::get_opts();
        foreach ($opts['fields'] as $f) {
            if (empty($f['key'])) continue;
            $name = self::FIELD_PREFIX . $f['key'];
            if ( ! empty($src[$name]) ) return $f['key'];
        }
        return false;
    }
    private function has_nonce($src) {
        $nonce = isset($src[self::NONCE_KEY]) ? sanitize_text_field($src[self::NONCE_KEY]) : '';
        return $nonce && wp_verify_nonce($nonce, self::NONCE_KEY);
    }

    // Woo checkout
    public function render_for_checkout() {
        $o = self::get_opts();
        foreach ($o['fields'] as $f) {
            if ( in_array('checkout', (array)$f['places'], true) ) {
                echo $this->field_html($f['key'], $f['label'], !empty($f['required_look']) || !empty($o['required_look']));
            }
        }
    }
    public function validate_checkout() {
        $src = $_POST;
        if ( ! $this->has_nonce($src) ) return; // express/offsite: skip
        $hit = $this->any_tripped($src);
        if ( $hit ) $this->on_trip('checkout', ['hit'=>$hit]);
    }

    // Login
    public function render_for_login() {
        $o = self::get_opts();
        foreach ($o['fields'] as $f) {
            if ( in_array('login', (array)$f['places'], true) ) {
                echo $this->field_html($f['key'], $f['label'], !empty($f['required_look']) || !empty($o['required_look']));
            }
        }
    }
    public function validate_login($user, $username, $password) {
        $src = $_POST;
        if ( ! $this->has_nonce($src) ) return $user;
        $hit = $this->any_tripped($src);
        if ( $hit ) { $this->on_trip('login', ['hit'=>$hit,'username'=>$username]); return new WP_Error('os_hp_block','Login blocked'); }
        return $user;
    }

    // Register
    public function render_for_register() {
        $o = self::get_opts();
        foreach ($o['fields'] as $f) {
            if ( in_array('register', (array)$f['places'], true) ) {
                echo $this->field_html($f['key'], $f['label'], !empty($f['required_look']) || !empty($o['required_look']));
            }
        }
    }
    public function validate_register($errors, $sanitized_user_login, $user_email) {
        $src = $_POST;
        if ( ! $this->has_nonce($src) ) return $errors;
        $hit = $this->any_tripped($src);
        if ( $hit ) { $this->on_trip('register', ['hit'=>$hit,'user'=>$sanitized_user_login]); $errors->add('os_hp_block','Registration blocked'); }
        return $errors;
    }

    // Comments
    public function render_for_comments() {
        $o = self::get_opts();
        foreach ($o['fields'] as $f) {
            if ( in_array('comments', (array)$f['places'], true) ) {
                echo $this->field_html($f['key'], $f['label'], !empty($f['required_look']) || !empty($o['required_look']));
            }
        }
    }
    public function validate_comment($commentdata) {
        $src = $_POST;
        if ( ! $this->has_nonce($src) ) return $commentdata;
        $hit = $this->any_tripped($src);
        if ( $hit ) { $this->on_trip('comment', ['hit'=>$hit]); wp_die(__('Comment blocked','order-sentinel'), 403); }
        return $commentdata;
    }

    // CF7
    public function cf7_inject($html) {
        $o = self::get_opts();
        if ( empty($o['enabled']) ) return $html;
        $buf = '';
        foreach ($o['fields'] as $f) {
            if ( in_array('cf7', (array)$f['places'], true) ) {
                $buf .= $this->field_html($f['key'], $f['label'], !empty($f['required_look']) || !empty($o['required_look']));
            }
        }
        if ( $buf ) {
            $new = preg_replace('</form>', $buf . '</form>', $html, 1);
            if ( $new === null ) $html .= $buf;
            else $html = $new;
        }
        return $html;
    }
    public function cf7_validate($contact_form, &$abort, $submission) {
        $src = $_POST;
        if ( ! $this->has_nonce($src) ) return;
        $hit = $this->any_tripped($src);
        if ( $hit ) { $abort = true; $this->on_trip('cf7', ['hit'=>$hit,'form_id'=>method_exists($contact_form,'id')?$contact_form->id():0]); }
    }

    // Shortcode
    public function shortcode_field($atts = []) {
        $a = shortcode_atts(['name'=>'extra_field','label'=>'Additional Info','required_look'=>'0'], $atts, 'ordersentinel_honeypot');
        return $this->field_html(sanitize_key($a['name']), sanitize_text_field($a['label']), !empty($a['required_look']));
    }

    // ---------- Trip handling (Slice 1: log only) ----------
    private function on_trip($route, $ctx = []) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        error_log("[OrderSentinel Honeypot] route={$route} ip={$ip} ua={$ua} ctx=" . (function_exists('wp_json_encode')? wp_json_encode($ctx) : json_encode($ctx)) );
        do_action('ordersentinel_honeypot_trip', [
            'route' => $route,
            'ip'    => $ip,
            'ua'    => $ua,
            'ctx'   => $ctx,
            'ts'    => time(),
        ]);
    }
    public static function admin_bar_shortcut($wp_admin_bar) {
        if ( ! is_admin() || ! current_user_can('manage_options') ) return;
        $wp_admin_bar->add_node(array(
            'id'    => 'ordersentinel_honeypot',
            'title' => 'Honeypot',
            'href'  => admin_url('admin.php?page=ordersentinel_honeypot'),
            'parent'=> 'top-secondary'
        ));
    }

    public static function register_wc_nav() {
        if ( function_exists('wc_admin_register_page') ) {
            wc_admin_register_page( array(
                'id'         => 'ordersentinel-honeypot',
                'title'      => __('Honeypot','order-sentinel'),
                'parent'     => 'woocommerce',
                'capability' => 'manage_woocommerce',
                'path'       => 'admin.php?page=ordersentinel_honeypot',
            ) );
        }
    }

}}
add_action('plugins_loaded', ['OS_Honeypot','boot']);
add_action('admin_menu', ['OS_Honeypot','admin_menu'], 60);
add_action('init', ['OS_Honeypot','register_wc_nav']);
add_action('admin_bar_menu', ['OS_Honeypot','admin_bar_shortcut'], 100);
