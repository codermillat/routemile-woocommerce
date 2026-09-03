=== RouteMile for WooCommerce ===
Contributors: codermillat
Tags: woocommerce, delivery, food delivery, restaurant, local delivery
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Map-based delivery management for single-restaurant WooCommerce stores: pin-drop checkout, distance-based fees, custom order statuses, rider app.

== Description ==

RouteMile turns a single-restaurant WooCommerce store into a full delivery operation. Customers drop a pin on a map at checkout; the store owner manages orders through a dedicated deliveries dashboard; riders work from an installable mobile web app.

= Checkout =

* Interactive map location picker (Google Maps with an API key, or a free OpenStreetMap + Leaflet mode with no key required)
* Distance-based validation — orders outside your delivery radius are blocked before payment
* Distance-based delivery fee: flat rate, per-km rate, or distance tiers, with an optional free-delivery threshold and minimum order amount
* Live fee update in the Order summary as the customer moves the pin
* Store opening hours: per-weekday schedule, "open all day" / "closed all day" toggles, and a special-occasion override
* Estimated arrival time shown to the customer (kitchen prep time + travel time)

= Store management =

* Deliveries dashboard listing unassigned, assigned, and out-for-delivery orders, including the customer's kitchen note
* Custom order statuses: In Kitchen → Assigned → Picked Up → Completed, each with its own WooCommerce email notification
* One-click assignment of delivery riders, with automatic reassignment handling
* "Accept Order" action to move new orders into the kitchen with one click
* Admin-bar toggle to open/close ordering instantly
* Rename the five order stages (labels, colours, icons) to match your restaurant's wording — shown in the admin, the rider app and customer tracking; order data itself is untouched
* Set a brand colour once and every plugin surface (checkout, tracking, My Account, rider app) recolours automatically, with WCAG-checked text contrast

= Delivery rider app =

* Installable PWA (Add to Home Screen) that looks and feels like a native app
* Live order list with New / In Progress / Delivered tabs and automatic refresh when new orders arrive or statuses change
* Per-order actions: call the customer, open the exact delivery location in maps, mark picked up / delivered
* Cash-on-delivery awareness: shows the amount to collect per order and a running "cash in hand" total
* Rider-initiated cash settlement requests that a manager or admin accepts or rejects, with a full audit ledger
* Riders see delivery instructions only — kitchen notes stay private to the store

= Customer features =

* Order tracking page showing live status and, once assigned, the rider's name and phone number
* Reorder shortcut and an upgraded My Account dashboard with order stats and saved delivery address
* Thermal-printer-friendly receipt template

= Privacy & external services =

This plugin contacts third-party services only when the map provider you choose requires them. The customer's chosen coordinates (latitude, longitude) and your store address are sent to whichever provider you select. The plugin stores no telemetry and contains no advertising or analytics tracking.

