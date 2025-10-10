=== OrderSentinel ===
Contributors: mattsbasementarcade
Tags: woocommerce, fraud, osint, security, orders
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.38
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a bulk action to WooCommerce Orders to run lightweight OSINT on ordering IPs (RDAP/whois, geolocation, and optional AbuseIPDB) and attaches results to orders.

== Description ==
OrderSentinel helps you quickly research suspicious WooCommerce orders by fetching:
* RDAP (whois-like) data (via rdap.org)
* IP geolocation/ISP/ASN (via ip-api.com)
* Optional AbuseIPDB reputation (if API key is configured)

Results are stored as order meta and a private order note, and surfaced in an **order meta box** and a **Reports** page.

== Features ==
- Bulk action on Orders: "Run OSINT Research (OrderSentinel)".
- RDAP (whois-like) via rdap.org, IP geolocation via ip-api.com, optional AbuseIPDB checks.
- Stores research JSON in post meta `_ordersentinel_research` and adds a private order note.
- **Order meta box**: see results on the order screen and re-run research.
- **Reports & Settings** (WooCommerce → OrderSentinel): clusters by ASN/ISP/email-domain/phone, CSV export, enable/disable services, AbuseIPDB key.

== Installation ==
1. Upload the `order-sentinel` folder to `/wp-content/plugins/` or upload the ZIP via **Plugins → Add New → Upload**.
2. Activate the plugin.
3. Go to **WooCommerce → Orders**, select orders, choose **Run OSINT Research (OrderSentinel)** in Bulk actions, click **Apply**.

== Frequently Asked Questions ==

= Where do I see the results? =
Open a processed order. Look for:
- The **OrderSentinel** meta box (right sidebar) with IP/Geo/ISP/ASN, quick links, and “Re-run”.
- A private order note added by OrderSentinel.
- Post meta key `_ordersentinel_research` (JSON).

= How do I enable AbuseIPDB? =
Either add to `wp-config.php`:
define( 'ORDERSENTINEL_ABUSEIPDB_KEY', 'your-key-here' );

or set the key in **WooCommerce → OrderSentinel → Settings**.

== Changelog ==
= 1.0.38 = (2025-10-10)
* Fix: AbuseIPDB quick-report button uses canonical admin-post handler; stable redirect; nonce/caps verified.
* Improve: Reporter flow verified live (reports visible on AbuseIPDB).

= 0.1.0 =
* Initial release.

