# AGENTS.md — RouteMile for WooCommerce

> Context for AI coding agents (OpenCode, Codex, Cursor, Aider, Devin, Gemini CLI, Claude Code, etc.).
> Read this before touching the codebase. The repo is **public and open source** (GPL-3.0-or-later since v1.2.1); the user (MD Millat Hosen) is the sole maintainer.

---

## 0. TL;DR

- **Project:** RouteMile for WooCommerce — a delivery-management plugin for single-restaurant WooCommerce stores
- **Current version:** **v1.5.0** (released 2026-09-01 — see CHANGELOG.md). Highlights: customer /my-account dashboard overhaul (`ROUTEW_My_Account`, `my-account-dashboard.css`), delivery-rider PWA (manifest/SW via query-string endpoints, heartbeat auto-refresh, COD strips, bottom tabs), cash settlement workflow (`ROUTEW_Agent_Cash` ledger: agent requests → manager/admin accept/reject with audit trail), work-tracking table (`ROUTEW_Dashboard_Agents`), settings redesign for non-tech admins (conditional visibility via `data-routew-show-when` contract in `admin-settings.js`, masked keys, Google fields under Map Provider section), open-all-day + special-occasion override hours, admin-bar toggle acts on effective state, kitchen-note privacy split (riders see delivery instructions only; Kitchen Note column for managers), `post_status_exists()` → `get_post_status_object()` fix at 3 sites, receipt bill rebuild (`wc_price` line totals, full breakdown, comma-separated address). readme.txt with external-service disclosure + setup/API-key/security docs. Tests: **147/147** (PHP 8.5 CLI and live PHP 7.4.30 both verified). Mimosa deep scan 0 findings. Repo public, GPL-3.0-or-later. See CHANGELOG.md. Historical notes below.

