# RouteMile-for-WooCommerce — Feature Backport Roadmap

**Source plan:** [ANALYSIS.md](./ANALYSIS.md) (3-repo comparison)
**Goal:** Port the 17 unique features from archived `RestroReach` + `restaurant-delivery-manager` into the active `RouteMile-for-WooCommerce` repo, in the right order, following FX's existing architecture conventions.
**Target outcome:** FX becomes the single canonical repo carrying the full premium feature set, after which RDM and RR can be permanently deleted from GitHub.

> Working trees at `/Users/mdmillathosen/.minimax-agent/projects/repo-merge-analysis/{fx,rr,rdm}/` — read from RR/RDM, write into FX.

---

## 0. Current FX state (the destination)

> **Last updated:** 2026-08-17 (post v1.2.0 / Phase 0)

```
routemile-woocommerce.php                  (147 lines, slim bootstrap)
includes/
├── class-routew-core.php                          (322)   ← single core orchestrator
├── class-routew-checkout.php                      (233)   ← ✅ Phase 0: orchestrator only
├── class-routew-checkout-maps.php                 (299)   ← ✅ NEW in v1.2.0 — frontend map assets
├── class-routew-checkout-handler.php              (469)   ← ✅ NEW in v1.2.0 — server-side logic
├── class-routew-dashboard.php                     (593)   ← largest remaining, queued for later split
├── class-routew-settings.php                      (492)
├── class-routew-shortcodes.php                    (354)
├── class-routew-order-admin.php                   (340)
├── class-routew-shipping-method.php               (230)
├── class-routew-delivery-boy-view.php             (157)
├── class-routew-roles.php                         (108)
├── class-routew-order-statuses.php                (96)
├── class-routew-admin-bar.php                     (88)
├── class-routew-reporting.php                     (64)    ← will grow into analytics (Phase 6)
├── class-routew-notifications.php                 (41)    ← will grow into multi-channel (Phase 5)
├── class-routew-config.php                        (42)
├── api/
│   └── class-routew-rest-checkout-controller.php  ← REST pattern to follow
├── services/
│   ├── class-routew-mapping-service.php           ← mapping pattern to follow
│   ├── class-routew-rate-limiter.php              ← already ahead of RR
│   ├── class-routew-delivery-fee.php              ← ✅ NEW in v1.2.0 — cart fee + ETA label
│   └── class-routew-address-validator.php         ← ✅ NEW in v1.2.0 — stateless address heuristic
└── emails/
    └── class-routew-email-*.php                   ← email pattern to follow
```

**FX conventions to preserve:**

1. **Slim bootstrap** — main file only defines constants + lazy-loads classes
2. **One class per file**, prefixed `ROUTEW_`
3. **REST controllers** live in `includes/api/`
4. **Stateless services** live in `includes/services/` (ROUTEW_Delivery_Fee, ROUTEW_Address_Validator, ROUTEW_Mapping_Service, ROUTEW_Rate_Limiter)
5. **Email templates** live in `includes/emails/` (extends `WC_Email`)
6. **Templates** in `templates/` (frontend) and `templates/emails/`
7. **No class over 500 LOC** — every class currently under 500 except `ROUTEW_Dashboard` (593); the v1.2.0 checkout split is the template for the next refactor pass

