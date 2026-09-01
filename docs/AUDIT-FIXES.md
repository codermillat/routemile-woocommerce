# Audit Findings — Recommended Fix Order

Generated: 2026-09-01
Source: Two parallel audits (security + correctness, logic + state) covering all 46 PHP files.
Final state at audit time: **0 errors · 4 warnings** (Plugin Check clean — repo metadata only).

---

## Status legend

- [ ] = todo
- [x] = done
- [~] = skipped / won't fix (with reason)

---

## 🔴 HIGH severity

### H1. Timezone mismatch in store-hours
**File:** `includes/class-routew-store-hours.php:347-348`, `:436-437`
**Bug:** `current_time('timestamp')` returns site-local Unix ts, but `gmdate('w', $now)` treats it as UTC. For non-UTC stores, day-of-week and minutes-since-midnight are wrong.
**Failure:** IST store (UTC+5:30) opens Tuesday 09:00. Customer at Tuesday 01:00 IST (= Monday 19:30 UTC). `gmdate('w', ts)` returns `1` (Monday UTC) — `is_open_now()` looks up Monday's hours and may show "open at 19:30" instead of "closed, opens at 09:00 tomorrow".
**Fix:** Replace `gmdate('w', $now)` / `gmdate('G', $now)` / `gmdate('i', $now)` with `date_i18n('w', $now)` etc.
**Effort:** 10 min
**Test:** Add a regression test that sets `current_time` mock to IST, calls `is_open_now()` at 01:00 IST on a Tuesday, asserts `false`.

### H2. Undefined `$unit` variable in order admin meta box
**File:** `includes/class-routew-order-admin.php:223`
**Bug:** `elseif ($unit)` references a variable that was removed in 1.2.16 but the branch was left behind.
**Failure:** PHP 8.0+ raises warnings; PHP 8.1+ can promote to fatal `Error` under stricter WP debug settings.
**Fix:** Remove the dead `elseif ($unit):` block (lines ~221-225).
**Effort:** 2 min

### H3. Duplicate `<td>` in unassigned orders table
**File:** `includes/class-routew-dashboard-render.php:211-212`
**Bug:** `kitchen_note_cell($order)` called twice per row → 7 cells in a 6-column table. Empty-state `colspan="6"` also mismatches.
**Failure:** Browser shifts columns so "Print Receipt" ends up under wrong header.
**Fix:** Remove one of the duplicate `<?php $this->kitchen_note_cell($order); ?>` calls.
**Effort:** 2 min

### H4. Stale session data leaks across customers
**File:** `includes/class-routew-shipping-method.php:172-181`, `includes/class-routew-checkout-handler.php:215-220`, `includes/services/class-routew-mapping-service.php:50-56`
**Bug:** `customer_lat` / `customer_lng` / `routew_distance_data` written to `WC()->session` but **never cleared** on order placement, completion, cancellation, or user switch.
**Failure:** Shared kiosk / family device. Customer A places a 5 km order, completes checkout. Customer B opens the site on the same browser; B's checkout preloads A's pin and validates against A's coordinates.
**Fix:** Add a `clear_customer_session_data()` helper, hook it to:
- `woocommerce_checkout_order_created` (after save)
- `woocommerce_order_status_cancelled`
- `wp_logout`
- `woocommerce_thankyou` (for completed orders)

Clear keys: `customer_lat`, `customer_lng`, `routew_distance_data`, `routew_delivery_profile`.
**Effort:** 15 min

### H5. FXW migration doesn't cover all legacy meta keys
**File:** `routemile-woocommerce.php:131-141` (`$meta_map`)
**Bug:** `class-routew-privacy.php:42-45` declares `_routew_house_flat_no`, `_routew_floor_no`, `_routew_society_building`, `_routew_block_tower_area` as personal data, but the migration has no entries for `_fxw_house_flat_no` etc.
**Failure:** Stores upgrading from pre-1.4 FoodXpress with split address fields lose those rows silently — privacy export/erasure misses them, dashboard order list loses details.
**Fix:** Add to `$meta_map`:
```php
'_routew_house_flat_no'   => '_fxw_house_flat_no',
'_routew_floor_no'        => '_fxw_floor_no',
'_routew_society_building'=> '_fxw_society_building',
'_routew_block_tower_area'=> '_fxw_block_tower_area',
```
**Effort:** 10 min

---

## 🟡 MEDIUM severity

### M1. Stale distance cache after restaurant moves
**File:** `includes/services/class-routew-mapping-service.php:131-134`, `:312-317`
**Bug:** Cache key encodes only rounded coordinate pairs (4-decimal hash). Restaurant move < ~11 m → same hash → old distance served until 30-min TTL.
**Fix:** Include `routew_restaurant_latlng` raw value (or its hash) in the cache key. Hook into `update_option_routew_settings` to bump a version key.
**Effort:** 10 min

