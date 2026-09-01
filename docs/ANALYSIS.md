# Three-Repo Analysis: codermillat Restaurant Delivery Plugin

**Author:** Mavis (Mavis)
**Date:** 2026-08-16
**Repos inspected (all PRIVATE, GitHub CLI authenticated as `codermillat`):**

| Repo | Created | Last push | Default branch | Lang (GitHub) | Plugin main | Size |
|---|---|---|---|---|---|---|
| `restaurant-delivery-manager` (RDM) | 2025-06-07 | 2025-08-16 | `main` | PHP | 95 lines | 2.9 MB |
| `RestroReach` (RR) | 2025-07-04 | 2025-07-17 | `main` | PHP | 1057 lines | 2.9 MB |
| `RouteMile-for-WooCommerce` (FX) | 2025-08-31 | **2026-08-14** | `main` | "Python" (mis-tag) — actually PHP | 143 lines | 16 MB |

> All three are the **same product**, three different rebrand/refactor passes. RDM → RR → FX is the evolution.

---

## TL;DR — Recommendation

**Keep `RouteMile-for-WooCommerce`** as the canonical repo. **Archive** the other two.

| Decision | Repo | Reason |
|---|---|---|
| ✅ KEEP | `RouteMile-for-WooCommerce` | Cleanest, most active (last push today-2 days), modern structure, HPOS-compatible, active dev. |
| 🗄️ ARCHIVE | `RestroReach` | Most feature-rich but frozen at v1.1.0 / 85% complete. Keep accessible for code archaeology. |
| 🗄️ ARCHIVE | `restaurant-delivery-manager` | Oldest, has duplicate classes and 32K messy LOC. Only useful as the v1 baseline. |

The right consolidation is **FX as the destination**, with FX receiving a "premium features backport" from RR (PWA, mobile agent, GPS, payments, analytics, COD reconciliation) over time. RDM can stay archived as a read-only fallback.

---

## 1. Repository profiles

### 1.1 Source-code scale (excluding vendor / WordPress / testsprite artifacts)