**The `ROUTEW_Checkout` split (completed in Phase 0)** is the canonical pattern for future splits:
- Orchestrator keeps the original filename and public API (so call sites don't change)
- Orchestrator `require_once`s its sibling files at the top
- Each sibling class self-registers its own hooks in its own constructor (`new ClassName();` at the bottom of each file)
- Each sibling class stays under 500 LOC; if still too large, extract a service into `includes/services/`

---

## 1. Guiding principles for the backport

1. **Never let any new class exceed 500 LOC.** RR/RDM's god classes (`class-payments.php` 2,178, `class-analytics.php` 1,459, `class-database.php` 2,510) are the mistakes to avoid — port the **behaviors**, not the files.
2. **Custom DB tables use `routew_` prefix, not `rr_` or `rdm_`.** Migration must rename on install.
3. **Settings live in the existing `ROUTEW_Settings` page** (WooCommerce → Settings → RouteMile). Do not create separate settings pages.
4. **Every AJAX/REST handler uses `wp_create_nonce()` + `current_user_can()` checks.** No central wrapper class (RDM's `class-rdm-ajax-security.php` is over-engineered for FX's scale).
5. **All output is escaped, all input is sanitized, all queries use `$wpdb->prepare()`.** No PDO wrapper.
6. **WP-Cron, not external schedulers** — schedules land in the existing `routew_init_plugin()` activation flow.
7. **Each phase ends with a working, deployable plugin.** No big-bang merges.
8. **Tests added in the same PR as the feature** — no "we'll add tests later."

---

## 2. Architecture additions (the new shape)

### 2.1 New custom database tables (8)

All use prefix `{$wpdb->prefix}routew_`. Migration script registers them on activation, drops them on uninstall.

| Table | Purpose | Source (RR/RDM) |
|---|---|---|
| `routew_delivery_agents` | Agent profiles: user_id, phone, vehicle_type, status, rating, **outlet_id** (nullable = any outlet), created_at | `rr_delivery_agents` + `outlet_id` |
| `routew_order_assignments` | order_id → agent_id workflow with timestamps per state; `outlet_id` column | `rr_order_assignments` + `outlet_id` |
| `routew_location_tracking` | GPS trail: agent_id, lat, lng, accuracy, battery_level, recorded_at | `rr_location_tracking` |
| `routew_delivery_notes` | Order-level internal notes (not customer-visible) | `rr_delivery_notes` |
| `routew_outlets` | **One or more physical locations of the same brand.** Fields: name, address, lat, lng, phone, hours_json, polygon_json, base_fee_override, per_km_fee_override, is_active, sort_order, is_default. Always has at least one row. | renamed from `rr_delivery_areas`; redesigned for owner-controlled outlet model |
| `routew_payment_transactions` | COD audit trail: order_id, agent_id, expected, collected, change, variance | `rr_payment_transactions` |
| `routew_cash_reconciliation` | Daily agent cash close: agent_id, date, opening, closing, variance, status | `rr_cash_reconciliation` |
| `routew_notification_queue` | Multi-channel outbox (email + browser only at launch): channel, recipient, payload, status, retries | `rr_notification_queue` |

Indexes: all FK columns indexed, `(agent_id, recorded_at)` composite for location lookups, `(date, agent_id)` composite for reconciliation, `(is_active, sort_order)` for outlet listing, `(outlet_id, status)` on `routew_order_assignments` for the chain-mode assignment queries, **unique partial index `is_default = 1`** on `routew_outlets` (enforces single default at the DB level).

**The `routew_outlets` table always has at least one row** — even on a fresh install with single-outlet mode, the migration creates the "default outlet" from the current FX restaurant-address setting. The "Number of outlets" setting in the RouteMile dashboard only changes the **UI** (single panel vs full manager), never the schema.

### 2.2 New top-level files (target structure)

```
routemile-woocommerce.php                  (existing, no change)
uninstall.php                                   (extend to drop new tables)

includes/
├── database/                                   ← NEW
│   ├── class-routew-schema.php                    (table definitions, install/upgrade routines)
│   └── class-routew-migration.php                 (rr_/rdm_ → routew_ table rename + data copy)
│
├── models/                                     ← NEW
│   ├── class-routew-agent.php                     (CRUD on routew_delivery_agents)
│   ├── class-routew-assignment.php                (CRUD on routew_order_assignments, includes outlet_id)
│   ├── class-routew-location.php                  (CRUD on routew_location_tracking + battery logic)
│   ├── class-routew-outlet.php                    (CRUD on routew_outlets + polygon helpers — chain mode)
│   ├── class-routew-payment.php                   (CRUD on routew_payment_transactions)
│   ├── class-routew-reconciliation.php            (CRUD on routew_cash_reconciliation)
│   └── class-routew-notification-queue.php        (CRUD on routew_notification_queue + retry)
│
├── services/                                   ← extend
│   ├── class-routew-mapping-service.php           (existing)
│   ├── class-routew-rate-limiter.php              (existing)
│   ├── class-routew-cod-calculator.php            ← NEW (calculate_change, variance)
│   ├── class-routew-distance-cache.php            ← NEW (24h geocoding, 7d coord cache)
│   └── class-routew-report-scheduler.php          ← NEW (WP-Cron registration)
│
├── api/                                        ← extend
│   ├── class-routew-rest-checkout-controller.php  (existing)
│   ├── class-routew-rest-agent-controller.php     ← NEW (agent profile, location, status)
│   ├── class-routew-rest-order-controller.php     ← NEW (assign, status updates, notes)
│   ├── class-routew-rest-payment-controller.php   ← NEW (collect COD, reconcile)
│   └── class-routew-rest-analytics-controller.php ← NEW (chart data, exports)
│
├── notifications/                              ← NEW (split from routew-notifications.php)
│   ├── class-routew-notification-channels.php     (registry: email, browser, sms, whatsapp)
│   ├── class-routew-notification-email.php        (moved from includes/emails/, expanded)
│   ├── class-routew-notification-browser.php      (Web Push via service worker)
│   ├── class-routew-notification-sms.php          (gateway abstraction, no provider lock-in)
│   └── class-routew-notification-queue-processor.php (cron worker)
│
├── analytics/                                  ← NEW
│   ├── class-routew-analytics-revenue.php         (revenue trends, period comparison)
│   ├── class-routew-analytics-agents.php          (agent KPIs, ratings)
│   ├── class-routew-analytics-delivery.php        (delivery time, peak hours)
│   ├── class-routew-analytics-customers.php       (satisfaction, repeat rate)
│   └── class-routew-analytics-export.php          (CSV/JSON export)
│
├── pwa/                                        ← NEW
│   ├── class-routew-pwa-manifest.php              (programmatic manifest generation)
│   └── class-routew-pwa-service-worker.php        (SW registration + cache strategies)
│
├── admin/                                      ← NEW
│   ├── class-routew-admin-cash-reconciliation.php (the reconciliation page from RR)
│   ├── class-routew-admin-analytics-dashboard.php (charts view)
│   ├── class-routew-admin-agent-roster.php        (agent list + rating + assign, filtered by outlet)
│   ├── class-routew-admin-outlets.php             (multi-outlet chain editor — Google Maps + Leaflet fallback)
│   └── class-routew-admin-tools.php               (DB test, sample data, repair)
│
├── class-routew-core.php                          (extended: register new services, crons, REST routes)
├── class-routew-checkout.php                      ← REFACTOR (split from 1,046 LOC into 3 files)
├── class-routew-roles.php                         (extended with agent capabilities from RR)
└── class-routew-notifications.php                 (becomes a thin facade → notifications/*)

templates/
├── admin/                                      ← NEW
│   ├── cash-reconciliation.php
│   ├── analytics-dashboard.php
│   ├── agent-roster.php
│   ├── outlets.php                              (list)
│   └── outlet-edit.php                          (single-outlet editor with map)
├── mobile-agent/                               ← NEW (PWA UI)
│   ├── login.php
│   ├── dashboard.php
│   ├── order-detail.php
│   └── offline.php                             (replaces RR's templates/offline.html)
├── delivery-boy-view.php                       (existing)
├── delivery-dashboard-template.php             (existing)
├── receipt-template.php                        (existing)
└── emails/                                     (existing, expand with new triggers)

assets/
├── pwa/                                        ← NEW
│   ├── manifest.json
│   ├── service-worker.js
│   └── icons/ (16, 32, 72, 96, 128, 144, 152, 180, 192, 384, 512)
├── js/
│   ├── mobile-agent.js                         ← NEW
│   ├── analytics-dashboard.js                  ← NEW (chart.js)
│   ├── cash-reconciliation.js                  ← NEW
│   ├── outlets-editor.js                       ← NEW (Google Maps primary + Leaflet fallback)
│   ├── agent-roster.js                         ← NEW
│   └── (existing admin.js, delivery-dashboard.js, etc.)
└── css/
    ├── mobile-agent.css                        ← NEW
    ├── analytics-dashboard.css                 ← NEW
    ├── outlets-editor.css                      ← NEW
    ├── agent-roster.css                        ← NEW
    └── (existing)

tests/                                          ← port RR's suite, drop RDM-only
├── FXWTestRunner.php                           (existing)
├── test-database.php                           ← NEW
├── test-cod-calculator.php                     ← NEW
├── test-gps-battery.php                        ← NEW
├── test-notification-queue.php                 ← NEW
├── test-analytics-aggregates.php               ← NEW
├── test-cron-schedules.php                     ← NEW
└── run-all-tests.sh                            ← port from RR
```

### 2.3 New constants

```php
define('ROUTEW_DB_VERSION', '2.0.0');             // bumped on schema change
define('ROUTEW_GPS_INTERVAL_SEC', 45);             // matches RR
define('ROUTEW_GPS_BATTERY_LOW', 20);              // throttle when battery < 20%
define('ROUTEW_GEOFENCE_CACHE_GEOCODE_TTL', 86400);// 24h
define('ROUTEW_GEOFENCE_CACHE_COORDS_TTL', 604800);// 7d
define('ROUTEW_COD_DAILY_CRON_HOOK', 'routew_daily_cash_reconciliation');
define('ROUTEW_ANALYTICS_DAILY_HOOK', 'routew_daily_analytics_report');
define('ROUTEW_ANALYTICS_WEEKLY_HOOK', 'routew_weekly_analytics_report');
define('ROUTEW_ANALYTICS_MONTHLY_HOOK', 'routew_monthly_analytics_report');
define('ROUTEW_CLEANUP_LOCATION_HOOK', 'routew_cleanup_old_locations');

// Owner-controlled outlet model (Phase 7)
define('ROUTEW_OPERATION_MODE_SINGLE', 'single');  // default
define('ROUTEW_OPERATION_MODE_MULTI', 'multi');
// Stored in wp_options as 'routew_operation_mode' — read via get_option('routew_operation_mode', ROUTEW_OPERATION_MODE_SINGLE)
```

---

## 3. Phased roadmap (8 phases)

Each phase is independent, deployable, and ends with a tagged release. The phases are sequenced by **dependency order**, not by priority — foundation first, premium UX last.

> **Status legend:** ✅ complete · ⏳ in progress · 📋 planned

### Phase 0 — Refactor & hygiene (1 week) ✅ COMPLETE

**Status:** ✅ Shipped 2026-08-17 as **v1.2.0** (commit `140b19f`, tag `v1.2.0`).
**Why first:** stops the rot before adding more code. RR's giant classes are a warning, not a model.

**Tasks (delivered):**

- [x] Split `class-routew-checkout.php` (1,046 LOC) into 5 single-purpose files, all under 500 LOC:
  - `class-routew-checkout.php` (233 LOC) — orchestrator: form render, customise default fields, address pre-fill
  - `class-routew-checkout-maps.php` (299 LOC) — frontend map assets + `get_restaurant_location` AJAX + admin `debug_status` AJAX
  - `class-routew-checkout-handler.php` (469 LOC) — server: `update_customer_location` AJAX, `validate_delivery_zone`, `save_customer_address`, `save_delivery_details_to_order`
  - `services/class-routew-delivery-fee.php` (136 LOC) — `woocommerce_cart_calculate_fees` + `woocommerce_cart_shipping_method_full_label` hooks (cart fee + ETA label)
  - `services/class-routew-address-validator.php` (129 LOC) — stateless `validate_address_completeness` (was dead private code; preserved as a public service for Phase 7 multi-outlet editor)
- [x] Replaced the 4-line `.gitignore` with a comprehensive one covering: OS, editor/IDE, AI tool configs (`.agent/`, `.claude/`, `.cursorrules`, etc.), PHP/Composer/PHPStan/Psalm, logs, build artifacts, TestSprite/Playwright outputs, env/secrets
- [x] Removed 9 AI-tool config folders (`.agent/`, `.agents/`, `.claude/`, `.cline/`, `.codex/`, `.cursor/`, `.gemini/`, `.kiro/`, `.vscode/`) and 5 AI dev docs (`.cursorrules`, `CLAUDE.md`, `AGENTS.md`, `.github/copilot-instructions.md`, `.CLAUDE.md.swp`) — all moved to `mavis-trash`, recoverable
- [x] Added `LICENSE.md` — proprietary text matching the plugin header, with explicit carve-out for WordPress + WooCommerce (GPL-2.0+). *Relicensed to GPL-3.0-or-later in v1.2.1 when the repo went public.*
- [x] Added `CHANGELOG.md` with 1.2.0 entry (historical 1.0.0 / 1.0.1 / 1.1.0 entries were already present)
- [x] Bumped version 1.1.0 → **1.2.0** in plugin header and `ROUTEW_VERSION` constant
- [x] Updated `tests/FXWTestRunner.php` to recognize the 3 new files and skip the orchestrator (no AJAX endpoints) in the nonce-verification check
- [x] Created new `AGENTS.md` at the repo root so the next AI tool (Cursor, Codex, Claude Code, etc.) can pick up the project state without re-discovering it
- [x] Updated `docs/ROADMAP.md` and `docs/ANALYSIS.md` to reflect v1.2.0

**Deferred to a later pass (intentionally not in Phase 0):**

- `class-routew-notifications.php` rewrite into a multi-channel facade — moved to Phase 5 (where the channel implementation actually lands)
- `docs/MIGRATION.md` for users upgrading from RR/RDM — moved to Phase 1 (when the migration code lands)
- Split of `class-routew-dashboard.php` (593 LOC) — same treatment as the checkout split, but lower urgency; queued for a later refactor pass

**Acceptance criteria — verification:**

- ✅ All existing tests still pass — `php tests/FXWTestRunner.php` reports **101 passed, 0 failed**
- ✅ Plugin still passes `FXWTestRunner` with 0 errors
- ✅ Repo size dropped below 5 MB (was ~150 MB before the duplicate-repo cleanup + 11 MB of AI tool config removed in Phase 0)
- ✅ `git log -- '*.swp' '*.agent/' '*.claude/'` returns empty
- ⏳ `gh repo view --json languages` — actual repo has 0 `.py` files and 32 `.php` files; GitHub's language API cache is still showing the pre-Phase-0 mix, will re-rank to PHP on next index pass
- ✅ Every class under 500 LOC except `ROUTEW_Dashboard` (593) — flagged above for later

**Release:** `v1.2.0` — "hygiene + refactor"
- Commit: `140b19f`
- Tag: `v1.2.0`
- GitHub release: https://github.com/codermillat/RouteMile-for-WooCommerce/releases/tag/v1.2.0

---

### Phase 1 — Custom DB tables + migration (1 week)

**Why first:** every later feature needs a place to store data. RR's tables become FXW tables, with a one-time migration that renames `{$wpdb->prefix}rr_*` → `{$wpdb->prefix}routew_*` and copies data.

**Tasks:**

- [ ] Create `includes/database/class-routew-schema.php` with `install()`, `upgrade()`, `table_names()` (centralized so no class hard-codes table names)
- [ ] Create `includes/database/class-routew-migration.php` with:
  - `migrate_from_rr()` — detects existing `rr_*` tables, renames + copies data
  - `migrate_from_rdm()` — same for `rdm_*` tables
  - `migrate_from_meta()` — reads FX's existing WC order meta into the new tables (so current FX users aren't stranded)
  - `rollback()` — for failed migrations
