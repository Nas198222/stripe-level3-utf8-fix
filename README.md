# Stripe Level3 UTF-8 Fix for WooCommerce

**Status:** 🟢 Deployed to production on 2026-04-18
**Site:** aladdinshouston.com (Aladdin Mediterranean Cuisine, Houston TX)
**Upstream plugin affected:** [WooCommerce Stripe Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/) v10.5.3

## TL;DR

The WooCommerce Stripe Gateway plugin has a bug in its Level 3 data builder
that produces **invalid UTF-8** byte sequences when a product name contains a
multibyte character (e.g. `″` U+2033 — the inch mark used in catering tray
sizes like `16″` / `24″`). Stripe rejects the Level3 payload with
`invalid_request_error`, the plugin silently drops Level3, and the charge
falls back to standard interchange pricing — costing roughly **0.3–1% extra**
on B2B/commercial-card transactions.

This repo contains a surgical **must-use plugin** that hooks the existing
`wc_stripe_payment_request_level3_data` filter to rewrite each
`product_description` using `mb_strcut()` — which respects UTF-8 character
boundaries. Zero change to the upstream plugin; survives plugin updates.

## Repository layout

```
├── README.md              ← this file
├── src/
│   └── aladdin-stripe-level3-utf8-fix.php   ← the fix (drop into wp-content/mu-plugins/)
├── docs/
│   ├── BUG_FORENSICS.md   ← deep dive: why this happens, line numbers, bytes
│   ├── DEPLOY.md          ← deployment runbook (staging → production)
│   ├── TESTING.md         ← full test battery (23 tests) and results
│   └── MONITORING.md      ← how to verify the fix is working in production
└── tests/
    └── level3-fix-tests.php   ← runnable test suite (wp eval)
```

## The bug in one sentence

> Line 1517 of `abstract-wc-stripe-payment-gateway.php` uses byte-based
> `substr($item->get_name(), 0, 26)` on potentially multibyte strings,
> creating broken UTF-8 sequences that Stripe rejects.

## The fix in one sentence

> Add a `wc_stripe_payment_request_level3_data` filter that rebuilds each
> line item's `product_description` from the original `$order` item name
> using `mb_strcut()` — byte-limited truncation that stops at the last full
> UTF-8 character boundary.

## Quick install (any WooCommerce + Stripe site)

1. Copy `src/aladdin-stripe-level3-utf8-fix.php` into your site's
   `wp-content/mu-plugins/` directory.
2. Verify load: `wp plugin list --status=must-use`
3. Verify filter: `wp eval 'echo has_filter("wc_stripe_payment_request_level3_data") ? "OK" : "FAIL";'`

No database changes, no plugin activation needed. Must-use plugins load
automatically.

## Documentation

| Document | What it covers |
|----------|---------------|
| [docs/BUG_FORENSICS.md](docs/BUG_FORENSICS.md) | Full forensic audit — upstream code references, byte-level analysis of failing orders, why the plugin's own error handler is misleading |
| [docs/DEPLOY.md](docs/DEPLOY.md) | Step-by-step deployment (staging → production), verification commands, rollback procedure |
| [docs/TESTING.md](docs/TESTING.md) | 23-test battery with results, edge case matrix, functional proof |
| [docs/MONITORING.md](docs/MONITORING.md) | Post-deploy monitoring, signals to watch, what a healthy vs broken state looks like |

## Why this matters (business impact)

For a catering-heavy Woo store with B2B / corporate card volume, Level 3
interchange can save **~0.3–1%** on qualifying commercial-card charges.
Stripe does not refund this silently — every failing checkout overpays and
the merchant never sees it unless they specifically inspect the
`balance_transaction.fee_details` for "Level 3" attribution.

For Aladdin Mediterranean Cuisine:
- ~$12,000/month catering volume, ~60% B2B estimate
- Estimated annual leakage before fix: **$600 – $1,000 / year**

## License

MIT. See [LICENSE](LICENSE).

## Credits

- **Bug reported by:** Ali Nahhas (Aladdin Mediterranean Cuisine)
- **Root cause identified, fix authored, and deployed:** 2026-04-18
- **Upstream plugin:** WooCommerce Stripe Gateway by Automattic

## Upstream report

This bug exists in the upstream plugin. An upstream fix would be preferred
so this workaround can be retired. Issue reference (if filed):
_(to be added — see docs/BUG_FORENSICS.md for a report-ready write-up)_
