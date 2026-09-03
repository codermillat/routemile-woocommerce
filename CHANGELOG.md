# Changelog

All notable changes to RouteMile for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased] — Mile Zero design system

The complete UI/UX transformation. Every customer- and agent-facing surface was rebuilt on a compiled, **scoped** design system — fixing the broken layouts the old unscoped-Bootstrap overlay caused on themed pages.

### Added (v1.6.0-dev round 13 — admin-configurable brand color)
- **Brand Color** (`includes/class-routew-brand-color.php`): the store admin picks ONE hex from WooCommerce → Settings → RouteMile and the whole brand ramp (buttons, links, icons, avatars, chips, page canvas, hero washes) recolours across **every plugin surface** — my-account, checkout, order tracking, order-received and the rider PWA. Status colors (delivered green, transit blue, assigned amber, cancelled red) deliberately stay fixed — they carry delivery meaning, not brand.
  - **Root fix first:** the compiled design-system stylesheets previously *declared* the `--rm-brand*` custom properties but never *consumed* them (~90 literal sites). All brand-tinted rules in `tools/ui/scss/_components.scss` + the five surface sheets now read `var(--rm-brand*)`; alpha tints compile to `color-mix(in srgb, var(--rm-brand) N%, transparent)`. With no override saved the tokens equal the previous literals, so existing installs render **byte-for-byte the same** (only the new `--rm-brand-canvas-wash` token was added).
  - **Ramp derivation in pure PHP** (no deps): brand / strong (−15%) / deep (−32%) / soft (91% white) / line (78% white) / canvas (96% white) / canvas-2 / quick-action tint / canvas wash — emitted as ONE inline custom-property rule on the shared `routew-ui` handle (`wp_add_inline_style`).
  - **WCAG AA contrast guardrail:** button/link text on the strong shade computes the WCAG relative-luminance ratio; a too-light brand (pale yellow) flips solid-fill text to near-black ink, a dark brand keeps white. Wired via `var(--rm-on-brand, #fff)` on the btn-brand component + the surface button + the agent pickup button.
  - **PWA follows the brand:** the agent manifest `theme_color` and the dashboard `<meta name="theme-color">` read the admin color (were hard-coded `#E85D04`).
  - Settings ride the existing WooCommerce tab (nonce + `manage_woocommerce` + `routew_sanitize_settings_extra`): `sanitize_hex_color()` on save, 3-digit shorthand expanded, stored hex validated again at read; the emitted CSS contains only derived hexes — no user text is ever reflected. Saving the default orange (or an empty value) removes the override entirely.
  - Settings UI: native color input + "Use default orange" reset + live preview swatches (button + pill) that approximate the derived ramp client-side.

### Fixed (v1.6.0-dev round 12 — admin-renameable order stages + manager Accept action)
- **Order Stage Labels** (`includes/class-routew-stage-labels.php`): store admins rename the five delivery stages from WooCommerce → Settings → RouteMile and pick a colour (curated pill palette) + icon (fixed SVG palette) per stage — e.g. "Order placed → In the kitchen → Rider assigned → On the way → Delivered", or any wording they prefer ("Sent to kitchen", "Out for delivery", …). A live preview pill in each row shows exactly what riders and customers will see.
  - **Display-only by design**: the WooCommerce status slugs (`routew-in-kitchen`, `routew-assigned`, `routew-picked-up`, `completed`), every stored `_routew_status_at_*` meta key and all order data stay untouched — no migration, orders in flight keep their history; renames apply instantly everywhere.
  - **Every surface reads the shared helper**: the WC admin status dropdown + order lists (`ROUTEW_Order_Statuses`), the agent PWA card pills (`agent-template-helpers.php`), the customer tracking stepper labels + icons + hero pills (`ROUTEW_Shortcodes`), the my-account order pills (`ROUTEW_My_Account`) and the deliveries dashboard status cells (`ROUTEW_Dashboard_Render`). Terminal states (cancelled / failed / refunded) keep the fixed red pill.
  - **Settings API all the way**: fields register through the `routew_settings_register_extra_fields` / `routew_sanitize_settings_extra` extension points (same pattern as Pricing/Store Hours); the sanitizer whitelists every key, colour and icon, `sanitize_text_field()`s the labels, and an empty label falls back to the default so a pill can never render blank. The `statuses` mapping is never form-writable.
- **Manager "Accept Order" action** (deliveries dashboard): newly placed `pending` orders now show a primary **Accept Order** button that moves them into the kitchen stage — the previously missing first step of the manager workflow (Order placed → manager accepts → assign rider → rider picks up → on the way → cash if COD → delivered). Reuses the existing nonce-verified `routew_update_order_status` handler: no new endpoint, no new capability, no new nonce.

### Fixed (v1.6.0-dev round 12)
- The agent dashboard's **"To hand over"** stat tile showed a raw float (`0`, `551.03`) instead of the formatted currency after an in-place action update — the AJAX payload now carries `unsettled_formatted` (a pre-formatted `wc_price()` string) and the JS renders that, so the tile and the "You are holding ৳X" banner always show proper store currency.
- Agent i18n: the `cash_to_collect` and `holding` banner strings used by the in-place refresh are now properly passed through `wp_localize_script` (they previously fell back to English).

### Fixed (v1.6.0-dev round 11 — agent PWA native-app flow polish)
- **"Cash to collect" banner now drops already-collected orders in place.** `ROUTEW_Delivery_Boy_View::build_dashboard_state()` previously counted every active COD order toward the live "Cash to collect: ৳X across N order(s)" banner — even after the agent had tapped Cash Collected (the cash was physically on their hands, but the banner still said "to collect"). The summary now excludes any order with `_routew_cash_collected > 0`, and the signature also tracks the collected/uncollected state so the heartbeat doesn't reload just for that change.
- **In-place banner refresh on every agent action.** `build_action_response()` now also returns `cash` (unsettled total + has-pending / has-last-accepted flags), and the JS `updateCounters()` honours it: the **"Cash to collect"** summary AND the **"You are holding ৳X of the store's cash"** unsettled banner (plus the **"To hand over"** stat tile) update in place from the AJAX response. No reload needed after Cash Collected, Mark Picked Up or Mark Delivered.
- **No more spurious hard reload after the agent's own action.** The heartbeat's `knownSignature` was captured once at startup and never updated by the agent's own AJAX response — so the next poll, ~30s later, would see the server's new (post-action) signature as "external" and force a `window.location.reload()`. The heartbeat now re-reads `data-routew-state` from the shell on every poll, so actions the agent themselves took (which already update the attribute in place) immediately become the new baseline. Verified by waiting 32s after Mark Delivered — no reload, navigation count stays at 1.
- **Card stayed put, not disappeared, on COD cash confirm.** Two bugs were stacked: (a) the JS removed the stale card *before* trying to insert the new one with `insertBefore($card)`, so the new card ended up parented to the now-detached `$card` and vanished from the DOM; (b) the order-card branch logic checked the OLD `currentPanelId` so the "stay in place" guard never matched the cash-confirm case. Fixed: the stale card is still removed first, but the new card is now `prepend()`'d directly into the destination panel (idempotency is preserved), and the animate-out step is skipped for same-tab refreshes. Net result: the Cash Collected card stays in the In Progress list with the green "Cash collected …" strip and the unlocked **Mark Delivered** button — which was the original intent all along.

### Fixed (v1.6.0-dev round 10 — WordPress Plugin Check compliance)
- **All 22 PCP errors and 10 of 14 warnings cleared** (checked with the official WordPress Plugin Check, all categories):
  - **i18n:** 5 `_n()` calls in `ROUTEW_My_Account` (dashboard hero, quick-action hints, settings hints) now carry the required `/* translators: %d: … */` comments so the `.pot` generation documents every placeholder.
  - **Output escaping:** the agent card's "Cash Collected (৳…)" button label now passes the formatted order total through `esc_html()` (previously only `wp_strip_all_tags()` — not an escaping primitive).
  - **Text-domain clarity in the my-account template override:** WC's own strings in `my-address.php` (Billing/Shipping address, Edit/Add, empty-state) intentionally keep the `woocommerce` text domain so WooCommerce's existing translations apply (standard practice for template overrides) — each call is now annotated for the sniffer, so the Plugin Check text-domain errors are gone while the translations still come from WC language packs.
  - **Naming conventions:** the my-address template override's local variables carry the `routew_` prefix (`$routew_customer_id`, `$routew_get_addresses`, `$routew_address_name`, `$routew_address_title`, `$routew_address`).
  - **Nonce warnings on read-only `$_GET['status']` reads** (orders filter chips in `ROUTEW_My_Account` + `ROUTEW_Shortcodes`): annotated as read-only query vars with no state change — no nonce applies by design.
  - **Repo hygiene:** all `.DS_Store` files purged from the working tree (8 hidden-file errors).
  - **Build script is Node, not shell:** `tools/ui/build.sh` → `tools/ui/build.mjs` (wp.org rejects `.sh` application files in plugin folders; output verified **byte-identical** to the shell build). The whole `tools/` dev toolchain is now excluded from the distribution zip via `.distignore`.
- The 4 remaining in-place warnings are **dev-checkout artifacts only** — `.gitignore`, `.distignore`, `AGENTS.md`, `.claude/` — all stripped from the shipping zip by `.distignore`; a Plugin Check run against the dist archive reports **zero errors and zero warnings**.