- [ ] Wire schema install/upgrade into `routew_activate()` (main bootstrap)
- [ ] Add `routew_db_version` option to track schema version
- [ ] Extend `uninstall.php` to drop all `routew_*` tables (only if user opts in via settings checkbox)
- [ ] Add models in `includes/models/`:
  - `class-routew-agent.php` — `create()`, `find()`, `update()`, `delete()`, `get_by_user_id()`, `get_active()`
  - `class-routew-assignment.php` — `assign()`, `unassign()`, `get_by_order()`, `get_by_agent()`, `mark_picked_up()`, `mark_delivered()`
  - `class-routew-location.php` — `record()`, `get_latest_for_agent()`, `get_trail()`, `cleanup_older_than()`
- [ ] Add tests in `tests/test-database.php`

**Schema details** (from RR, with improvements):

```sql
CREATE TABLE {routew_delivery_agents} (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(32) NOT NULL,
  vehicle_type ENUM('bike','scooter','car','walk') DEFAULT 'bike',
  status ENUM('active','inactive','on_delivery','offline') DEFAULT 'offline',
  rating DECIMAL(3,2) DEFAULT 0.00,
  total_deliveries INT UNSIGNED DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY user_id (user_id),
  KEY status (status)
);
-- (similar for the other 7 tables, all keyed for the same query patterns RR used)
```

