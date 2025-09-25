# OrderSentinel — Roadmap

## Near-term
1. **TOR exit-node flag (optional)**
   - Integrate Onionoo / Tor Project lists; cache per IP 24–72h.
   - Badge in Recent Research + REST summaries; filter by TOR.

2. **REST logs CSV export (shipped in 0.3.0)**
   - Admin → OrderSentinel REST → Export CSV for last N days.

3. **Datacenter heuristic badge**
   - Curated ASN/ISP list (AWS, GCP, Azure, OVH, Hetzner, M247, Leaseweb, DigitalOcean, Linode, Vultr, etc.).
   - Flag IPs where ASN/ISP matches list (heuristic only).

4. **Refactor into `includes/`**
   - `includes/class-os-core.php`, `class-os-rest-monitor.php`, `class-os-updater.php`, `class-os-abuseipdb.php`, `helpers.php`.
   - Keep main file lightweight; strict uninstall cleanup.

## Payment intelligence
- **Attempt counters & gateway breakdown (shipped in 0.3.0):**
  Tracks failed attempts per order; future: surface in dashboard columns.
- **Stripe Radar guidance:**
  - Review/block when **card country ≠ IP country** for low baskets; require CVC; step-up SCA.
  - Rate-limit by IP/email on repeated **failed attempts**.
  - For WooPayments (Stripe), map exposed risk fields to “Risk meta keys”.

## Hardening (optional)
- **Cloudflare WAF rules** for `/wp-json/`, `/wc/store/` & checkout with rate thresholds → challenge/block.
- **Fail2ban/server logs** adapters.
- **Rule-based auto-report to AbuseIPDB** (opt-in; thresholds + cooldown; no PII).
