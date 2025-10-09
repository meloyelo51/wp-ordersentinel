# OrderSentinel — Project Snapshot

## Project Summary
- **Project:** OrderSentinel — Fraud/OSINT helper for WooCommerce orders  
- **Repo:** https://github.com/meloyelo51/wp-ordersentinel  
- **Default branch:** `main`

**What it does:** WordPress/WooCommerce plugin that monitors REST traffic (logs to a custom table; shows recent requests, top IPs/routes; CSV export; blacklist/allowlist) and adds OSINT helpers to order admin, including AbuseIPDB integration. Goal: keep `order-sentinel.php` minimal and bake everything into `/includes` and `/assets` (no MU-plugins in the ZIP).

**Key structure & tech**
- `order-sentinel/order-sentinel.php` — minimal loader + version header
- `order-sentinel/includes/`
  - `class-os-rest-monitor.php` — core REST monitor + pages/tables/CSV
  - `os-rest-monitor-bootstrap.php` — wires hooks, admin notices, helpers
  - `os-rest-capture.php` — request logging
  - `os-order-metabox.php` — combined order metabox (IP, actions)
- `order-sentinel/assets/admin.js`, `assets/icon.svg`
- **Tech:** PHP (WP/Woo), WordPress REST API, AbuseIPDB, RDAP, ip-api
- **Build:** Python one-shots using `zipfile` to `dist/OrderSentinel-<ver>.zip` (excludes backups/hidden files)

---

## Current Roadmap Summary

### ✅ Completed successfully
- **baked-rest-monitor** — Migrated MU functionality into `/includes`; menu under **WooCommerce → REST Monitor** (fallback **Tools**).
- **ui-tabs-restored** — Overview/Tools/Settings visible; CSV column label uses **Status**.
- **timeframe-controls** — Top IPs/Routes honor configurable window; titles reflect timeframe.
- **blacklist-import-placement** — Import/replace UI grouped in **Settings** with existing lists.
- **checkbox-persistence-fix** — “Store JSON on the order” no longer re-checks itself; read/save normalizers and strict render check added.
- **packaging-cleanup** — MU files excluded; backup/hidden files excluded; Python build avoids Windows `zip` quirks.
- **header-encoding-repairs** — BOM/garbage stripped; header/class version bumps work with one-shot.

### ⚙️ In progress
- **combined-order-metabox** — Single “OrderSentinel” metabox with IP/XFF, OSINT links, jump links (REST/Tools), AbuseIPDB action.  
  **Next:** Add “Latest REST hits for this IP” subtable; show last report timestamp.
- **abuseipdb-reporter** — Server-side reporter method implemented; admin notices wired.

### ⏸️ Paused / removed
- **mu-plugins-approach** — Testing via MU is paused; goal is fully baked-in.

### 🧩 Stuck / broken (needs fix)
- **abuseipdb-button-submits-wrong** — From order edit, “Report to AbuseIPDB” redirects to `edit.php` (list screen) instead of hitting `admin-post.php`.  
  **Likely cause:** Nested `<form>` in order edit; inner form ignored.  
  **Fix:** Use `<button formaction="admin-post.php" formmethod="post" name="action" value="ordersentinel_report_ip">…</button>` + nonce; or signed GET link; or AJAX.

- **occasional-version-packaging-drift** — Header vs ZIP mismatch or “slashes in filenames.”  
  **Fix:** Stick to Python-only pack; bump header + class const together; keep excludes list.

### 💡 Planned / idea backlog
- **quick-ban-allow** — Actions from Recent Requests and order metabox.
- **table-scroll-resize** — Horizontal scroll + resizable columns for REST tables.
- **full-address-fields** — Show more address fields once tables are resizable.
- **hpos-compat** — Ensure metabox and storage work with HPOS enabled.
- **activity-line** — Last AbuseIPDB report timestamp in metabox.
- **debug-page** — Dedicated small debug page under Tools (instead of URL flags).

---

## Additional Notes
- **Testing & regression**
  - Avoid nested forms in metabox; use `formaction`/`formmethod` or AJAX.
  - Keep UI structure stable (tabs/labels/placements); CSV uses **Status**.
  - DB schema must include `code`, `meth`, etc.
  - Ensure zips never include `mu-plugins`.

- **Patch/apply method**
  - **Preferred:** Python one-shot (pure Python build with `zipfile`); reliable on Windows/MINGW.
  - Avoid PowerShell and the `zip` CLI; use Python for bump + pack.
  - One-shot should be idempotent and exclude backups/hidden files.

- **Workflow quirks to preserve**
  - Minimal main plugin; everything in `/includes` & `/assets`.
  - Primary menu under **WooCommerce**, fallback **Tools**.
  - Don’t change UI unless requested.
  - Distribute as `dist/OrderSentinel-<ver>.zip`.
