## 0.3.0 — REST menu bootstrap + CSV + attempts
- Ensure REST Monitor submenu registers early (reliable).
- Add CSV export of REST logs (`admin-post.php?action=ordersentinel_export_rest_csv`).
- Track failed payment attempts per order (meta: `_ordersentinel_payment_fails`, per-gateway map: `_ordersentinel_gateway_fails`).
- Add roadmap doc for TOR flag & datacenter heuristic.