| | RDM | RR | FX |
|---|---|---|---|
| PHP source LOC | 33,697 | 20,370 | **5,170** |
| JS source LOC | 1,667 | 412 | 938 |
| CSS source LOC | 519 | 519 | 2,117 |
| PHP files in `includes/` | 32 (RDM_*) + 5 (legacy unprefixed) | 19 | 14 (`routew_*` only) |
| Plugin main file | 95 lines (slim) | 1057 lines (monolithic) | 143 lines (slim) |
| Classes > 1500 LOC | 7 | 5 | 0 |
| `includes/` total LOC | 32,284 | 19,010 | 3,973 |
| Templates | 15 | 13 | 3 |
| Email templates | 0 | 0 | 3 |
| Dedicated `services/` dir | ✗ | ✗ | ✅ |
| Dedicated `api/` dir | ✗ | ✗ | ✅ |
| Service worker / PWA | ✅ | ✅ | ✗ |
| Database tables | 7 | 7 | 0 (uses WC orders only) |
| Tests directory | 0 | 7 files | 1 (FXWTestRunner.php static analysis only) |
| Custom DB tables | 7 (`rr_` prefix) | 7 (`rr_` prefix) | 0 |
| API endpoints | 50+ | 50+ | ~6 |
| GitHub open issues | 0 | 2 (#1, #3 — Copilot review) | 0 |
| Commits | 1 | 7 | 11 |
| Last commit | 2025-08-16 | 2025-07-17 | 2026-08-14 (most recent) |
| Author of last commit | `RestroReach` | `MD MILLAT HOSEN` | `MD MILLAT HOSEN` |
| Top-level AI-tool config dirs | 0 | 1 (`.cursor`) | 9 (`.agent`, `.agents`, `.claude`, `.cline`, `.codex`, `.cursor`, `.gemini`, `.kiro`, `.vscode`) |
| License header | GPL-2.0-or-later | GPL v2 or later | **Proprietary** |

### 1.2 Plugin header (top-of-file metadata)

| Field | RDM | RR | FX |
|---|---|---|---|
| Plugin Name | Restaurant Delivery Manager | Restaurant Delivery Manager Professional | **RouteMile for WooCommerce** |
| Version | 1.1.0 | 1.1.0 | 1.1.0 |
| Text Domain | `restaurant-delivery-manager` | `restaurant-delivery-manager` | **`routemile`** |
| Prefix | `RDM_` (mixed) | `RDM_` (mostly clean) | **`ROUTEW_` (consistent)** |
| Min PHP | 8.0 | 8.0 | 7.4 (lower — broader compat) |
| Min WP | 6.0 | 6.0 | 6.0 |
| Min WC | 8.0 | 8.0 | 7.0 (lower — broader compat) |
| WC tested up to | 8.5 | 9.2 | **9.4** |
| HPOS compat declared | Yes | Yes | Yes |
| Cart-checkout-blocks compat | No | No | **Yes (false — opt-out)** |

### 1.3 File tree (top-level only — abridged)

```
RDM/
├── restaurant-delivery-manager.php  (95 lines, slim)
├── rdm-manifest.json, rdm-debug-setup.php, rdm-service-worker.js
├── index.php (6008 bytes), readme.txt, build.sh, build.js
├── includes/   32,284 LOC, 32+5 classes
│   ├── class-rdm-*.php (32)
│   └── class-*.php  (5: database, customer-tracking, payments, api-endpoints, distance-shipping, notifications, analytics, security-utilities, error-handling, location-utilities, user-roles, woocommerce-integration, performance-optimization-integration)
├── admin/   assets/   templates/{admin, mobile, mobile-agent, customer-tracking.php, offline.html}
└── tests/   ✗ none

RR/
├── restaurant-delivery-manager.php  (1057 lines, monolithic)
├── readme.txt, build.sh, manifest.json, package.json
├── .cursorrules (12 KB), .github/, cookies.txt ⚠
├── includes/   19,010 LOC, 19 classes (all clean RDM_*)
│   └── class-rdm-{admin-interface, admin-tools, api-endpoints, database-tools, google-maps, gps-tracking, mobile-frontend, payments} + class-{analytics, customer-tracking, database, database-utilities, distance-shipping, error-handling, location-utilities, notifications, payments, security-utilities, user-roles, woocommerce-integration}
├── admin/{css,js,partials}/   assets/{css,images,js,sounds}/   templates/{admin, mobile, mobile-agent, customer-tracking.php, offline.html}
├── tests/   7 files
└── markdown/   docs

FX/
├── routemile-woocommerce.php  (143 lines, slim bootstrap)
├── uninstall.php
├── includes/   3,973 LOC, 14 classes (all clean routew_*)
│   ├── class-routew-{admin-bar, checkout, config, core, dashboard, delivery-boy-view, notifications, order-admin, order-statuses, reporting, roles, settings, shipping-method, shortcodes}.php
│   ├── api/class-routew-rest-checkout-controller.php
│   ├── services/class-routew-{mapping-service, rate-limiter}.php
│   └── emails/class-routew-email-{assigned, in-kitchen, picked-up}.php
├── templates/   3 files
│   ├── delivery-dashboard-template.php, delivery-boy-view.php, receipt-template.php
│   └── emails/{routew-order-status.php, plain/}
├── assets/{css, js}/    (small — 92 KB total)
├── tests/   static analyzer (FXWTestRunner.php) + TestSprite config
├── skills/{wordpress-pro, wordpress-advanced-architecture}/  ← WordPress development knowledge packs
└── docker-compose*.yml, start-wordpress.sh, DOCKER_SETUP.md, TESTSPRITE_*.md, LANDING-PAGE.md (33 KB), PRODUCT_SPEC.md (8 KB)
```

---

## 2. Feature comparison

✅ implemented · ✗ missing · 🟡 partial

| Feature | RDM | RR | FX |
|---|:-:|:-:|:-:|
| **Core delivery flow** | | | |
| Custom order statuses (In Kitchen → Assigned → Picked Up → Delivered) | ✅ | ✅ | ✅ |
| Delivery Boy user role + dashboard at `/delivery-dashboard/` | ✅ | ✅ | ✅ |
| Order assignment from admin | ✅ | ✅ | ✅ |
| Customer order tracking | ✅ | ✅ | ✅ |
| **Location & distance** | | | |
| Google Maps JavaScript + Geocoding + Distance Matrix | ✅ | ✅ | ✅ |
| Distance-based delivery fee (base + per-km) | ✅ | ✅ | ✅ |
| Delivery radius validation at checkout | ✅ | ✅ | ✅ |
| Google Maps API caching (24h geocoding / 7d coords) | ✅ | ✅ | ✗ |
| **Checkout / shipping** | | | |
| Custom WooCommerce shipping method | ✅ | ✅ | ✅ |
| Address geocoding at checkout | ✅ | ✅ | ✅ |
| Rate limiter (FX-only) | ✗ | ✗ | ✅ |
| REST API for checkout | ✗ | ✗ | ✅ |
| **Customer notifications** | | | |
| Email: Order In Kitchen | ✗ | ✗ | ✅ |
| Email: Driver Assigned | ✗ | ✗ | ✅ |
| Email: Order Picked Up | ✗ | ✗ | ✅ |
| Multi-channel (SMS/Push) | 🟡 | ✅ | ✗ |
| **Admin / dashboard** | | | |
| Admin dashboard with order list | ✅ | ✅ | ✅ |
| Receipt printing (thermal) | ✅ | ✅ | ✅ |
| Reporting & analytics dashboard | ✅ | ✅ | 🟡 basic only |
| Business intelligence (revenue, agent perf, customer behavior) | 🟡 | ✅ | ✗ |
| Automated email reports (daily/weekly/monthly) | 🟡 | ✅ | ✗ |
| **Mobile agent PWA** | | | |
| Service worker (offline) | ✅ | ✅ | ✗ |
| PWA manifest | ✅ | ✅ | ✗ |
| GPS tracking (battery-optimized 45s interval) | ✅ | ✅ | ✗ |
| Photo upload on delivery | ✅ | ✅ | ✗ |
| Touch-optimized agent UI | ✅ | ✅ | ✗ |
| **Payments (COD focus)** | | | |
| COD workflow management | ✅ | ✅ | ✗ |
| Cash reconciliation with variance reporting | ✅ | ✅ | ✗ |
| Daily/weekly financial reports | ✅ | ✅ | ✗ |
| Payment audit trail | ✅ | ✅ | ✗ |
| **Agent management** | | | |
| Agent profile + performance metrics | ✅ | ✅ | ✗ |
| GPS location tracking with privacy controls | ✅ | ✅ | ✗ |
| Agent availability endpoint | ✅ | ✅ | ✗ |
| **Customer tracking** | | | |
| Real-time order tracking page | ✅ | ✅ | ✅ |
| Live agent location on map | ✅ | ✅ | ✗ |
| ETA calculation | ✅ | ✅ | 🟡 |
| **Shortcodes** | | | |
| `[routew_track_order]` / `[rdm_track_order]` | ✅ | ✅ | ✅ |
| `[routew_reorder]` / `[rdm_reorder]` | ✗ | ✗ | ✅ |
| **WordPress / WC integration** | | | |
| HPOS (custom-order-tables) compat | ✅ | ✅ | ✅ |
| Cart-checkout-blocks compat declared | ✗ | ✗ | ✅ (opt-out) |
| WC 9.4 tested | ✗ | ✗ | ✅ |
| Min PHP 7.4 (broader) | ✗ | ✗ | ✅ |
| Min WC 7.0 (broader) | ✗ | ✗ | ✅ |
| **Operational** | | | |
| Performance monitor / cache / asset optimizer | ✅ | ✗ | ✗ |
| GitHub error reporter (dev artifact) | ✅ | ✗ | ✗ |
| Site health integration | ✅ | ✗ | ✗ |
| Static-analysis test runner (PHP lint + security) | ✗ | ✗ | ✅ |
| TestSprite MCP integration (auto E2E) | ✗ | ✗ | ✅ |
| Docker dev environment (WordPress + WC + plugin) | ✗ | ✗ | ✅ |
| Two development skills (`wordpress-pro`, `wordpress-advanced-architecture`) | ✗ | ✗ | ✅ |

**Summary:**

- **FX** = clean baseline + modern WP/WC + DX tooling + smart shipping extras (rate limit, REST API), but **missing the entire premium feature layer** (PWA, mobile agent, payments, analytics, multi-channel notifications).
- **RR** = most feature-complete, but code is monolithic, ships `cookies.txt`, and dev halted at v1.1.0.
- **RDM** = the v1 baseline + the most code, but has the worst hygiene (duplicate classes, naming chaos, biggest files).

---

## 3. Issues per repo

### 3.1 `restaurant-delivery-manager` (RDM) — 🗄️ archive

**Critical:**

1. **Duplicate class files with conflicting names.**
   - `class-payments.php` (2220 LOC) **and** `class-rdm-payments.php` (561 LOC)
   - `class-api-endpoints.php` (923 LOC) **and** `class-rdm-api-endpoints.php` (1165 LOC)
   - `class-rdm-admin-interface.php` (584 LOC) **and** `class-rdm-admin-interface-clean.php` (2728 LOC)
   - Unclear which is loaded; risk of class redeclaration fatal errors.
2. **Inconsistent class naming convention.** Mix of `class-Foo.php` and `class-rdm-foo.php` for the same domain (database, payments, api-endpoints, notifications, etc.).
3. **Singleton method inconsistencies** (per RR's own CODEBASE_DISCREPANCIES_REPORT): some classes use `instance()`, others `get_instance()`. Calls between them crash.
4. **Hardcoded asset paths** in places instead of `RDM_PLUGIN_URL` constant (also per its sibling repo's report).
5. **Mega-files that violate SRP:**
   - `class-database.php` — 2964 LOC
   - `class-rdm-admin-interface-clean.php` — 2728 LOC
   - `class-rdm-performance-utilities.php` — 2535 LOC
   - `class-rdm-google-maps.php` — 1595 LOC
   - `class-rdm-security-config.php` — 527 LOC (also `class-rdm-database-security.php` — 527 LOC, possible duplicate)
6. **No README.md, no test suite, only 1 commit.** No CI, no version history, no contribution path.

**Notable:** the `index.php` is 6008 bytes (intentionally non-empty — possible security-by-obscurity anti-pattern) and there's an `rdm-debug-setup.php` (10KB) checked in — looks like a dev artifact.

### 3.2 `RestroReach` (RR) — 🗄️ archive

**Critical:**

1. **`cookies.txt` committed to the repo root.** Contains session cookies — should never be in version control. The repo is private, so impact is low, but it's a footgun.
2. **2 open issues:** #1 and #3 — both titled *"Review and Fix Codebase Using Copilot"*. No responses since opened.

**High:**

3. **Monolithic main plugin file.** `restaurant-delivery-manager.php` is 1057 lines (vs. FX's 143). Puts bootstrap, init, and most business logic in one place.
4. **Mega-classes that will be hard to test:**
   - `class-database.php` — 2510 LOC
   - `class-payments.php` — 2178 LOC
   - `class-rdm-admin-interface.php` — 2169 LOC
   - `class-notifications.php` — 1903 LOC
5. **README claims 85–90% complete but dev stopped 2025-07-17** with two known incomplete areas: PWA push notifications, advanced ML predictions, "final optimization and documentation."
6. **Codebase_Discrepancies_Report itself admits** "26 total issues found (4 critical fixed, 22 non-critical pending)" — only the easy ones got fixed.

**Medium:**

7. **Many `.md` status reports** (PRODUCTION_HARDENING_COMPLETE, FINAL_COMPLETION_REPORT_v1.1.0, RELEASE_NOTES_v1.1.0, CODEBASE_DISCREPANCIES_REPORT, COMPLETION_SUMMARY, STATUS, CURSOR_RULES_BEST_PRACTICES) — useful archaeology, but bloats the repo and confuses readers about which doc is current.
8. **No CHANGELOG.md**, only `RELEASE_NOTES_v1.1.0.md`.
9. **`package.json` present but no `node_modules` / no `composer.json`** — unclear what runtime it serves.

**Notable:** The `.cursorrules` file is 12 KB — extensive, well-curated Cursor rules (a model of what good AI-dev-rules look like). Worth porting into FX.

### 3.3 `RouteMile-for-WooCommerce` (FX) — ✅ keep

**Critical:**

1. **Repo size bloat.** 16 MB on disk is fine, but the `testsprite_tests/tmp/` directory looks like generated artifacts; `.gitignore` should exclude it. (Sample shows only 36 KB currently — low risk but monitor.)
2. **Multiple AI-tool config folders committed.** `.agent/`, `.agents/`, `.claude/`, `.cline/`, `.codex/`, `.cursor/`, `.gemini/`, `.kiro/`, `.vscode/` all at the repo root. Each adds noise and some may include user-specific settings. Should be gitignored or relocated to `.config/`.
3. **`.CLAUDE.md.swp` (12 KB) committed** — vim swap file, should be in `.gitignore`.
4. **GitHub language mis-tagged as "Python"** — actually pure PHP. Cosmetic but affects discoverability.

**High:**

5. **Feature gap vs. RR/RDM.** Missing the entire premium layer: PWA / mobile-agent / GPS / COD reconciliation / analytics / multi-channel notifications / BI / automated email reports. FX today is roughly the **MVP tier** of what RR ships.
6. **String "RestroReach" appears in legacy code paths** (verified: zero matches in the fresh clone, but the commit history of FX shows the rename wasn't 100% clean — the readme of the old local Desktop copy at `~/Desktop/RouteMile for WooCommerce/` still had it).
7. **No real test coverage** — only `FXWTestRunner.php` static analysis. The TestSprite setup is documented but not run anywhere.
8. **License says "Proprietary" in plugin header** but `LICENSE.md` not present in repo (only inferred from header). Decide explicitly.

**Medium:**

9. **Two `delivery-dashboard-template.php` and `delivery-boy-view.php` templates** — could be one.
10. **`includes/class-routew-checkout.php` is 1046 LOC** — biggest FX class, but well within reason (vs. RDM's 2700+ LOC admin interface).
11. **`routemile-landing` is a separate sibling project** at `~/Desktop/RouteMile/routemile-landing/` (not in the repo) — landing page lives outside source control. Not a problem per se, but the repo's `LANDING-PAGE.md` (33 KB) reads as a spec for an external site.

**Notable / positive:**

12. ✅ **Cleanest file structure** of the three. Service classes and REST API controllers live in their own `includes/services/` and `includes/api/` folders.
13. ✅ **HPOS-compatible AND opt-out of cart-checkout-blocks**, which is the correct combo for a shipping-method plugin.
14. ✅ **Modern minimums** (PHP 7.4 / WC 7.0) — broader install base than the 8.0/8.0 floors in RDM and RR.
15. ✅ **Slim 143-line bootstrap** that lazy-loads classes contextually (admin vs frontend vs AJAX).
16. ✅ **Test runner** for static analysis + ABSPATH/nonce/syntax checks — quick CI guard.
17. ✅ **Two included skills** (`wordpress-pro`, `wordpress-advanced-architecture`) — WordPress dev knowledge packs.
18. ✅ **Docker dev environment** with `start-wordpress.sh` for spinning up a clean WP+WC+plugin stack.

---

## 4. Consolidation plan

### 4.1 Today (read-only / non-destructive)

```bash
# 1. On GitHub, mark RDM and RR as archived
gh repo edit codermillat/restaurant-delivery-manager --archive
gh repo edit codermillat/RestroReach --archive

# 2. Clone FX as the active working repo
gh repo clone codermillat/RouteMile-for-WooCommerce

# 3. From RR, copy the valuable artifacts that FX needs (NOT the code yet)
#    - .cursorrules → merge into FX's .cursorrules
#    - markdown/{PROJECT_OVERVIEW, FEATURE_SPECIFICATIONS, API_REFERENCE}.md → FX/docs/
#    - CODEBASE_DISCREPANCIES_REPORT.md → FX/docs/ (lessons learned)
```

### 4.2 Short term (1–2 weeks)

| Task | Source | Target |
|---|---|---|
| Cleanup `.gitignore` | — | FX: exclude `.CLAUDE.md.swp`, `.agent*`, `.claude*`, `.cline*`, `.codex*`, `.cursor/`, `.gemini/`, `.kiro/`, `.vscode/`, `testsprite_tests/tmp/` |
| Remove committed AI-config folders | — | FX |
| Add `LICENSE.md` | new | FX |
| Set GitHub repo language to PHP | new | FX (Settings → Languages → add PHP, drop Python) |
| Add a CHANGELOG.md | new | FX (mirror RR's release-notes content) |
| Port `RR/.cursorrules` as baseline | RR | FX |
| Write a `docs/MIGRATION.md` for users coming from RDM / RR | new | FX |

### 4.3 Medium term (1–3 months) — port premium features into FX

Priority order, by user value and risk:

1. **Custom DB tables** (7 tables, `rr_` prefix → rename to `routew_`). Land migration scripts.
2. **Mobile agent PWA**: `manifest.json` + service worker + `templates/mobile-agent/`.
3. **GPS tracking** class + REST endpoint + 45s-interval battery optimization.
4. **COD payment workflow** + cash reconciliation (with audit trail table).
5. **Analytics & BI dashboard** (revenue, agent perf, customer behavior).
6. **Multi-channel notification** (email + admin bar + future SMS/Push).
7. **Automated email reports** (daily/weekly/monthly via WP-Cron).
8. **Photo upload on delivery** (already-classed feature in RR).
9. **Google Maps API caching layer** (24h geocoding, 7d coords).

**Architecture rule for the backport:** every new feature ships as its own `includes/services/<Name>.php` or `includes/api/<Name>Controller.php` (FX's current convention) — no more 2000-LOC god classes.

### 4.4 Long term

- Once parity is reached, FX v2.0.0 can ship with the full premium layer, and RDM / RR can be safely deleted from GitHub.
- The two reference skills (`wordpress-pro`, `wordpress-advanced-architecture`) should be expanded with a third: `restaurant-delivery-domain` (capturing the recurring patterns: order-status flow, COD audit, mobile agent UX).

---

## 5. Quick answers to the implicit questions

- **"Which is the most production-ready?"** None fully. RR is closest to "complete" (85% per its own claim) but has the monolithic main file and `cookies.txt`. FX is the cleanest and most actively maintained but ships only the MVP feature set.
- **"Which has the best code quality?"** FX by a wide margin — small classes, clear namespacing, REST-API split, no duplicates, slim bootstrap, real test runner.
- **"Which has the most features?"** RR > RDM > FX. PWA, mobile agent, COD reconciliation, BI, and multi-channel notifications only exist in RR/RDM.
- **"Which is actively developed?"** FX (last push 2026-08-14, 11 commits). RR and RDM have not been touched since Aug 2025.
- **"Can I delete two of them safely?"** Not yet — RR/RDM carry features FX doesn't have. Delete after the backport lands.
- **"What if I want to ship a paid tier?"** FX's `Proprietary` header is the right starting point. The premium features in RR become the natural upsell.

---

## 6. Status updates since this report

| Date | Event | Reference |
|---|---|---|
| 2026-08-16 | `RestroReach` and `restaurant-delivery-manager` archived on GitHub (recoverable, not deleted) | Session 140b19f-Δ |
| 2026-08-16 | Duplicate local working trees removed to `mavis-trash` (~378 MB): `~/Desktop/RouteMile*/` and `~/Local Sites/routemile-woocommerce/` | Session 140b19f-Δ |
| 2026-08-16 | This report + 8-phase backport roadmap committed to `fx/docs/` | commits `83e42ff`, `cbcdbe8` |
| 2026-08-17 | **Phase 0 complete** — `v1.2.0` shipped: checkout class split, 2 new services extracted, `.gitignore` rewritten, `LICENSE.md` added, 9 AI-tool config folders + 5 AI dev docs removed (~11 MB), 101/101 tests pass | commit `140b19f`, tag `v1.2.0`, [release](https://github.com/codermillat/RouteMile-for-WooCommerce/releases/tag/v1.2.0) |
| 2026-08-17 | New `AGENTS.md` at repo root so the next AI tool (Cursor, Codex, Claude Code, etc.) can pick up the project state without re-discovering it | commit (in this session) |

For the live phase tracker and full delivery plan, see [ROADMAP.md](./ROADMAP.md).

---

*End of report.*
