<?php
/**
 * Plugin Name: Aladdin — Level3 Fix Watchdog
 * Description: Daily health check for the Stripe Level3 UTF-8 fix. Emails
 *              admin if the fix is missing, the filter is not registered,
 *              recent Stripe logs show Level3 errors again, or the Stripe
 *              Gateway plugin version changes unexpectedly.
 * Author:      Aladdin
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Schedule the daily watchdog. Runs once per 24 hours via WP-Cron.
register_activation_hook( __FILE__, 'aladdin_fix_watchdog_activate' );
add_action( 'plugins_loaded', 'aladdin_fix_watchdog_ensure_scheduled' );

function aladdin_fix_watchdog_activate() {
	if ( ! wp_next_scheduled( 'aladdin_fix_watchdog_check' ) ) {
		wp_schedule_event( time() + 60, 'daily', 'aladdin_fix_watchdog_check' );
	}
}

function aladdin_fix_watchdog_ensure_scheduled() {
	if ( ! wp_next_scheduled( 'aladdin_fix_watchdog_check' ) ) {
		wp_schedule_event( time() + 60, 'daily', 'aladdin_fix_watchdog_check' );
	}
}

add_action( 'aladdin_fix_watchdog_check', 'aladdin_fix_watchdog_run' );

/**
 * The watchdog run. Collects every issue into $issues; emails once if any.
 */
function aladdin_fix_watchdog_run() {
	$issues = array();

	// 1) mu-plugin file must exist.
	$fix_path = WPMU_PLUGIN_DIR . '/aladdin-stripe-level3-utf8-fix.php';
	if ( ! file_exists( $fix_path ) ) {
		$issues[] = "CRITICAL: Fix mu-plugin file is MISSING. Expected at: {$fix_path}. The fix is NOT active. Redeploy from https://github.com/Nas198222/stripe-level3-utf8-fix";
	}

	// 2) Filter must be registered.
	if ( ! has_filter( 'wc_stripe_payment_request_level3_data' ) ) {
		$issues[] = 'CRITICAL: Filter wc_stripe_payment_request_level3_data is NOT registered. Fix mu-plugin exists but is not loading. Check wp-content/debug.log for PHP errors.';
	}

	// 3) mbstring must be available.
	if ( ! function_exists( 'mb_strcut' ) ) {
		$issues[] = 'CRITICAL: PHP mbstring extension is MISSING. Fix cannot function. Contact hosting to enable mbstring.';
	}

	// 4) No "Level3 data sum incorrect" errors in the last 24h of Stripe logs.
	$log_dir  = WP_CONTENT_DIR . '/uploads/wc-logs';
	$cutoff   = time() - DAY_IN_SECONDS;
	$l3_count = 0;
	if ( is_dir( $log_dir ) ) {
		$logs = glob( $log_dir . '/woocommerce-gateway-stripe-*.log' ) ?: array();
		foreach ( $logs as $log ) {
			if ( filemtime( $log ) < $cutoff ) {
				continue;
			}
			$content = @file_get_contents( $log );
			if ( $content ) {
				$l3_count += substr_count( $content, 'Level3 data sum incorrect' );
			}
		}
	}
	if ( $l3_count > 0 ) {
		$issues[] = "WARNING: Found {$l3_count} 'Level3 data sum incorrect' entries in the last 24h. The fix may no longer be catching all cases (new edge case? upstream plugin changed?). Inspect wc-logs for details.";
	}

	// 5) Stripe plugin version — record baseline and alert on change.
	$stored_version = get_option( 'aladdin_fix_watchdog_stripe_version', '' );
	$current_version = aladdin_fix_watchdog_get_stripe_version();
	if ( $current_version ) {
		if ( $stored_version === '' ) {
			update_option( 'aladdin_fix_watchdog_stripe_version', $current_version );
		} elseif ( $stored_version !== $current_version ) {
			$issues[] = "INFO: WooCommerce Stripe Gateway updated from {$stored_version} to {$current_version}. Verify the upstream bug at abstract-wc-stripe-payment-gateway.php line 1517 still exists — if upstream fixed it, this mu-plugin can be retired. See: https://github.com/Nas198222/stripe-level3-utf8-fix/blob/main/docs/MONITORING.md";
			update_option( 'aladdin_fix_watchdog_stripe_version', $current_version );
		}
	}

	// 6) Level3 not globally disabled by a poison transient.
	if ( get_transient( 'wc_stripe_level3_not_allowed' ) ) {
		$issues[] = 'WARNING: Stripe set the wc_stripe_level3_not_allowed transient. Stripe is refusing Level3 for this account (possibly rate-limit or account-flag). Delete transient to retry: wp transient delete wc_stripe_level3_not_allowed';
	}

	// Persist the last-check timestamp + issue count for visibility.
	update_option(
		'aladdin_fix_watchdog_last_check',
		array(
			'time'         => time(),
			'issue_count'  => count( $issues ),
			'l3_log_count' => $l3_count,
			'stripe_ver'   => $current_version,
		)
	);

	// Email if any issues found.
	if ( ! empty( $issues ) ) {
		$to      = get_option( 'admin_email' );
		$subject = '[Aladdin] Stripe Level3 fix watchdog — ' . count( $issues ) . ' issue(s) detected';
		$body    = "The daily Level3 fix watchdog found the following issue(s) on " . home_url() . ":\n\n";
		foreach ( $issues as $i => $msg ) {
			$body .= ( $i + 1 ) . ') ' . $msg . "\n\n";
		}
		$body .= "----\nFix repo: https://github.com/Nas198222/stripe-level3-utf8-fix\n";
		$body .= "Rollback: ssh kinsta-aladdin \"rm /www/aladdinshoustoncom_274/public/wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php\"\n";
		$body .= "Last check: " . gmdate( 'Y-m-d H:i:s T' ) . "\n";
		wp_mail( $to, $subject, $body );
	}
}

/**
 * Read the WooCommerce Stripe Gateway plugin version from its main file.
 */
function aladdin_fix_watchdog_get_stripe_version() {
	$plugin_file = WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';
	if ( ! file_exists( $plugin_file ) ) {
		return '';
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( $plugin_file, false, false );
	return $data['Version'] ?? '';
}

// Clean up cron on deactivation (for completeness — mu-plugins don't really deactivate).
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'aladdin_fix_watchdog_check' );
} );