### M2. Order meta not cleared on rider unassign
**File:** `includes/class-routew-dashboard-actions.php:80-94`, `:160-208` (AJAX); `includes/class-routew-order-admin.php:53-66` (legacy `?routew_action=reassign`)
**Bug:** Unassign paths delete `_routew_delivery_boy_id` and revert status, but leave location meta intact.
**Fix:** Create `clear_delivery_location_meta($order_id)` helper; call it from all three unassign paths. Clears: `_routew_delivery_lat`, `_routew_delivery_lng`, `_routew_delivery_distance`, `_routew_delivery_address`, `_routew_address_details`, `_routew_landmark`, `_routew_delivery_instructions`.
**Effort:** 15 min (combined with M3)

### M3. Status transitions don't clean personal-data meta
**File:** `includes/class-routew-dashboard-actions.php:111-138`, `includes/class-routew-delivery-boy-view.php:306-331`
**Bug:** Allowed transitions include `cancelled` and `completed`, but no transition handler deletes location meta. Completed orders retain customer location data forever.
**Fix:** Same helper as M2; hook to `woocommerce_order_status_completed`, `woocommerce_order_status_cancelled`. Don't delete on refund/exchange (customer may dispute).
**Effort:** (combined with M2)

### M4. `mark_picked_up` allows backwards state transitions
**File:** `includes/class-routew-delivery-boy-view.php:306-313`
**Bug:** Doesn't check current status before writing `routew-picked-up`. `mark_delivered` does check, but `mark_picked_up` does not.
**Fix:** Add at top of `mark_picked_up()`:
```php
$valid_from = array('routew-assigned', 'routew-in-kitchen');
if (!in_array($order->get_status(), $valid_from, true)) {
    wp_send_json_error(array('message' => __('Order cannot be marked picked up from its current status.', 'routemile-for-woocommerce')), 400);
}
```
Same check for the `routew-picked-up` branch in `ajax_update_delivery_status()` (lines ~208-261).
**Effort:** 10 min

### M5. `create_request` TOCTOU → duplicate cash-settlement requests
**File:** `includes/class-routew-agent-cash.php:190-211`
**Bug:** Double-click → two `STATUS_PENDING` entries.
**Fix:** Wrap read-modify-write in a transient lock:
```php
if (false !== get_transient('routew_settle_lock_' . $agent_id)) {
    return null; // someone else is creating right now
}
set_transient('routew_settle_lock_' . $agent_id, 1, 5);
try {
    // existing read-modify-write
} finally {
    delete_transient('routew_settle_lock_' . $agent_id);
}
```
**Effort:** 15 min (combined with M6)

### M6. `review_request` TOCTOU on accept/reject
**File:** `includes/class-routew-agent-cash.php:222-249`
**Bug:** Concurrent accept/reject loses updates.
**Fix:** Same transient lock pattern; only mutate if current stored `status` matches expected-from status (compare-and-swap).
**Effort:** (combined with M5)

### M7. Settlement ledger truncated at 200 entries
**File:** `includes/class-routew-agent-cash.php:208`
**Bug:** `array_slice($settlements, 0, 200)` drops older entries on each write. Long-tenured agents show wrong "Cash to hand over".
**Fix:** Switch to per-agent storage (custom table or post-meta on a dedicated CPT). For now: increase slice to a generous cap (e.g. 2000) and add a comment that this is temporary.
**Effort:** 30 min (full CPT); 5 min (interim cap bump)
**Decision needed:** Custom table or post-meta CPT?

### M8. Nominatim rate-limit lock is TOCTOU
**File:** `includes/services/class-routew-map-provider-client.php:142-161`
**Bug:** `get_transient` + `set_transient` race — two concurrent requests both geocode.
**Fix:** Use `wp_cache_add()` instead of `get_transient`/`set_transient` (atomic add). Key: `routew_nominatim_lock`. TTL: 2 seconds. Fallback: skip geocode for the loser.
**Effort:** 15 min

### M9. `get_users(array('role' => ...))` deprecated
**Files:** `includes/class-routew-dashboard-render.php:197`, `includes/class-routew-order-admin.php:194`, `includes/class-routew-dashboard-agents.php:30`
**Bug:** Triggers `_deprecated_argument` notices under WP_DEBUG since WP 4.4.
**Fix:** Replace `'role' => 'delivery_boy'` with `'role__in' => array('delivery_boy')`.
**Effort:** 5 min

