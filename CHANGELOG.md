# Changelog

## [1.1.0] — 2025-10-17

### Added
- Contact Form 7 integration: global inject via `wpcf7_form_elements` + server-side block with `wpcf7_before_send_mail`.
- Checkout enforcement: honeypot trip blocks submission across core checkout and common express gateways (Stripe, WooPayments, PayPal/PayPal/Venmo).
- Per-field “required look” label support.

### Fixed
- Admin menu wiring stabilized; non-static callbacks and duplicate entries addressed.
- Honeypot settings sanitize hardened.

## [1.0.40] — 2025-10-17

### Improved

- AbuseIPDB reporter flow verified live; successful reports visible on AbuseIPDB.


### Fixed

- AbuseIPDB quick-report button now posts to canonical `admin-post.php?action=ordersentinel_report_ip` (GET anchor). Stable redirect back to order edit; nonce/caps verified.

## [1.0.39] — 2025-10-16

### Added
- Honeypot scaffold: settings page, WooCommerce submenu, admin-bar shortcut.

### Fixed
- Settings save fatal (sanitize) resolved; checkboxes and field config persist.
- CSS hide toggle works; duplicate submenu removed.

## [1.0.38] — 2025-10-10

[1.1.0]: https://github.com/meloyelo51/wp-ordersentinel/compare/v1.0.40...v1.1.0
[1.0.39]: https://github.com/meloyelo51/wp-ordersentinel/compare/v1.0.38...v1.0.39
[1.0.38]: https://github.com/meloyelo51/wp-ordersentinel/compare/v1.0.16...v1.0.38


[1.1.0]: https://github.com/meloyelo51/wp-ordersentinel/compare/v1.0.40...v1.1.0
[1.0.39]: https://github.com/meloyelo51/wp-ordersentinel/compare/v1.0.38...v1.0.39
[1.0.38]: https://github.com/meloyelo51/wp-ordersentinel/compare/v1.0.16...v1.0.38