- **In progress:** **Mile Zero design system** (unreleased, in working tree — see CHANGELOG "[Unreleased]"). All customer + agent UI rebuilt on a compiled, **scoped** Bootstrap 5.3 re-skin (`assets/css/routew-ui.css`, built from `tools/ui/scss/` via `node tools/ui/build.mjs`). Root fix: v1.5.0 loaded full unscoped Bootstrap on themed pages and broke layouts; every rule now lives under `.routew-ui`. Design tokens + bundled Plus Jakarta Sans (OFL) in `assets/fonts/`. Surface stylesheets: `my-account.css`, `my-account-dashboard.css`, `checkout.css`, `tracking.css`, `delivery-dashboard.css` (sources in `assets/css-src/*.scss`). Old `frontend.css` + `assets/vendor/bootstrap/` deleted. Tests rewritten UI1–UI12 → DS1–DS16 + PG1: **311/311** (PHP 8.5 CLI + live PHP 7.4.30). Security-audit hardening (2026-09): manual order-completion is now gated by `ROUTEW_Order_Lifecycle::can_complete_order()` on all four transition paths (manager admin-post/AJAX + rider admin-post/AJAX) — WC auto-marks `completed` as paid, so an unpaid order can no longer be forged to paid via the dashboard or rider PWA; paid/COD-collected/zero-total orders still pass, and the PG1 suite tripwires any FUTURE completed-capable transition function that forgets the gate. Round 13 landed: admin-configurable **brand color** (`includes/class-routew-brand-color.php`) — one admin-picked hex re-derives the full brand ramp (strong/deep/soft/line/canvas/canvas-2/tint/canvas-wash + `--rm-on-brand`) in pure PHP and prints as ONE inline custom-property rule on the shared `routew-ui` handle; root fix: the compiled stylesheets now CONSUME `var(--rm-brand*)` (~90 previously-literal sites across `_components.scss` + the 5 surface sheets, alpha tints → `color-mix`), so with no override saved the rendering is identical to before; WCAG AA guardrail flips solid-fill text to ink when the derived strong shade is too light for white (pale-yellow brands) and keeps white for dark ones (wired via `var(--rm-on-brand, #fff)`); PWA manifest `theme_color` + dashboard `<meta name=theme-color>` read the admin color; settings field rides the existing WC tab + `routew_sanitize_settings_extra` with `sanitize_hex_color` + 3-digit expansion + default-equals-no-override semantics; live preview swatches in admin (`admin-settings.js` `brandPreview()`). Round 12 landed: admin-renameable order stages (`includes/class-routew-stage-labels.php`) — admins rename the 5 delivery stages + pick colour (curated pill palette) + icon (fixed SVG palette) from WooCommerce → Settings → RouteMile, with a live preview pill per row; display-only (status slugs + `_routew_status_at_*` + order data untouched, no migration), and every surface reads the shared helper (WC status dropdown + order lists via `ROUTEW_Order_Statuses`, agent PWA card pills, customer tracking stepper labels + icons + hero pills, my-account pills, deliveries dashboard cells; terminal states keep the fixed red pill); saves go through the Settings API extension points with whitelisted keys/colours/icons + `sanitize_text_field` + empty→default fallback, `statuses` mapping never form-writable. Plus a manager **Accept Order** button on pending orders in the deliveries dashboard (pending → routew-in-kitchen) reusing the nonce-verified `routew_update_order_status` handler — completes the manager workflow (Order placed → accept → assign rider → rider picks up → on the way → cash if COD → delivered). Also: the agent PWA "To hand over" tile now updates with a pre-formatted `wc_price()` string (`unsettled_formatted` in `build_action_response()`) instead of a raw float, and the `cash_to_collect`/`holding` i18n strings are properly localized. Round 11 landed: agent PWA polish — Cash Collected orders no longer count toward the "Cash to collect" banner (server-side `build_dashboard_state` excludes them + signature tracks the collected/uncollected state); `build_action_response()` returns `cash` too so the JS in-place-updates the **"Cash to collect"** + **"You are holding ৳X"** banners + the **"To hand over"** stat tile from the AJAX response (no reload needed after Mark Picked Up / Cash Collected / Mark Delivered); heartbeat re-reads `data-routew-state` from the shell on every poll so the agent's own action no longer triggers a spurious hard reload 30s later (verified live: 32s wait after Mark Delivered → navigation count stays at 1); same-tab card refresh for COD cash confirm no longer drops the card from the DOM (the idempotency remove ran before `insertBefore($card)` and left the new card parented to the now-detached `$card` — fixed by prepending directly into the destination panel + skipping the animate-out for same-tab refreshes). Round 10: WordPress Plugin Check (PCP) compliance — 0 errors on the shipping zip (5 `_n()` translators comments, `esc_html()` on the cash-collected total, WC-domain `phpcs:ignore` annotations in the my-address override, `routew_`-prefixed template vars, read-only `$_GET` reads annotated, `.DS_Store` purge, `build.sh` → `build.mjs` Node build with byte-identical output, `tools/` distignored). Round 9: agent PWA native-app flow. Round 8.1: order-received page fixed for the BLOCKIFIED WC template (`render_block` scope injection per block — the classic thankyou hooks split the wrapper across sibling blocks; also a 30-vs-32 strncmp bug made the filter a no-op). Round 8: creation-status stamping via `woocommerce_new_order` (WC never fires the changed-hook for the initial status), 4-step sequential tracking timeline (assigned = still in the kitchen; rider card shows assignment time; current step shows "Started …"), COD cash-collection gate on the agent PWA (server-enforced on AJAX + admin_post paths; `_routew_cash_collected` meta + `date_paid`), order-received page scoped via `woocommerce_before_thankyou`/`woocommerce_thankyou` wrapper with a thank-you hero. Round 7: per-status transition timestamps, full 10-status tracking map, mandatory phone (option filter for Checkout block + `woocommerce_billing_fields` for classic + 7–15-digit server-side validation), view-order Re-order button + WC status `<mark>` pill styling. Phase 1 data-layer backport is next after this ships.
- **User profile:** Freelance web developer. The plugin is a **general open-source release for everyone** (GPL-3.0-or-later) — not tied to any specific restaurant or client. It is the **primary project**; everything else on the machine is secondary.
- **Repo location:** `~/Desktop/RouteMile-for-WooCommerce/` (moved here from `~/.minimax-agent/projects/repo-merge-analysis/fx/` on 2026-08-17 so non-Mavis tools can access it directly)
- **Remote:** `https://github.com/codermillat/routemile-woocommerce` (public since v1.2.1, GPL-3.0-or-later)
- **Test runner:** `php tests/RouteWTestRunner.php` → must report **311/311 pass** before any commit (includes Leaflet SHA-256 integrity, the DS1–DS16 design-system suite, and the PG1 payment-gate suite)

