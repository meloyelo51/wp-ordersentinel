<?php
/**
 * Plugin Name: OrderSentinel Diagnostics
 * Description: Read-only inspector for hooks & files to verify duplicate handlers, call sites, and related symbols.
 * Version: 0.1.0
 * Author: Matt's Basement Arcade
 */

if ( ! defined('ABSPATH') ) { exit; }

add_action('admin_menu', function () {
    add_management_page(
        'OrderSentinel Diagnostics',
        'OrderSentinel Diag',
        'manage_options',
        'ordersentinel-diag',
        'ordersentinel_diag_render_page'
    );
});

function ordersentinel_diag_render_page() {
    if ( ! current_user_can('manage_options') ) { return; }

    $default_hook = 'admin_post_ordersentinel_report_ip';
    $hook = isset($_GET['hook']) ? sanitize_text_field(wp_unslash($_GET['hook'])) : $default_hook;

    // Find the OrderSentinel plugin path if present
    $plugins = get_plugins();
    $os_dir = '';
    foreach ($plugins as $file => $data) {
        if (stripos($file, 'order-sentinel/') === 0 || stripos($data['Name'] ?? '', 'OrderSentinel') !== false) {
            $os_dir = WP_PLUGIN_DIR . '/' . dirname($file);
            break;
        }
    }
    if ( ! $os_dir ) {
        // fallback: common folder name
        $candidate = WP_PLUGIN_DIR . '/order-sentinel';
        if ( is_dir($candidate) ) { $os_dir = $candidate; }
    }

    echo '<div class="wrap"><h1>OrderSentinel Diagnostics</h1>';
    echo '<p>This page is <strong>read-only</strong>. It inspects live hook registrations and scans your plugin files.</p>';

    echo '<form method="get" style="margin-bottom:10px">';
    echo '<input type="hidden" name="page" value="ordersentinel-diag" />';
    echo '<label>Hook to inspect: <input type="text" name="hook" value="' . esc_attr($hook) . '" size="40" /></label> ';
    submit_button('Inspect', 'secondary', '', false);
    echo '</form>';

    // 1) Live hook inspection via $wp_filter
    echo '<h2>1) Live hook callbacks for <code>' . esc_html($hook) . '</code></h2>';
    global $wp_filter;
    if ( empty($wp_filter[$hook]) ) {
        echo '<p>No callbacks registered for this hook.</p>';
    } else {
        $callbacks = $wp_filter[$hook]->callbacks ?? array();
        echo '<table class="widefat striped" style="max-width:1200px"><thead><tr>';
        echo '<th>Priority</th><th>Type</th><th>Callable</th><th>File</th><th>Line</th>';
        echo '</tr></thead><tbody>';
        foreach ($callbacks as $prio => $items) {
            foreach ($items as $cb) {
                $type = $callable_str = $file = $line = '';
                $func = $cb['function'];

                if ( is_string($func) ) {
                    $type = 'function';
                    $callable_str = $func;
                    try {
                        $rf = new ReflectionFunction($func);
                        $file = $rf->getFileName();
                        $line = $rf->getStartLine();
                    } catch (\Throwable $e) {}
                } elseif ( is_array($func) ) {
                    $obj = $func[0]; $method = $func[1];
                    if ( is_object($obj) ) {
                        $type = 'object method';
                        $callable_str = get_class($obj) . '::' . $method;
                        try {
                            $rm = new ReflectionMethod($obj, $method);
                            $file = $rm->getFileName();
                            $line = $rm->getStartLine();
                        } catch (\Throwable $e) {}
                    } else {
                        $type = 'static method';
                        $callable_str = $obj . '::' . $method;
                        try {
                            $rm = new ReflectionMethod($obj, $method);
                            $file = $rm->getFileName();
                            $line = $rm->getStartLine();
                        } catch (\Throwable $e) {}
                    }
                } elseif ( $func instanceof Closure ) {
                    $type = 'closure';
                    $callable_str = '(closure)';
                    try {
                        $rf = new ReflectionFunction($func);
                        $file = $rf->getFileName();
                        $line = $rf->getStartLine();
                    } catch (\Throwable $e) {}
                } else {
                    $type = gettype($func);
                    $callable_str = '(unprintable)';
                }

                echo '<tr>';
                echo '<td>' . esc_html((string)$prio) . '</td>';
                echo '<td>' . esc_html($type) . '</td>';
                echo '<td><code>' . esc_html($callable_str) . '</code></td>';
                echo '<td><code>' . esc_html((string)$file) . '</code></td>';
                echo '<td>' . esc_html($line ? (string)$line : '') . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }

    // 2) Static scan: find add_action calls in OrderSentinel plugin dir
    echo '<h2 style="margin-top:24px">2) Static scan of add_action() in OrderSentinel plugin</h2>';
    if ( ! $os_dir ) {
        echo '<p><em>OrderSentinel directory not found under plugins.</em></p>';
    } else {
        echo '<p>Scanning: <code>' . esc_html($os_dir) . '</code></p>';
        $hits = array();
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($os_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            /** @var SplFileInfo $f */
            if ( $f->isDir() ) continue;
            $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
            if ( $ext !== 'php' ) continue;
            $path = $f->getPathname();
            $contents = file_get_contents($path);
            if ($contents === false) continue;
            // simple pattern: add_action('admin_post_ordersentinel_report_ip', ...)
            if ( preg_match_all("#add_action\s*\\(\s*['\"]admin_post_ordersentinel_report_ip['\"]\s*,[^;]+;#i", $contents, $m, PREG_OFFSET_CAPTURE) ) {
                foreach ($m[0] as $match) {
                    $offset = $match[1];
                    $lines_before = substr_count(substr($contents, 0, $offset), "\n") + 1;
                    $snippet = substr($contents, $offset, 200);
                    $hits[] = array($path, $lines_before, trim($snippet));
                }
            }
        }
        if (empty($hits)) {
            echo '<p>No <code>add_action(\'admin_post_ordersentinel_report_ip\', ...)</code> calls found in the OrderSentinel plugin files.</p>';
        } else {
            echo '<p>Found the following <code>add_action</code> call sites:</p>';
            echo '<ol>';
            foreach ($hits as $h) {
                echo '<li><code>' . esc_html($h[0]) . '</code> : line <strong>' . intval($h[1]) . '</strong><br/><pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;border:1px solid #ddd;">' . esc_html($h[2]) . '...</pre></li>';
            }
            echo '</ol>';
            echo '<p><em>Tip:</em> If the live hook table above shows only a single callback from the class in <code>order-sentinel/order-sentinel.php</code>, you can safely comment out any extra <code>add_action</code> lines shown here.</p>';
        }
    }

    // 3) Quick symbol existence checks (optional)
    echo '<h2 style="margin-top:24px">3) Quick symbol checks</h2>';
    $symbols = array(
    'ordersentinel_handle_report_ip (legacy function)' => function_exists('ordersentinel_handle_report_ip'),
    'OS_Order_Sentinel::handle_report_ip' => class_exists('OS_Order_Sentinel') && method_exists('OS_Order_Sentinel', 'handle_report_ip'),
);
    echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Symbol</th><th>Present?</th></tr></thead><tbody>';
    foreach ($symbols as $name => $present) {
        echo '<tr><td>' . esc_html($name) . '</td><td>' . ($present ? '<span style="color:green">YES</span>' : '<span style="color:#a00">NO</span>') . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<p style="margin-top:16px"><strong>Copy-out instructions:</strong> Select any section above and paste the text back here in chat if you want me to generate a cleanup patch.</p>';
    echo '</div>';
}

/**
*How to use
*Create the folder wp-content/plugins/ordersentinel-diag/
*Save the file above as ordersentinel-diag.php.
*Activate it in Plugins.
*Inspect the default hook (admin_post_ordersentinel_report_ip).
*If you see one callback (your class method from order-sentinel/order-sentinel.php) → it’s safe to comment out any extra add_action('admin_post_ordersentinel_report_ip', …) call sites the scan lists in includes/os-abuseipdb.php or elsewhere.
*If you see more than one callback, paste the table back here and I’ll give you a surgical one-liner to disable the redundant ones.
*
*If you’d rather ship this as a MU-plugin (always on), just drop the same PHP file into wp-content/mu-plugins/ordersentinel-diag.php (no activation step). The Tools page still shows up and behavior remains read-only.

*Once you run the diag and paste the “Live hook callbacks” table back here, I’ll confirm whether it’s safe to remove/comment the duplicates and generate the exact removal patch you want (comment vs delete).
*/

/**Optional tidy one-shot (run only after you confirm with the diag page)

If the live list shows only the class method is active, this small script will comment out the procedural registration
inside order-sentinel/includes/os-abuseipdb.php (non-destructive; just adds // to that line):

python - <<'PY'
import re
from pathlib import Path
p = Path("order-sentinel/includes/os-abuseipdb.php")
s = p.read_text(encoding="utf-8", errors="ignore")
orig = s
# Comment the specific admin_post add_action line(s)
s = re.sub(
    r"^(\s*)add_action\(\s*'admin_post_ordersentinel_report_ip'\s*,\s*[^)]+\)\s*;\s*$",
    r"\1// DISABLED: canonical handler lives in order-sentinel/order-sentinel.php\n\1// add_action('admin_post_ordersentinel_report_ip', ...);",
    s, flags=re.MULTILINE
)
if s != orig:
    p.write_text(s, encoding="utf-8"); print("[write] commented duplicate admin_post handler in includes/os-abuseipdb.php")
else:
    print("[ok] no uncommented admin_post handler found in includes/os-abuseipdb.php")
PY

git add -A
git commit -m "tidy: comment duplicate admin_post handler (canonical handler in main class)" || echo "(no changes to commit)"
*/