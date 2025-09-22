---
title: "Initial implementation (OrderSentinel)"
date: 2025-09-22
---

### Added
- OrderSentinel plugin with bulk action on WooCommerce Orders: **Run OSINT Research (OrderSentinel)**.
- Lookups: RDAP (rdap.org), IP geolocation (ip-api.com), optional AbuseIPDB (API key).
- Stores JSON results in post meta `_ordersentinel_research` and adds a private order note.
- Non-blocking UX using AJAX after bulk redirect.

### Testing notes
1) Activate plugin.
2) In **WooCommerce → Orders**, select suspicious orders → Bulk actions → *Run OSINT Research (OrderSentinel)* → Apply.
3) After the info banner flips to success, open any processed order:
   - Check **Order notes** for the OrderSentinel note.
   - Inspect post meta key `_ordersentinel_research` for the structured JSON.
