# Post-deploy Monitoring

## What to watch

### 1. Stripe error log — should stay clean

From the deploy date forward, no new `Level3 data sum incorrect` entries
should appear in any `woocommerce-gateway-stripe-YYYY-MM-DD-*.log` file.

Quick check (runs via WP-CLI so PHP reads files the shell user cannot):

```bash
wp eval '
  $logs = glob("wp-content/uploads/wc-logs/woocommerce-gateway-stripe-*.log");
  $total = 0;
  foreach ($logs as $log) {
    $content = file_get_contents($log);
    $count = substr_count($content, "Level3 data sum incorrect");
    if ($count > 0) {
      echo basename($log) . ": $count\n";
      $total += $count;
    }
  }
  echo "TOTAL: $total\n";
'
```

**Expected:** only entries in files dated on/before the deploy date. Zero
in files dated after.

### 2. Stripe Dashboard — Level 3 field populated

On any new B2B catering charge, in the Stripe Dashboard:

1. Navigate to **Payments** → open the charge → expand "Payment details".
2. The **Level 3 data** section should show:
   - `merchant_reference`: the WC order ID
   - `shipping_amount`: shipping in cents
   - `shipping_from_zip`: store postcode
   - `line_items[]`: each with `product_code`, `product_description`
     (readable, ASCII or valid UTF-8), `unit_cost`, `quantity`,
     `tax_amount`, `discount_amount`

If Level 3 data is absent, either:

- The mu-plugin was removed or disabled, OR
- Stripe set the `wc_stripe_level3_not_allowed` transient (denies Level 3
  for the account) — check with `wp transient get wc_stripe_level3_not_allowed`

### 3. Fee attribution on commercial cards

For B2B charges, inspect `balance_transaction.fee_details` in the Stripe
API (Dashboard does not show this granularity):

```bash
curl https://api.stripe.com/v1/balance_transactions/<TXN_ID> \
  -u "<SECRET_KEY>:" \
  -d "expand[]=source"
```

Look for `fee_details[].description` values that reference "Commercial" /
"Business" / "Purchasing" with Level 3 interchange-rate values. Before the
fix these would show the standard (non-qualified) card-present rate.

### 4. Filter still registered

```bash
wp eval 'echo has_filter("wc_stripe_payment_request_level3_data") ? "OK\n" : "MISSING — INVESTIGATE\n";'
```

A missing filter means either:

- The mu-plugin file was deleted or renamed
- The file has a PHP fatal that prevented registration (check `debug.log`)

### 5. mu-plugin still loaded

```bash
wp plugin list --status=must-use --format=table
```

The row `aladdin-stripe-level3-utf8-fix | must-use | 1.0.1` should be present.

## What a healthy state looks like

- Zero new `Level3 data sum incorrect` entries in Stripe logs
- `wc_stripe_level3_not_allowed` transient is NOT set
- Filter `wc_stripe_payment_request_level3_data` returns `has_filter() === 10` (or whatever priority you set — we use 10)
- mu-plugin visible in `wp plugin list --status=must-use`

## What a broken state looks like

| Signal | Likely cause | Response |
|--------|--------------|----------|
| New `Level3 data sum incorrect` in logs | mu-plugin removed OR upstream plugin changed OR new edge case (non-″ multibyte hitting byte 25-26) | Re-check mu-plugin file exists, inspect a failing order's item names for new multibyte patterns |
| `has_filter()` returns false | mu-plugin fatal-errored during load | Check `wp-content/debug.log` for PHP errors, run `php -l` on the file |
| `wc_stripe_level3_not_allowed` transient set | Stripe refused Level3 for the account | `wp transient delete wc_stripe_level3_not_allowed`, investigate why (may indicate a NEW field format issue beyond UTF-8) |
| Level 3 section missing from Stripe Dashboard | Level3 being stripped before send | Pull latest `woocommerce-gateway-stripe-*.log` and look for errors |

## Alerting (optional setup)

If you want active alerts rather than periodic checks, add a cron-driven
watchdog:

```php
// Example: wp-cron hook that fires daily and emails if Level3 errors appear
add_action( 'aladdin_check_stripe_level3', function () {
    $logs = glob( WP_CONTENT_DIR . '/uploads/wc-logs/woocommerce-gateway-stripe-*.log' );
    $total = 0;
    foreach ( $logs as $log ) {
        // Only count errors from the last 24 hours
        if ( filemtime( $log ) < strtotime( '-1 day' ) ) {
            continue;
        }
        $total += substr_count( file_get_contents( $log ), 'Level3 data sum incorrect' );
    }
    if ( $total > 0 ) {
        wp_mail(
            get_option( 'admin_email' ),
            '[Aladdin] Stripe Level3 errors detected',
            "Found $total Level3 error(s) in the last 24h. Check wc-logs."
        );
    }
} );
if ( ! wp_next_scheduled( 'aladdin_check_stripe_level3' ) ) {
    wp_schedule_event( time(), 'daily', 'aladdin_check_stripe_level3' );
}
```

_(Not included in the mu-plugin by default — add only if desired.)_

## Retiring the fix

If Automattic ships an upstream fix for `abstract-wc-stripe-payment-gateway.php`
line 1517 (replacing `substr` with `mb_strcut` or equivalent):

1. Verify the fix on your running Stripe plugin version by grepping the
   source for `mb_strcut` near the Level3 builder.
2. Confirm the filter is no longer needed by temporarily removing the
   mu-plugin on staging and re-running the production-mirror test case.
3. Delete the mu-plugin from production:
   `rm wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php`
4. Update this repo's README to note the upstream version where the
   underlying bug was fixed.