---

## 1. What the plugin does

A complete delivery-management layer for single-restaurant WooCommerce stores:

- **Map-based checkout** — Google Maps location picker on the WooCommerce checkout, distance-based delivery validation, dynamic fee calculation, ETA-aware shipping label
- **Custom order statuses** — `routew-in-kitchen` → `routew-assigned` → `routew-picked-up` → completed
- **Delivery agent role** — `delivery_boy` user role with its own mobile dashboard
- **Order tracking + reorder** — shortcodes for customers
- **Receipt printing** — thermal-printer-friendly templates
- **WooCommerce email integration** — 3 custom email classes (In Kitchen, Driver Assigned, Picked Up)

**Roadmap (8 phases, ~8 weeks):** see [`docs/ROADMAP.md`](./docs/ROADMAP.md).

| # | Phase | Status | Release |
|---|---|---|---|
| 0 | Refactor & hygiene (checkout split, gitignore, license, AI-bloat cleanup) | ✅ Done | **v1.2.0** |
| 1 | Data layer + migration (8 `routew_*` tables, models, RR/RDM import) | 📋 Next | v1.3.0 |
| 2 | Mobile agent PWA (manifest, service worker, offline) | 📋 | v1.4.0 |
| 3 | GPS tracking engine (REST, battery-aware 45s/135s) | 📋 | v1.5.0 |
| 4 | COD payments + cash reconciliation | 📋 | v1.6.0 |
| 5 | Multi-channel notifications — **email + browser push only at launch** (NO SMS) | 📋 | v1.7.0 |
| 6 | Analytics & BI — Chart.js, CSV export, cron reports | 📋 | v1.8.0 |
| 7 | Owner-controlled outlet model — Google Maps primary, **Leaflet fallback** when no API key | 📋 | v1.9.0 |
| 8 | Polish, tests, docs, CI matrix | 📋 | v2.0.0 |

---

## 2. Tech stack

| Layer | Version | Notes |
|---|---|---|
| PHP | 7.4 minimum (8.0 target in v2.0) | `Requires PHP: 7.4` |
| WordPress | 6.0+ | `Requires at least: 6.0` |
| WooCommerce | 7.0+ (tested 9.4) | `WC requires at least: 7.0` |
| HPOS | Custom order tables **compatible** + **opt-out of cart-checkout-blocks** | Declared via `FeaturesUtil::declare_compatibility` in main file |
| JavaScript | jQuery + vanilla ES6 | No build step — plain `.js` files in `assets/js/` |
| Maps | Google Maps API primary, Leaflet fallback (Phase 7) | `routew_google_maps_api_key` setting |
| Database | MySQL via `$wpdb` | No PDO wrapper. **Always use `$wpdb->prepare()`** |
| DI | None. Plain `new` + static factories. No container. | |
| AJAX security | Per-handler `wp_create_nonce()` + `current_user_can()`. **No central wrapper.** | |
| Cron | WordPress `wp_schedule_event` only. **No external schedulers.** | |
| Tests | `RouteWTestRunner.php` static checks. PHPUnit+WP test suite planned for Phase 8. | |

---

## 3. File structure (post-v1.2.0)

