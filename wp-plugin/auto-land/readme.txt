=== Auto Land Wp Funnels ===
Contributors: techoperation
Tags: wpfunnels, landing-page, automation, rest-api
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.0.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST endpoint that imports a WP Funnels JSON export sent from the Landing Page Auto tool, and links a WooCommerce product to the imported checkout step.

== Description ==

Adds two REST routes used by the external Landing Page Auto tool:

* `GET  /wp-json/landing-auto/v1/ping`            — health check, returns plugin version and whether WP Funnels is detected.
* `POST /wp-json/landing-auto/v1/import-funnel`   — receives a WP Funnels JSON export, creates the funnel + steps + Elementor data, and (optionally) links a WC product into the checkout step.

Authentication uses the standard WordPress Application Password mechanism (HTTP Basic Auth on the request). Only users with the `manage_options` capability can call the endpoints.

== Installation ==

1. Download `landing-auto-bridge.zip`.
2. WP Admin → Plugins → Add New → Upload Plugin → pick the ZIP → Install Now.
3. Activate.
4. (Optional) Open `https://yourstore.com/wp-json/landing-auto/v1/ping` while logged in as admin to verify it's working.

== Changelog ==

= 1.0.11 =
* LANDING step now gets the clean slug (e.g. `/swimmingfloat/`); checkout becomes `<slug>-checkout`. Lets you share the landing URL directly in ads.

= 1.0.10 =
* Remap step IDs in funnel meta after import (so imported funnels are visible in WP Funnels admin).
* Add `GET /funnels` and `DELETE /funnels/{id}` endpoints for diagnostics & cleanup.

= 1.0.2 =
* Adds `_wpfnl_offer_products` linking on the imported checkout step when a WC product ID is supplied.

= 1.0.1 =
* Initial release.
