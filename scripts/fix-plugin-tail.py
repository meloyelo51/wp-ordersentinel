import re, sys
from pathlib import Path

SLUG = "order-sentinel"
p = Path(f"{SLUG}/{SLUG}.php")
s = p.read_text(encoding="utf-8")

# Find the class start
m = re.search(r'\bclass\s+OS_Order_Sentinel\b', s)
if not m:
    print("✖ Could not find class OS_Order_Sentinel in plugin.", file=sys.stderr)
    sys.exit(1)

# Find the first "{" after the class name
i = s.find("{", m.end())
if i == -1:
    print("✖ Could not find opening brace for OS_Order_Sentinel.", file=sys.stderr)
    sys.exit(1)

# Walk braces to locate the class end
depth = 1
j = i + 1
L = len(s)
while j < L and depth > 0:
    c = s[j]
    if c == "{":
        depth += 1
    elif c == "}":
        depth -= 1
    j += 1

if depth != 0:
    print("✖ Brace depth did not return to 0. Aborting to avoid damage.", file=sys.stderr)
    sys.exit(1)

class_end = j  # index *after* the closing brace of the class

# Clean footer we trust (minimal, safe, idempotent)
footer = r"""

// -- end class OS_Order_Sentinel --

// Bootstrap main plugin instance early.
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'OS_Order_Sentinel' ) ) { return; }
    if ( ! isset( $GLOBALS['ordersentinel'] ) ) {
        $GLOBALS['ordersentinel'] = new OS_Order_Sentinel();
    }
}, 1 );

/**
 * OrderSentinel — baked-in REST monitor loader
 * Safe no-op if files are missing.
 */
add_action( 'admin_init', function () {
    $base = plugin_dir_path( __FILE__ );
    $bootstrap = $base . 'includes/os-rest-monitor-bootstrap.php';
    if ( file_exists( $bootstrap ) ) {
        include_once $bootstrap;
    }
} );

"""

# Replace anything after the class with our known-good footer
fixed = s[:class_end] + footer

# Trim accidental duplicate closers that might follow (defensive)
fixed = re.sub(r'\n\}\s*\Z', '\n', fixed)

Path(p).write_text(fixed, encoding="utf-8")
print(f"✔ Rebuilt plugin footer after class at byte {class_end}")
