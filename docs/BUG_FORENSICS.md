# Bug Forensics — Stripe Level3 UTF-8 Truncation

**Upstream plugin:** WooCommerce Stripe Gateway v10.5.3
**Affected file:** `includes/abstracts/abstract-wc-stripe-payment-gateway.php`
**Affected line:** 1517
**Symptom:** Intermittent "Level3 data sum incorrect" entries in the Stripe
log. Actual cause is **NOT** a sum mismatch.

---

## Symptom in the wild

The Stripe log (`wp-content/uploads/wc-logs/woocommerce-gateway-stripe-*.log`)
shows entries like:

```text
2026-04-17T20:30:43+00:00 ERROR Level3 data sum incorrect CONTEXT: {
  "stripe_version":"10.5.3",
  "stripe_api_version":"2025-09-30.clover",
  "error":{"message":"Invalid request (check your POST parameters): For
    assistance, contact support at https://support.stripe.com/contact/.",
    "type":"invalid_request_error"},
  "order_line_items":{...},
  ...
}
```

**Misleading:** the string `"Level3 data sum incorrect"` is the WooCommerce
Stripe plugin's generic catch-all label for any `invalid_request_error`
returned by Stripe while Level3 data is attached. The actual Stripe error
code, parameter path, and message are **not** logged.

## Plugin source: where the misleading log message comes from

`includes/class-wc-stripe-api.php` (method `request_with_level3_data`):

```php
$is_level_3data_incorrect = (
    isset( $result->error )
    && isset( $result->error->type )
    && 'invalid_request_error' === $result->error->type
);

if ( $is_level3_param_not_allowed ) {
    // ...
} elseif ( $is_level_3data_incorrect ) {
    WC_Stripe_Logger::error(
        'Level3 data sum incorrect',
        [ 'error' => $result->error, 'order_line_items' => $order->get_items(), ... ]
    );
}

// Make the request again without level 3 data.
if ( $is_level3_param_not_allowed || $is_level_3data_incorrect ) {
    unset( $request['level3'] );
    return self::request( $request, $api );
}
```

Every `invalid_request_error` (bad UTF-8, bad field value, exceeded length,
etc.) is labeled "Level3 data sum incorrect", the Level3 data is silently
stripped, and the payment is retried without it. The merchant's log shows
the generic label; the actual Stripe error code is discarded.

## Root cause: byte-based truncation of multibyte strings

`includes/abstracts/abstract-wc-stripe-payment-gateway.php` line 1517, inside
`get_level3_data_from_order()`:

```php
$product_description = substr( $item->get_name(), 0, 26 );
```

`substr()` in PHP operates on **bytes**, not characters. When:

1. `$item->get_name()` contains a multibyte UTF-8 character, AND
2. The multibyte character's byte sequence straddles the 26-byte boundary,

…then `substr()` cuts the byte sequence in half, producing an invalid UTF-8
string. Stripe's API validates UTF-8 on every string field and rejects the
entire request with `invalid_request_error`.

## Byte-level proof with the `″` (U+2033, double prime, "inch mark")

`″` encodes in UTF-8 as **3 bytes**: `0xE2 0x80 0xB3`.

Consider the catering product "Roasted Cauliflower - 16″ (20-30 ppl)":

| Byte # | Character |
|--------|-----------|
| 1–24   | `Roasted Cauliflower - 16` (ASCII) |
| 25     | `0xE2` (start of `″`) |
| 26     | `0x80` (continuation) |
| 27     | `0xB3` (continuation) |
| 28+    | ` (20-30 ppl)` |

`substr($name, 0, 26)` returns bytes 1 through 26 → `Roasted Cauliflower - 16` + `0xE2 0x80`.

This is a **truncated UTF-8 sequence**: a multibyte start byte (`0xE2` —
indicating a 3-byte character) followed by one continuation byte, with the
final continuation byte missing. This is **invalid UTF-8**, and Stripe
rejects it.

## Production incidents tracked

Two orders on 2026-04-17 logged "Level3 data sum incorrect":

| Time (UTC) | Line items count | Poison-pill item |
|------------|-----------------|------------------|
| 17:06:20 | 3 | `Bow Tie Pesto Salad - 24″ (40-50 ppl)` — 24 ASCII + ″ starting at byte 25 |
| 20:30:43 | 8 | `Roasted Cauliflower - 16″ (20-30 ppl)` — 24 ASCII + ″ starting at byte 25 |

Both items match the pattern `^.{24}″` — exactly the byte offset where
`substr(0, 26)` cuts the multibyte sequence mid-character. These were
**not** random sum-mismatch errors; both were the UTF-8 truncation bug.

## Why the math looked fine in the error log

The Stripe plugin's error log attached the order's line items, tax, and
shipping for debugging. Manual reconciliation of the logged values showed
that the arithmetic was correct (the sum of `unit_cost × qty + tax_amount`
plus `shipping_amount` equaled the PaymentIntent `amount` down to the cent).
That was a red herring — the error was never about sums. It was about UTF-8
validity in a string field.

