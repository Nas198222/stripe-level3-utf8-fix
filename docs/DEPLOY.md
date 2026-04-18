# Deployment Runbook

This document records the exact deployment sequence executed for
aladdinshouston.com on 2026-04-18, and generalizes it so any other
WooCommerce + Stripe site can follow the same path.

## Prerequisites

- SSH access to both staging and production environments (or at least
  production).
- `wp` CLI installed server-side (standard on Kinsta, WP Engine, Pressable,
  SiteGround, Flywheel, and most managed hosts).
- PHP 8.x with `mbstring` extension enabled (default on virtually all
  modern hosts; we explicitly check).

## Deployment sequence (staging first)

### 1. Pre-flight checks on staging

```bash
# SSH in and verify environment
ssh your-staging-alias "wp option get siteurl"
ssh your-staging-alias "ls wp-content/mu-plugins/"
ssh your-staging-alias "wp eval 'echo function_exists(\"mb_strcut\") ? \"mbstring OK\" : \"MISSING mbstring — ABORT\";'"
```

Expected:

- `siteurl` prints the staging URL
- `mu-plugins/` directory exists and does not already contain `aladdin-stripe-level3-utf8-fix.php`
- `mb_strcut` is available

### 2. Syntax-check locally

```bash
php -l src/aladdin-stripe-level3-utf8-fix.php
# → No syntax errors detected
```

### 3. Upload to staging

```bash
scp src/aladdin-stripe-level3-utf8-fix.php \
    your-staging-alias:/path/to/site/wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php
```

### 4. Verify on staging

```bash
ssh your-staging-alias bash -lc '
  cd /path/to/site
  echo "--- file on disk ---"
  ls -la wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php
  echo "--- server-side syntax check ---"
  php -l wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php
  echo "--- WordPress must-use plugin list ---"
  wp plugin list --status=must-use --format=table
  echo "--- filter registered? ---"
  wp eval "echo has_filter(\"wc_stripe_payment_request_level3_data\") ? \"YES\" : \"NO\";"
'
```

All four expected: file present, no syntax errors, appears in must-use list,
filter returns YES.

### 5. Functional test on staging

Run the test battery from [`tests/level3-fix-tests.php`](../tests/level3-fix-tests.php):

```bash
ssh your-staging-alias "cd /path/to/site && wp eval-file wp-content/mu-plugins/level3-fix-tests.php"
```

See [`TESTING.md`](TESTING.md) for full expected output.

### 6. Browser E2E on staging (optional but recommended)

- Add a catering product whose name contains a multibyte character at byte
  25 (for Aladdin: any 16″ or 24″ item).
- Check out with a Stripe test card (`4242 4242 4242 4242` if staging has
  test-mode Stripe keys; otherwise use a real low-amount card and refund).
- Tail the Stripe log via PHP (file perms often block shell user):

```bash
ssh your-staging-alias "cd /path/to/site && wp eval '
  \$logs = glob(\"wp-content/uploads/wc-logs/woocommerce-gateway-stripe-*.log\");
  usort(\$logs, fn(\$a,\$b) => filemtime(\$b) - filemtime(\$a));
  echo file_get_contents(\$logs[0]);
' | tail -80"
```

Expected: zero `Level3 data sum incorrect` entries for transactions dated
after deploy.

### 7. Deploy to production

**Important:** don't use a managed-host "staging → production" bulk sync
if staging and production have diverged (different mu-plugins, different
data). Use surgical SSH deploy instead:

```bash
# Pre-check: confirm target file does NOT exist on production yet
ssh your-production-alias "ls /path/to/site/wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php 2>&1"
# → expected: No such file or directory

# Upload
scp src/aladdin-stripe-level3-utf8-fix.php \
    your-production-alias:/path/to/site/wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php

# Verify
ssh your-production-alias bash -lc '
  cd /path/to/site
  php -l wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php
  wp plugin list --status=must-use --format=table
  wp eval "echo has_filter(\"wc_stripe_payment_request_level3_data\") ? \"YES\" : \"NO\";"
'
```

### 8. Live sanity check on production

Run one more functional check on the live site with a fabricated (in-memory,
uncommitted) order:

```bash
ssh your-production-alias "cd /path/to/site && wp eval '
  \$order = new WC_Order();
  \$order->set_currency(\"USD\");
  \$it = new WC_Order_Item_Product();
  \$it->set_name(\"Roasted Cauliflower - 16\xe2\x80\xb3 (20-30 ppl)\");
  \$it->set_quantity(1);
  \$it->set_subtotal(79.99); \$it->set_total(79.99); \$it->set_total_tax(6.60);
  \$order->add_item(\$it);

  \$gw = null;
  foreach (WC()->payment_gateways()->payment_gateways() as \$g) {
    if (method_exists(\$g, \"get_level3_data_from_order\")) { \$gw = \$g; break; }
  }
  \$l3 = \$gw->get_level3_data_from_order(\$order);
  \$desc = \$l3[\"line_items\"][0]->product_description;
  echo \"desc=\\\"\$desc\\\" utf8=\" . (mb_check_encoding(\$desc,\"UTF-8\")?\"YES\":\"NO\") . \"\n\";
'"
```

Expected: `desc="Roasted Cauliflower - 16" utf8=YES`

## Rollback

A single file, no database changes, no schema migrations. To revert:

```bash
ssh your-production-alias "rm /path/to/site/wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php"
```

That is the entire rollback procedure. The site returns to the previous
behavior (Level3 stripped on poison-pill names, standard interchange fees).

## Plugin-update considerations

The upstream WooCommerce Stripe Gateway plugin may ship a fix for line
1517. If so:

1. This mu-plugin remains harmless — the filter runs after the upstream
   builder and only rewrites when the description needs fixing. If upstream
   produces valid UTF-8, `mb_strcut` on a valid string returns it unchanged.
2. You can retire this mu-plugin by deleting it once upstream is verified
   fixed on the running version.

Monitor the upstream plugin changelog for fixes to
`get_level3_data_from_order()` or `substr` → `mb_strcut` migrations.

## Deployment record for aladdinshouston.com

| Event | When (UTC) | Environment | Verified by |
|-------|-----------|-------------|-------------|
| Fix authored | 2026-04-18 ~22:13 | local | php -l, 23 tests |
| v1.0.0 deployed to staging | 2026-04-18 22:15 | staging | wp plugin list, functional test |
| Self-review found edge case (26-byte already-broken input) | 2026-04-18 22:17 | — | functional test |
| v1.0.1 redeployed to staging (rebuild from source `$order`) | 2026-04-18 ~22:20 | staging | 23/23 test battery passed |
| E2E smoke test on staging | 2026-04-18 ~22:36 | staging | zero Level3 errors in log after test checkout |
| **Deployed to production** | **2026-04-18 22:40** | **production** | live sanity check passed |