* **Google Maps** — JavaScript is loaded from `maps.googleapis.com` on the checkout page; geocoding and routing requests are sent server-side to `maps.googleapis.com`. Service: [Google Maps Platform](https://cloud.google.com/maps-platform). Terms: [Google Maps Platform Terms of Service](https://cloud.google.com/maps-platform/terms). Privacy: [Google Privacy Policy](https://policies.google.com/privacy).
* **OpenStreetMap tiles** — map tiles are loaded from `tile.openstreetmap.org`. Service: [OpenStreetMap](https://www.openstreetmap.org/). Terms / licence: [OpenStreetMap Copyright and License](https://www.openstreetmap.org/copyright). Privacy: [OpenStreetMap Foundation Privacy Policy](https://osmfoundation.org/wiki/Privacy_Policy).
* **Nominatim geocoding (OpenStreetMap mode only)** — coordinate and address lookups are sent to `nominatim.openstreetmap.org`. Subject to the OpenStreetMap Foundation's [Nominatim Usage Policy](https://operations.osmfoundation.org/policies/nominatim/) (1 request/second application-wide). Privacy: [Nominatim privacy notice](https://nominatim.org/release-notes/latest#privacy).
* **Project OSRM routing (OpenStreetMap mode only)** — driving-distance and travel-time queries are sent to `router.project-osrm.org`. Service: [Project OSRM](https://project-osrm.org/). Terms: [Project OSRM Terms of Service](https://project-osrm.org/about/). Privacy: the OSRM public router is operated by the OSRM community on a best-effort basis; please review the operator notice linked on the OSRM project page.
* **MapTiler** — map tiles and geocoding calls go to `api.maptiler.com`. Service: [MapTiler](https://www.maptiler.com/). Terms: [MapTiler Terms of Service](https://www.maptiler.com/terms/). Privacy: [MapTiler Privacy Policy](https://www.maptiler.com/privacy/).
* **Geoapify** — tiles, geocoding and routing calls go to `api.geoapify.com` and `maps.geoapify.com`. Service: [Geoapify](https://www.geoapify.com/). Terms: [Geoapify Terms of Use](https://www.geoapify.com/terms-of-use/). Privacy: [Geoapify Privacy Policy](https://www.geoapify.com/privacy-policy/).

Each provider's "Powered by ..." attribution string is shown on the customer-facing map only when you tick "Show provider credit" under WooCommerce → Settings → RouteMile → Map Provider. The default is OFF per the WordPress.org Plugin Directory Guideline 10; you should turn it ON whenever your selected provider's licence requires visible credit.

This plugin does not set its own cookies, embed tracking pixels, or otherwise contact the visitor's browser beyond what the chosen provider does directly.

== Installation ==

1. Install and activate WooCommerce first.
2. Upload the plugin files to `/wp-content/plugins/routemile-woocommerce`, or install through the WordPress plugins screen.
3. Go to WooCommerce → Settings → RouteMile and pick a map provider (see Configuration below for getting API keys).
4. Set your restaurant location on the map, delivery radius, and delivery fee settings.
5. Enable the RouteMile Delivery method in your shipping zone(s), or let the plugin add it automatically.
6. Create users with the "Delivery Boy" role — their mobile app lives at the Delivery Dashboard link on their profile menu.

== Configuration ==

= Map provider: which one? =

* **OpenStreetMap (default, no key)** — free, works instantly. Best for small/low-volume stores; address lookups are throttled by OSM's usage policy.
* **Google Maps** — best data quality and road distances; needs a Google Cloud account with billing enabled.
* **MapTiler** — free key, 100k tile loads + geocodes/month; no road routing.
* **Geoapify** — free key, 3,000 requests/day covering tiles, geocoding and road routing.

You can switch providers at any time — orders already placed are not affected.

= Getting a Google Maps key =

1. Go to [Google Cloud Console](https://console.cloud.google.com/) and create (or pick) a project.
2. Enable **Maps JavaScript API**, **Geocoding API**, and **Distance Matrix API**.
3. Create an API key under APIs & Services → Credentials.
4. Paste it into WooCommerce → Settings → RouteMile → Map Provider → "Google Maps JavaScript API Key".
5. **Recommended:** also paste a *second* key into the "Server-side API Key" field and restrict each one:
   * Browser key → restrict to your domain under Application restrictions → HTTP referrers (`https://*.yourdomain.com/*`).
   * Server key → restrict to your server's IP address. The plugin automatically uses this one for behind-the-scenes distance/geocoding calls.

= Getting a MapTiler or Geoapify key =

MapTiler: sign up at [cloud.maptiler.com](https://cloud.maptiler.com/account/keys/) and copy a key.
Geoapify: sign up at [myprojects.geoapify.com](https://myprojects.geoapify.com/) and create a project key.
Paste either into the "Provider API Key" field that appears when you select that provider. Restrict the key to your domain in the provider's dashboard if it offers referrer restrictions.

= Delivery fees =

Choose flat rate, per-kilometre, or distance tiers under Delivery Fee Settings — only the fields relevant to your choice are shown. Optional free-delivery threshold and minimum order amount are on the same screen.

= Opening hours =

Set per-weekday times, tick "Open all day" for 24-hour days, or use the special-occasion override to stay open despite a closed schedule. The admin-bar "Deliveries: Open/Closed" toggle is always the master switch.

== Security Recommendations ==

This plugin handles real orders and real money (including cash on delivery). A few settings matter beyond the plugin itself:

* **Use HTTPS.** Checkout coordinates, rider sessions, and admin logins must travel encrypted. Most hosts offer free Let's Encrypt certificates.
* **Restrict your map keys.** Browser-exposed keys (Google JS, MapTiler, Geoapify tiles) should be locked to your domain in the provider's dashboard; the Google server-side key should be locked to your server IP. Keys without restrictions can be abused if extracted from your page source.
* **Limit who sees what.** Managers/admins need `edit_shop_orders` or `manage_woocommerce`; riders only need the "Delivery Boy" role — they see only their own assigned orders and never other agents' data.
* **Cash settlement is trust-based by design.** The plugin records exactly how much cash each rider collected and what was handed over (with who approved what, when), but physical cash counts must still be reconciled by a human.
* **Back up before big changes.** Standard WordPress database backups cover all RouteMile data (orders stay in WooCommerce's own tables).

== Frequently Asked Questions ==

= Do I need a Google Maps API key? =

No. Pick the OpenStreetMap provider and the plugin uses bundled Leaflet plus free OSM tiles and Nominatim geocoding. Use Google Maps if you prefer its data quality and already have a key.

= How is the delivery fee calculated? =

From the straight-line (haversine) distance between your restaurant coordinates and the customer's dropped pin. You choose flat, per-km, or tiered pricing, and can set a minimum order amount and a free-delivery threshold.

= Does it work with HPOS (high-performance order storage)? =

Yes. RouteMile declares full HPOS compatibility and reads/writes order data only through WooCommerce CRUD APIs.

= Which payment methods work? =

All of them. RouteMile handles fulfilment, not payment. For cash on delivery it additionally tracks how much each rider has collected and pending hand-over.

= Are my API keys safe? =

Keys are stored in your own WordPress database, never sent anywhere except the map provider they belong to, and never printed in frontend JavaScript data (only in the map-provider URLs that require them). Restricting keys to your domain/server IP as described under Security Recommendations closes the remaining abuse vector.

= Can customers see the rider's contact details? =

Only after you assign a rider to their order, and only the rider's name and phone number, on the order-tracking page.

= Where do riders get the app? =

It is not a separate download. A rider logs into your site, opens the Delivery Dashboard page, and uses "Add to Home Screen" in their browser to install it as a PWA.

== Screenshots ==

1. Map location picker on the checkout with live delivery fee.
2. Deliveries dashboard for store managers.
3. Mobile delivery-rider app with cash-on-delivery totals.
4. RouteMile settings inside WooCommerce.

== Changelog ==

= 1.6.0 =
* Mile Zero design system: every customer and rider surface (checkout, order tracking, order-received, My Account, delivery-rider app) rebuilt on a fast, self-contained design system with bundled fonts — no more theme conflicts from generic Bootstrap styling.
* Order stage labels: rename the five delivery stages (label, colour, icon) from the settings screen; renames apply everywhere instantly and never touch stored order data.
* Brand colour: pick one colour and the whole plugin UI recolours, including the rider app's PWA theme; text contrast is checked automatically.
* Manager "Accept Order" action for newly placed orders; rider actions update the app in place with no page reloads; cash settlement banners update live.
* WordPress Plugin Check compliance: zero errors and warnings on the shipped zip.

= 1.5.0 =
* Plugin renamed to "RouteMile for WooCommerce" (plugin slug `routemile-woocommerce`) — distinct from the existing commercial "FoodXpress" food-ordering plugin per the WP.org review feedback.
* WP.org plugin-directory compliance: text domain unified to the plugin slug; inline script/style extracted to external files; `[order_number]` in the receipt title now escaped (defence against merchant-controlled sequential-order-number formats); explicit capability check added to all admin-side data-migration paths; menu moved under the WooCommerce top-level (was a top-level item at a high position); map-provider "Powered by ..." credit is now an opt-in checkbox; readme's privacy section links each provider's Terms of Service and Privacy Policy and discloses Project OSRM.
* One-time data migration copies every old `fxw_*` option, transient, post meta, user meta, and `wc-fxw-*` order status to the new `routew_*` keys on upgrade. Existing 1.4.x stores keep their settings, rider assignments, order statuses, and stored delivery addresses.

= 1.4.0 =
* Customer My Account dashboard overhaul.
* Delivery-rider PWA: installable app, live auto-refreshing order list, COD collection totals and settlement workflow with manager approval.
* Settings redesigned for non-technical admins: fields appear based on the selected map provider and fee mode; masked API-key input; clearer opening-hours controls ("open all day", special-occasion override).
* Kitchen notes now visible only to managers/admins; riders see delivery instructions only.
* Fixed: admin-bar open/close toggle, weekly-hours saving, receipt printing, unassign crash, and more — see CHANGELOG.md.

= 1.3.9 =
* Address deduplication on receipts and order screens; one-time migration normalises existing orders; free-delivery rates labelled clearly.

Earlier releases: see CHANGELOG.md in the repository.

== Upgrade Notice ==

= 1.6.0 =
New design system across all customer and rider pages, renameable order stages, and a custom brand colour. No settings migration needed — existing settings, riders and orders are untouched.

= 1.5.0 =
Plugin renamed (display name, slug, folder) and WP.org review-blockers resolved. Settings, rider assignments, and order statuses migrate automatically on update from 1.4.x; no manual steps required.
