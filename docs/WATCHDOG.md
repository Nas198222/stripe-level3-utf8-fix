# Watchdog — Automated Health Check

`src/aladdin-fix-watchdog.php` is a companion must-use plugin that runs a
daily health check on the Level3 UTF-8 fix and emails the WordPress admin
if anything is wrong.

## What it checks (daily via WP-Cron)

1. **Fix mu-plugin exists** on disk at
   `wp-content/mu-plugins/aladdin-stripe-level3-utf8-fix.php`.
2. **Filter is registered** — `has_filter('wc_stripe_payment_request_level3_data')`.
3. **mbstring extension available** — `mb_strcut` must exist.
4. **Zero "Level3 data sum incorrect" entries** in the Stripe log files
   from the last 24 hours.
5. **Stripe Gateway plugin version has not changed** — records baseline on
   first run, emails on change so you can verify the upstream bug still
   exists (or the fix can be retired if Automattic patched it).
6. **`wc_stripe_level3_not_allowed` transient NOT set** — if Stripe set
   this flag, Level3 is globally disabled for the account.

## When it emails

Emails `admin_email` only when at least one issue is detected. A clean day
produces no email, no noise.

Email subject: `[Aladdin] Stripe Level3 fix watchdog — N issue(s) detected`
Email body lists every detected issue with context and remediation hints.

## Install

Drop `src/aladdin-fix-watchdog.php` into `wp-content/mu-plugins/`. WP-Cron
schedules the daily check on the first page load after install.

```bash
scp src/aladdin-fix-watchdog.php \
    your-alias:/path/to/site/wp-content/mu-plugins/aladdin-fix-watchdog.php
```

Verify installation:

```bash
ssh your-alias "cd /path/to/site && \
  wp plugin list --status=must-use --format=csv | grep watchdog && \
  wp cron event list --fields=hook,next_run_relative --format=csv | grep fix_watchdog"
```

Expected:
- `aladdin-fix-watchdog,must-use,...,1.0.0` in plugin list
- `aladdin_fix_watchdog_check` scheduled in cron list

## Run manually (no waiting for cron)

```bash
wp eval 'aladdin_fix_watchdog_run(); print_r(get_option("aladdin_fix_watchdog_last_check"));'
```

Expected output on a healthy install:

```text
Array
(
    [time] => 1776555460
    [issue_count] => 0
    [l3_log_count] => 0
    [stripe_ver] => 10.5.3
)
```

## Last-check visibility

The option `aladdin_fix_watchdog_last_check` always holds the last run's
timestamp, issue count, Level3 error count, and Stripe plugin version.
Useful for dashboards or a quick `wp option get` health check.

## Rollback

```bash
rm wp-content/mu-plugins/aladdin-fix-watchdog.php
# Optional: also clear the cron event
wp cron event delete aladdin_fix_watchdog_check
wp option delete aladdin_fix_watchdog_last_check
wp option delete aladdin_fix_watchdog_stripe_version
```

## Deployment record for aladdinshouston.com

| Environment | Deploy time | Verified |
|-------------|------------|---------|
| Staging | 2026-04-18 22:57 UTC | 0 issues, cron scheduled |
| Production | 2026-04-18 22:57 UTC | 0 issues, cron scheduled, stripe_ver=10.5.3 recorded |