## Why the upstream plugin log is misleading, re-stated

The Stripe API returns (for a bad UTF-8 field):

```json
{
  "error": {
    "code": "parameter_invalid_string_blank"  // or similar
    "param": "level3[line_items][6][product_description]",
    "message": "Invalid UTF-8 byte sequence in product_description"
    "type": "invalid_request_error"
  }
}
```

The plugin:
- Checks only `$result->error->type === 'invalid_request_error'`
- Logs the generic string "Level3 data sum incorrect"
- Discards `->code`, `->param`, `->message`
- Strips Level3 and retries

A single-line fix to the upstream plugin — logging `$result->error->code`
and `$result->error->param` — would have made this bug trivial to diagnose.
We have not filed the upstream issue yet (see `README.md`).

## Cost of the fallback retry

When Level3 is stripped and retried, the charge proceeds at **standard
interchange**. For card-present merchants this typically means 2.9% + $0.30
(Stripe's published rate). For B2B / corporate card transactions that would
have qualified for the Level 3 discount, the interchange difference is
**0.3% – 1.0%** depending on card type:

- Visa Purchasing / Business / Fleet cards — biggest discount
- Visa Signature Business — smaller
- Mastercard Commercial — similar tiering

For Aladdin Mediterranean Cuisine (~$12k/mo catering, ~60% B2B estimate):

```text
Monthly B2B volume:  ~$7,200
Level3 lift:          0.5% midpoint estimate
Monthly leakage:     ~$36
Annual leakage:      ~$432 – $1,080 depending on card mix
```

This cost is **invisible** in the Woo admin — Stripe collapses "Level 3
applied" / "Level 3 denied" into the same `balance_transaction.fee` line
unless you specifically pull `balance_transaction.fee_details` from the API.

## What the fix does

Hook into the plugin's existing (but previously unused here) filter:

```php
add_filter(
    'wc_stripe_payment_request_level3_data',
    function ( $level3_data, $order ) {
        // Rebuild product_description from the original WC_Order item name
        // using mb_strcut() — byte-limited cut that stops at the last full
        // UTF-8 character boundary, never leaving half a multibyte sequence.
        ...
    },
    10, 2
);
```

Full source in [`src/aladdin-stripe-level3-utf8-fix.php`](../src/aladdin-stripe-level3-utf8-fix.php).

### Why rebuild from `$order` rather than fix the string we were passed?

By the time our filter runs, the Stripe plugin has already run `substr(0,
26)` and handed us a string that may be **already** invalid UTF-8
(truncated mid-character). We cannot recover the original text from a
string with missing bytes. Rebuilding from the upstream `$item->get_name()`
gives us the original full UTF-8 name to re-cut correctly.

### Why `mb_strcut` and not `mb_substr`?

- `mb_substr($s, 0, 26, 'UTF-8')` counts **characters** (so 26 characters
  might be 60+ bytes). Stripe's byte limit would be violated.
- `mb_strcut($s, 0, 26, 'UTF-8')` counts **bytes** up to the limit but
  backs off to the last full character boundary — exactly the semantics we
  need.

## Reproducing locally

See [`tests/level3-fix-tests.php`](../tests/level3-fix-tests.php) for a full
reproducer using the upstream Stripe plugin's own
`get_level3_data_from_order()` method. Example subset:

```php
$order = new WC_Order();
$order->set_currency("USD");

$item = new WC_Order_Item_Product();
$item->set_name("Roasted Cauliflower - 16\xe2\x80\xb3 (20-30 ppl)"); // 16″
$item->set_quantity(1);
$item->set_subtotal(79.99);
$item->set_total(79.99);
$item->set_total_tax(6.60);
$order->add_item($item);

// Without the fix:
$raw_desc = substr($item->get_name(), 0, 26);
echo mb_check_encoding($raw_desc, 'UTF-8') ? 'VALID' : 'INVALID';  // prints INVALID

// With the fix:
$safe_desc = mb_strcut($item->get_name(), 0, 26, 'UTF-8');
echo mb_check_encoding($safe_desc, 'UTF-8') ? 'VALID' : 'INVALID';  // prints VALID
```

## Alternative fixes considered and rejected

| Option | Why rejected |
|--------|--------------|
| Fork the Stripe plugin | Loses upstream security updates |
| Patch the plugin in place | Overwritten on every update |
| Remove all `″` from product names | Changes the menu copy, visible to customers |
| `__return_false` on `wc_stripe_send_level3` | Disables Level3 entirely, permanent fee penalty |
| Sanitize the broken string post-hoc (strip invalid bytes) | Works but loses data; rebuilding from source is cleaner |

Rebuilding from `$order` via the filter is the minimal, reversible,
upstream-safe fix.
