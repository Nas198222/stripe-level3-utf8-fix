<?php
/**
 * Stripe Level3 UTF-8 Fix — Test Battery
 *
 * Run with: wp eval-file tests/level3-fix-tests.php
 *
 * Requires: WooCommerce + WooCommerce Stripe Gateway active, plus the
 * mu-plugin from src/aladdin-stripe-level3-utf8-fix.php installed.
 *
 * 23 tests covering UTF-8 boundaries, non-destruction of other Level3
 * fields, malformed input resilience, and end-to-end integration with the
 * upstream Stripe plugin's real Level3 builder.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$pass = 0;
$fail = 0;

function _t( $name, $cond, &$pass, &$fail, $detail = '' ) {
    echo ( $cond ? '✅' : '❌' ) . " $name" . ( $detail ? " — $detail" : '' ) . "\n";
    if ( $cond ) { $pass++; } else { $fail++; }
}

function _apply_fix_to_name( $name ) {
    $order = new WC_Order();
    $order->set_currency( 'USD' );
    $item = new WC_Order_Item_Product();
    $item->set_name( $name );
    $item->set_quantity( 1 );
    $item->set_subtotal( 10 );
    $item->set_total( 10 );
    $item->set_total_tax( 0.83 );
    $order->add_item( $item );
    $payload = array(
        'merchant_reference' => 1,
        'shipping_amount'    => 0,
        'line_items'         => array(
            (object) array(
                'product_code'        => 'X',
                'product_description' => substr( $name, 0, 26 ),
                'unit_cost'           => 1000,
                'quantity'            => 1,
                'tax_amount'          => 83,
                'discount_amount'     => 0,
            ),
        ),
    );
    $result = apply_filters( 'wc_stripe_payment_request_level3_data', $payload, $order );
    return $result['line_items'][0]->product_description;
}

echo "\n━━━━━━━━━━ UTF-8 BOUNDARY CASES ━━━━━━━━━━\n";

// T01 — Short ASCII (< 26 bytes) passes through
$out = _apply_fix_to_name( 'Pita Bread' );
_t( 'T01 short ASCII untouched', $out === 'Pita Bread', $pass, $fail, "\"$out\"" );

// T02 — Exact 26 bytes ASCII untouched
$out = _apply_fix_to_name( 'Classic Hummus Tray (Cater' );
_t( 'T02 exact 26 ASCII untouched', $out === 'Classic Hummus Tray (Cater' && strlen( $out ) === 26, $pass, $fail, strlen( $out ) . 'b' );

// T03 — 27 bytes ASCII → trunc to 26
$out = _apply_fix_to_name( 'Roasted Cauliflower Side 16' );
_t( 'T03 27 ASCII → trunc 26', strlen( $out ) <= 26 && mb_check_encoding( $out, 'UTF-8' ), $pass, $fail, "\"$out\" (" . strlen( $out ) . 'b)' );

// T04 — ″ at byte 25 (poison pill) → trunc to 24 clean
$out = _apply_fix_to_name( "Roasted Cauliflower - 16\xe2\x80\xb3 (20-30 ppl)" );
_t( 'T04 ″@25 → trunc 24 clean', $out === 'Roasted Cauliflower - 16' && mb_check_encoding( $out, 'UTF-8' ), $pass, $fail, "\"$out\"" );

// T05 — ″ at byte 22 → keep full ″
$out = _apply_fix_to_name( "Short Salad - Size 12\xe2\x80\xb3 extra" );
_t( 'T05 ″@22 → keep full ″', mb_check_encoding( $out, 'UTF-8' ) && strpos( $out, "\xe2\x80\xb3" ) !== false, $pass, $fail, "\"$out\"" );

// T06 — ″ at byte 27 (past window) → ASCII-only trunc
$out = _apply_fix_to_name( "ABCDEFGHIJKLMNOPQRSTUVWXYZ\xe2\x80\xb3abc" );
_t( 'T06 ″@27 → ASCII trunc', strlen( $out ) === 26 && mb_check_encoding( $out, 'UTF-8' ), $pass, $fail, strlen( $out ) . 'b' );

// T07 — 4-byte emoji straddling limit
$out = _apply_fix_to_name( "Caesar Salad with greens \xf0\x9f\x8d\x85 extra" ); // 🍅 at byte 26
_t( 'T07 emoji straddle → valid UTF-8', mb_check_encoding( $out, 'UTF-8' ) && strlen( $out ) <= 26, $pass, $fail, strlen( $out ) . 'b' );

// T08 — 2-byte accented char (é)
$out = _apply_fix_to_name( "Brûléed Crème Catering 16\xc3\xa9" ); // é = 0xc3 0xa9
_t( 'T08 2-byte accent safe', mb_check_encoding( $out, 'UTF-8' ), $pass, $fail, strlen( $out ) . 'b' );

// T09 — empty name
$out = _apply_fix_to_name( '' );
_t( 'T09 empty name no crash', is_string( $out ), $pass, $fail, "\"$out\"" );

// T10 — all multibyte
$out = _apply_fix_to_name( str_repeat( "\xe2\x80\xb3", 10 ) );
_t( 'T10 all-multibyte clean cut', mb_check_encoding( $out, 'UTF-8' ) && strlen( $out ) <= 26, $pass, $fail, strlen( $out ) . 'b' );

echo "\n━━━━━━━━━━ NON-DESTRUCTION TESTS ━━━━━━━━━━\n";

// T11 — Other fields preserved
$order = new WC_Order();
$order->set_currency( 'USD' );
$it = new WC_Order_Item_Product();
$it->set_name( "Roasted Cauliflower - 16\xe2\x80\xb3 (20-30 ppl)" );
$it->set_quantity( 3 );
$it->set_subtotal( 99 );
$order->add_item( $it );
$payload = array(
    'merchant_reference' => 77777,
    'shipping_amount'    => 1234,
    'line_items'         => array(
        (object) array(
            'product_code'        => 'ABC999',
            'product_description' => substr( $it->get_name(), 0, 26 ),
            'unit_cost'           => 3300,
            'quantity'            => 3,
            'tax_amount'          => 275,
            'discount_amount'     => 50,
        ),
    ),
);
$fixed = apply_filters( 'wc_stripe_payment_request_level3_data', $payload, $order );
$li    = $fixed['line_items'][0];
$preserved = ( $fixed['merchant_reference'] === 77777
    && $fixed['shipping_amount'] === 1234
    && $li->product_code === 'ABC999'
    && $li->unit_cost === 3300
    && $li->quantity === 3
    && $li->tax_amount === 275
    && $li->discount_amount === 50 );
_t( 'T11 other fields preserved', $preserved, $pass, $fail );

// T12 — Idempotent
$once  = apply_filters( 'wc_stripe_payment_request_level3_data', $payload, $order );
$twice = apply_filters( 'wc_stripe_payment_request_level3_data', $once, $order );
_t( 'T12 idempotent', $once['line_items'][0]->product_description === $twice['line_items'][0]->product_description, $pass, $fail );

echo "\n━━━━━━━━━━ MALFORMED INPUT TESTS ━━━━━━━━━━\n";

// T13 — Empty line_items
$res = apply_filters( 'wc_stripe_payment_request_level3_data', array( 'line_items' => array() ), $order );
_t( 'T13 empty line_items', isset( $res['line_items'] ) && is_array( $res['line_items'] ), $pass, $fail );

// T14 — Missing line_items
$res = apply_filters( 'wc_stripe_payment_request_level3_data', array( 'merchant_reference' => 1 ), $order );
_t( 'T14 missing line_items key', $res['merchant_reference'] === 1, $pass, $fail );

// T15 — Malformed entries
$res = apply_filters( 'wc_stripe_payment_request_level3_data', array( 'line_items' => array( 'garbage', null, 42 ) ), $order );
_t( 'T15 malformed entries no crash', is_array( $res['line_items'] ), $pass, $fail );

// T16 — Null order
$res = apply_filters( 'wc_stripe_payment_request_level3_data', $payload, null );
_t( 'T16 null order no crash', is_array( $res ), $pass, $fail );

echo "\n━━━━━━━━━━ INTEGRATION — real Stripe builder ━━━━━━━━━━\n";

if ( function_exists( 'WC' ) && class_exists( 'WC_Stripe_Helper' ) ) {
    $order = new WC_Order();
    $order->set_currency( 'USD' );
    $items_data = array(
        array( "Saffron Rice - 16\xe2\x80\xb3 (20-30 ppl)", 1, 49.99, 4.12 ),
        array( 'Classic Chicken Kabob', 25, 199.75, 16.48 ),
        array( 'Filet Steak Kabob', 25, 224.75, 18.54 ),
        array( "Greek Salad - 16\xe2\x80\xb3 (20-30 ppl)", 1, 69.99, 5.77 ),
        array( "Lebanese Cucumber Salad - 16\xe2\x80\xb3 (20-30 ppl)", 1, 69.99, 5.77 ),
        array( 'Pita Bread', 1, 30.00, 2.48 ),
        array( "Roasted Cauliflower - 16\xe2\x80\xb3 (20-30 ppl)", 1, 79.99, 6.60 ),
        array( "Classic Hummus Tray (Catering) - 12\xe2\x80\xb3 (10-20 ppl)", 1, 45.99, 3.79 ),
    );
    foreach ( $items_data as $d ) {
        $it = new WC_Order_Item_Product();
        $it->set_name( $d[0] );
        $it->set_quantity( $d[1] );
        $it->set_subtotal( $d[2] );
        $it->set_total( $d[2] );
        $it->set_total_tax( $d[3] );
        $order->add_item( $it );
    }
    $order->set_shipping_total( 30.00 );
    $order->set_total( 864.00 );

    $stripe_gw = null;
    foreach ( WC()->payment_gateways()->payment_gateways() as $g ) {
        if ( method_exists( $g, 'get_level3_data_from_order' ) ) {
            $stripe_gw = $g;
            break;
        }
    }

    if ( $stripe_gw ) {
        $l3        = $stripe_gw->get_level3_data_from_order( $order );
        $all_valid = true;
        foreach ( $l3['line_items'] as $li ) {
            if ( ! mb_check_encoding( $li->product_description, 'UTF-8' ) ) {
                $all_valid = false;
            }
        }
        _t( 'T17 real builder → all UTF-8 valid', $all_valid, $pass, $fail, 'all ' . count( $l3['line_items'] ) . ' items clean' );

        $sum = 0;
        foreach ( $l3['line_items'] as $li ) {
            $sum += ( $li->unit_cost * $li->quantity ) + $li->tax_amount - $li->discount_amount;
        }
        $sum     += $l3['shipping_amount'];
        $expected = WC_Stripe_Helper::get_stripe_amount( $order->get_total(), 'USD' );
        _t( 'T18 sum math preserved', $sum === $expected, $pass, $fail, "$sum = $expected cents" );

        _t( 'T19 merchant_reference OK', $l3['merchant_reference'] === $order->get_id() || $l3['merchant_reference'] === 0, $pass, $fail );
        _t( 'T20 line_items count OK', count( $l3['line_items'] ) === 8, $pass, $fail, count( $l3['line_items'] ) . ' items' );

        // T21 — before/after proof
        $raw_invalid = 0;
        foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $oi ) {
            if ( ! mb_check_encoding( substr( $oi->get_name(), 0, 26 ), 'UTF-8' ) ) {
                $raw_invalid++;
            }
        }
        $fix_invalid = 0;
        foreach ( $l3['line_items'] as $li ) {
            if ( ! mb_check_encoding( $li->product_description, 'UTF-8' ) ) {
                $fix_invalid++;
            }
        }
        _t( 'T21 before/after proof', $raw_invalid > 0 && $fix_invalid === 0, $pass, $fail, "raw=$raw_invalid, fixed=$fix_invalid" );
        _t( 'T22 math reconciliation', $sum === 86400, $pass, $fail, "$sum cents (expected 86400)" );

        // T23 — performance
        $start = microtime( true );
        for ( $i = 0; $i < 1000; $i++ ) {
            apply_filters( 'wc_stripe_payment_request_level3_data', $l3, $order );
        }
        $elapsed_ms = ( ( microtime( true ) - $start ) / 1000 ) * 1000;
        _t( 'T23 performance < 1ms/call', $elapsed_ms < 1.0, $pass, $fail, sprintf( '%.4f ms/call', $elapsed_ms ) );
    } else {
        echo "⚠️  Stripe gateway not found — skipping T17-T23\n";
    }
} else {
    echo "⚠️  WooCommerce / Stripe plugin not loaded — skipping integration tests\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "RESULT: $pass passed, $fail failed\n";
if ( $fail === 0 ) {
    echo "✅ ALL GREEN\n";
} else {
    echo "❌ FAILURES DETECTED\n";
}