```
RouteMile-for-WooCommerce/
├── routemile-woocommerce.php   ← slim bootstrap, defines ROUTEW_VERSION + ROUTEW_PLUGIN_DIR/URL
├── uninstall.php                   ← cleanup hook
├── LICENSE.md                      ← GPL-3.0-or-later (relicensed from proprietary in v1.2.1)
├── CHANGELOG.md                    ← Keep a Changelog format
├── README.md                       ← user-facing (this is for AI, README is for humans)
├── AGENTS.md                       ← you are here
├── docs/
│   ├── ANALYSIS.md                 ← 3-repo comparison + status updates
│   ├── ROADMAP.md                  ← 8-phase backport plan (current source of truth)
│   └── archive/                    ← Nov 2025 docs from the old Local Sites install
├── includes/
│   ├── class-routew-core.php          ← bootstrap: requires, hook wiring, admin/frontend dispatch
│   ├── class-routew-checkout.php      ← ORCHESTRATOR: form render, field customisation, address pre-fill
│   ├── class-routew-checkout-maps.php ← frontend map assets + get_restaurant_location AJAX + debug_status AJAX
│   ├── class-routew-checkout-handler.php ← server: update_customer_location, validate_delivery_zone, save_*
│   ├── class-routew-dashboard.php     ← admin deliveries dashboard orchestrator (split in v1.2.9)
│   ├── class-routew-dashboard-render.php ← dashboard page rendering (v1.2.9)
│   ├── class-routew-dashboard-actions.php ← dashboard form-POST + AJAX handlers (v1.2.9)
│   ├── class-routew-settings.php      ← WooCommerce → Settings → RouteMile page
│   ├── class-routew-shortcodes.php    ← [routew_track_order], [routew_reorder]
│   ├── class-routew-order-admin.php   ← WC order meta boxes
│   ├── class-routew-shipping-method.php ← FX shipping method registration
│   ├── class-routew-delivery-boy-view.php ← mobile agent dashboard
│   ├── class-routew-roles.php         ← delivery_boy role
│   ├── class-routew-order-statuses.php ← registers routew-* statuses with WC
│   ├── class-routew-admin-bar.php     ← admin bar delivery toggle
│   ├── class-routew-reporting.php     ← delivery analytics (will grow in Phase 6)
│   ├── class-routew-notifications.php ← email dispatch (will grow into multi-channel in Phase 5)
│   ├── class-routew-privacy.php       ← WP/WC privacy export & erasure (added in v1.2.6)
│   ├── class-routew-blocks-checkout.php ← blocks-checkout fields + map + order persistence (v1.2.9)
│   ├── class-routew-store-hours.php     ← scheduled opening hours (v1.2.12)
│   ├── class-routew-pricing.php          ← fee tiers / free threshold / minimum order (v1.2.13)
│   ├── class-routew-checkout-address.php ← hide country/state/city/postcode + relabel address_1 (v1.3.0)
│   ├── class-routew-my-account.php       ← customer /my-account dashboard widgets (v1.4.0)
│   ├── class-routew-config.php        ← constants (ROUTEW_Config::DEFAULT_DELIVERY_RADIUS, etc.)
│   ├── api/
│   │   └── class-routew-rest-checkout-controller.php  ← REST pattern reference
│   ├── services/                              ← STATELESS services, one per file
│   │   ├── class-routew-mapping-service.php       ← Google Maps wrapper
│   │   ├── class-routew-rate-limiter.php          ← rate limiting
│   │   ├── class-routew-delivery-fee.php          ← cart fee + ETA label (NEW in v1.2.0)
│   │   └── class-routew-address-validator.php     ← address completeness heuristic (NEW in v1.2.0)
│   └── emails/
│       ├── class-routew-email-in-kitchen.php
│       ├── class-routew-email-assigned.php
│       └── class-routew-email-picked-up.php
├── templates/                      ← frontend templates (delivery dashboard, delivery-boy view, receipt)
├── assets/
│   ├── css/                        ← routew-ui.css (scoped Bootstrap build), my-account.css, my-account-dashboard.css, checkout.css, tracking.css, delivery-dashboard.css, receipt.css, admin-settings.css (compiled — sources in assets/css-src/ + tools/ui/scss/)
│   └── js/                         ← checkout.js, delivery-dashboard.js, admin.js, admin-dashboard.js
├── tests/
│   └── RouteWTestRunner.php           ← static analysis + security checks
└── skills/                         ← Claude skill content (developer reference, not plugin runtime)
```

---

## 4. Class map (what each class does)

