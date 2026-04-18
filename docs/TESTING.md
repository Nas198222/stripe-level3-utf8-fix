# Testing

## Test battery overview

23 tests covering four categories:

1. **UTF-8 boundary cases** — does the filter correctly handle truncation
   at various character positions, character widths (1/2/3/4-byte), and
   input lengths?
2. **Non-destruction** — does the filter preserve all other Level3 fields
   (`merchant_reference`, `shipping_amount`, `unit_cost`, `quantity`,
   `tax_amount`, `discount_amount`, `product_code`) untouched?
3. **Malformed input resilience** — does the filter degrade gracefully on
   empty, missing, or malformed inputs?
4. **Integration with the real upstream builder** — when fed through the
   actual `WC_Stripe_UPE_Payment_Gateway::get_level3_data_from_order()`
   method, does the output satisfy Stripe's validation requirements?

Plus a before/after proof and a performance measurement.

## How to run

The tests live in `tests/level3-fix-tests.php`. Run with WP-CLI:

```bash
wp eval-file tests/level3-fix-tests.php
```

Tests are runnable against any WordPress installation that has WooCommerce
+ WooCommerce Stripe Gateway active.

## Result summary (last run — 2026-04-18, production-mirror staging)

| # | Test | Result |
|---|------|--------|
| T01 | Short ASCII name untouched | ✅ PASS |
| T02 | Exact 26 bytes ASCII untouched | ✅ PASS |
| T03 | 27 bytes ASCII → truncate to 26 | ✅ PASS |
| T04 | `″` at byte 25 (poison pill) → trunc to 24 clean | ✅ PASS |
| T05 | `″` at byte 22 → keep full `″` | ✅ PASS |
| T06 | `″` at byte 27+ → truncate ASCII-only | ✅ PASS |
| T07 | 4-byte emoji straddling limit → valid UTF-8 | ✅ PASS |
| T08 | 2-byte accented char (é) safe | ✅ PASS |
| T09 | Empty name does not crash | ✅ PASS |
| T10 | All-multibyte name → valid UTF-8 at char boundary | ✅ PASS |
| T11 | Other Level3 fields preserved (merchant_ref, shipping, unit_cost, qty, tax, discount, code) | ✅ PASS |
| T12 | Idempotent — double apply produces same result | ✅ PASS |
| T13 | Empty `line_items` array handled | ✅ PASS |
| T14 | Missing `line_items` key handled | ✅ PASS |
| T15 | Malformed entries (strings / null / numbers in array) do not crash | ✅ PASS |
| T16 | Null `$order` does not crash | ✅ PASS |
| T17 | Real Stripe builder + our filter → all UTF-8 valid on mirror of failing order | ✅ PASS |
| T18 | Level3 sum math preserved (`Σ(unit_cost × qty) + Σ tax_amount − Σ discount + shipping_amount = amount`) | ✅ PASS |
| T19 | `merchant_reference` = order ID | ✅ PASS |
| T20 | `line_items` count matches source order | ✅ PASS |
| T21 | Before/after proof: raw builder produces invalid UTF-8, fixed builder does not | ✅ PASS |
| T22 | Math reconciliation: Level3 sum = PaymentIntent amount (86400¢ = 86400¢ for mirror of failing production order #39049) | ✅ PASS |
| T23 | Performance — filter overhead under 0.001 ms per call (1000 iterations) | ✅ PASS |

**23/23 PASS**

## Edge-case matrix

Testing `mb_strcut($name, 0, 26, 'UTF-8')` against names where `″` (3 bytes)
appears at every possible byte position:

| `″` starts at byte | Bytes of `″` in the 26-byte window | Expected output | Actual |
|-------------------|-----------------------------------|----------------|--------|
| 1 | all 3 | full `″` preserved | ✅ |
| 24 | all 3 | full `″` preserved | ✅ |
| 25 | 2 of 3 (INVALID if left alone) | back off to byte 24, drop `″` | ✅ |
| 26 | 1 of 3 (INVALID if left alone) | back off to byte 25, drop `″` | ✅ |
| 27 | 0 of 3 | cut cleanly in ASCII, `″` not in output | ✅ |
| 50+ | 0 | cut cleanly in ASCII | ✅ |

## Production incident reproduction

The two orders that originally triggered "Level3 data sum incorrect" in
production were mirrored in the test battery (T17, T21, T22):

### Order 39049 mirror (2026-04-17 20:30:43 UTC)

| Item | Name | Qty | Subtotal | Line tax |
|------|------|-----|----------|---------|
| 1 | Saffron Rice - 16″ (20-30 ppl) | 1 | $49.99 | $4.12 |
| 2 | Classic Chicken Kabob | 25 | $199.75 | $16.48 |
| 3 | Filet Steak Kabob | 25 | $224.75 | $18.54 |
| 4 | Greek Salad - 16″ (20-30 ppl) | 1 | $69.99 | $5.77 |
| 5 | Lebanese Cucumber Salad - 16″ (20-30 ppl) | 1 | $69.99 | $5.77 |
| 6 | Pita Bread | 1 | $30.00 | $2.48 |
| **7** | **Roasted Cauliflower - 16″ (20-30 ppl)** | **1** | **$79.99** | **$6.60** |
| 8 | Classic Hummus Tray (Catering) - 12″ (10-20 ppl) | 1 | $45.99 | $3.79 |
| Shipping | — | — | $30.00 | $0 |
| **Total** | | | **$864.00** | $63.55 tax |

Without fix: item 7 `product_description` = `Roasted Cauliflower - 16` +
`\xE2\x80` — **26 bytes, invalid UTF-8**.

With fix: item 7 `product_description` = `Roasted Cauliflower - 16` —
**24 bytes, valid UTF-8**.

Level3 sum with fix = 86400 cents = PaymentIntent `amount` of
`round($864.00 × 100) = 86400`. Stripe accepts.

### Order 39048 mirror (2026-04-17 17:06:20 UTC)

3 line items including `Bow Tie Pesto Salad - 24″ (40-50 ppl)` — same
`^.{24}″` pattern. Before: 1 invalid UTF-8 item. After: 0.

## Why a declined test card on staging still validates the fix

When Boss ran a browser checkout on staging after deploy, the test card
(`4242 4242 4242 4242`) declined because staging was connected to **live
Stripe keys** rather than test-mode keys. Despite the decline:

1. Stripe logs validation errors (`invalid_request_error`) **before** card
   authorization. If the Level3 payload had been invalid UTF-8, the log
   would have contained a `Level3 data sum incorrect` entry from
   `request_with_level3_data()` in `class-wc-stripe-api.php`. It did not.
2. Instead, the log contained only a `payment_intent_payment_attempt_failed`
   with `decline_code: generic_decline` — which is a card decline after
   successful PaymentIntent creation, demonstrating that the PaymentIntent
   POST (which includes Level3) had been accepted by Stripe before any
   card authorization was attempted.
3. Additionally, the plugin's transient flag `wc_stripe_level3_not_allowed`
   remained unset, confirming Stripe had not rejected Level3 for this
   account.

This provides reasonable confidence that the fix works end-to-end,
separate from the 23 code-level tests above.

## Continuous verification

To confirm the fix continues working weeks / months after deploy:

```bash
wp eval '
  $logs = glob("wp-content/uploads/wc-logs/woocommerce-gateway-stripe-*.log");
  foreach ($logs as $log) {
    $content = file_get_contents($log);
    $count = substr_count($content, "Level3 data sum incorrect");
    if ($count > 0) {
      echo basename($log) . ": $count Level3 error(s)\n";
    }
  }
  echo "done\n";
'
```

Expected: zero hits from the deploy date forward. If any appear, investigate
immediately — either a new type of name break-out is hitting a different
edge case, or the upstream plugin has changed behavior.