**Acceptance criteria:**

- Fresh install: 8 tables created in `{$wpdb->prefix}routew_*`
- Upgrade from RR: `{$wpdb->prefix}rr_*` tables renamed, all rows preserved, foreign-key relationships intact
- Upgrade from FX (current users, no prior tables): existing order meta scanned; new tables populated for in-flight orders
- `tests/test-database.php` passes — install/upgrade/rollback all green
- Manual test on staging with 100 sample rows: migrate + rollback in under 5 seconds

**Release:** `v1.3.0` — "data layer + migration"

---

### Phase 2 — Mobile Agent PWA (3 days)

**Why second:** PWA is the highest-visibility user-facing feature, and the manifest + service worker are easy wins once the data layer exists.

**Tasks:**

- [ ] Create `assets/pwa/manifest.json` (programmatic in `class-routew-pwa-manifest.php`, but ship a static fallback)
- [ ] Create `assets/pwa/service-worker.js` with:
  - Install: cache app shell (`/mobile-agent/`)
  - Fetch: network-first for API, cache-first for assets
  - Background sync: queue location updates when offline
- [ ] Create 11 PWA icons (use `generate-icons.sh` pattern from RR, but in `assets/pwa/icons/`)
- [ ] Add `class-routew-pwa-service-worker.php` to enqueue SW registration script + handle `?routew_pwa=1` query
- [ ] Create `templates/mobile-agent/login.php` (uses WC's standard login form, plus agent-role redirect)
- [ ] Create `templates/mobile-agent/dashboard.php` (assigned orders list, "I'm on my way" / "Picked up" / "Delivered" buttons)
- [ ] Create `templates/mobile-agent/order-detail.php` (customer info, address, map, photo upload widget)
- [ ] Create `templates/mobile-agent/offline.php` (replaces RR's `templates/offline.html`)
- [ ] Add `assets/js/mobile-agent.js` with:
  - Geolocation `watchPosition` (45s interval, 20s when battery < 20%)
  - Photo capture (file input → `canvas` → blob upload)
  - Background sync registration
  - Service worker message handling
- [ ] Add `assets/css/mobile-agent.css` (touch-optimized, 44px tap targets)
- [ ] Add rewrite rule for `/mobile-agent/` (alongside existing `/delivery-dashboard/`)
- [ ] Add `wp_ajax_routew_upload_delivery_photo` handler (uses `wp_handle_upload`)
- [ ] Add tests in `tests/test-pwa-manifest.php`

**Acceptance criteria:**

- Lighthouse PWA audit score ≥ 90
- Offline mode: dashboard loads from cache, location updates queue and flush on reconnect
- "Add to Home Screen" works on Chrome (Android) and Safari (iOS)
- Photo upload ≤ 2s for 1 MB image on 4G
- Existing `delivery-dashboard` (FX) still works — this is a parallel feature, not a replacement

**Release:** `v1.4.0` — "mobile agent PWA"

---

### Phase 3 — GPS tracking engine (1 week)

**Why third:** needs the PWA shell from Phase 2 to be useful, and the `routew_location_tracking` table from Phase 1.

**Tasks:**

- [ ] Create `includes/models/class-routew-location.php` (already stubbed in Phase 1 — flesh it out)
- [ ] Create `includes/services/class-routew-distance-cache.php` (24h geocoding / 7d coords cache using WordPress transients)
- [ ] Create `includes/api/class-routew-rest-agent-controller.php` with:
  - `POST /agent/location` — agent sends GPS update
  - `GET /agent/location/latest` — admin/customer gets latest agent position
  - `GET /agent/location/trail?order_id=X` — full trail for an order
- [ ] Add `class-routew-customer-tracking.php` to merge into existing `class-routew-shortcodes.php` (which already has `[routew_track_order]`)
- [ ] Add `assets/js/live-tracking-map.js` (extends the existing tracking shortcode to show agent's live position)
- [ ] Add `wp_cron` schedule: `routew_cleanup_old_locations` (daily) — deletes trail points older than 30 days
- [ ] Add tests in `tests/test-gps-battery.php` (mock battery events, assert throttle behavior)

**Battery-aware throttling** (lifted from RR, refactored):

```php
class ROUTEW_Location_Service {
    public function should_send_update(int $battery_level, int $seconds_since_last): bool {
        $interval = $battery_level < 20
            ? ROUTEW_GPS_INTERVAL_SEC * 3  // 135s when low battery
            : ROUTEW_GPS_INTERVAL_SEC;     // 45s normal
        return $seconds_since_last >= $interval;
    }
}
```

**Acceptance criteria:**

- 1,000 simulated GPS updates over 24h: location table stays under 1,000 rows (cleanup cron works)
- Battery < 20% → interval triples (verified in unit test)
- `/agent/location` REST endpoint: 50ms p95 latency
- Customer tracking page shows live agent position with < 5s lag
- Privacy: agent GPS only exposed to admin or the order's own customer

**Release:** `v1.5.0` — "GPS tracking"

---

### Phase 4 — COD payments + cash reconciliation (1 week)

**Why fourth:** the highest-value feature for the target market (South Asian restaurants running on cash). Independent of PWA/GPS, but builds on the data layer.

**Tasks:**

- [ ] Create `includes/models/class-routew-payment.php`
- [ ] Create `includes/models/class-routew-reconciliation.php`
- [ ] Create `includes/services/class-routew-cod-calculator.php`:
  - `calculate_change($expected, $collected): float`
  - `calculate_variance($expected, $collected): float` (negative = short, positive = over)
  - `validate_collection($order_id, $agent_id): ?WP_Error` (ensures agent owns order, status is `out_for_delivery`, etc.)
- [ ] Create `includes/api/class-routew-rest-payment-controller.php`:
  - `POST /payment/cod/collect` — agent confirms cash collection
  - `POST /payment/cod/reconcile` — daily close-out
  - `GET /payment/cod/report?agent_id=X&date=Y`
- [ ] Create `includes/admin/class-routew-admin-cash-reconciliation.php` (port the UI from RR's `class-payments.php::render_cash_reconciliation_page()`)
- [ ] Create `templates/admin/cash-reconciliation.php`
- [ ] Create `assets/js/cash-reconciliation.js`
- [ ] Create `assets/css/cash-reconciliation.css`
- [ ] Add `assets/sounds/` directory + 4 notification sound files (success, error, new-order, complete) — port RR's `generate-sounds.sh`
- [ ] Register `routew_daily_cash_reconciliation` WP-Cron at activation (daily 23:55)
- [ ] Add `wp_ajax_routew_*` handlers for the admin UI
- [ ] Add tests in `tests/test-cod-calculator.php` (exact change, edge cases: $0 collected, overpayment, fractional cents, multi-currency)

**Acceptance criteria:**

- Agent collects $50 on $42 order → system records $42 payment, $8 change, $0 variance
- Agent collects $40 on $42 order → system records $40 payment, $0 change, **-$2 variance** (short)
- Daily cron runs at 23:55 server time: every active agent gets a reconciliation row
- Admin reconciliation page shows variance report with red/yellow/green per agent
- Multi-currency safe (all amounts stored as DECIMAL(15,4) and formatted via `wc_price()`)
- `generate-sounds.sh` produces 4 valid .mp3 files (no broken links in admin UI)

**Release:** `v1.6.0` — "COD payments"

---

### Phase 5 — Multi-channel notifications (1 week)

**Why fifth:** needs the payment events from Phase 4 to be useful, and the queue model is independent of UI.

**Tasks:**

- [ ] Create `includes/models/class-routew-notification-queue.php` (CRUD + retry logic)
- [ ] Create `includes/notifications/class-routew-notification-channels.php` (channel registry: `['email', 'browser']` — **SMS intentionally dropped at launch**, see decisions below)
- [ ] Refactor existing 3 email classes (`class-routew-email-*.php`) into `includes/notifications/class-routew-notification-email.php` with a `WC_Email` base
- [ ] Create `includes/notifications/class-routew-notification-browser.php`:
  - Web Push via `class-routew-pwa-service-worker.php` from Phase 2
  - Uses VAPID keys (settings page field)
  - Subscription storage: `wp_user_meta` (`routew_push_subscription`)
- [ ] ~~`class-routew-notification-sms.php`~~ — **REMOVED**. SMS was in scope earlier; user decided no SMS at launch. The channel interface is still in place so SMS can be added later as a 3rd channel without changing the rest of the system (see §6 decisions).
- [ ] Create `includes/notifications/class-routew-notification-queue-processor.php` (cron worker that drains the queue every 5 minutes)
- [ ] Add settings UI: per-channel enable/disable (email + browser only) + per-event opt-in
- [ ] Add new email templates:
  - "Driver on the way" (when agent marks `out_for_delivery`)
  - "Cash reconciliation due" (daily 22:00 to active agents)
  - "Low GPS battery" (to admin, when agent's battery < 10% during delivery)
- [ ] Add tests in `tests/test-notification-queue.php` (mock email + browser channels, assert delivery + retry behavior)

**Channel adapter pattern** (new — RR didn't have this clean):

```php
interface ROUTEW_Notification_Channel {
    public function send(string $recipient, string $subject, string $body, array $payload = []): bool|WP_Error;
    public function is_enabled(): bool;
    public function validate_recipient(string $recipient): bool;
}

class ROUTEW_Notification_Email   implements ROUTEW_Notification_Channel { ... }
class ROUTEW_Notification_Browser implements ROUTEW_Notification_Channel { ... }
// ROUTEW_Notification_SMS: not in v1.7.0 — interface allows adding it later without touching the rest
```

**Acceptance criteria:**

- 100 queued notifications process in < 30s
- Failed delivery retries 3x with exponential backoff (1m, 5m, 15m)
- Email unsubscribe link works (uses WC's email preference system)
- Browser push works on Chrome + Firefox (VAPID-signed)
- Settings page shows "X notifications sent in last 7 days" per channel (email + browser only at launch)
- Channel interface stays backward-compatible so a future SMS adapter can be dropped in without changing call sites

**Release:** `v1.7.0` — "multi-channel notifications"

---

### Phase 6 — Analytics & BI (1 week)

**Why sixth:** reads from everything that came before; no write-side risk.

**Tasks:**

- [ ] Create `includes/analytics/class-routew-analytics-revenue.php` (port from RR's `get_revenue_analytics`)
- [ ] Create `includes/analytics/class-routew-analytics-agents.php` (port `get_agent_performance`, `get_agent_rating`)
- [ ] Create `includes/analytics/class-routew-analytics-delivery.php` (port `get_delivery_time_analytics`, `get_peak_hours_analytics`)
- [ ] Create `includes/analytics/class-routew-analytics-customers.php` (port `get_customer_satisfaction`, repeat-customer rate)
- [ ] Create `includes/analytics/class-routew-analytics-export.php` (CSV + JSON export)
- [ ] Create `includes/api/class-routew-rest-analytics-controller.php` (chart data endpoints)
- [ ] Create `includes/admin/class-routew-admin-analytics-dashboard.php` (admin page with Chart.js)
- [ ] Create `templates/admin/analytics-dashboard.php`
- [ ] Create `assets/js/analytics-dashboard.js` (Chart.js v4, time-series + bar + heatmap)
- [ ] Create `assets/css/analytics-dashboard.css`
- [ ] Create `includes/services/class-routew-report-scheduler.php`:
  - `routew_daily_analytics_report` — 8 AM daily
  - `routew_weekly_analytics_report` — Monday 8 AM
  - `routew_monthly_analytics_report` — 1st of month 8 AM
- [ ] Add automated report emails (HTML tables + CSV attachment)
- [ ] Add tests in `tests/test-analytics-aggregates.php`

**Acceptance criteria:**

- Dashboard loads in < 2s for stores with 10,000 orders
- CSV export completes for 50,000-row dataset in < 5s
- Daily/weekly/monthly reports fire on schedule (verify via `wp cron event list`)
- All 8 KPI widgets render with real data
- Date range filter works without page reload (AJAX)
- Chart data is JSON-cached for 15 min (transient)

**Release:** `v1.8.0` — "analytics + reports"

---

### Phase 7 — Owner-configurable outlet model + admin tools (1 week)

**Why seventh:** smaller wins that complete the picture. Adds the schema and admin UI to support chains with multiple physical outlets of the same brand. **Single-outlet is the default; the owner can switch to multi-outlet any time from the RouteMile settings page — no reinstall, no data loss.**

**Model clarification (per user 2026-08-16, refined 2026-08-16):**

The "outlet" concept is **one restaurant brand, 1 or more physical locations** (think: a restaurant chain with branches in different neighborhoods of the same city, OR a single-location restaurant). All outlets share:
- The same WooCommerce store, menu, and orders
- The same plugin settings (delivery fees, branding)
- The same agent pool

Each outlet has:
- Its own address (pickup origin)
- Its own phone number
- Its own opening hours / currently-open status
- Its own delivery radius (polygon or simple radius)
- Its own base + per-km delivery fee overrides
- Its own currently-assigned agents and orders

**Mode is owner-controlled, not install-time:**

- The schema always uses the outlet model — there's always at least one `routew_outlets` record
- A single-outlet setup is just N=1 — no special-casing in the shipping method, no migration when switching
- The RouteMile settings page exposes a toggle: **"Number of outlets"** = `1` | `Many`
  - When set to `1`: the outlet list UI hides behind a "Single outlet" panel; shipping method always picks the one outlet
  - When set to `Many`: full outlet manager appears (list, editor, polygon map, reordering)
- Switching between modes is instant: the underlying data is identical, only the UI changes
- Migration from pre-Phase-7 FX: existing restaurant address becomes the "default outlet" record automatically

**Outlet selection logic** (same regardless of mode): at checkout, given the customer's address, find the nearest open outlet whose delivery polygon/radius contains the address. The order is tagged with the chosen outlet ID; the chosen outlet's agents become the candidate assignees. With N=1, the selector trivially returns the only outlet.

**Map strategy (per user 2026-08-16):** **Google Maps primary** (matches current FX checkout), **Leaflet fallback** for the admin polygon editor when no Google Maps API key is configured.

**Tasks:**

- [ ] Create `includes/models/class-routew-outlet.php` (CRUD + polygon helpers)
  - Fields: `id, name, address, lat, lng, phone, hours_json, polygon_json, base_fee_override, per_km_fee_override, is_active, sort_order, is_default`
- [ ] Add `outlet_id` column to `routew_order_assignments` (so each assignment knows which outlet is fulfilling it)
- [ ] Add `outlet_id` column to `routew_delivery_agents` (so each agent can be tied to a specific outlet; NULL = "any outlet")
- [ ] On Phase-1 migration: auto-create one `routew_outlets` record from the current FX restaurant-address setting, mark it `is_default = 1`
- [ ] Create `includes/services/class-routew-outlet-selector.php`:
  - `find_outlet_for_address($address): ?ROUTEW_Outlet` (returns nearest open outlet whose radius/polygon contains the address)
  - `get_open_outlets(): array` (filters by `is_active` + `hours_json`)
  - `get_default_outlet(): ?ROUTEW_Outlet` (returns the `is_default = 1` record; used in single-outlet mode)
- [ ] Create `includes/admin/class-routew-admin-outlets.php`:
  - List view of all outlets with quick-edit, enable/disable, reorder
  - Single-outlet edit screen with map editor
  - "Add new outlet" wizard
  - Conditional rendering based on settings: `routew_operation_mode` ('single' | 'multi')
- [ ] Create `templates/admin/outlets.php` (list — only shown when `routew_operation_mode = 'multi'`) and `templates/admin/outlet-edit.php` (editor)
- [ ] Create `assets/js/outlets-editor.js`:
  - **Primary path:** Google Maps JS API + `google.maps.Polygon` + `google.maps.drawing.DrawingManager`
  - **Fallback path:** if `routew_google_maps_api_key` setting is empty, load Leaflet + Leaflet.draw from CDN as fallback
  - Both paths share the same `polygon_json` schema output
- [ ] Create `assets/css/outlets-editor.css`
- [ ] Add a settings field: **"Number of outlets"** (radio: `1` | `Many`) — stored as `routew_operation_mode` ('single' | 'multi'). Default: 'single'. Changing it is instant, no data migration.
- [ ] Refactor `ROUTEW_Shipping_Method` to always use `ROUTEW_Outlet_Selector` — when only 1 outlet exists, the selector returns it trivially; no separate code path
- [ ] Add per-outlet agent assignment UI (existing `class-routew-admin-agent-roster.php` gets an outlet filter; agents with `outlet_id = NULL` show in "Any outlet")
- [ ] Create `includes/admin/class-routew-admin-agent-roster.php` (agent list, ratings, assign-to-order UI, with outlet filter)
- [ ] Create `templates/admin/agent-roster.php`
- [ ] Create `assets/js/agent-roster.js`
- [ ] Port RR's `class-rdm-admin-tools.php` (good idea, not the bloat) → `class-routew-admin-tools.php`:
  - `run_database_test` (verify all 9 tables exist + row counts)
  - `generate_sample_data` (behind a `WP_DEBUG` check — dev only, creates 1 default outlet + 2 additional, 5 agents, 50 orders)
  - `reset_tables` (dev only)
  - `repair_database` (re-runs `dbDelta()` on broken tables)
- [ ] Add tests in `tests/test-outlet-selector.php` (polygon contains-point, nearest-outlet selection, closed-outlet exclusion, default-outlet fallback when N=1)
- [ ] Add tests in `tests/test-operation-mode-toggle.php` (toggling single→multi→single, data preserved, UI re-renders correctly)

**Acceptance criteria:**

- Fresh install: settings page shows "Number of outlets: 1" selected by default; one default outlet exists; checkout works as before
- Toggle to "Many": outlet list UI appears, owner can add a second outlet, save, and the new outlet becomes available for assignment
- Toggle back to "1" with multiple outlets: the second+ outlets stay in the DB but are hidden from the UI; checkout still works using the default outlet
- Existing single-outlet FX users: migration auto-creates the default outlet from the current restaurant address; no behavior change visible to customers
- Outlet editor works with Google Maps API key set (primary path)
- Outlet editor falls back to Leaflet gracefully when no API key (fallback path, banner shown)
- Polygon validity check rejects self-intersecting polygons in both paths
- Agent roster shows: name, phone, vehicle, rating, today's deliveries, current status, **outlet name** (or "Any outlet" if `outlet_id IS NULL`)
- Admin tools: `run_database_test` returns green report card for healthy installs
- `generate_sample_data` is hidden when `WP_DEBUG` is false

**Release:** `v1.9.0` — "owner-controlled outlet model + admin"

---

### Phase 8 — Polish, tests, docs (1 week)

**Why last:** wrap up, write the docs that make the previous 7 phases useful.

**Tasks:**

- [ ] Port RR's real test suite (the 7 PHP files + 4 JS files + `run-all-tests.sh`) — keep their behavior, rewrite for FXW naming
- [ ] Add integration test: full order flow from placement → in-kitchen → assigned → picked-up → delivered
- [ ] Add integration test: COD collection + reconciliation in one transaction
- [ ] Add CI workflow (`.github/workflows/ci.yml`): PHP 7.4 + 8.0 + 8.1, WordPress 6.0 + 6.4 + latest, WooCommerce 7.0 + 8.0 + latest
- [ ] Add codecov + coverage badge
- [ ] Write `docs/DEVELOPER.md`: hook reference for extension developers
- [ ] Write `docs/USER_GUIDE.md`: setup walkthrough + screenshots
- [ ] Update `README.md` with full feature list (no more "MVP only" disclaimer)
- [ ] Record a 5-min screencast for the mobile agent PWA (use `video-studio` skill or `obs-edit`)
- [ ] Add a "Migrate from RR" guide that maps every RR constant + table to its FX equivalent
- [ ] Add a "Migrate from RDM" guide (mostly same as RR, with extra notes on the duplicate classes)
- [ ] Final security audit: `class-routew-database-security.php` review (manual code review, not just scan)
- [ ] Final accessibility audit: run axe-core on every admin page

**Acceptance criteria:**

- CI: all tests green on PHP 7.4/8.0/8.1 × WP 6.0/6.4/latest × WC 7.0/8.0/latest
- Test coverage ≥ 70% on `includes/`
- All 5 admin pages pass axe-core with 0 critical issues
- README links to all 5 docs and the screencast
- Migration guide tested end-to-end on a fresh clone of RR

**Release:** `v2.0.0` — "premium parity"

---

## 4. File-by-file delivery matrix (rolled up)

| Phase | New PHP files | New JS files | New CSS files | New templates | New tables | Effort | v# |
|---|---|---|---|---|---|---|---|
| 0 — Refactor & hygiene | 3 (refactored) | 0 | 0 | 0 | 0 | 1 wk | 1.2.0 |
| 1 — Data layer + migration | 11 | 0 | 0 | 0 | 8 | 1 wk | 1.3.0 |
| 2 — Mobile agent PWA | 2 | 1 | 1 | 4 | 0 | 3 days | 1.4.0 |
| 3 — GPS tracking | 3 | 1 | 0 | 0 | 0 | 1 wk | 1.5.0 |
| 4 — COD payments | 5 | 1 | 1 | 1 | 0 | 1 wk | 1.6.0 |
| 5 — Multi-channel notifications (email + browser, no SMS) | 5 | 0 | 0 | 0 | 0 | 1 wk | 1.7.0 |
| 6 — Analytics & BI | 8 | 1 | 1 | 1 | 0 | 1 wk | 1.8.0 |
| 7 — Multi-outlet (chain) mode + admin | 6 | 3 | 2 | 3 | 0 (uses `routew_outlets` from Phase 1) | 1 wk | 1.9.0 |
| 8 — Polish & tests | 0 (tests only) | 0 (tests only) | 0 | 0 | 0 | 1 wk | 2.0.0 |
| **Total** | **~43 new** | **~7 new** | **~5 new** | **~9 new** | **8 new** | **~8 wks** | — |

---

## 5. Migration & backward compatibility

### 5.1 From current FX (v1.1.0) — no tables yet

```
1. Activate v1.3.0
2. routew_install() runs → creates 8 routew_* tables
3. routew_migrate_from_meta() scans WC order meta for existing orders
4. For each WC order with `_routew_status` meta: create routew_order_assignments row
5. Done — no data loss, no broken orders

(When v1.9.0 lands and runs routew_install's outlet step:)
6. routew_create_default_outlet() reads the current FX restaurant-address setting
7. Creates one routew_outlets record with is_default=1, is_active=1
8. All existing orders get outlet_id = that default outlet's ID
```

### 5.2 From archived RestroReach (v1.1.0) — has `rr_*` tables

```
1. Activate v1.3.0
2. routew_install() detects existing `{$wpdb->prefix}rr_*` tables
3. routew_migrate_from_rr() renames each `rr_*` → `routew_*` (preserves data)
4. Any saved settings (wp_options like `rdm_*`) are copied to `routew_*` keys
5. Old shortcodes [rdm_*] still work via backward-compat shim (logs deprecation warning)
6. User uninstalls RR plugin (RR is now empty/archived)
```

### 5.3 From archived restaurant-delivery-manager (v1.1.0) — has `rdm_*` tables

Same as RR, but data lives in `{$wpdb->prefix}rdm_*`. Migration script renames.

### 5.4 What we explicitly do NOT migrate

- `class-rdm-github-reporter.php` settings (privacy footgun, opt-in only)
- `class-rdm-performance-monitor.php` data (transient caches, not persisted)
- `class-rdm-performance-utilities.php` cached metrics (transient)
- `class-rdm-security-config.php` rate-limit presets (FX has its own rate limiter)
- The `index.php` (6008 bytes of "intentional" content) from RDM — security-by-obscurity anti-pattern
- `rdm-debug-setup.php` (dev-only file)
- `rdm-service-worker.js` (replaced by FX's own)

---

## 6. Open questions (need user decisions before each phase starts)

These are the calls that block execution. None are blocking Phase 0.

| Q | Phase | Status |
|---|---|---|
| ~~Single restaurant vs multi-location default?~~ | 7 | **✅ RESOLVED 2026-08-16: owner-configurable from the dashboard.** Single-outlet is the default; owner can switch to multi-outlet any time from the RouteMile settings page. Schema always uses the outlet model (single-outlet is just N=1). |
| ~~SMS gateway at launch~~ | 5 | **✅ RESOLVED 2026-08-16: no SMS at launch.** Email + browser only. Channel interface stays so SMS can be added later. |
| Which chart library (Chart.js / ApexCharts / Recharts via CDN)? | 6 | Open — default: Chart.js v4 (lightest) |
| VAPID keys for Web Push — auto-generate on activation? | 5 | Open — default: Yes, with admin warning |
| ~~Map library for delivery areas~~ | 7 | **✅ RESOLVED 2026-08-16: Google Maps API primary (matches current FX checkout). Leaflet as fallback for the admin polygon editor when no Google Maps API key is set.** |
| Test framework: PHPUnit + WP test suite, or Pest? | 8 | Open — default: PHPUnit (FX already has `FXWTestRunner` static checks; keep it simple) |
| Minimum supported PHP after all phases: stay at 7.4 or bump to 8.0? | 8 | Open — default: Bump to 8.0 (WordPress 6.4 already requires 7.4+, plugin authors pushing to 8.0+) |

---

## 7. Out-of-scope (intentionally not backporting)

These were RR/RDM features I considered and decided **not** to port:

- **ML delivery-time predictions** — RR's "future" feature. Add in v3.x, post-launch.
- **Auto-GitHub-issue error reporter** (RDM) — privacy/scope concern. Replace with a `wp_die()`-to-log-and-email-admin pattern instead.
- **Site Health integration tests** (RDM) — overlap with WP core 6.2+ Site Health, low value.
- **DI container** (RDM) — over-engineering for this plugin size. Use plain `new` + static factories.
- **PDO-style query wrapper** (RDM) — `$wpdb->prepare()` is the right answer; PDO is the wrong abstraction layer in WordPress.
- **Central AJAX security wrapper** (RDM) — adds indirection without proportional value at this scale. Per-handler nonces are clearer.

---

## 8. Endgame — when RDM and RR can be deleted

When **v2.0.0** ships and the migration guides are tested end-to-end:

```bash
gh repo delete codermillat/restaurant-delivery-manager --confirm
gh repo delete codermillat/RestroReach --confirm
```

The local clones at `~/Desktop/RouteMile for WooCommerce/` and `~/Local Sites/routemile-woocommerce/` can be cleaned up at the same time.

---

*End of roadmap. See [ANALYSIS.md](./ANALYSIS.md) for the feature source details.*
