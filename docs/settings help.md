Settings Explanations: (from Version: 0.3.6-mu4)

What those settings mean (plain English)

Trust proxies (OFF by default)

When OFF: we log the direct socket IP seen by your PHP (REMOTE_ADDR).

If you’re behind Cloudflare or a host proxy, this can be the proxy’s IP (e.g., GoDaddy/Cloudflare), not the visitor.

When ON: we prefer client IPs from trusted headers in this order:
CF-Connecting-IP → True-Client-IP → X-Forwarded-For (first public IPv4 if present, else first public IP).

Turn this ON if your site is behind Cloudflare/Akamai/another CDN. It’ll give you real client IPs (and we still fall back to REMOTE_ADDR if headers are missing).

Rate limit (REST/min)

Per-IP, per-minute limiter on WordPress REST calls. Default 300/min (very loose).

Counts are stored in object cache/transients. Exceeding the limit returns HTTP 429 to that IP (bots slow down; normal users unaffected).

Rate limit (search/min)

Per-IP limiter on front-end ?s= search requests. Default 60/min.

Helpful if someone hammers your internal search (bad for SEO/affiliates/CPU).

Verify search engines via DNS (OFF by default)

Real Googlebot/Bingbot are verified by reverse DNS, then forward-confirm to the same IP.

When enabled, we skip rate-limits for verified crawlers so SEO isn’t impacted.

Leave OFF if you’re conserving DNS lookups; turn ON if you’re seeing lots of “fake Googlebot”.