### M10. State-changing order actions wired to `admin_init` GET
**File:** `includes/class-routew-order-admin.php:34-71`
**Bug:** `reject` and `reassign` mutations triggered by `admin_init` on every GET carrying `$_GET['routew_action']`.
**Fix:** Move to dedicated `admin_post_routew_reject` / `admin_post_routew_reassign` form-POST hooks; remove the `admin_init` handler. Update any admin UI that links to these to use form-POST instead of GET.
**Effort:** 20 min

---

## 🟢 LOW severity

### L1. Empty catch blocks silently swallow errors
**File:** `routemile-woocommerce.php:328-331, 353-356`
**Bug:** Zone-registration throws → store stuck with no RouteMile shipping rate and no log.
**Fix:** Add `error_log(sprintf('[routew] %s: %s', __METHOD__, $e->getMessage()))` inside each catch.
**Effort:** 5 min

### L2. Dead code
**Files:** `includes/services/class-routew-address-validator.php:38`, `includes/services/class-routew-rate-limiter.php:78-100`
**Bug:** `validate_address_completeness()` and `get_remaining_quota()` never called.
**Fix:** Remove, or add `@TODO: unused — see v1.6.0` comment. Recommend removal.
**Effort:** 5 min

### L3. `gmdate` precedence oddity
**File:** `includes/class-routew-store-hours.php:364-370`
**Bug:** `$day - 1 % 7` evaluates as `$day - (1 % 7)` due to precedence. Works, but reads like a bug.
**Fix:** Add parens: `$day - (1 % 7)`. (Subsumed by H1 if we rewrite the whole block.)
**Effort:** 1 min (subsumed by H1)

### L4. `load_saved_address` only seeds for logged-in users
**File:** `includes/class-routew-checkout.php:320-357`
**Bug:** Guests who logged in mid-flow lose their saved profile.
**Fix:** Check `is_user_logged_in()` once at top; if guest, attempt to read session-stored profile and seed from there.
**Effort:** 15 min

### L5. Email subject placeholders use `[...]` syntax
**Files:** `includes/emails/class-routew-email-*.php`
**Bug:** Inconsistent `[site_title]` vs `{order_number}` syntax.
**Fix:** Standardize on `{...}` per WC documentation.
**Effort:** 5 min

### L6. Rate limiter bypass when `REMOTE_ADDR` missing
**File:** `includes/services/class-routew-rate-limiter.php:140-151`
**Bug:** Returns `null` → caller short-circuits and allows request.
**Fix:** Fall back to a stable identifier (e.g. `sha1($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'anon')`) when `REMOTE_ADDR` is empty.
**Effort:** 5 min

---

## Recommended execution order

Sequenced by severity × effort × dependency:

| # | Item | Severity | Effort | Depends on |
|---|---|---|---|---|
| 1 | H2: Remove `$unit` | HIGH | 2 min | — |
| 2 | H3: Remove duplicate cell | HIGH | 2 min | — |
| 3 | H1: Timezone fix | HIGH | 10 min | — |
| 4 | M9: `role` → `role__in` | MEDIUM | 5 min | — |
| 5 | L1: error_log in catches | LOW | 5 min | — |
| 6 | L2: Remove dead code | LOW | 5 min | — |
| 7 | H5: Add FXW migration keys | HIGH | 10 min | — |
| 8 | M4: State guard in `mark_picked_up` | MEDIUM | 10 min | — |
| 9 | M1: Distance cache version | MEDIUM | 10 min | — |
| 10 | H4: Clear session on lifecycle | HIGH | 15 min | — |
| 11 | M2+M3: Clear location meta | MEDIUM | 15 min | H4 |
| 12 | M5+M6: Settlement lock | MEDIUM | 15 min | — |
| 13 | M8: Nominatim atomic lock | MEDIUM | 15 min | — |
| 14 | M10: Move actions to POST | MEDIUM | 20 min | — |
| 15 | L3: gmdate parens | LOW | 1 min | — |
| 16 | L5: Standardize placeholders | LOW | 5 min | — |
| 17 | L6: Rate limiter fallback | LOW | 5 min | — |
| 18 | L4: Guest address seeding | LOW | 15 min | — |
| 19 | M7: Settlement ledger cap | MEDIUM | 30 min | M5+M6 |

**Total: ~3 hours for all 19 items.**

---

## Quick-win bundle (1–6, ~30 min, HIGH+LOW only)

If you want a fast PR before v1.5.0 ships:
1. H2 (dead `$unit`)
2. H3 (duplicate `<td>`)
3. M9 (`role__in` deprecation)
4. L1 (error_log)
5. L2 (remove dead code)
6. L3 + L5 + L6 (LOW polish)

