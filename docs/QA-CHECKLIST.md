# Production QA Checklist — RouteMile for WooCommerce

The last gate before going live. Everything in the repo is verified statically
(112-test suite, lint, security scans, docs compliance); this checklist verifies
the plugin **running on a real store**. Work top to bottom; every step has an
expected outcome.

---

## 1. Environment

Pick one:

- **Local by Flywheel / MAMP / XAMPP** — fastest path
- **The repo's Docker stack** — `./start-wordpress.sh` (requires Docker Desktop; see `DOCKER_SETUP.md`), then complete the WordPress install wizard and install WooCommerce from Plugins → Add New

Requirements: WordPress 6.0+, WooCommerce 7.0+ (blocks-checkout fields need WC 8.9+), PHP 7.4+.

Install the plugin: symlink or copy this folder into `wp-content/plugins/`, then activate.
**Expected:** activates with no errors/warnings in the admin and nothing in `debug.log`.

## 2. Store configuration (retires the "not configured" warning)

| # | Step | Expected |
|---|---|---|
| 2.1 | **WooCommerce → Settings → General**: set store country/base location | Country sticks; orders will default to it |
| 2.2 | **Google Cloud Console**: enable *Maps JavaScript API*, *Geocoding API*, *Distance Matrix API*; create an API key with billing enabled; restrict the key to your site's domain | Key works on the site only |
| 2.3 | **WooCommerce → Settings → RouteMile**: paste the API key; set *Restaurant Address* **and ideally Restaurant Coordinates* (e.g. from Google Maps right-click → the first two numbers, `lat, lng`); set radius (km), base fee, per-km fee, prep time | Settings save; the red **"delivery is not configured"** admin notice disappears |
| 2.4 | **WooCommerce → Settings → Shipping**: create a zone covering your area → add the **RouteMile Delivery** method | Method listed and enabled |
| 2.5 | *Pricing Rules* section: try **distance tiers** (e.g. 3 km → small fee, 0 → higher fee), a **free-delivery threshold**, and a **minimum order**; retest §3/§5 with each | Fee matches the tier; threshold order shows Free; below-minimum cart shows the notice and blocks checkout |

## 3. Checkout — classic shortcode (default `[woocommerce_checkout]`)

| # | Step | Expected |
|---|---|---|
| 3.1 | Add a product, go to checkout | Map renders with search box, *Use My Location* button; **green radius circle** visible around the restaurant; map centered on the restaurant (zoom shows the whole circle) |
| 3.2 | Drop the pin **inside** the circle | Green toast: fee · distance · ETA; delivery method with ETA label appears |
| 3.3 | Drop the pin **outside** the circle | Red "we do not deliver to this location"; no delivery method |
| 3.4 | Try checkout without touching the map | Validation error asking to select the location on the map |
| 3.5 | Pin inside, leave *House / Flat / Building No.* empty | Field-level validation error |
| 3.6 | Fill the exact address (+ optional landmark/instructions), place the order | Order completes; fee matches base + km × rate |

## 4. Order data + operations

| # | Step | Expected |
|---|---|---|
| 4.1 | Open the order in admin | Meta box shows delivery details, landmark, coordinates, distance; billing country = store country; address second line = exact address |
| 4.2 | **Deliveries** admin page | Order listed under Unassigned; order link works (HPOS-safe) |
| 4.3 | Create a `delivery_boy` user (Users → Add New, role *Delivery Boy*); assign the order | Status → *Assigned*; assignment email arrives |
| 4.4 | Log in as the delivery boy → `/delivery-dashboard/` | Sees the assigned order; can update status |
| 4.5 | **Print Receipt** from the dashboard | Thermal receipt opens for printing **and actually prints** (fixed nonce bug, v1.2.9) |
| 4.6 | Advance In Kitchen → Assigned → Picked Up → Completed | Status emails fire at each step |
| 4.7 | `[routew_track_order]` page | Customer sees live status |

## 5. Saved-address defaults (returning customer)

| # | Step | Expected |
|---|---|---|
| 5.1 | As the same logged-in customer, add another product and open checkout | Map opens **on the saved pin**; exact address + landmark + instructions pre-filled; fee shown with zero interaction |
| 5.2 | Move the pin somewhere else in-zone, edit the address, order | New values saved for next time |
| 5.3 | Place the second order | Order data identical in shape to the first |

## 6. Checkout — **block-based** (v1.2.9+)

| # | Step | Expected |
|---|---|---|
| 6.1 | Edit the checkout page → replace shortcode with the **Checkout block** | Page shows the map **above** the block; the three RouteMile fields appear in the block's address/order sections (WC 8.9+) |
| 6.2 | Pin inside the radius, fill the fields, place order | Order completes; order meta identical to a classic order (check 4.1) |
| 6.3 | Pin outside / no pin | Field-level error blocks the order |
| 6.4 | Returning customer on blocks checkout | Fields pre-filled from the same saved profile |
| 6.5 | Known limitation (OK by design) | After dropping the pin the shipping total may refresh on the next interaction rather than instantly — order-time enforcement is what matters |

## 7. Privacy + hygiene

| # | Step | Expected |
|---|---|---|
| 7.1 | **Tools → Export Personal Data** for the test customer | "RouteMile Delivery Profile" group with address/landmark/coordinates; order rows include RouteMile data |
| 7.2 | **Tools → Erase Personal Data** | Profile and order meta removed |
| 7.3 | Temporarily remove the API key | Red **"delivery is not configured"** notice appears for admins |

## 8. Sign-off

All rows green → the store is production-ready. File any failure at
<https://github.com/codermillat/RouteMile-for-WooCommerce/issues> with the
step number and what you saw.
