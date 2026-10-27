# Testing: City Shipping & COD Rules 1.0.0

Runs on 2026-10-27, Windows host, Docker Desktop, wp-env, WordPress 7.1.3, WooCommerce 11.2.1.

| Check | Result |
|---|---|
| `php -l` | clean |
| PHPCS (WordPress-Extra, Docs, PHPCompatibilityWP `7.4-`) | 0 errors, 0 warnings (`tests/` excluded) |
| PHPStan level 6, with WooCommerce stubs | 0 errors (level 8 not attempted). One scoped ignore: the WooCommerce stubs type `WC()->session` as always set, but it can be null at runtime. |
| Unit, no WordPress (`phpunit.unit.xml`) | 10 tests, 52 assertions OK |
| WordPress + WooCommerce suite, PHP 7.4.33 | 36 tests, 170 assertions OK |
| Same suite with `CITYRULES_HPOS=1` | 36 tests, 170 assertions OK (this plugin stores no order data; the run proves it does not depend on the order-storage mode) |
| Same suite, PHP 8.3, with coverage | 36 tests, 170 assertions OK; **90.53% line coverage** on `includes/` (344/380) |
| Plugin Check on the release zip (PHP 8.3 site) | 0 errors, 0 warnings |
| Playwright, Chromium: rules added through the admin screen, CSV import and export, then real Store API carts and an order | 1 passed |
| Queries per front-end request, plugin off vs on (5 samples each) | `/shop/` 56 vs 56 (+0). `/sample-page/` and `/missing-xyz/` were lower with the plugin on, which is WooCommerce warm-up noise, not a saving. The plugin adds nothing to normal page views: lookups run only for shipping, payment-method and fee calculations. |

Release zip SHA-256: `5DBA78C1116D636020A56AA9D5CAE32D4B5F1785311F9E4DDF47E2977177F6F8`.

## What the browser test covers
- Two rules added through the form (Lahore: shipping 200, free over 3000, COD fee 50; Remote Village: shipping 400, COD not available), one more by CSV import (a row with no city is skipped), a store-wide COD fee of 25, and the CSV export.
- Lahore (typed in lower case): shipping 200; COD offered. After choosing COD on the block checkout the cart gains the **city** fee of 50 (not the store-wide 25) and totals 750. The placed order's stored total is exactly 750.00 and the admin order screen lists the fee and the shipping line.
- 7 items (3500) into Lahore: shipping 0, because the city's threshold is 3000.
- Karachi (imported, no city fee): shipping 350, and the store-wide fee of 25 applies.
- Remote Village: COD is not offered, **and placing a COD order for it anyway is refused by WooCommerce**.
- A city with no rule and no default cost: no shipping offered.
- The plugin is deactivated and deleted through the Plugins screen; the test confirms the plugin is gone, the table is dropped, no options remain and the shipping zone's method rows are removed. A positive control proves the clean-database query can see the data first.

## Problems found while testing (all fixed)
- **Empty optional amounts were stored as `0.00`**, which would have meant free shipping for every cart (see SECURITY_REVIEW.md, finding 1).
- A test-isolation problem: shipping zones persist between tests in the test database, so an old zone was matched first. Tests now clear zones before each run.
- Browser-test mistakes of mine: WooCommerce answers "add to cart" with HTTP 201, and the login step needed to wait on a slow site.

## Not verified (honest limits)
- **WooCommerce versions:** only 11.2.1. The minimum is set to 11.0 for that reason; the COD fee on the block checkout relies on WooCommerce recording the chosen payment method, which 11.2 does.
- **Classic (shortcode) checkout:** the fee and the COD gate use the same hooks, but only the block checkout's Store API was exercised in the browser.
- **Multisite:** not run.
- **PHP × WordPress matrix:** only PHP 7.4.33 and 8.3 on WordPress 7.1.3. WordPress latest-1 and PHP 8.4+ are not tested.
- **PHPStan level 8** not attempted.
- **Tax:** shipping and the COD fee were tested with taxes disabled; the fee is added as non-taxable.
- **Scale:** the admin list shows the first 2000 rules; lookups use a unique index but were not benchmarked with thousands of rows.
- Chromium only, one scenario.