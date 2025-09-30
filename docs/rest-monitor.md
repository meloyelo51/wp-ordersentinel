## OrderSentinel — REST/Search Monitor (MU)

### Settings
- **Enable REST logging**: Records every REST request (method, route, status, IP, UA, time).
- **Flag threshold (per IP, per hr)**: Used to highlight “Suspicious IPs (last hr)” on the dashboard.
- **Retention (days)**: How long to keep logs; purge runs automatically and on demand.
- **Trust proxies**: When behind Cloudflare/CDN/reverse proxy, use `CF-Connecting-IP` / `True-Client-IP` / `X-Forwarded-For` so the **client’s real IP** is logged instead of the proxy’s IP. Only enable if you truly run behind a trusted proxy.
- **Rate limit (REST/min)**: Per-IP per-minute limit for REST. Exceeded requests get **HTTP 429**.
- **Rate limit (search/min)**: Per-IP per-minute limit for `?s=` site search. Exceeded requests get **HTTP 429** with a friendly message.
- **Verify search engines via DNS**: For UAs claiming to be Googlebot/Bingbot, perform reverse DNS and forward confirm. Verified bots are exempt from rate limits. Cached for 24h.

### Dashboards
- **Top IPs (24h)**: Highest-volume client IPs (v4 or v6).
- **Top IPv4s (24h)**: Highest-volume IPv4s (uses headers if “Trust proxies” is on).
- **Top Routes (24h)**: Most-hit REST routes or logged search URIs.
- **Suspicious IPs (last hr)**: IPs over the “Flag threshold” in the past hour.

### CSV Export
**Settings → Export REST logs CSV (last 7 days)**. Columns: `ts, ip, ip_ver, ip_v4, method, route, status, user_id, ua, ref, took_ms, flags`.

### Notes
- Consider enabling **Trust proxies** when on Cloudflare; otherwise you’ll see proxy/host IPs.
- “Chrome Prefetch Proxy” IPs may appear; harmless. (Roadmap: exclude from rate-limiting.)
- Purge logs after changing settings to clean up old artifacts.