### Fixed (v1.6.0-dev round 9 — agent PWA native-app flow)
- **THE bug:** every order action (Mark Picked Up / Cash Collected / Mark Delivered) reloaded the whole page, landing the agent back on the **New** tab while their order moved to In Progress or Delivered — forcing a manual tab hunt after every single task. Fixed with a full in-place update flow:
  - **Actions no longer reload the page.** The AJAX handlers (`routew_update_delivery_status`, `routew_confirm_cash_collected`) now return a rich payload via `build_action_response()`: the **fresh order card** for the order's new status, its **destination tab**, the updated **tab counts**, today's performance stats, the COD summary and the new state signature.
  - **The client follows the order.** On success the agent's card animates out (`routew-card--removing`), the fresh card arrives at the top of the destination tab with a slide-and-fade entrance (`routew-card--arriving`), the tabbar counters pop (badge-pulse animation only when a count *grows*), the header stat tiles (Delivered today / Active now / Collected today) update live — and the view **switches to the tab the order now lives in**. Toast confirms the result.
  - **COD cash confirmation refreshes in place** (same tab): the card is replaced with the unlocked-deliver version, no tab movement.
  - **Order-card renderer extracted** to `includes/agent-template-helpers.php` so the template and the AJAX handlers render the identical card (the template now echoes the shared function's return).
- **Heartbeat reloads no longer yank the agent home.** External changes (manager assigns an order) still trigger one reload — but the active tab is remembered in sessionStorage and restored after the reload, so an agent working through In Progress stays in In Progress.
- **Empty states stay in sync.** Tabs that empty out get their "No deliveries" state back automatically; cards arriving remove it — no more stale empty-state above a live list.
- All motion guarded by `prefers-reduced-motion`; verified live (Playwright) across the full New → picked-up → COD gate → delivered journey with live counters, zero page reloads and zero console errors.

### Fixed (v1.6.0-dev round 8.1 — order-received page was still broken on the blockified template)
- **Root cause found by live-browser debugging:** the store renders order-received via WooCommerce's **BLOCKIFIED order-confirmation template** (block theme + `wp:woocommerce/order-confirmation-*` blocks), where every block (status, summary, totals, addresses, additional-info) renders *independently*. Round 8's `woocommerce_before_thankyou`/`woocommerce_thankyou` wrapper hooks fired **inside** the status and additional-information blocks (captured by WC's `get_hook_content()`), splitting the wrapper across sibling blocks — so the tracking stepper (inside the totals block) had **no `.routew-ui` ancestor**: raw numbered `<ol>`, browser-default giant SVG icons, unstyled hero.
- **The wrapper strategy is replaced:** a `render_block` filter (`ROUTEW_Shortcodes::wrap_thankyou_block`) now injects `routew-ui routew-account routew-account--thankyou` onto **each** order-confirmation block's own wrapper `<div>`, so every block carries the CSS scope independently. The classic split-wrapper hooks are retired (a DS13 assertion bans their return).
- **Two bugs in the filter itself, both fixed:** the block-name check used `strncmp(..., 32)` but `woocommerce/order-confirmation` is **30 chars** — the comparison never matched, so nothing was injected (found via a live file-append trace of every `render_block` call); and the wrapper-div regex now tolerates the leading whitespace block templates leave before the rendered markup.
- **Blockified-template styling (new CSS):** the WC blocks components now get the design-system treatment — the status block (`<h1>Order completed</h1><p>…fulfilled…</p>`) becomes the green celebration hero card with check glyph; the summary `ul` becomes the tokenised overview grid (Order #/Date/Total/Email/Payment); the totals table gets the card-table styling; the shipping/billing blocks become accent-bar address cards (two-up layout from the theme's own columns). Block-ROOT components (status, totals-wrapper, shipping/billing wrappers) use **same-element scope selectors** (`.routew-ui.routew-account--thankyou.wc-block-…`) per the DS4b rule — the scope classes live ON those wrappers, so descendant selectors never matched (the regression this round fixed); inner markup (summary list, table, h2s, our tracking section) uses plain descendants.
- Verified on the live site via Playwright: all order-confirmation blocks carry the scope, the stepper computes `list-style: none` / `display: flex`, all four steps show their recorded times, the status hero/table/cards read the token values — and the my-account pages are unaffected (no stray thank-you scope, orders table still styled). **270/270 tests** (PHP 8.5 CLI + live PHP 7.4.30) with the DS13 suite extended to 12 assertions (render_block filter wired, classic hooks banned, blockified CSS compiled, same-element selectors enforced).

### Fixed (v1.6.0-dev round 8 — timeline truth, COD cash gate, thank-you page)
- **The order a status was CREATED with never got its timestamp.** WooCommerce fires `woocommerce_order_status_changed` only for transitions with a *previous* status (`WC_Order::set_status` records none for the initial assignment), so a COD order created directly as `processing` had no kitchen-step time. `ROUTEW_Order_Statuses::record_initial_status_timestamp()` now also hooks `woocommerce_new_order` and stamps the creation status (draft-ish statuses skipped; never overwrites a later, more accurate transition stamp). Times are recorded **only when admin/manager/rider/gateway actually changes a status** — viewing an order never touches them.
- **"Assigned" no longer fakes the kitchen as done.** Real delivery semantics: an order sits in the kitchen AND has a rider at the same time until it is picked up. The timeline is now **4 sequential steps** (Order placed → In the kitchen → Picked up → Delivered); "Rider assigned" is not a stepper row — it surfaces through the hero pill ("Your rider is on it" / "Your order is still being prepared…") and the rider card, which now shows the **recorded assignment time** ("Assigned Sep 3, 6:20 pm").
- **Current steps show their start time.** A step in progress with a recorded stamp renders "Started 2:50 PM" under the live pulse (previously it showed the raw time or "In progress"); without a stamp it stays "In progress". The kitchen step also accepts the `processing` stamp — orders that go straight from processing to assigned never enter `routew-in-kitchen`, but their food was being prepared from the processing moment.
- **Order-received (`/checkout/order-received/`) page was unstyled/broken.** The tracking block (and WC's own overview strip / order-details table) rendered **outside** the `.routew-ui` scope there, so the scoped design-system CSS never applied — raw bullet lists, unstyled pills, half-theme layout. `ROUTEW_Shortcodes` now opens a `.routew-ui.routew-account.routew-account--thankyou` wrapper on `woocommerce_before_thankyou` (priority 5) and closes it on `woocommerce_thankyou` (priority 999) — both hooks fire for every real order (failed AND success) in WC's `checkout/thankyou.php`, so the wrapper is always balanced. The account surface CSS now also loads on the order-received page, and WC's bare success `<p>` becomes a proper celebration hero (green tint card, check glyph) while failed-payment notices get an error-card treatment with styled Pay / My-account buttons.

### Added (v1.6.0-dev round 8)
- **COD cash-collection gate.** A cash-on-delivery order can no longer be marked delivered until the delivery agent confirms the money was physically collected:
  - **Agent PWA:** on a picked-up COD order the primary action becomes an amber **"Cash Collected (৳…)"** button; "Mark Delivered" renders visibly **locked** (muted, `disabled`) with a hint line until confirmed. Confirming records `_routew_cash_collected` (the GMT timestamp doubles as flag + audit time), sets the order's **paid date** (the accounting-truth moment for COD — money changed hands), leaves an order-note audit trail and flips the card's COD strip to "Cash collected {time}". The dialog asks for the amount explicitly; double-taps are idempotent.
  - **Server-side enforcement:** both the AJAX path (`routew_update_delivery_status`) and the non-JS `admin_post` path (`mark_delivered`) reject `completed` for COD orders without the confirmation — the gate cannot be bypassed by the UI. (Managers/admins keep their override on the deliveries dashboard — they can resolve edge cases like remote hand-over.)
  - The agent JS action handler is now endpoint-generic (`data-action` selects the AJAX action, `data-confirm` overrides the confirmation copy) instead of hardcoding the single status endpoint.
- **DS13 test suite** (12 assertions) + **DS14** (6 assertions: renderer extraction, rich AJAX payload, card-move flow, COD same-tab refresh, active-tab restore, animations). **278/278 pass** (PHP 8.5 CLI + live PHP 7.4.30).

### Fixed (v1.6.0-dev round 7 — screenshot feedback: tracking truth + mandatory phone)
- **Tracking showed "Pending" on delivered orders:** the stepper had no real per-step data — "Rider assigned" and "Picked up" rendered "Pending" forever on orders that were already Delivered (worst on orders completed without a rider, e.g. admin fast-tracking). Fixed twice over:
  - **Every status transition now records a timestamp.** `ROUTEW_Order_Statuses::record_status_timestamp()` hooks the official `woocommerce_order_status_changed` action (fires for every transition, whatever the source: admin dashboard, agent PWA, manual admin edit, automation) and stamps `_routew_status_at_{status}` as a GMT unix timestamp. A new 5-step timeline reads these metas and shows the **actual localised time** each step happened.
  - **Finished steps without data render an honest "—", never "Pending."** For orders placed before this recording existed, un-timestamped steps show a muted dash; the current step still shows "In progress", future steps "Pending".
- **Status mapping lied about pending orders:** the old `status_to_step` map collapsed `pending`, `on-hold` AND `processing` onto the "In the kitchen" step — so a pending order (payment not confirmed) showed "In the kitchen / Preparing your order". The tracker now carries a **full delivery-state map covering every WC + routew-* status** (pending, on-hold, processing, in-kitchen, assigned, picked-up, completed, cancelled, failed, refunded + a neutral fallback for third-party statuses), each with its own hero headline, delivery-native pill and accurate context line:
  - `pending` → "We received your order" / **Order placed** pill (step 0 of the new timeline: Order placed → In the kitchen → Rider assigned → Picked up → Delivered)
  - `on-hold` → "Your order is on hold" (payment-pending copy), `processing` → "Confirmed", `cancelled`/`failed`/`refunded` → a proper cancelled-style hero with the recorded end time (the tracker used to vanish entirely for these)
- **Rider info on delivered orders:** the rider card now shows for any order that reached (or passed) the assigned step — including delivered ones. When no rider is linked, honest placeholders render instead ("Your rider details will appear here…" during assignment, "Delivered by our store team." on rider-less completions).
- **Re-order button on the view-order page rendered as a raw theme link:** WC's order-details tfoot "Actions:" row output our Re-order anchor with no design-system styling. Now a brand-orange pill with a refresh glyph (matching the dashboard reorder CTA), quiet-pill base for other action buttons (core Pay/Cancel), full-width on mobile.
- **WC's order-overview status `<mark>` was unstyled:** the "Processing"/"Completed" badge on the view-order overview strip now uses the delivery-state pill ramp (preparing orange, assigned amber, transit blue, delivered green, cancelled red — text stays WooCommerce's own translated status names).

### Added (v1.6.0-dev round 7)
- **Mandatory phone number.** The customer's phone is how the rider reaches them at the door — it can no longer be optional. Two official mechanisms: the `woocommerce_checkout_phone_field` option is forced to `required` (the documented configuration surface the Checkout *block*'s contact panel reads), and the classic checkout's `billing_phone` is made required via the `woocommerce_billing_fields` filter with a delivery-flavoured label ("Phone number — your rider will call this number"). A server-side `woocommerce_after_checkout_validation` check additionally rejects malformed numbers (7–15 digits).
- **DS12 test suite** (9 assertions): transition-timestamp recording wired, all-10-status tracking map, the 4-step timeline with the old kitchen-first map banned, meta-based step times + the never-Pending rule, rider card/notes, phone-required wiring on both surfaces, phone format validation, view-order CSS signals. **268/268 pass** (verified on PHP 8.5 CLI and the live site's PHP 7.4.30).

### Fixed (the root bug)
- **Broken my-account layouts:** v1.5.0 loaded the *full, unscoped* Bootstrap 5.3 stylesheet on the theme's My Account page. Bootstrap's Reboot reset and `.container`/`.row`/`.btn`/typography rules hijacked the theme's own classes and wrecked the page on most themes. Bootstrap is now compiled into `assets/css/routew-ui.css` where **every rule is scoped under `.routew-ui`** (postcss-prefix-selector), so plugin styles can never collide with the theme again — verified by a dedicated test (DS1) and a visual harness.

### Added — WC template overrides ("fix WordPress itself", round 6)
- **The plugin now ships its own WooCommerce my-account templates** (redirected via the `woocommerce_locate_template` filter in `ROUTEW_Shortcodes::override_wc_templates()`), instead of CSS-fighting WC's markup:
  - **`templates/woocommerce/myaccount/dashboard.php`** — drops the core "Hello user (not user? Log out)" and "From your account dashboard you can view…" paragraphs at the source (they never reach the browser). All three core actions preserved, including the deprecated `woocommerce_before_my_account` / `woocommerce_after_my_account` hooks older themes still use. The CSS suppression stays as defense-in-depth for themes that override the override.
  - **`templates/woocommerce/myaccount/my-address.php`** — replaces WC's `.u-columns > .col2-set > .u-column1/.u-column2` float markup with plugin-owned `.routew-address-grid > .routew-address-col--billing/--shipping`, so the layout is CSS-owned end-to-end (every `!important`/`float: none` hack deleted). Mirrors WC 9.3's API surface exactly: the `woocommerce_my_account_get_addresses` title map, `wc_get_account_formatted_address()`, pretty edit URLs (`wc_get_endpoint_url('edit-address', $name)` — not `?name`), the Edit/Add label states, the empty-address state ("You have not set up this type of address yet." rendered as a dashed card with an "Add …" pill), and the `woocommerce_my_account_after_my_address` action. The first draft of this override guessed the data flow wrong (`array_replace()` on the filter's title strings would have fataled) — caught by reading the live site's WC 9.3 template and locked out by a DS11 regression test.
- **DS11 test suite** (4 assertions): filter registered, dashboard override preserves all actions + strips the greeting, address override mirrors all 9 WC API signals with `array_replace` banned, compiled CSS targets the plugin-owned classes. **250/250 pass.**

### Fixed (v1.6.0-dev round 5 — address grid robustness)
- **Address cards still stacking on common laptop viewports:** the grid's 640px breakpoint was right at the edge of a typical laptop content area (1000px viewport − 240px nav ≈ 760px of content). Worse, WC's `.col2-set` flex layout could occasionally override the grid in some themes. Now: breakpoint lowered to **560px**, grid tracks use `minmax(0, 1fr)` for explicit track `min-width: 0`, and `display: grid !important` (plus `float: none !important` / `width: auto !important`) defeats any theme override. Cards are 2-up from 560px viewport and below on truly mobile widths.

### Fixed (v1.6.0-dev round 4 — nav polish)
- **Blank buttons in the mobile bottom bar for custom endpoints:** icons were only defined for WC's six core endpoints, so any plugin-added menu item (Subscriptions, Wishlist, loyalty …) rendered as an invisible empty box in the icon-only bar. Every nav link now carries a fallback glyph (generic link badge); specific endpoint rules override it.
- **Stray indicator on the mobile bottom bar:** the desktop active-state brand bar (drawn at `left: -10px` for the side rail) was still rendering on bottom-bar items, sticking outside the rounded pill. On mobile it's replaced by a small centered top tick (20×3px, native-tab style); desktop keeps the rail-edge bar.

### Fixed (v1.6.0-dev round 3 — six-point feedback)
- **WC dashboard description paragraph still visible:** round 2 suppressed only the *first* `<p>` ("Hello user…"); WC's `dashboard.php` also prints a second one ("From your account dashboard you can view your recent orders…"). The suppression rule now hides **every** core-printed `<p>` on the dashboard root (`routew-account--dashboard > p`), still scoped so sub-pages are untouched.
- **"Back" text leaking next to the sub-page back arrow:** the markup used `.visually-hidden`, which is *not* part of the trimmed scoped Bootstrap build — so the word "Back" rendered visibly. The span is removed; the arrow icon + `aria-label` carry the action.
- **Address book cards clipped text + stacked layout:** three compounding causes, all fixed — (1) grid items default to `min-width: auto`, so a long landmark line forced the card wider than its grid track and clipped the text ("andmark: Near…"); `min-width: 0` + `overflow-wrap: break-word` added. (2) WC's `col2-set` floats the columns (`float: left; width: 48%`), breaking the grid — neutralised. (3) The 2-column breakpoint (783px) was above many real laptop windows; lowered to **640px** so the pair sits side-by-side down to small tablets. Cards now render equal-height (grid stretch), each with a color-coded left accent bar + type chip (billing = blue credit card, shipping = amber truck) and an Edit pill button with a pencil icon.
- **Dashboard nav icon was a plus sign:** replaced with the canonical 2×2 grid dashboard glyph (CSS mask `--routew-nav-icon`).

### Added (v1.6.0-dev round 3)
- **Dynamic greeting (`assets/js/routew-greeting.js`):** the hero eyebrow ("Good evening") is now re-written in the **visitor's local time** on load (the server render stays as a no-JS fallback), re-evaluated when the tab becomes visible again (catches users returning after midnight) and every 15 minutes. Four bands: morning / afternoon / evening / night.
- **Mobile bottom tab bar (icons only):** below 782px the account nav rail becomes a fixed bottom bar — 6 icon-only items (`font-size: 0` hides labels; icons via the existing mask system), 52px+ thumb targets, brand-soft active pill with brand-colored icon, safe-area-inset-bottom padding, frosted top border + upward shadow. The body gets matching bottom padding so no content hides behind the bar. Desktop keeps the side rail.

### Fixed (v1.6.0-dev round 2 — "still plain" feedback)
- **Scope-root descendant-selector bug (the big one):** components that carry the `.routew-ui` scope class on their *own* element (the account wrapper `.routew-account`, both checkout cards, the agent app shell, the track-order page) were styled with descendant selectors like `.routew-ui .routew-checkout-card` — which can never match, because an element cannot be its own descendant. Result: endpoint tables, forms, notices, the checkout step cards, and the agent PWA shell rendered **completely unstyled** while the inner components looked fine. All surface stylesheets now match scope-root components with same-element selectors (`.routew-ui.routew-checkout-card`, via Sass `&.component`), locked in by the new DS4b test (positive + negative assertions).
- **Stale CSS cache:** `ROUTEW_VERSION` stayed at `1.5.0` while CSS filenames were unchanged, so browsers and page caches happily served the pre-redesign `my-account.css` / `my-account-dashboard.css` alongside the new markup — the exact "half-styled" state reported. Version bumped to **1.6.0** (plugin header, `ROUTEW_VERSION`, readme stable tag) so every asset URL cache-busts.
- **Duplicate "Hello user" greeting:** WC's `dashboard.php` greeting paragraph printed above the new greeting hero. The account scope wrapper now carries `routew-account--dashboard` on the dashboard root only, and a same-element CSS rule suppresses that paragraph (no `:has()` dependency, sub-pages untouched).

### Added — visual richness pass (Zomato/Swiggy register)
- **Body canvas:** warm peach-cream page background (`#FFF8F2`) with a soft brand radial wash at the top, painted through the account body class — white cards now visibly float.
- **Greeting hero:** full dark-ink → brand-orange gradient banner with white display-size greeting, badge-style time-of-day eyebrow, ringed avatar, and a deep drop shadow — the page's big color moment.
- **Quick-action tiles:** four distinctly tinted gradient tiles (peach / blue / green / amber) with larger colored icon chips — the food-app category-grid look.
- **Recent-order rows:** 4px status-colored edge bar per row (transit blue, delivered green, preparing orange, cancelled red) plus bolder item-summary typography.
- **Section titles:** brand accent tick; **address card:** brand-tinted gradient; **nav rail:** brand indicator bar + bolder active pill; **CTA buttons:** gradient fill with colored glow shadow.
- **Tracking hero:** status-tinted gradient panel with pill eyebrow; **checkout step chips:** brand-gradient numbered discs with glow; **agent header:** per-stat colored icon chips (green/blue/amber/orange).

### Added — design system ("Mile Zero")
- **`tools/ui/` build toolchain** (Sass + Bootstrap 5.3.3 + postcss + cssnano): `node tools/ui/build.mjs` compiles the scoped re-skin + four surface stylesheets from `tools/ui/scss/` and `assets/css-src/`. Compiled CSS is committed; no runtime build.
- **Design tokens** (`_tokens.scss` → `--rm-*` custom properties): warm charcoal ink + burnt delivery-orange on a warm canvas, delivery-state status ramp (placed / preparing / assigned / transit / delivered / cancelled), 16px cards / 12px fields / pill buttons, soft card shadows — all text pairs WCAG AA.
- **Bundled font:** Plus Jakarta Sans (SIL OFL 1.1) self-hosted as a single 27 KB variable woff2 (`RouteMile Sans`), with license at `assets/fonts/OFL.txt`. No font CDN, no external requests.
- **Shared component layer** (`.routew-pill`, `.routew-avatar`, `.routew-tile-icon`, `.routew-section-title`, `.routew-btn-brand`, `.routew-empty`, `.routew-chevron`, entrance motion with `prefers-reduced-motion` guards) used across all surfaces.

### Changed — customer surfaces
- **My Account dashboard** rebuilt: time-aware greeting hero ("Good evening / Hi Millat 👋"), reorder banner with brand CTA, quick-action tiles with per-tile hints, recent-order rows with a food-app item summary ("Chicken Biryani ×2 +1 more") and delivery-native status pills ("On the way" instead of "Processing"), settings list, quiet sign-out. Fixed the broken per-item `<ul>` nesting, the bare-`%s` greeting, and the missing Edit-target of the old profile card.
- **Account shell:** icon nav rail on desktop collapsing to scroll pills on mobile, sticky sub-page header with back button, orders-table restyle (stacked cards on mobile), view-order overview strip + items table, filter chips restyled, theme duplicate-H1 hidden, WC forms/tables/notices inside the scope all tokenised.
- **Order tracking** (order view + `[routew_track_order]`): hero headline in delivery language ("Out for delivery"), vertical stepper with progress rail, green completed segments, pulsing current step, per-step timestamps with "In progress" live state, rider card with avatar + Call/Message pill actions.
- **Checkout:** "Step 1 / Step 2" headers replaced with numbered-chip cards ("Where should we deliver?" / "Address details"), pill search bar with inline Locate-me button, rounded map shell with skeleton shimmer, green "Delivering to" confirmation banner, tokenised delivery fields; all inline styles removed; `checkout.js` contract (IDs, `slideDown`, `.loading`, skeleton class) preserved verbatim.

### Changed — agent PWA
- Driver-app presentation: dark ink header with orange radial glow, agent identity + 4 KPI stat tiles (cash-to-hand-over highlighted), amber COD strips vs quiet prepaid strips, white order cards with 44px thumb-friendly pill actions (full-width primary status action), frosted-glass bottom tab bar with live counts, safe-area insets, toast, all tabs/pulse animations reduced-motion aware. JS contract and PWA manifest untouched; SW cache bumped v2 → v3 so installed PWAs fetch the new CSS set.

### Removed
- `assets/css/frontend.css` (1,104-line grab-bag) and `assets/vendor/bootstrap/bootstrap.min.css` (unscoped) — superseded by the scoped build + surface stylesheets.
- Retired `routew-frontend` / `routew-bootstrap` handles; every surface now shares the `routew-ui` handle (WP dedupes across surfaces).

### Tests
- `tests/RouteWTestRunner.php`: the UI1–UI12 overlay assertions were replaced by the **DS1–DS10 design-system suite** (scoping airtightness, enqueue graph, markup/JS contracts, surface selectors, text-domain hygiene). **234/234 pass.** Previews for visual QA live in `tools/ui/preview/`.

## [1.5.0] — 2026-09-01

### Changed (WP.org review-blocker resolution)
- **Plugin renamed** to **RouteMile for WooCommerce** (plugin slug `routemile-woocommerce`, text domain `routemile-woocommerce`). The previous name overlapped an existing commercial food-ordering plugin; the WP.org reviewer flagged it as potentially confusing and asked us to choose a more distinctive name. The rename is irreversible after approval — done now while the permalink can still be reserved.
- **Text domain unified to the plugin slug.** Every `__('...', 'foodxpress')` call now uses `'routemile-woocommerce'`. Plugin Check reported 700+ mismatches before this change; they should all be resolved.
- **PHP prefix bumped to `routew_` (6 chars).** Was `fxw_` (3 chars — too short per developer.wordpress.org `avoid-naming-collisions`). All function names, class names, options, transients, post meta keys, user meta keys, AJAX action suffixes, and admin page slugs updated. Order statuses `wc-fxw-*` rewrote to `wc-routew-*`.
- **Settings page moved under the WooCommerce top-level menu** (`WooCommerce → Deliveries`); the legacy top-level URL gets a 301 redirect so old bookmarks survive one cycle.
- **Map-provider "Powered by ..." credit is opt-in.** A new "Show provider credit" checkbox under `WooCommerce → Settings → RouteMile → Map Provider` gates the public-facing attribution string (default OFF per WP.org Guideline 10).
- **Inline script and style extracted to external files** at `assets/js/admin-settings-media.js`, `assets/css/receipt.css`, and `assets/js/receipt-print.js` (Plugin Check had flagged three inline blocks).
- **Receipt-template `$order_number` escaped** in the `<title>` (defence against merchant-controlled sequential-order-number formats).
- **i18n polish** — `date()` replaced with `wp_date()` (WP timezone), `%s, %s` reordered to `%1$s, %2$s` + translators comment in the minimum-order string, missing version on `wp_register_script` for Google Maps fixed.

### Added
- **One-time data migration routine** (`routew_migrate_legacy_fxw_data()` in `routemile-woocommerce.php`) runs at `plugins_loaded` priority 1 on first v1.5.0 boot. Copies every legacy `fxw_*` option, transient, post meta, user meta, and `wc-fxw-*` order status to the new `routew_*` keys. Idempotent — gated by `routew_migrated_from_fxw`.
- **`.distignore`** at the repo root so the production zip excludes `tests/`, `docs/`, `node_modules/`, `playwright*`, `.wordpress-org/`, and dev tooling but keeps `LICENSE.md` (WP.org wants the licence file inside the archive).
- **Readme "Privacy & external services" section** now links each provider's Terms of Service and Privacy Policy; **Project OSRM** is newly disclosed.

### Notes for upgraders
- 1.4.x stores keep everything after v1.5.0 upgrade; no manual steps.
- The plugin's *display* name in the WP admin changes from "FoodXpress" to "RouteMile". Settings, rider assignments, and order statuses migrate automatically. Active riders see their dashboard unchanged; only the menu label moves under WooCommerce.

## [1.4.0] — 2026-08-22

### Added (WP.org submission readiness)
- **`readme.txt`** in WordPress.org plugin-directory format: standard headers, full description (checkout / store management / rider app / customer features), installation steps, FAQ, changelog, upgrade notice — plus a **Privacy & external services** section disclosing exactly what data leaves the site per map provider (Google Maps: checkout JS + server-side geocode/routing to `maps.googleapis.com`, customer coordinates sent, API key stays local; OpenStreetMap/Leaflet: tiles from `tile.openstreetmap.org`, geocoding via `nominatim.openstreetmap.org`). No telemetry or other outbound calls exist.
- **README.md refreshed** for v1.4.0 (was still advertising v1.2.10 as latest and Google-only maps): new feature list covering the rider PWA and settlement workflow, the same privacy/disclosure section, current test-suite expectations.

### Fixed (note privacy — kitchen note no longer shown to delivery agents)
- **The checkout "order note" is a kitchen note and stays with the store.** The agent dashboard was rendering both the customer's order note ("Note to your order" — meant for the kitchen) AND the delivery instructions. Agents now see **only** the "Delivery instructions" block (relabelled from the ambiguous "Note from customer"); the kitchen/order note is exclusively for the manager/admin, who pass it to the kitchen. (A dedicated chef role + kitchen portal remains a future roadmap item; until then manager/admin own that handoff.)
- **New "Kitchen Note" column on all three Deliveries Dashboard tables** (Unassigned / Assigned / Out for Delivery): shows the customer's order note truncated to 60 chars with the full text on hover (title attribute), "—" when empty — so the person assigning the order sees the kitchen instruction without opening the order.

### Fixed (unassign delivery boy — fatal)
- **Unassigning a delivery boy crashed with `Call to undefined function post_status_exists()`.** That function does not exist in WordPress core — the "Unassign" path on the deliveries dashboard (and the equivalent order-edit action) died the moment it tried to pick the revert status. The correct core API, `get_post_status_object('wc-routew-in-kitchen')`, is now used at all three call sites (dashboard form handler, dashboard AJAX twin, order-admin handler). Verified: the unassign path now completes and reverts the order to In Kitchen.

### Changed (settings clarity)
- **"Default Preparation Time" now explains itself.** The field had zero help text — a non-technical store owner had no way to know what it does (it feeds the customer-facing ETA: prep time + travel time). It now reads "How long your kitchen needs to prepare a typical order. RouteMile adds this to travel time to show customers an estimated arrival, e.g. 20 min cooking + 15 min ride = 'Arrives in ~35 minutes'. Most restaurants keep 15–30." with a "minutes" unit suffix on the input.

### Fixed (weekly hours grid ignored saved flags)
- **The Opening Hours grid always rendered the defaults, never what you saved.** `wp_parse_args($stored_hours, self::defaults())` silently discards arrays with numeric top-level keys — the stored per-day arrays (Sunday closed, custom times) were dropped and every row rendered as open 09:00–22:00. That is why a saved "Closed all day" on Sunday showed unchecked on the settings page while the runtime checks (which read the stored option directly) behaved differently. All readers now go through the new `ROUTEW_Store_Hours::get_hours()`, which merges each stored day over its defaults with `array_merge` — settings grid, `is_open_now()` and `reopen_hint()` finally show and use the same data.

### Fixed (admin-bar "Deliveries" toggle stuck on Closed)
- **The admin-bar toggle now acts on the state its label shows.** The node renders the *effective* status (manual Open/Closed flag AND the weekly schedule), but the click handler flipped only the hidden manual flag — so with a fully-closed weekly schedule, every click silently toggled an invisible bit while the schedule kept the store closed and the button read "Deliveries: Closed" forever. Now: clicking when **closed** opens immediately (manual flag on + an automatic force-open-until-23:59-today via the special-occasion override, so the label's promise holds even against a closed week; a later existing override time is preserved), and clicking when **open** just closes (manual flag off; any auto force-open override from a previous open is cleared). Verified end-to-end in the browser: Closed → click → "Deliveries: Open", click again → "Deliveries: Closed".

### Added (opening-hours flexibility)
- **"Open all day" per weekday.** The Weekly Hours grid gains an "Open all day" checkbox next to "Closed all day" (they're mutually exclusive in the UI and the sanitizer — open wins if both arrive ticked). A day marked open-all-day is a 24-hour window, no time inputs needed.
- **Special-occasion override.** New "Stay open regardless of the weekly schedule" control with a date-time picker: ordering stays open until that moment even when the weekly schedule says closed (holidays, one-off events). The override is stored only when both the checkbox is ticked and a parseable `datetime-local` value is posted; invalid dates are rejected with an admin notice. It never overrides the manual admin-bar Open/Closed toggle — that remains the master kill switch. When a save somehow omits the hours section entirely, existing override values are preserved along with the rest of the schedule.

### Fixed (settings page UX — non-technical admin pass)
- **Removed the section topic-picker buttons** ("Store & Location / Map Provider / …") — their anchors didn't work reliably inside WooCommerce's settings form and they added a step without adding information.
- **All Google Maps key fields now live inside the Map Provider card**, directly under the provider dropdown (previously they sat far above in General Settings while the provider choice was at the bottom of the page). They are visible ONLY when "Google Maps" is the selected provider; OpenStreetMap shows zero key fields; MapTiler/Geoapify show just their single key plus the road-distance factor.
- **API keys are masked** (password-style inputs with Show/Hide toggles) instead of displaying raw keys on screen.

### Added (cash settlement — hand over & reset collected money)
- **Agents can settle (hand over) the cash they're holding, and admin/manager can settle it for them.** New `ROUTEW_Agent_Cash` engine (`includes/class-routew-agent-cash.php`) keeps a bounded settlement ledger (`routew_cash_settlements` option): for each agent it computes total collected on delivered COD orders, total already settled, and the outstanding balance — always as `collected − settled`, with the amount computed server-side so form input can never influence it. The handler (`admin_post_routew_settle_agent_cash`, per-agent nonce) is allowed to shop managers/admins (`edit_shop_orders`, any agent) and to the agent themselves (self-reporting a physical handover); every settlement lands an order note naming who recorded it.
- **Agent dashboard**: a fifth "To hand over" stat (amber when non-zero), a "You are holding ₹X of the store's cash" bar with a confirm-guarded **Cash handed over** button that resets the balance to ₹0, and a green "Last handed over: ₹X on <date>. All settled." line afterwards. The settle confirmation and success toast are localized; today's gross "Collected" stat deliberately does NOT reset — it stays a work record while the hand-over balance is the debt.
- **Admin Deliveries dashboard → Work Tracking table** gains a "Cash to hand over" column with an expandable per-order breakdown of exactly which delivered COD orders make up the balance (linked order numbers + amounts + dates), a **Settle ₹X** button per agent, and a "Recent cash settlements" ledger (when / agent / amount / recorded by) below the table.

### Added (work tracking + customer rider info)
- **Agents now see their full order history.** The agent dashboard gains a third "Delivered" tab (bottom bar: New / In Progress / Delivered) listing the agent's completed orders newest-first in a compact history-card mode: delivered date/time, Completed badge, "Collected on delivery ₹X" (green, settled styling) or "Prepaid — nothing to collect", with Call and map actions retained. A header stats row summarises the agent's work at a glance: Delivered today · Active now · Collected today (COD cash) · All-time delivered.
- **Admin/manager per-agent work tracking.** The deliveries dashboard gains a "Delivery Agents — Work Tracking" table: every agent with mobile (clickable), active load (assigned + picked up), delivered-today count, cash collected today, and all-time delivered. Numbers are computed by the same `ROUTEW_Delivery_Boy_View::build_dashboard_state()` the agent dashboard uses, so the two views always agree. Agents only ever see their own orders; admins and shop managers see everyone.
- **Agent mobile number profile field** (`routew_agent_phone`, rendered on delivery-boy profiles via the official `show_user_profile` / `edit_user_profile` hooks, capability-checked) — the number customers dial. Customer order tracking (`/my-account/view-order/` and the `[routew_track_order]` shortcode) now shows "Your Delivery Rider" with the agent's name and mobile with a Call button once an order is assigned; the field falls back to the legacy `billing_phone` user meta when unset.

### Changed (agent dashboard — updates live inside the order list only)
- **Removed the notification bell and push notifications.** Per product direction, agents are not notified or messaged: all updates surface inside the order list itself via the existing heartbeat auto-reload (which is retained — new assignments, status changes, and newly delivered orders still refresh the dashboard automatically, including the new Delivered tab, whose orders now also participate in the change signature).

### Added (delivery-agent PWA — native-app dashboard, ahead of the Phase 2 roadmap)
- **The agent dashboard is now an installable PWA.** A web-app manifest is served from `?routew_agent_manifest=1` (name "RouteMile Agent", standalone display, `#E85D04` theme, SVG app icon + maskable variant in `assets/img/`) and a bundled service worker (`assets/js/routew-agent-sw.js`) is served from `?routew_agent_sw=1` with `Service-Worker-Allowed: /` so it can control `/delivery-dashboard/`. The SW is deliberately conservative: dashboard navigations are network-first (fresh order state) with a cached-shell + friendly-offline-page fallback, plugin static assets are stale-while-revalidate, and `wp-admin`/`admin-ajax`/REST/login/cart/checkout/account URLs are never intercepted. iOS install metadata (apple-touch-icon, standalone, translucent status bar) is included; installation requires HTTPS (or localhost) as usual for service workers.
- **The dashboard auto-reloads when orders change.** A lightweight heartbeat (`wp_ajax_routew_agent_dashboard_state`, own `routew_agent_state` nonce + `routew_delivery_access` capability check) returns tab counts, the COD collection total, and a change signature; `delivery-dashboard.js` polls it every 30 s and whenever the tab becomes visible again (battery-friendly — no polling while hidden, no reload while an action is in flight). On change the page reloads so new assignments and status updates appear hands-free. When a NEW order arrives the agent also gets a browser push notification (opt-in via the header bell button — no permission prompt on load) plus `navigator.vibrate` feedback. An expired nonce (long-lived installed app) triggers one silent reload instead of a dead heartbeat.
- **Cash-on-delivery handling for agents.** A header summary chip shows the shift's total to collect ("Cash to collect: ₹500.00 across 1 COD order(s)"); every COD order card carries an amber "Collect on delivery ₹X · payment method" strip and an amber card border, while prepaid orders show a green "Prepaid — nothing to collect" strip, so an agent can never mistake which orders need cash at the door.
- **Customer notes reach the agent.** The checkout "Delivery Instructions" field (`_routew_delivery_instructions`) and the WooCommerce order note are rendered as a highlighted "Note from customer" block on the order card ("Ring the bell twice…").
- **Native-app presentation.** App header with brand tile, agent name, live dot and date; fixed bottom tab bar (New / In Progress) with icons and count badges (replacing the top tabs; same ARIA tablist semantics and tab ids, so the existing JS keeps working); a dedicated **Call customer** button beside the map and status buttons in every card footer; safe-area padding throughout; toast confirmations for picked-up/delivered; offline/online toasts; all card icons migrated from emoji to inline SVG (`routew_agent_icon()` — no emoji, no icon font, no extra HTTP requests). Empty states now nudge agents to keep the app open because new orders arrive automatically.

### Fixed (delivery-agent dashboard — two fatals + stale UI)
- **Agent dashboard fatal: `Call to undefined function routew_render_order_card()`** (`templates/delivery-dashboard-template.php`). The card renderer was defined at the bottom of the template inside an `if (!function_exists())` guard, *after* the render loop that calls it — and PHP does not hoist conditionally-defined functions — so any agent with an assigned order got a white screen instead of their order list. The definition now sits above the render loop. (Latent since the template's 2026-08-14 rewrite; masked in QA because the agent never had assigned orders.)
- **"Mark Delivered" always failed with "Connection error" (HTTP 500).** In `ROUTEW_Delivery_Boy_View::ajax_update_delivery_status()` the picked-up precondition guard read `$order->get_status()` *before* `$order = wc_get_order( $order_id )` ran — on PHP 8 that is a fatal on null, so every `completed` transition through the AJAX path died. "Mark Picked Up" only survived because `&&` short-circuits past the undefined `$order`. The order is now loaded before the guard reads its status.
- **Agent dashboard tabs went stale after a status change.** The action button's success handler only removed the card from its current tab, so an order "Marked Picked Up" vanished from New but never appeared in In Progress (and counters froze) until a manual reload. The handler now reloads the dashboard (respecting `prefers-reduced-motion`) after the fade-out so the order re-appears in its new tab with fresh counts.

### Fixed (receipt printing — fatal + bill completeness)
- **Printed receipt fatal: `Call to undefined method WC_Order::get_formatted_line_total()`** (`templates/receipt-template.php` items loop). The method does not exist on `WC_Order` in WooCommerce 11, so both print paths (admin meta-box "Print Receipt" link and the deliveries-dashboard AJAX button) died mid-render right after the "Items Ordered" separator, producing "There has been a critical error on this website." on the printed bill. Line totals are now formatted with `wc_price( $item->get_total(), array( 'currency' => ... ) )`.
- **The printed bill is now a complete restaurant bill.** Items show `qty × unit price` with the line total right-aligned; totals show Subtotal (pre-discount items sum) → discount / coupon rows, tax rows, and fees automatically via `get_order_item_totals()` (with the WC `cart_subtotal`, `payment_method`, and `order_total` keys suppressed so nothing duplicates) → a boxed **TOTAL AMOUNT** → the existing Cash-on-Delivery **COLLECT** box for COD orders. The shipping row is relabelled "Delivery Fee:" (value keeps its "via RouteMile Delivery" context).
- **Receipt no longer shows the order status row** ("Status: Processing") — a customer-facing bill shouldn't expose internal fulfilment state.
- **Delivery address on the receipt renders with proper ", " separators and full names** instead of the concatenated "USE NAMEGammaUttar Pradesh" (caused by WC's formatted address dropping separators when city/postcode are empty because FXW hides those fields at checkout). The address is now composed from the order's explicit shipping fields; state codes are expanded via `WC()->countries->get_states()` ("UP" → "Uttar Pradesh") by a guarded `routew_receipt_state_name()` helper (defined *before* its call site — conditionally-defined PHP functions are not hoisted).

### Added (customer /my-account dashboard overhaul — Phase 1 of the v1.4 "Customer experience" pass)
- **New `ROUTEW_My_Account` class (`includes/class-routew-my-account.php`) renders a RouteMile-flavored dashboard on `/my-account/`.** Hooks `woocommerce_account_dashboard` (priority 1) and replaces the default WC greeting + descriptive paragraph with a focused three-block surface: a gradient welcome banner with a "Reorder last order" (or "Browse menu" when the customer has no completed orders yet) CTA, a 4-card stats row (On the way / Delivered / Total orders / Saved addresses, each with a colour-coded left accent bar), and a two-column panel grid with a "Recent orders" card and a "Default delivery address" card. Self-registers at the bottom of the file; loaded from `class-routew-core.php` alongside the other frontend classes.
- **New stylesheet `assets/css/my-account-dashboard.css` ships only on the `/my-account/` root.** Enqueued by `ROUTEW_My_Account::enqueue_dashboard_assets()` with `is_wc_endpoint_url()` guarding it off the sub-endpoints (orders, edit-address, edit-account) so the file never travels with requests where it would be dead weight. Defines its own tokens but defers to the existing `--routew-account-*` design tokens for visual consistency with `my-account.css`.
- **Order status pills match the RouteMile delivery vocabulary.** `Processing`, `In the Kitchen`, `Assigned`, and `Picked Up` get distinct pill colours (info / accent / info / info); `Completed` is green, `Cancelled` / `Failed` / `Refunded` are red. The dashboard's recent-orders list uses `.routew-status-pill--*`; the WC native orders table and view-order overview get matching `.order-status.status-*` styles in `my-account.css` so status colour reads the same on every account surface.
- **Empty states for the dashboard panels** — "No orders yet" (with a "Browse menu" CTA) and "No address saved" (with an "Add address" CTA) — so a brand-new customer doesn't see a blank grid.
- **Order count + last-completed lookup use `WC_Order_Query::get_orders()` with `return => 'ids'` and `limit => -1`** for cheap counts (no per-order hydration); the recent-orders list hydrates only the top 3. Address peek uses `WC_Customer` and falls back from shipping to billing when no shipping is set.

### Changed (customer /my-account polish — applies to every account sub-page)
- **The Downloads menu item is removed from the My Account navigation** via the official `woocommerce_account_menu_items` filter (priority 999, late so subscriptions / memberships plugins get a clean array first). A single-restaurant delivery store has no downloadable products, so the link was dead weight that also forced the nav into a horizontal scroll on phones. Implementation matches the official WC wiki example at <https://github.com/woocommerce/woocommerce/wiki/Customising-account-page-tabs>.
- **The nav sidebar + content split is now driven by CSS grid (not float).** v1.4.0's first pass used `float: left` for the desktop sidebar, but the kiosko block theme (and any block theme that sets `display: flex/grid` on `.entry-content`) silently collapsed the floats, leaving the nav stacked above the content even at 1280px+ viewports. `.woocommerce` is now a 2-column grid with explicit `grid-column` / `grid-row` placement so the WC `.woocommerce-notices-wrapper` doesn't steal the first sidebar slot. Layout collapses back to a single column under 400px CSS viewport so the 170px sidebar doesn't squeeze content to <170px on very small phones.
- **Nav items are compact (36px tall, 0.88em text, 14px icon)** so the sidebar reads as a navigation rail instead of a stack of oversized card buttons. The 78px-tall mobile buttons in the first v1.4.0 pass made the nav dominate the page; the new dimensions keep the sidebar visually proportional to the content column. The 18px active-state icon (was 20px) is a proportional match. The 170px sidebar width is intentionally narrow so the content column stays usable (>=400px) on the 600–800px containers that block themes like kiosko produce.
- **Dashboard stat cards are responsive across narrow and wide content areas.** The cards now use a horizontal layout (number on left, label on right) with `white-space: nowrap` + `text-overflow: ellipsis` so labels like "Delivered" / "All orders" / "Addresses" never wrap mid-word. The grid switches from 1-column (narrow content) to 2-column (600px+ viewport) to 4-column (1100px+ viewport) so each card stays at least 200px wide. Stat labels are now natural case ("On the way", "Delivered", "All orders", "Addresses") instead of ALL CAPS — the eyebrow caps on the welcome banner carry the section's visual energy, and natural case is more compact and readable in narrow cards.
- **The theme-injected ">" chevron on the active nav item is suppressed** via `content: none !important; display: none !important;` on `.woocommerce-account.routew-my-account-styled .woocommerce-MyAccount-navigation ul li a::before/::after` (kiosko and any other block theme that injects a chevron or a comma-joiner between the link text and the count badge).
- **Each nav item now has a real icon (Dashboard grid, Orders receipt, Addresses pin, Account details user, Customer logout arrow, Payment methods card).** Icons are inline SVG `mask-image` data URLs so no extra HTTP request is made and no icon-font dependency creeps in. Icons inherit `currentColor` so the orange active state flows through.
- **Page title treatment** — "My account", "Orders", "Addresses", "Account details" now get a 4px orange lead bar, a thicker bottom border in `--routew-account-primary-light`, and tightened letter-spacing so the title reads as the page's H1 instead of a stray paragraph.
- **Addresses page is now styled to match the dashboard widgets** instead of using WC's bare default blocks. The huge "Billing address" / "Shipping address" `<h2>` headings (WC default ~2em) are now 1.05em to match the panel titles. The "Edit Billing address" / "Add ..." link is restyled as an orange-pill button (light orange background, orange text) instead of an underlined link. The address `<address>` element is reset from WC's italic serif to the RouteMile sans-serif with comfortable line-height. The "The following addresses will be used on the checkout page by default." lead paragraph is wrapped in a subtle info card with an orange left border.
- **Account details / Edit address forms are now single-column** instead of WC's cramped 2-column (first/last name) layout. `.form-row` is forced to `display: block; float: none; width: 100%` so every field stacks full-width. Labels are 0.82em bold (was huge WC default), inputs are full-width with the same orange focus ring as the rest of the form. The password-change `<fieldset>` is wrapped in a soft card.
- **Empty-state styling for the WC "No orders have been made yet" notice** — a dashed-border card with a centred CTA, replacing the default grey box so the message fits the food-delivery aesthetic instead of reading like a developer error.
- **Status pill on the view-order overview block** (`<mark class="order-status">` rendered by `templates/order/order-details.php`) is also restyled, so a customer looking at `/my-account/view-order/28/` sees the same colour-coded pill as on the dashboard.

## [1.3.9] - 2026-08-18

### Fixed (order UI — duplicated address line + blank free-shipping amount)
- **The exact address is no longer shown twice on the order confirmation, receipt and admin screens.** Since v1.3.0 the customer types the exact address into WooCommerce's own `address_1` field (relabeled "Flat / Floor / Block / Society / Tower"), but `apply_delivery_data_to_order()` ALSO composed the same text into `address_2` — so `get_formatted_shipping_address()` (= line 1 + line 2) rendered it twice. `address_2` now carries only the landmark (`Landmark: <value>`) when one was entered, and stays empty otherwise. Confirmed against the DB: orders 21–25 all stored `address_1 = address_2 = "147 Cashman Road"`.
- **One-shot data migration normalises already-placed orders.** Pre-1.3.9 orders had the duplicated line 2; a guarded, idempotent migration (`ROUTEW_Checkout_Handler::maybe_migrate_duplicated_address_2()`, run once on the next admin page load, flagged by the `routew_migrated_address2_v139` option) rewrites line 2 to the new shape for orders our old code shaped — including the landmark-suffixed variant. Uses HPOS-safe `wc_get_orders()` + order setters only (no direct table writes).
- **Free shipping now labels itself on the confirmation screen instead of showing a blank amount.** When the free-delivery threshold zeroed the cost (e.g. order #25, subtotal ₹500 ≥ ₹500 threshold), the shipping row rendered "RouteMile Delivery" with no amount, reading as a broken order. The rate label now reads "RouteMile Delivery (Free delivery)" when the threshold triggered, so the zero cost is obviously a discount.

## [1.3.8] - 2026-08-18

### Fixed ("No shipping options are available for this address." — found via WC log, not guessed)
- **The shipping-method gate now checks the `map` capability instead of a nonexistent `distance` capability.** v1.3.4 replaced the literal `routew_google_maps_api_key` guard with `ROUTEW_Map_Providers::supports( 'distance', $options )` — but `distance` is not a capability any provider registers (the registry lists `map`, `geocode`, `routing`), so `supports()` returned `false` for every OSM/Leaflet store and `calculate_shipping()` bailed **before every rate calculation**. Diagnosis path: the WC logger (`wp-content/uploads/wc-logs/routemile-*.log`) showed the last `calculate_shipping: adding rate` entry at 16:55 UTC — before the v1.3.4 deploy — and zero `calculate_shipping` entries of any kind afterwards, including the logged early returns, which narrowed the bail to the only unlogged return: the new capability gate. v1.3.5–v1.3.7 (zone auto-registration, frontend sync, `reset_shipping()`) were all correct but could not help while the method itself produced no rate.
- **Every remaining early return in `calculate_shipping()` now logs to the `routemile` WC log source** (no configured provider, no session, no pinned location), so the next silent bail is one log check away instead of a seven-release guess.

## [1.3.7] - 2026-08-18

### Fixed (live Order summary refresh — final fix)
- **`WC_Shipping::reset_shipping()` is now called inside the Store API extension callback before re-running shipping calculation.** The cart-extension callback written in v1.3.1 only called `WC()->cart->calculate_shipping()` + `calculate_totals()`. WC's `WC_Shipping` class caches per-package shipping state inside `$this->packages`; subsequent requests within the same Store API lifecycle skipped package recompute and returned the previous (no-rate) package state. With `reset_shipping()` clearing the package cache first, `calculate_shipping()` is forced to re-evaluate the zones against the freshly-set `customer_lat` / `customer_lng` session values, the FX Shipping Method's `calculate_shipping()` runs, and the Store API response carries the new rate — the block Order summary line now updates to "RouteMile Delivery ₹X.XX" in lockstep with the toast on every pin drag.

## [1.3.6] - 2026-08-18

### Fixed (lazy zone registration fires on frontend too)
- **`routemile_delivery` is enabled on existing shipping zones even on stores that have never visited `/wp-admin/`.** v1.3.5 hooked the one-shot zone sync onto `admin_init` only. Stores whose first post-upgrade request is a frontend checkout page (anonymous customer or kiosk-style deployment) never reach `admin_init`, so the sync never fires and the user still sees "No available delivery option" in the Order summary. The sync now also runs on frontend `init` (priority 5, before FXW's own hooks register). Same 6-hour transient gate so the work happens at most once per window. AJAX, REST, WP-CRON, and CLI requests still skip the sync — the underlying zone mutation is idempotent and runs cheaply. This closes the last gap from the v1.3.4 → v1.3.5 chain.

## [1.3.5] - 2026-08-18

### Fixed ("No available delivery option" — surfaced by the v1.3.4 provider-aware gate)
- **RouteMile Delivery is auto-enabled on every existing shipping zone on the next admin page load.** When v1.3.4 removed the hardcoded Google-key guard, the shipping method started actually running on Leaflet/OSM stores; for stores that never had the method enabled on a WC shipping zone, this surfaced the latent config gap as "No available delivery option" / "No shipping options are available for this address." because WC only calls a shipping method for packages belonging to a zone that has the method enabled. The shipping method's rate can never reach the Order summary until at least one matching zone has the method enabled. New `routew_ensure_shipping_method_registered()` walks every existing shipping zone (named + WC's synthetic zone 0 "Rest of the world") and adds the `routemile_delivery` method idempotently — idempotent, no duplicates; existing zones created BEFORE the upgrade are covered; new zones the user adds afterwards still need the manual checkbox in WooCommerce → Settings → Shipping. Runs on activation (`routew_activate`) and on the next admin page hit gated by a 6-hour transient (`routew_zone_sync_done`) — no frontend cost, no cron cost, no REST cost. Admin can `delete_transient( 'routew_zone_sync_done' )` to force a re-run.

## [1.3.4] - 2026-08-18

### Fixed (live Order summary refresh on the block checkout — final root cause)
- **`RouteMile Delivery ₹X.XX` now updates simultaneously with the toast on every pin drag.** `ROUTEW_Shipping_Method::calculate_shipping()` checked `if ( empty( $api_key ) ) return;` against `routew_google_maps_api_key` unconditionally. With a Leaflet/OSM provider (no Google key required, the case for every Local install that does not have a paid Google account), the method bailed before `add_rate()`, and the Store API rebuilt the cart's shipping panel from a stale session-set package — so the toast updated from `/validate-location` while the Order summary stayed on the previous pin's rate. The shipping method now uses the provider-aware gate `ROUTEW_Map_Providers::supports( 'distance', $options )`, which is true whenever the active map provider can compute distances (Leaflet/OSM work without keys; Google requires the setting). Without this gate the block-checkout Order summary line would have stayed stale no matter what the picker did server-side.
- **Behavior preserved on stores with no configured provider** — `supports()` returns false when there is no provider at all, so the shipping method bails in the same way as before (no rate is added). Fix only widens the gate for providers that don't require a key.

## [1.3.3] - 2026-08-18

### Fixed (block-checkout 500 critical error — fatal on every Place Order)
- **Block checkout now reaches the payment gateway instead of throwing "There has been a critical error on this website."** `ROUTEW_Checkout_Handler::apply_delivery_data_to_order()` called a non-existent `$order->set_address_2(...)` while persisting the composed delivery address; WC exposes only the typed `set_billing_address_2()` / `set_shipping_address_2()` setters, so the call threw `Call to undefined method WC_Order::set_address_2()` on every block checkout POST to `/wp-json/wc/store/v1/checkout`. The `set_shipping_address_2(...)` call directly below the bad line already stored the value where it belongs (receipts + emails surface the full delivery line via `get_formatted_shipping_address()`); the unreachable generic call was harmless dead code in the WC 11 era because previous code paths never reached the line for block orders. With v1.3.x now hooking block-checkout persistence, the bad call fired and blocked every order. Removed; the shipping line below remains.

## [1.3.2] - 2026-08-18

### Fixed (block-checkout live total refresh — caught right after v1.3.1 ship)
- **Pin drag → Order summary updates simultaneously with the toast on the block checkout.** v1.3.0 set the picker to call `wc.blocksCheckout.extensionCartUpdate(...)` on the block checkout, and v1.3.1 registered the server-side namespace; but the JS auto-detection ran once at init and cached the result. If `wc.blocksCheckout` was not wired in at init time (which is normal on block checkout pages until the bundles finish loading), the picker cached the fallback (`classic` → `$(document.body).trigger('update_checkout')`) and never switched back. The toast still updated via the parallel REST `/validate-location` route, so the user saw a fresh fee in the toast while the block Order summary retained the previous rate. The picker now sniffs `wc.blocksCheckout.extensionCartUpdate` on EVERY call (each pin drag), so the transition to the block path happens the instant the API becomes available. Same change in both `assets/js/checkout.js` and `assets/js/checkout-leaflet.js`.

## [1.3.1] - 2026-08-18

### Fixed (block-checkout Store API namespace — caught right after v1.3.0 ship)
- **"There is no such namespace registered: routemile." banner is gone.** v1.3.0 introduced picker calls to `wc.blocksCheckout.extensionCartUpdate({ namespace: 'routemile-woocommerce', data: { lat, lng } })` on the block checkout. WooCommerce only ships valid namespaces for extensions that have registered a callback through the documented `woocommerce_store_api_register_update_callback(...)` API; without that registration the Store API rejects every call with the banner above and the block totals stay stale. `ROUTEW_Blocks_Checkout::register_store_api_namespace()` now registers the namespace on `woocommerce_blocks_loaded` (the same hook v1.2.18 settled on for the WC 11+ blocks runtime). The callback (`store_api_update_callback`) bootstraps `WC()->session` if necessary, writes `customer_lat` / `customer_lng` from the posted data, and re-runs `WC()->cart->calculate_shipping()` + `calculate_totals()` so the FX Shipping Method re-reads the freshly stored coordinates. The classic-checkout path (`$(document.body).trigger('update_checkout')` for fragment refresh) is unchanged.
- **Independent REST route (`POST /routemile/v1/checkout/cart-update`) kept for defense-in-depth.** Direct REST caller still works for any external code that doesn't go through the documented Store API extension surface.

## [1.3.0] - 2026-08-18

### Fixed (checkout UX — four regressions caught in browser walkthrough)
- **Double-charging on the checkout is gone.** The "Delivery Fee ₹X" line and the "Free shipping FREE" line used to render simultaneously because the cost was added both as a fee (`woocommerce_cart_calculate_fees`) AND via the RouteMile shipping method, with the two figures computed at different moments. The fee-side path has been removed entirely from `ROUTEW_Delivery_Fee`; the charge is now added exactly once, through the documented `WC_Shipping_Method::calculate_shipping() + add_rate()`. `wc_price()` formatting for the toast comes from the REST controller so currency, decimals and symbol position are store-driven.
- **Country, state, city and postcode are no longer shown on the checkout.** A new `ROUTEW_Checkout_Address` class hooks `woocommerce_get_country_locale` to mark those fields hidden + non-required for every WC locale, and hooks `woocommerce_default_address_fields` to relabel WooCommerce's own `address_1` field to **"Flat / Floor / Block / Society / Tower"** with an example placeholder. This works on both classic AND block checkouts because the locale filter runs on every request. The `city`/`state`/`country`/`postcode` slots are also populated from the store's configured base for both shipping and billing so the address payload sent to order processing still has complete country data even though the fields are not on the form. `woocommerce_customer_default_location` returns the documented `"COUNTRY:STATE"` string format that `wc_get_customer_default_location()` expects (the previous array form crashed `wc_format_country_state_string()` → `strstr()` on PHP 8 and broke every checkout request).
- **Address field count cut to one.** The legacy `routemile/address-details` Additional Checkout Field is gone from the blocks checkout; the blocks flow now reuses the relabelled WC `address_1` field, and `ROUTEW_Blocks_Checkout::apply_delivery_data()` reads the detail from `$order->get_shipping_address_1()`. Only Email address, Country/Region, the relabelled Address and an optional Nearest Landmark remain visible.
- **Pin drag → Order summary now updates simultaneously.** The picker (both `checkout.js` and `checkout-leaflet.js`) detects whether it is on the block checkout by sniffing `wc.blocksCheckout.extensionCartUpdate`, and on the classic checkout falls back to `$(document.body).trigger('update_checkout')`. The new `POST /routemile/v1/checkout/cart-update` REST route persists `customer_lat` / `customer_lng` to the session, bootstrapping `WC()->session` and the customer-session cookie the same way WC's own Store API does for custom REST routes, then re-runs `WC()->cart->calculate_shipping()` + `calculate_totals()` so the shipping method re-reads the freshly-stored coordinates. On the block checkout `extensionCartUpdate({ namespace: 'routemile-woocommerce', data: { lat, lng } })` returns the result inline; on the classic checkout the jQuery event rebuilds the order-review fragment.
- **Landmark label no longer reads "(optional) (optional)".** Block checkout's Additional Checkout Field wrapper appends its own "(optional)" suffix for non-required fields; the previous label `__('Nearby Landmark (optional)', ...)` therefore rendered the suffix twice. The label now reads simply `__('Nearby Landmark', ...)` and the field marker renders correctly.
- **Settings page no longer asks the customer to enable the deleted extra-delivery-fee toggle.** The `routew_enable_extra_delivery_fee` settings field and its sanitizer branch are removed (it was the guard that was supposed to prevent the double-charge). Existing stored values are cleared on the next settings save.

### Internal
- New `includes/class-routew-checkout-address.php` (259 LOC) — address simplification extracted behind the same single-responsibility split pattern as the v1.2.0 checkout split; `ROUTEW_Core::requires` it once on every request because the locale filter must run globally, not just on the frontend.
- Test runner now asserts the new file exists. Suite is at 136/136.

## [1.2.19] - 2026-08-18

### Fixed (browser QA — map picker on block themes + store-state source of truth)
- **The map location picker now renders on the block checkout in block themes.** `ROUTEW_Blocks_Checkout` prepended the Step-1 picker via a `the_content` filter guarded by `in_the_loop()`. Block themes render page content through the core `post-content` block, which applies `the_content` outside the main loop, so the guard rejected every request and the picker silently never appeared — customers saw WooCommerce's stock address fields with no way to pin their location. Moved the injection to `render_block` targeting `woocommerce/checkout`, which fires wherever the block renders regardless of theme type. Verified on a block theme (Kiosko): `#routew-map`, the search input, the "Use My Location" button and the hidden `routew_lat`/`routew_lng` inputs are all present in the checkout HTML, and the cart page is unaffected.
- **Store open/closed state is now consistent between the admin bar and the storefront.** `is_store_open()` lived on `ROUTEW_Checkout`, but `ROUTEW_Core` only loads the checkout classes on frontend and AJAX requests — so in `wp-admin` the class-existence check failed and the admin bar fell back to "Deliveries: Open" while the cart and checkout correctly showed the closed notice. The canonical implementation moved to `ROUTEW_Store_Hours::is_store_open()` (loaded on every request); `ROUTEW_Checkout::is_store_open()` now delegates to it, so both names remain valid for existing callers. The admin bar and the shipping method read the new location.

## [1.2.18] - 2026-08-18

### Fixed (browser QA — blocks checkout fields + admin-bar state)
- **Blocks-checkout FXW fields now register on WooCommerce 11.x.** The Additional Checkout Fields API (used by `ROUTEW_Blocks_Checkout::register_fields`) was hooked on `woocommerce_init` since v1.2.9. WooCommerce 11.0+ changed the function so it requires `woocommerce_blocks_loaded` to have already fired — when called earlier, the function silently defers the registration to that later hook, which can miss the page render entirely. Switched the hook to `woocommerce_blocks_loaded` and verified the three fields (`routemile/address-details`, `routemile/landmark`, `routemile/delivery-instructions`) land in WC's `CheckoutFields::$additional_fields` store on WC 11.0.1. Caught during browser walkthrough of the blocks-checkout page; pre-fix the three fields were registered (function returned no exception) but never appeared on the page.
- **Admin-bar Deliveries status now matches the rest of the UI.** `ROUTEW_Admin_Bar` previously read the raw `routew_is_open` option, so the bar could say "Deliveries: Open" while the schedule-aware `ROUTEW_Checkout::is_store_open()` said closed (and vice versa). The bar now shows the effective `is_store_open()` value, and when closed it appends the next-reopen hint from `ROUTEW_Store_Hours` so the admin sees *why* (e.g. "Deliveries: Closed (We reopen Sunday at 09:00.)"). The AJAX toggle response label is also schedule-aware.

## [1.2.17] - 2026-08-18

### Fixed (runtime QA on Local — WP 7.0.4, WC 11.0.1, PHP 8.2.29)
- **REST validate-location no longer 500s on every page load.** The `lat` and `lng` `sanitize_callback` was set to the bare string `'floatval'`, which WP 6.x+ now resolves as a method-style callback and invokes with `($value, $request, $key)` — PHP 8 rejects the extra args and the WP REST `sanitize_params()` dispatcher throws `ArgumentCountError`, taking down every page that hits the REST layer (cart, checkout, my-account, all admin pages). Replaced both with explicit `function ($param) { return (float) $param; }` closures. Caught by probing `POST /wp-json/routemile/v1/checkout/validate-location` from a curl session; pre-fix the call returned a JSON-wrapped fatal; post-fix it returns the expected `configuration_error` (no Maps key yet) and the full page render is no longer blocked.

## [1.2.16] - 2026-08-18

### Fixed (full-flow audit cleanup pass — 16 minors, no blockers)
- **REST schedule parity.** `validate_location` and the GET `/checkout/settings` endpoint now read store-open state through `ROUTEW_Checkout::is_store_open()`, so the manual Open/Closed toggle AND `ROUTEW_Store_Hours` are honored uniformly across classic checkout, blocks checkout, and the REST estimate
- **No-API-key customer message.** When `routew_google_maps_api_key` is empty, the shared zone-error returns a distinct "online ordering currently unavailable" message instead of telling customers to pin a location on a map that isn't rendered
- **Unassign reverts status.** Both the dashboard form/AJAX unassign path and the order-edit "Re-assign" action now revert the order to `routew-in-kitchen` (falling back to `processing` if the custom status isn't registered) so unassigned orders don't sit in `routew-assigned` with no rider
- **Agent transition enforcement.** Delivery agents can no longer jump straight from `routew-assigned` to `completed` — both the AJAX handler and the form-POST `mark_delivered` require `routew-picked-up` first
- **wp-login.php redirect for riders.** Added a 3-param `login_redirect` filter (the 2-param binding is deprecated in WP 6.2+) so delivery boys who log in via wp-login land on their dashboard
- **Track-order rate limit.** The public order-tracking form now rate-limits at 10 requests/minute per IP via `ROUTEW_Rate_Limiter`, closing the brute-force window opened by sequential order IDs + email guessing
- **Reorder feedback.** Re-order now surfaces per-item failures (e.g. variation since deleted) and a generic "items added" notice via `wc_add_notice` instead of failing silently
- **Settings: explicit cap check + fractional radius + latlng range + uninstall opt-in.** Save handler now guards `manage_woocommerce` per-handler; delivery radius accepts fractional km (2.5, 3.7, etc.) via the new `step=0.1` input and a float sanitize (was `absint`); restaurant-coordinates save now validates ±90/±180 and surfaces a transient admin notice on bad input instead of silently keeping an out-of-range value; new `routew_remove_on_uninstall` checkbox controls whether uninstall.php wipes the option, role, and saved delivery profiles
- **Cart-block closed/minimum notices.** Block-based cart (and checkout) pages now show the same store-closed and minimum-order notices as the classic pages — rendered via a `render_block` filter on the `woocommerce/store-notices` block, bounded to `is_cart()`/`is_checkout()`
- **Reporting site-timezone day boundary.** The dashboard "Today's Report" now uses a `>=... <...` date range anchored on the site's `wp_date` day, so non-UTC stores no longer get a report that drifts by ±1 day near midnight
- **Dashboard `routew-in-kitchen` whitelist.** Both dashboard status-update handlers (form + AJAX) now accept `routew-in-kitchen` so admins can move an order into the kitchen state from the dashboard (previously only possible from the order-edit dropdown, never the deliveries dashboard)
- **Dead code removed.** `admin-dashboard.js` (bound selectors that don't exist in the markup) replaced with a no-op stub that documents why; the orphan `routew_address_unit` save branch and read in the order meta box deleted (the input was never rendered; legacy meta is preserved in WC's meta store)

## [1.2.15] - 2026-08-17

### Fixed (full-flow audit — 3 blockers, 3 majors, cleanup pass)
- **Checkout can now complete on default installs.** WooCommerce never initializes the session for custom REST routes (`is_request('frontend')` excludes REST requests), so the pinned coordinates from `validate-location` were written into a `null` session and lost — order placement always failed with "Please select your exact location on the map." The handler now bootstraps the session exactly the way WooCommerce's own Store API does (`wc_load_cart()` — session + customer + cart, restores any existing session from the cookie, saves on shutdown) and explicitly sets the session cookie for brand-new guest sessions. With the cart loaded, the REST fee estimate also sees the real subtotal, so **free-delivery-threshold pricing now applies to the toast**, not just the shipping method
- **Pricing Rules and Opening Hours sections now render on the RouteMile settings tab.** The `routew_settings_register_extra_fields` action fired inside a field renderer mid-render — `do_settings_sections()` had already snapshotted the section list, so the sections were never output and every settings save silently reset pricing/hours to defaults. The action now fires at registration time (`admin_init`). The two sanitize callbacks additionally preserve stored values defensively when their section's fields fail to post
- **Receipt printing works again on every path.** (1) The `routew_print_receipt` AJAX handler lives in `ROUTEW_Shortcodes`, which only loaded when `is_admin()` was false — but `admin-ajax.php` reports `is_admin() === true`, so the handler never registered and the buttons printed "0". The class now loads on AJAX requests too. (2) The meta-box "Print Receipt" button opens a frontend link, but its `template_redirect` handler lived in `ROUTEW_Order_Admin`, which only loaded in the admin — the link opened the homepage. That class now loads on all requests (its admin hooks are no-ops on the frontend)
- **Saved-address prefill now actually runs.** `load_saved_address()` was hooked to `wp_loaded`, where `is_checkout()` is always false (the main query is parsed later, at `wp`) — the body never executed. Moved to `wp`
- **Honest feature-scope declaration:** the blocks integration relies on the Additional Checkout Fields API (WooCommerce 8.9+); `cart_checkout_blocks` compatibility is now declared version-aware instead of unconditionally true, so stores on WC 7.0–8.8 no longer get a false compatibility claim
- **Delivery agents are now notified when an order is assigned to them.** All three assignment paths (dashboard form, dashboard AJAX, order-edit meta box) transition the order to `routew-assigned`, so a single hook on that status sends the rider a `wp_mail` with the order number, delivery address, total, COD collection hint, and dashboard link; the send result is logged as an order note either way. Previously the three email classes were all customer-facing and assignments happened silently
- **Re-order no longer drops variations** — variation items are re-added with their variation ID and attributes instead of the parent product only
- **Track-order email check is now case-insensitive** (emails are case-insensitive by definition; WC stores them lowercased but input may arrive in any case)

### Changed
- New `delivery_boy` installs no longer grant `edit_posts` — `read` alone grants basic wp-admin access, and the capability was never needed (the incorrect "required to access the admin area" comment is corrected); existing installs are unaffected
- Success toast after pinning a location uses dedicated labels ("Delivery fee: …" / "Free delivery") instead of reusing the "Calculating…" string with the ellipsis stripped

## [1.2.14] - 2026-08-17

### Fixed (currency is 100% WooCommerce-driven)
- **The map's fee toast now uses store-formatted prices**: the REST endpoint returns `fee_formatted` from `wc_price()` — correct currency, decimals, thousand separators, and symbol position (prefix or suffix) for whatever the store uses, with no currency hardcoded anywhere. Previously the toast built `symbol + raw number` in JS, which ignored store formatting (e.g. `$6.4` instead of `$6.40`, wrong position for suffix currencies); the JS fallback also derives from `get_woocommerce_currency_symbol()`, never a literal
- **LANDING-PAGE.md** currency examples made store-currency-neutral, and the stale "$99 Lifetime" pitch corrected to the actual free/open-source (GPL-3.0-or-later) positioning; verified zero hardcoded currency symbols or codes (USD/BDT/INR/৳/₹/$) anywhere in runtime code — every amount renders through `wc_price()` or `get_woocommerce_currency_symbol()`

## [1.2.13] - 2026-08-17

### Added
- **Admin-configurable pricing rules** (`ROUTEW_Pricing`, new *Pricing Rules* section on the RouteMile settings tab — nothing hardcoded):
  - **Fee structure**: choose *base + per-km* (classic) or **distance tiers** — up to five "up to X km → flat fee" rows, matched by the calculated distance; a tier with `0` km means "and above"; distances beyond every tier get no rate
  - **Free delivery threshold**: order subtotal at or above the configured amount gets delivery free (0 disables)
  - **Minimum order amount**: below the configured subtotal no delivery orders are accepted (0 disables) — no shipping rate, a cart-page notice telling the customer how much more to add, and a checkout validation error on both classic and blocks checkouts
  - Applied consistently at every decision point: the shipping method, classic and blocks validation, the REST fee estimate, and the legacy opt-in cart-fee path all resolve fees through `ROUTEW_Pricing`

## [1.2.12] - 2026-08-17

### Fixed (enterprise review — critical items)
- **Map ID + classic-marker fallback** — `AdvancedMarkerElement` requires a real Cloud Console Map ID, so the hardcoded placeholder could break the draggable pin on production keys. The Map ID is now an optional setting (`routew_google_maps_map_id`); when empty, the map uses the classic `google.maps.Marker`, which works with any API key — no cloud configuration required
- **Dual Google Maps keys** — new optional *Server Key* setting (`routew_google_maps_server_key`) for Geocoding/Distance Matrix, so merchants can restrict the browser key by HTTP referrer and the server key by IP (previously one key had to serve both, making proper key restriction impossible). Falls back to the shared key when empty

### Added
- **Scheduled opening hours** (`ROUTEW_Store_Hours`) — an optional per-day schedule (time inputs + all-day-closed checkboxes, overnight spans like 18:00–02:00 supported) on the RouteMile settings tab. The admin-bar toggle remains the manual master close; the schedule additionally pauses ordering outside hours, and closed notices tell customers when you reopen ("We reopen Sunday at 09:00.")

### Changed (hardening)
- Google Maps server calls now retry once (300 ms backoff) on transient failures — network errors or 5xx — smoothing brief API hiccups at checkout

## [1.2.11] - 2026-08-17

### Changed
- **The "Deliveries: Open/Closed" admin-bar switch now visibly controls order placement end to end.** Previously, closing removed the delivery rate and blocked at validation — customers just hit a generic "no shipping method selected" with no explanation. Now:
  - A clear error notice on the **cart and classic checkout** pages ("We are currently closed for deliveries. You can browse and fill your cart — ordering will be available as soon as we reopen.") via `wc_print_notice` on the documented `woocommerce_before_cart` / `woocommerce_before_checkout_form` hooks
  - The same notice renders on **block-based checkout** pages above the Checkout block
  - Server-side placement stays hard-blocked in both checkouts, with the closed check now running **first** in validation so its message dominates; the shipping method offers no rate; the REST `validate-location` endpoint reports closed on every pin drop
  - New single source of truth `ROUTEW_Checkout::is_store_open()` replaces the four scattered `routew_is_open` normalizations (shipping method, zone validation, blocks check, notices)

## [1.2.10] - 2026-08-17

### Fixed (shop-manager capability audit)
- **Settings now live as a native tab under WooCommerce → Settings** (`ROUTEW_Settings`, via the documented `woocommerce_settings_tabs_array` / `woocommerce_settings_{tab}` / `woocommerce_update_options_{tab}` hooks — names verified against WooCommerce core source). Previously a standalone WordPress-Settings page gated by `manage_options`, which (a) blocked shop managers who can configure every other WooCommerce shipping/fee setting, (b) contradicted the plugin's own "delivery is not configured" warning (shown to `manage_woocommerce` users, linking to a page they couldn't open), and (c) didn't match the documented "WooCommerce → Settings → RouteMile" location. Saving is gated consistently (`manage_woocommerce` on `register_setting` + the WC settings nonce)
- **Admin-bar "Deliveries: Open/Closed" toggle now works for shop managers** — it was displayed to admins *or* shop managers but the AJAX action accepted admins only, so shop managers saw the toggle and got "Unauthorized access" on click. The action now matches the display capability
- The configuration warning's settings link now points to the new tab URL

## [1.2.9] - 2026-08-17

### Added
- **Block-based Checkout support** (`ROUTEW_Blocks_Checkout`, built entirely on documented extension points, verified against current WooCommerce docs):
  - The three delivery fields register via the **Additional Checkout Fields API** (WC 8.9+; older stores keep full classic-checkout support): required *House / Flat / Building No.* (`routemile/address-details`, address section), optional *Landmark* (address), optional *Delivery Instructions* (order section)
  - The **map picker renders above the Checkout block** — same Step-1 markup as classic checkout (`ROUTEW_Checkout::render_location_picker()`), same `checkout.js`, same REST zone validation
  - **Block orders persist identical data to classic orders** through the shared `ROUTEW_Checkout_Handler::apply_delivery_data_to_order()` via `woocommerce_store_api_checkout_update_order_from_request` — same `_routew_*` meta, composed address, address second line, coordinates, distance, country defaults, and delivery-profile auto-save
  - Zone enforcement on blocks: the required field is validated through `woocommerce_validate_additional_field`, running the same store-open / pin-present / radius check as classic checkout (`get_zone_error()`)
  - `cart_checkout_blocks` compatibility now declared **true**; the v1.2.1 blocks warning is removed (real support replaced it)
  - Known v1 limitation: after dropping a pin, the blocks shipping total refreshes on the next checkout interaction rather than instantly (no build step → no React slotFill); order-time enforcement is unaffected

### Changed
- **`ROUTEW_Dashboard` split into three files** (593 → 137/365/278 LOC, all under the 500 cap, logic unchanged): orchestrator keeps `ROUTEW_Dashboard`; rendering → `ROUTEW_Dashboard_Render`; write handlers → `ROUTEW_Dashboard_Actions`
- **Two real bugs fixed during the split**: admin *Print Receipt* buttons always failed their nonce check (dashboard created `routew_nonce`, handler verifies `routew_print_receipt`), and dashboard order links were empty under HPOS (`get_edit_post_link()` returns null — now falls back to the HPOS admin URL)
- New `docs/QA-CHECKLIST.md` — the full pre-production runtime pass (configuration + classic/blocks checkout + operations + privacy), with expected outcomes per step

## [1.2.8] - 2026-08-17

### Changed (author credits)
- **Author/developer credits standardized across the project**: plugin header `Author URI` now points to the developer's site ([millat.is-a.dev](https://millat.is-a.dev/)), the `@author` docblock in all 22 PHP files carries the same link, and the README author section lists both the website and GitHub ([@codermillat](https://github.com/codermillat))

## [1.2.7] - 2026-08-17

### Changed (general open-source release — no client/region specifics)
- **Region-neutral defaults everywhere.** The plugin is a general open-source release for any restaurant worldwide:
  - Map fallback center is no longer hard-coded to a specific city — the initial viewport now picks the saved pin (zoom 15) → restaurant zone (zoom 11) → neutral world view (zoom 2)
  - Place autocomplete no longer biases searches to a specific country or language — results follow the user's own language and query anywhere in the world
  - Currency fallback is no longer a specific currency symbol; it comes from WooCommerce store settings
  - Settings example coordinates replaced with a neutral example
- **Removed the client reference in AGENTS.md** — the project is documented as a general open-source release, not tied to any specific restaurant or client

## [1.2.6] - 2026-08-17

### Added (production-readiness)
- **Privacy integration (`ROUTEW_Privacy`)** — RouteMile personal data now flows through the official WordPress/WooCommerce privacy frameworks, using only documented hooks: the saved delivery profile (pin coordinates, exact address, landmark, instructions) is included in **Tools → Export/Erase Personal Data** via `wp_privacy_personal_data_exporters`/`_erasers`, and order meta (current + pre-1.2.2 legacy address fields, coordinates) joins WooCommerce's own order export via `woocommerce_privacy_export_order_personal_data` and is removed on order anonymization via `woocommerce_privacy_before_remove_order_personal_data`
- **"Delivery not configured" admin warning** — persistent notice for shop managers when the Google Maps API key or the restaurant location (coordinates or address) is missing, with a direct settings link; cheap checks only, no API calls on admin renders
- **Translation template** — `languages/routemile.pot` with all 271 translatable strings (verified against the WooCommerce privacy-framework documentation before implementation)

## [1.2.5] - 2026-08-17

### Removed (dead code — full-project audit, every item verified caller-free)
- **3 orphaned AJAX endpoints** (registered in PHP, called by no JS file, template, or inline script): `routew_update_customer_location` (superseded by the REST `validate-location` route in v1.2.3), `routew_get_restaurant_location` (restaurant center is now localized server-side), `routew_debug_status` (debug-only, no caller)
- **6 localized params nothing reads** (`ajax_url`, `nonce`, `debug`, `prep_time`, `max_retries`, `retry_delay` in `routew_checkout_params`) plus the never-read `store_closed` translation key; `ROUTEW_Config::MAX_RETRIES` / `RETRY_DELAY` constants existed only to feed two of those params and are gone too
- Dead markup/CSS/JS: the never-written `#routew-geolocation-error` div + its CSS rules, and the unused `checkoutForm` element cache in `checkout.js`

### Fixed (docs)
- Plugin header `Plugin URI` corrected to the real repo casing (`RouteMile-for-WooCommerce`)
- AGENTS.md refreshed: current-version line (was stale at v1.2.1), class map no longer describes the removed endpoints, coding-standard rule numbering fixed (duplicate "3"), `DOCKER_SETUP.md` drops its stale TestSprite reference
- `ROUTEW_Address_Validator` intentionally retained (documented for the Phase 7 multi-outlet editor)

## [1.2.4] - 2026-08-17

### Fixed (WooCommerce-compatibility hardening, verified against current docs)
- **Default the customer's billing/shipping country to the store base country via `WC_Customer` CRUD** when empty on the checkout page. Since v1.2.3 removed the country select, WooCommerce's own posted-data/tax/payment-gateway pipeline could otherwise run without a country; seeding the customer object feeds every downstream step through the official flow (the `woocommerce_checkout_create_order` fallback from v1.2.3 stays as a second belt)
- Compliance audit of the v1.2.3 changes against current WooCommerce documentation (field removal via `woocommerce_checkout_fields` unset, order data via `woocommerce_checkout_create_order` + `$order->set_*`, Settings API, REST controller): all patterns confirmed documented; zero direct database access anywhere in the plugin (no `$wpdb` usage at all — order data flows exclusively through HPOS CRUD)

## [1.2.3] - 2026-08-17

### Changed (coordinates-only delivery engine)
- **The fee and zone checks now run on coordinates only — restaurant lat/lng ↔ customer pin lat/lng.** New optional setting *Restaurant Coordinates (lat, lng)*; when unset, the restaurant address is geocoded once and cached (24 h). All three distance call sites (shipping method, checkout validation, REST) share the new `ROUTEW_Mapping_Service::get_restaurant_location()` helper. **No customer-entered field can influence the fee.**
- **Google Maps supplies coordinates only.** `checkout.js` no longer reverse-geocodes into checkout fields and no longer pushes addresses to the WC Store API (`fillWCFields` / `pushToStoreAPI` removed). The reverse geocode feeds the read-only "Selected Location" caption under the map — it never fills an input.
- **WC billing/shipping address fields (address, city, state, postcode, country) are removed from checkout entirely** (unset, not hidden). Orders default their country to the store base country; the exact-address line remains on `address_2` for receipts/emails.
- **Delivery-radius circle** drawn on the map around the restaurant (subtle green overlay) so customers can see the selectable zone; pins outside it get the immediate out-of-zone error and Place Order stays blocked server-side. Map default center is now the restaurant (saved pin still wins).

### Changed (saved address = the default)
- **Returning customers no longer re-pin or re-type.** The delivery profile (pin lat/lng, exact address, landmark, instructions) is auto-saved for logged-in customers on every order — the opt-in checkbox is gone — and auto-fills the map, the fields, and the session on the next checkout. Everything stays editable.

### Removed
- Dead address-based fallback-geocode branches in `calculate_shipping()` and `validate_delivery_zone()` (unreachable once WC address fields are gone), `.routew-hidden-field` CSS, and the `fetchRestaurantCenter()` stub

## [1.2.2] - 2026-08-17

### Changed (Zomato/Swiggy-style checkout address)
- **Step 2 collapsed from five structured fields to a single required "House / Flat / Building No." field** (`routew_address_details`) + optional landmark + the existing optional delivery-instructions textarea — mirroring Swiggy's "Complete address*" + "Landmark (optional)" model next to the map pin. The fee engine is unchanged (it runs on the pin's coordinates only); `checkout.js` needed zero changes
- Order meta now stores `_routew_address_details` + `_routew_landmark`; `_routew_delivery_address` is composed as `details (Landmark: …)` so the admin meta box and delivery dashboard keep working unmodified
- The exact-address line is also written to billing/shipping `address_2`, so receipts and emails using `get_formatted_shipping_address()` now show the flat/house info (previously checkout-entered details never reached the receipt)
- Validation: one required check on `routew_address_details` (min 5 chars) replaces the two old required-field checks

### Fixed
- **"Save this address for future orders" now actually works.** The old save gate required POST keys that no form ever submitted (`routew_location-search-input` had no `name` attribute; `routew_delivery_address` was never rendered), so `_routew_delivery_profile` was never written and the returning-customer pre-fill (map pin + fields) was dead. The profile is now saved whenever the checkbox is ticked, including `address_details` + `landmark` for pre-fill

### Removed
- ~290 lines of dead v1 CSS (`.routew-address-*` feedback/suggestion/score styles, `#routew_delivery_address` rules) with no remaining PHP/JS references; live map/hidden-field/geolocation-error/mobile styles preserved
- Checkout no longer writes the obsolete `_routew_house_flat_no` / `_routew_floor_no` / `_routew_society_building` / `_routew_block_tower_area` metas to new orders (existing orders keep theirs)

## [1.2.1] - 2026-08-17

### License
- **Relicensed from proprietary to GPL-3.0-or-later** (matching the WooCommerce ecosystem), full text in `LICENSE.md`; plugin header now carries `License: GPL-3.0-or-later` + License URI. The repository is public as of this release. All future contributions are accepted under the same license (see `CONTRIBUTING.md`).

### Added
- **Blocks-checkout admin warning** (`ROUTEW_Core::warn_blocks_checkout`) — when the WooCommerce Checkout *block* is active on the checkout page, shop managers now see a persistent notice explaining that the RouteMile map picker, zone validation and distance fee only run on the classic `[woocommerce_checkout]` shortcode, with a direct edit link. The `cart_checkout_blocks` compatibility declaration is informational only and never forced the classic checkout, so previously a blocks store silently lost all delivery logic.

### Changed (performance)
- **Google Maps API responses are now cached in transients** (`ROUTEW_Mapping_Service`):
  - `get_distance()` results cached 30 minutes, keyed on origin/destination with coordinates rounded to 4 decimals (~11 m)
  - `get_coords()` geocode results cached 24 hours, keyed on the address
  - `calculate_shipping()` runs on every cart/checkout render — previously each run made a live, uncached Distance Matrix call (plus a fallback Geocode call), burning API quota and adding latency on every address change; only successful results are cached, errors still hit the API on retry

### Fixed
- `wp_redirect()` → `wp_safe_redirect()` in `ROUTEW_Core::handle_delivery_dashboard_access` (AGENTS.md §5 rule 10 violation)
- `in_array()` role checks in `ROUTEW_Core::disable_admin_bar` now use strict comparison (AGENTS.md §5 rule 6)

### Hygiene
- `class-routew-shipping-method.php` `calculate_shipping()` reindented to consistent tabs (the fallback-geocode block was pasted in with mixed spaces); one redundant duplicate `get_option('routew_settings')` call removed — behavior identical
- Header bumps: `Tested up to: 7.0`, `WC tested up to: 11.0` (static analysis + code review against current docs; runtime verification against these versions lands with the Phase 8 CI matrix)
- `.gitignore` now excludes `.mimosa/` (security-scan tool output)

## [1.2.0] - 2026-08-17

### Changed (refactor & hygiene)
- **Split `class-routew-checkout.php` (1,046 LOC) into 3 single-purpose files**
  - `class-routew-checkout.php` (233 LOC) — orchestrator: renders the checkout form, hides default WC fields, pre-fills saved address
  - `class-routew-checkout-maps.php` (299 LOC) — frontend map assets + `get_restaurant_location` AJAX + admin debug status
  - `class-routew-checkout-handler.php` (469 LOC) — server-side: location update, zone validation, address save, order meta
- **Extracted two reusable services** to keep all classes under 500 LOC:
  - `includes/services/class-routew-delivery-fee.php` — cart-fee + shipping-label ETA hooks (`add_delivery_fee`, `append_eta_to_label`)
  - `includes/services/class-routew-address-validator.php` — stateless `validate_address_completeness` heuristic (previously dead private code; preserved for Phase 7 multi-outlet editor)
- **Comprehensive `.gitignore`** replacing the 4-line `.DS_Store`-only file: OS, editors/IDE, AI tool configs (`.agent/`, `.claude/`, `.cursorrules`, etc.), PHP/Composer/Psalm/PHPStan, logs, build artifacts, TestSprite/Playwright outputs, env/secrets
- **Added `LICENSE.md`** — proprietary license text matching the plugin header, with third-party-component carve-out for WordPress/WooCommerce (GPL-2.0+)
- **Test runner updated** to verify the new files exist and that all four checkout files have proper nonce verification

### Removed (bloat cleanup)
- 9 AI-tool config folders (`.agent/`, `.agents/`, `.claude/`, `.cline/`, `.codex/`, `.cursor/`, `.gemini/`, `.kiro/`, `.vscode/`)
- AI-tool dev docs at the repo root (`.cursorrules`, `CLAUDE.md`, `AGENTS.md`, `.github/copilot-instructions.md`)
- Vim swap file (`.CLAUDE.md.swp`)

### Impact
- The largest class in the codebase is now `ROUTEW_Dashboard` (593 LOC) — every class stays under the 500-LOC ceiling except this single legacy file, which is queued for the same split treatment in a later refactor pass
- GitHub language detection switches from "Python" (driven by `.agent/skills/*/scripts/*.py` files) to "PHP" — the repo is now accurately classified
- No runtime behavior change. All hooks, AJAX endpoints, and class names are preserved
- The orchestrator file (`class-routew-checkout.php`) keeps its name and call site, so external code referencing it continues to work without modification

## [1.1.0] - 2024-12-20

### Added
- **WooCommerce Email Classes**: Customizable email templates via WooCommerce Settings
  - Order In Kitchen email
  - Driver Assigned email
  - Order Picked Up email
- **AJAX Admin Dashboard**: No page reloads for order management
  - AJAX delivery assignment
  - AJAX status updates
  - Loading states and toast notifications
- **Unit Test Suite**: 86 automated tests for code quality

### Changed
- Replaced `wp_mail()` with proper `WC_Email` classes
- Improved rate limiter with IP address hashing for privacy
- Enhanced security with strict IP validation (REMOTE_ADDR only)

### Fixed
- Removed duplicate `wc_get_order()` calls in order admin
- Removed console.log statements from production JavaScript
- Fixed orphaned orders appearing in wrong dashboard sections

### Security
- All AJAX endpoints now verify nonces and capabilities
- Input sanitization and output escaping verified across all files
- HPOS (High-Performance Order Storage) fully compatible

## [1.0.1] - 2024-12-15

### Added
- Receipt branding settings (logo, restaurant name, tagline)
- Custom footer message for receipts
- Delivery radius validation on checkout

### Fixed
- Google Maps callback race condition
- Session data persistence for coordinates
- Delivery fee calculation edge cases

## [1.0.0] - 2024-12-01

### Added
- Initial release
- Custom order statuses for delivery workflow
- Delivery boy user role
- Google Maps integration for location selection
- Distance-based delivery fee calculation
- Order tracking shortcode
- Reorder functionality
- Receipt printing with thermal printer support
- Admin dashboard for delivery management
