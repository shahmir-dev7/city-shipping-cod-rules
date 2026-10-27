=== City Shipping & COD Rules ===
Contributors: shahmir-dev7
Tags: woocommerce, shipping, cash on delivery, cod, city
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 11.0
WC tested up to: 11.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Charge shipping by city, allow or block cash on delivery per city, and add a COD fee, for WooCommerce stores where couriers price by city.

== Description ==
WooCommerce shipping zones can match a country, a state or a postcode, but not a city. In countries where couriers price by city, store owners end up with hacks. This plugin adds a "City rate" shipping method and a table of cities.

* A "City rate" shipping method you add to any shipping zone
* Per-city shipping cost, with a default cost for cities you have not listed (or no shipping to them)
* Free shipping over a cart value, per city
* Cash on Delivery on or off per city, enforced by WooCommerce itself when the order is placed
* A COD fee: store-wide default, overridable per city (including 0)
* CSV import and export, and WP-CLI commands
* Works with the block checkout and the classic checkout; compatible with High-Performance Order Storage

Cities are matched on what the customer types, ignoring case, spaces and punctuation. Accents are not folded: "Zurich" and "Zürich" are different cities.

== Installation ==
Install WooCommerce, then upload and activate this plugin. Add the "City rate" method to a shipping zone (WooCommerce > Settings > Shipping), then open WooCommerce > City Rules.

== Frequently Asked Questions ==
= What if the customer spells the city differently? =
It will not match and the zone's "cost for cities not in the table" applies (or no shipping is offered if that is empty). Add the common spellings as extra rows.

= Does the COD fee work on the block checkout? =
It was tested on WooCommerce 11.2.1, where the block checkout records the chosen payment method on the server. Older WooCommerce versions are not tested.

== Changelog ==
= 1.0.0 =
* Initial release.