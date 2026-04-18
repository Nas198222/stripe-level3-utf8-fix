<?php
/**
 * Plugin Name: Aladdin — Stripe Level3 UTF-8 Fix
 * Description: Fixes upstream bug in WooCommerce Stripe Gateway where product_description
 *              is truncated with byte-based substr(), producing invalid UTF-8 when the
 *              cut lands inside a multibyte character like "″" (U+2033). Invalid UTF-8
 *              causes Stripe to reject Level3 data with invalid_request_error, which the
 *              plugin misleadingly logs as "Level3 data sum incorrect", and payment falls
 *              back to standard interchange fees (costing ~0.5–1% extra on B2B catering).
 *              See: wp-content/plugins/woocommerce-gateway-stripe/includes/abstracts/abstract-wc-stripe-payment-gateway.php line 1517
 * Author:      Aladdin
 * Version:     1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'wc_stripe_payment_request_level3_data',
	function ( $level3_data, $order ) {

		if ( empty( $level3_data['line_items'] ) || ! is_array( $level3_data['line_items'] ) ) {
			return $level3_data;
		}

		if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
			return $level3_data;
		}

		// Rebuild the ordered list of order items the Stripe plugin iterates.
		// Must match exactly: array_values( $order->get_items( [ 'line_item', 'fee' ] ) ).
		$order_items = array_values( $order->get_items( array( 'line_item', 'fee' ) ) );

		foreach ( $level3_data['line_items'] as $index => $line_item ) {
			if ( ! is_object( $line_item ) || ! isset( $line_item->product_description ) ) {
				continue;
			}

			// Prefer rebuilding from the source order item name — the value we got may
			// already be byte-truncated into an invalid UTF-8 sequence.
			$original_name = '';
			if ( isset( $order_items[ $index ] ) && is_object( $order_items[ $index ] ) && method_exists( $order_items[ $index ], 'get_name' ) ) {
				$original_name = (string) $order_items[ $index ]->get_name();
			}

			if ( '' === $original_name ) {
				// Fallback: use whatever the plugin gave us, but sanitize invalid UTF-8 if any.
				$original_name = (string) $line_item->product_description;
				$original_name = mb_convert_encoding( $original_name, 'UTF-8', 'UTF-8' );
			}

			// mb_strcut respects UTF-8 boundaries — stops at the last full character
			// within the 26-byte budget, never leaves half of a multibyte sequence.
			if ( function_exists( 'mb_strcut' ) ) {
				$safe = mb_strcut( $original_name, 0, 26, 'UTF-8' );
			} else {
				$safe = substr( $original_name, 0, 26 );
			}

			$level3_data['line_items'][ $index ]->product_description = $safe;
		}

		return $level3_data;
	},
	10,
	2
);