| Class | Responsibility | Self-registers? |
|---|---|---|
| `ROUTEW_Core` | Bootstrap. `require`s all classes, wires admin/frontend dispatches, adds rewrite rules, enqueues admin assets. | ✓ |
| `ROUTEW_Checkout` | **Orchestrator.** Renders location picker + delivery fields on the WC checkout. Hides default billing/shipping fields. Pre-fills saved address for returning customers. | ✓ |
| `ROUTEW_Checkout_Maps` | **Frontend map assets.** `enqueue_scripts` (Google Maps with API-key guard + localized `restaurant_center`/`radius_km`/`saved_address` params), `add_async_defer_to_maps_script`. | ✓ |
| `ROUTEW_Checkout_Handler` | **Server-side logic.** `validate_delivery_zone` (coordinates-only, restaurant via `ROUTEW_Mapping_Service::get_restaurant_location()`), `save_customer_address` (auto-saves the delivery profile for logged-in users), `save_delivery_details_to_order` (HPOS-aware; store-base country default). | ✓ |
| `ROUTEW_Dashboard` | Admin order dashboard. Largest class at 593 LOC — **flagged for the same split treatment as the checkout split.** | ✓ |
| `ROUTEW_Settings` | Native tab in WooCommerce → Settings (manage_woocommerce). | ✓ |
| `ROUTEW_Shortcodes` | `[routew_track_order]`, `[routew_reorder]`, plus tracking page rewrite. | ✓ |
| `ROUTEW_Order_Admin` | Order meta boxes (delivery details, assignment, etc.). | ✓ |
| `ROUTEW_Shipping_Method` | Registers `routemile_delivery` shipping method with WC. | ✓ |
| `ROUTEW_Delivery_Boy_View` | Mobile agent dashboard page + template loader. | ✓ |
| `ROUTEW_Roles` | Adds `delivery_boy` role on activation. | ✓ |
| `ROUTEW_Order_Statuses` | Registers `routew-in-kitchen`, `routew-assigned`, `routew-picked-up` with WC. | ✓ |
| `ROUTEW_Admin_Bar` | Admin bar "Delivery" toggle. | ✓ |
| `ROUTEW_Reporting` | Delivery analytics queries (will grow into Phase 6 BI). | ✓ |
| `ROUTEW_Notifications` | Email dispatch facade (will grow into Phase 5 multi-channel). | ✓ |
| `ROUTEW_Privacy` | Personal-data export/erasure for WP privacy tools + WC order anonymization. | ✓ |
| `ROUTEW_Blocks_Checkout` | Blocks-checkout support: Additional Checkout Fields + map via the_content + Store API order persistence. | ✓ |
| `ROUTEW_Store_Hours` | Optional per-day opening schedule feeding `ROUTEW_Checkout::is_store_open()`. | ✓ |
| `ROUTEW_Pricing` | Admin-configurable fee tiers, free-delivery threshold, minimum order (settings extension + statics used by the rate/validation/REST paths). | ✓ |
| `ROUTEW_Checkout_Address` | Hides WooCommerce's country/state/city/postcode fields on both classic and block checkouts (`woocommerce_get_country_locale`), relabels `address_1` to the single combined "Flat / Floor / Block / Society / Tower" field, fills hidden fields from the store base on order persistence (`woocommerce_checkout_update_order_meta`, `woocommerce_store_api_checkout_update_order_from_request`). | ✓ |
| `ROUTEW_My_Account` | Customer `/my-account/` dashboard surface — hooks `woocommerce_account_dashboard` to render a welcome banner (with Reorder or Browse-menu CTA), a 4-card stats row (On the way / Delivered / Total / Saved addresses), and a two-column panel grid (Recent orders + Default delivery address). Enqueues `my-account-dashboard.css` only on the dashboard root (`is_wc_endpoint_url()` guard). | ✓ |
| `ROUTEW_Dashboard_Render` / `ROUTEW_Dashboard_Actions` | Dashboard page rendering / write handlers (v1.2.9 split). | ✓ |
| `ROUTEW_Config` | Constants only. No instance. | n/a |
| `ROUTEW_REST_Checkout_Controller` | Reference REST controller — follow this pattern for Phase 1+ REST endpoints. | n/a (registered by ROUTEW_Core) |
| `ROUTEW_Mapping_Service` | Google Maps wrapper (geocode, distance matrix). Static + instance methods. | n/a |
| `ROUTEW_Rate_Limiter` | Rate limiting by action key. Static. | n/a |
| `ROUTEW_Delivery_Fee` | `woocommerce_cart_calculate_fees` + `woocommerce_cart_shipping_method_full_label` hooks. | ✓ |
| `ROUTEW_Address_Validator` | Stateless `validate_address_completeness` (heuristic). | n/a (static only) |

