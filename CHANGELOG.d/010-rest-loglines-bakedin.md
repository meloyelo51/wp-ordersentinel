### Added
- Baked the REST monitor into the plugin (`includes/`), keeping the main file minimal.
- New admin page: **WooCommerce → OrderSentinel REST** with a configurable **Recent log lines** option (default 25).

### Migration notes
- After confirming the new page renders and counts move, you can delete the old MU file:
  `wp-content/mu-plugins/ordersentinel-rest-monitor.php`
