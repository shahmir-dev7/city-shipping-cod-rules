# Security review: City Shipping & COD Rules 1.0.0

Manual review of every superglobal read, output, query, file operation and endpoint. Reviewer: the author; not independent.

## Superglobals
| Location | Input | Handling |
|---|---|---|
| `Admin::handle_save` | `$_POST` fields city, country, shipping_cost, free_over, cod, cod_fee | After `guard()`: `sanitize_text_field( wp_unslash() )`, then `Rules::save` (city trimmed and normalised, amounts parsed by `Normalizer::money` which rejects anything but a non-negative decimal, country must be two letters, `cod` must be yes/no/default). |
| `Admin::handle_delete` | `$_POST['id']` | `absint`, used with `$wpdb->delete` and `%d`. |
| `Admin::handle_import` | `$_FILES['csv']` | `is_uploaded_file`, 1 MB cap, parsed with `str_getcsv`, 5000-row cap; every row goes through `Rules::save`. Nothing is written to disk. |
| `Admin::render` | `$_GET['cityrules_msg']`, `$_GET['edit']` | `sanitize_key` and a fixed allowlist or `^imported-\d+-\d+$` cast to `int`; `absint`. Display only. |
| (none) | `$_SERVER`, `$_COOKIE` | Not used. |

## Customer input
The only customer-supplied value used is the destination city typed at checkout. It is reduced to lower-case letters and digits (`Normalizer::city_key`, max 100 characters) and used only as a prepared-query parameter. It is never printed.

## Endpoints and capabilities
No REST routes and no AJAX. Four `admin_post_*` actions (save, delete, import, export). Each calls `guard()`: `current_user_can( 'manage_woocommerce' )` (403 on failure), then `check_admin_referer`. Not registered for `nopriv`. The settings form posts to `options.php`; its required capability is raised from `manage_options` to `manage_woocommerce` by the `option_page_capability_cityrules` filter (tested) so shop managers can save. The fee setting has a sanitise callback.

## Cash on Delivery enforcement
The plugin removes `cod` from the list of available gateways for a blocked city. WooCommerce re-checks the chosen payment method against that list when an order is placed, so a customer cannot bypass the rule by posting `payment_method=cod`. The browser test confirms WooCommerce refuses such an order (HTTP 4xx) on WooCommerce 11.2.1.

## Output
Every dynamic value in the admin screen goes through `esc_html`, `esc_attr`, `esc_url` or is cast to `int`. A test renders a rule whose city is `<script>alert(1)</script>` and confirms it is escaped.

## Queries
Lookups, upserts and lists use `$wpdb->prepare` with `%i` for the table name. Empty optional amounts are inserted with `NULLIF(%s, "")`. Deletes use `$wpdb->delete` with formats. Uninstall uses `prepare` for the table checks and drops only this plugin's table and rows.

## CSV
Exports prefix cells that start with `=`, `+`, `-`, `@`, tab or CR with an apostrophe (`Csv::safe`); import removes that prefix again (`Csv::unsafe`).

## Files / network
None, apart from the uploaded CSV, which is read and never stored.

## Findings
1. **Fixed during build: empty optional amounts were stored as `0.00`.** Passing a PHP `null` to `$wpdb->prepare` with `%s` inserts an empty string, which MySQL stores as 0.00 in a decimal column. An empty "free shipping over" would have meant "free for any cart of 0 or more", i.e. free shipping everywhere. Caught by an integration test; fixed with `NULLIF` and covered by tests.
2. **Limitation: matching is on typed text.** A misspelling does not match, so the zone's default cost applies, or no shipping is offered if the default is empty. This can stop a customer from checking out until they correct the city. Documented; a city dropdown is on the roadmap.
3. **Limitation: accents are not folded** ("Zurich" vs "Zürich").
4. **Limitation: block-checkout COD fee** is verified only on WooCommerce 11.2.1 (the minimum is set to 11.0).
5. **Limit:** the admin list shows the first 2000 rules.