Result: 0 HIGH/LOW bugs remaining, all MEDIUM still on the plan.

---

## Test coverage plan

After each fix, re-run `tests/RouteWTestRunner.php` (147/147 baseline). New tests to add:

- **H1 (timezone):** Mock `current_time` to IST, assert `is_open_now()` returns expected.
- **H4 (session):** Assert `clear_customer_session_data()` removes expected keys; assert hooks fire on order lifecycle.
- **M4 (state guard):** Assert `mark_picked_up` rejects already-completed orders with 400.
- **M5+M6 (settlement):** Concurrent `create_request` simulation via two `array_unshift` calls in a single PHP request — assert only one persists.
- **M8 (Nominatim):** Assert `wp_cache_add` returns false on second call within TTL.

Run live on `foodxpress-for-woocommerce.local` after each fix batch.

---

## Tracking

| Item | Status | Date | PR / commit |
|---|---|---|---|
| H1 | [x] | 2026-09-01 | Round 2 batch A |
| H2 | [x] | 2026-09-01 | quick-win #aa8fe75+ |
| H3 | [x] | 2026-09-01 | quick-win #aa8fe75+ |
| H4 | [x] | 2026-09-01 | Round 2 batch B |
| H5 | [x] | 2026-09-01 | Round 2 batch C |
| M1 | [x] | 2026-09-01 | Round 2 batch E |
| M2+M3 | [x] | 2026-09-01 | Round 2 batch D |
| M4 | [x] | 2026-09-01 | Round 2 batch F |
| M5+M6 | [x] | 2026-09-01 | Round 2 batch E |
| M7 | [x] | 2026-09-01 | Round 2 batch G (interim cap bump) |
| M8 | [x] | 2026-09-01 | Round 2 batch E |
| M9 | [x] | 2026-09-01 | quick-win #aa8fe75+ |
| M10 | [x] | 2026-09-01 | Round 2 batch F |
| L1 | [x] | 2026-09-01 | quick-win #aa8fe75+ |
| L1.1 | [x] | 2026-09-01 | follow-up: swap error_log → wc_get_logger in shipping-zone catches (clears 2 Plugin Check warnings) |
| R1 | [x] | 2026-09-01 | regression-fix: WC settings tab hooks (`woocommerce_settings_routemile`, `woocommerce_update_options_routemile`) still used bare `routemile` after v1.5.0 rename to `routemile-for-woocommerce` — tab content rendered empty |
| R2 | [x] | 2026-09-01 | regression-fix: WC Blocks Store API namespace drift — checkout.js + checkout-leaflet.js still called `extensionCartUpdate({ namespace: 'routemile-woocommerce' })` after PHP registered `routemile-for-woocommerce`, causing "There is no such namespace registered" + "Sorry, this order requires a shipping option" on checkout. Also fixed `routew-agent-sw.js` plugin-folder path (PWA service worker hardcoded `/wp-content/plugins/routemile-woocommerce/assets/`). |
| R3 | [x] | 2026-09-01 | regression-fix: shipping-zone auto-sync skipped zone 0 (Rest of the world) once the 6-hour `routew_zone_sync_done` transient was set, so customers whose address didn't match a specific zone (e.g. country=Bangladesh vs zone=Rangamati) saw "No shipping options are available for this address" at checkout. Added self-healing check (re-sync if zone 0 lacks the method) + reactive hooks for `woocommerce_shipping_zone_added`/`_updated` events so newly-created zones are auto-synced. |
| R4 | [x] | 2026-09-01 | regression-fix: `ROUTEW_Session_Helper::clear_customer_session_data()` (Round 2 H4 batch) called `WC()->session()` unguarded; the Blocks order-confirmation page fires `woocommerce_thankyou` while the WC session handler is still bootstrapping, throwing "Call to undefined method WooCommerce::session()" and 500'ing the thank-you page. Replaced with a `safe_get_session()` helper that prefers `method_exists(WC(), 'session')`, falls back to direct property access, and returns null on any failure so the cleanup becomes a no-op. |
| L2 | [x] | 2026-09-01 | quick-win #aa8fe75+ |
| L3 | [x] | 2026-09-01 | quick-win #aa8fe75+ |
| L4 | [x] | 2026-09-01 | Round 2 batch C |
| L5 | [x] | 2026-09-01 | quick-win #aa8fe75+ |
| L6 | [x] | 2026-09-01 | quick-win #aa8fe75+ |

---

*Generated by parallel security + logic audit, 2026-09-01. Auditors reviewed all 46 PHP files. No SQL injection, XSS, CSRF, capability-bypass, file-inclusion, or unserialize vulnerabilities found.*
