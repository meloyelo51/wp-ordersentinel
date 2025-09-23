---
title: "Admin UI: Meta box, Reports & Settings, single-order re-run"
date: 2025-09-23
---

### Added
- **Order meta box** on single order screen with OSINT summary, AbuseIPDB score, quick Google links, and a “Re-run research” button.
- **Admin page** under WooCommerce → **OrderSentinel**:
  - Dashboard: clusters by ASN/ISP/email-domain/phone; recent orders table; CSV export.
  - Settings: enable/disable services; store AbuseIPDB API key; report window (days).
- **Heuristics**: detect random-looking Address Line 2 (length + entropy), normalize email domain and phone digits.

### Notes
- Research data is stored in post meta key `_ordersentinel_research` and now surfaced in the UI (meta box + dashboard).
