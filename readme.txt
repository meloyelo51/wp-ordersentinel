OrderSentinel
=============

Administrative helper to attach lightweight OSINT research to suspicious WooCommerce orders.

Features
- Bulk action on Orders: "Run OSINT Research (OrderSentinel)".
- RDAP (whois-like) via rdap.org, IP geolocation via ip-api.com, optional AbuseIPDB checks.
- Stores research JSON in order meta `_ordersentinel_research` and adds a private order note.

Configuration
- To enable AbuseIPDB: add to wp-config.php  
  `define( 'ORDERSENTINEL_ABUSEIPDB_KEY', 'your-key-here' );`

Legal & Privacy
- Intended for defensive use by site owners/operators on their own order data.
- Respect API provider terms and local privacy laws (GDPR/CCPA).
- Avoid scraping/search-engine automation from your server to reduce legal and rate-limit risks.

Roadmap
- Settings UI for API keys & toggles
- CSV/JSON export of findings
- Clustering by IP ASN / ISP / email / phone / address
- Optional Slack alerts or SIEM forwarding