**Self-registration pattern:** each class with hooks has `new ClassName();` at the bottom of its file. No central wiring beyond `ROUTEW_Core::require_once`'ing them.

---

## 5. Coding standards (MUST follow)

### PHP

1. **WordPress Coding Standards** for PHP, JS, CSS. Run `phpcs` if you have it.
2. **Every PHP file:** `if (!defined('ABSPATH')) { exit; }` as the first non-comment line.
3. **Official WordPress/WooCommerce hooks and APIs ONLY — never bypass the platforms.** This applies to the entire project and all future changes: checkout-page changes, fee/tax calculations, order/customer/session data, settings, emails, uninstall — everything goes through documented hooks (`woocommerce_checkout_fields`, `woocommerce_checkout_create_order`, `woocommerce_cart_calculate_fees`, `wc_get_orders`, Settings API, `WP_REST_Controller`, `WC_Customer`/`WC_Order` CRUD, `WC_Email`, WP HTTP API, `wp_safe_redirect`, etc.). No direct writes to WooCommerce/WordPress tables, no `$_SESSION`, no raw cURL, no shims around core flows. **When unsure of the official pattern, verify against current documentation first** (Context7: `/woocommerce/woocommerce` for WC, WordPress docs for WP) — do not guess or copy unverified patterns.
4. **Every AJAX handler:** verify nonce with `wp_verify_nonce()` AND check capabilities with `current_user_can()`. **No central wrapper.** Per-handler explicit.
5. **Sanitize ALL input** with the right primitive: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `sanitize_textarea_field()`, etc. **`wp_unslash()` before `sanitize_*()`** on `$_POST`/`$_GET`/`$_REQUEST`.
6. **Escape ALL output** with the right primitive: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`. Match the context.
7. **Strict comparisons only** (`===` / `!==`) — never `==` / `!=`. Critical for auth checks.
8. **Null-check `WC()->session` and `WC()->customer`** before any method call: `WC()->session ? WC()->session->get('key') : null`.
9. **All queries use `$wpdb->prepare()`** with `%s`, `%d`, `%f` placeholders. **No PDO wrapper.**
10. **HPOS-aware order access:** use `wc_get_order($id)` (never `get_post($id)` for orders) and `$order->get_meta()` / `$order->update_meta_data()` (never `get_post_meta()` / `update_post_meta()` for order meta).
11. **`wp_safe_redirect()` always has a fallback URL:** `wp_safe_redirect($referer ? $referer : admin_url())`.
12. **`get_edit_post_link()` returns null under HPOS.** Fallback: `admin_url('admin.php?page=wc-orders&action=edit&id=' . $id)`.
13. **All translatable strings via `__()` / `_e()`** with `'routemile-woocommerce'` text domain.
14. **No class over 500 LOC.** Use the same split pattern as the v1.2.0 checkout split: orchestrator keeps the public API, sibling files `require_once`'d at the top, services extracted to `includes/services/`.

### JavaScript

1. `.textContent` not `.innerHTML` for untrusted data.
2. Guard global params with `typeof varName !== 'undefined'` checks before use.
3. Null-check `response.data` before accessing `.message` / `.label`.
4. The checkout script (`assets/js/checkout.js`) reads `routew_checkout_params` localised by PHP — never hard-code URLs or nonces.

### CSS

1. **All plugin UI lives in the Mile Zero design system.** Framework styling comes from the compiled, **scoped** Bootstrap 5.3 re-skin (`assets/css/routew-ui.css`) — never enqueue raw Bootstrap. Surface-specific styling goes in `assets/css-src/*.scss` (compiled by `node tools/ui/build.mjs`, output committed to `assets/css/`).
2. **Inside surface SCSS files, nest rules under `.routew-ui {}`** so they match scoped-Bootstrap specificity. Never use `&--modifier &__element` inside the wrapper (Sass expands `&` to the full parent chain and duplicates the scope — write flat `.block--mod .block__el` selectors at wrapper level instead).
3. **Use the tokens** (`--rm-*` custom properties / `$rm-*` Sass vars in `tools/ui/scss/_tokens.scss`) — never hard-code brand colors, radii, or shadows.
4. Never style `html`, `body`, or other global selectors. The only exception is the account-nav/layout rules in `my-account.scss`, which target WC's own markup *outside* the scope via the `routew-my-account-styled` body class.
5. Mobile-first responsive design. Interactive targets ≥44px on the agent PWA, ≥24px elsewhere.
6. Guard all motion with `prefers-reduced-motion`.

### Distance Matrix data shape (Google Maps)

```php
$distance_data['distance']  // object with ->value (metres) and ->text
$distance_data['duration']  // object with ->value (seconds) and ->text
```

**Always check** `isset($distance_data['distance']) && is_object($distance_data['distance']) && isset($distance_data['distance']->value)` before accessing `->value`. Same shape for `duration`.

---

## 6. Security checklist (verify on every PR)

- [ ] Nonce verification on every form + AJAX endpoint
- [ ] Capability check (`current_user_can()`) before any state change
- [ ] Input sanitisation before processing (right primitive for the type)
- [ ] Output escaping before rendering (right primitive for the context)
- [ ] `WC()->session` null-checked before any method call
- [ ] `WC()->customer` null-checked before any method call
- [ ] Strict comparisons for all auth checks
- [ ] No `get_post()` / `get_post_meta()` for order data (use HPOS APIs)
- [ ] `wp_safe_redirect()` always has a fallback URL
- [ ] `get_edit_post_link()` fallback for HPOS contexts

---

## 7. Running tests

```bash
cd ~/Desktop/RouteMile-for-WooCommerce   # or wherever the project lives
php tests/RouteWTestRunner.php
```

Expected: `Passed: 278, Failed: 0`. The runner checks:

- File structure (all required files exist, including the 3 checkout split files)
- Bundled vendor integrity (SHA-256 of the bundled Leaflet JS/CSS matches the pinned hashes — v1.3.8)
- Code quality (no unlimited queries, nonce verification on all AJAX files)
- Plugin headers (WordPress + WooCommerce)
- Hooks & filters (registered correctly, custom statuses registered)
- Security patterns (ABSPATH check, nonce verification)

**Run the test runner after every change. It must report 278/278 before commit.**

---

## 8. MCP / external tools available

| Tool | Purpose | How to call from a CLI agent |
|---|---|---|
| **Context7** | Up-to-date library documentation (WooCommerce, WordPress, PHP) — replaces stale web search | `mcporter call context7.resolve-library-id query=... libraryName=...` and `mcporter call context7.query-docs context7CompatibleLibraryID=/owner/repo query=...` |
| **GitHub CLI** | Repo ops, releases, issue management | `gh ...` (authenticated as `codermillat`) |
| **WordPress skills** | `wordpress-pro` and `wordpress-advanced-architecture` reference content lives in `skills/` | Read files directly |
| **mcporter** | Manages all MCP server configs. `mcporter list`, `mcporter call`, `mcporter config add/remove` | Binary at `/Users/mdmillathosen/.local/bin/mcporter` |

**Context7 note for the next agent:** Context7 auth was fixed on 2026-08-17 (a fresh API key now lives in `~/.cursor/mcp.json`); use Context7 normally. If `Invalid API key` reappears, the key in that file needs replacing — verify candidates with a direct curl to `https://mcp.context7.com/mcp` before writing, and note that an MCP connection caches its key until the session restarts. Anonymous access (no Authorization header) also works at lower rate limits as a fallback. If Context7 is unavailable entirely, fall back to the WooCommerce/WordPress source code in `wp-content/plugins/woocommerce/` if available, or to the bundled `wordpress-pro` / `wordpress-advanced-architecture` skills in `skills/`.

---

## 9. Open questions (from ROADMAP §6)

These are non-blocking for the next coding tool — pick the default in brackets unless told otherwise.

| Phase | Question | Default |
|---|---|---|
| 5 | VAPID keys for Web Push — auto-generate on activation? | **Yes, with admin warning** |
| 5 | ~~SMS at launch~~ | ✅ No — email + browser only |
| 6 | Chart library | **Chart.js v4** (lightest, sufficient for the use cases) |
| 7 | ~~Single restaurant vs multi-location default~~ | ✅ Owner-controlled dashboard toggle; schema always outlet-model |
| 7 | ~~Map library~~ | ✅ Google Maps primary, Leaflet fallback (no API key) |
| 8 | Test framework — PHPUnit+WP test suite or Pest? | **PHPUnit+WP** (matches the existing `RouteWTestRunner` static style) |
| 8 | Bump min PHP to 8.0? | **Yes** in v2.0 |

---

## 10. Things to NOT do (intentional non-features)

These came up in the original RR/RDM backport analysis and were explicitly **rejected** as over-engineering for FX's scale:

- ❌ **No DI container.** Use plain `new` and static factories.
- ❌ **No PDO query wrapper.** `$wpdb->prepare()` is the right abstraction in WordPress.
- ❌ **No central AJAX-security wrapper.** Per-handler nonces are clearer at this scale.
- ❌ **No auto-GitHub-issue error reporter.** Privacy footgun. Use a `wp_die()`-to-log pattern instead.
- ❌ **No ML delivery-time predictions** in v1–v2. Add in v3.x post-launch.
- ❌ **No Site Health integration tests** — overlap with WP core 6.2+ Site Health.
- ❌ **No `index.php` "intentional content" files** in every directory (RDM's security-by-obscurity anti-pattern).
- ❌ **No SMS channel at launch.** Channel interface is designed so SMS can be added later without a refactor.
- ❌ **No `vendor/` directory.** Composer is allowed for new dependencies but each dep must be justified.

---

## 11. Recent changes (Phase 0 / v1.2.0 — 2026-08-17)

**Commit:** `140b19f` · **Tag:** `v1.2.0` · **Release:** [GitHub](https://github.com/codermillat/routemile-woocommerce/releases/tag/v1.2.0)

- Split `class-routew-checkout.php` (1,046 LOC) into 3 single-purpose files + 2 new services, all under 500 LOC
- Replaced 4-line `.gitignore` with comprehensive 65-line version
- Added `LICENSE.md` (originally proprietary with WP/WC carve-out; relicensed to GPL-3.0-or-later in v1.2.1 when the repo went public)
- Bumped version 1.1.0 → 1.2.0
- Updated `tests/RouteWTestRunner.php` to recognize the new files
- Removed 9 AI-tool config folders + 5 AI dev docs (all recoverable in `mavis-trash`); GitHub language tag will re-rank from Python to PHP on next index
- Created this `AGENTS.md`

**All 103 tests pass.** No runtime behaviour change — all hooks, AJAX endpoints, and class names preserved. `class-routew-checkout.php` keeps its filename and call site, so external code referencing it continues to work.

---

## 12. Coordination with Mavis (this assistant)

- Mavis (Mavis) is the primary coding agent the user has been working with
- Mavis led the 3-repo consolidation (Aug 2026) and Phase 0 (v1.2.0)
- Mavis's working notes live in `docs/ANALYSIS.md`, `docs/ROADMAP.md`, and `CHANGELOG.md`
- When the user says "I will use another coding for the nexts", they mean a different agent/tool will take the next phase (likely Phase 1: data layer + 8 new `routew_*` tables)
- The user prefers **decisive recommendations** over hedging. If a decision has a clear default in §9, use it. If a question isn't in §9, ask before building.

---

*End of AGENTS.md. Last updated 2026-08-17 for v1.2.0.*
