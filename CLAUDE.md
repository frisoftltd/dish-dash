# 🧠 Dish Dash — Session Context & Workflow

> **This file is the single source of truth for every AI coding session.**
> Read this ENTIRELY before doing any work.
>
> ⚠️ MANDATORY RULE: This file MUST be updated in the same commit as every
> version bump. The `Last updated` line must always match `DD_VERSION` in
> `dish-dash.php`. A release that ships code without updating this file
> is incomplete. No exceptions.
>
> Last updated: v3.10.50 (2026-07-14)

---

## 🍽 CORE MISSION (READ THIS FIRST)

**DishDash is a smart ordering system that learns customer behavior and makes ordering faster, easier, and more personalized every time.**

### Core Value Proposition

- Reduce time to order
- Increase repeat orders
- Increase cart value
- Learn user behavior continuously

### Product Identity

DishDash IS:
- ✅ An ordering system (not a generic website)
- ✅ Behavior-driven (every feature tracks data)
- ✅ AI-powered (subtle, invisible to users)
- ✅ A white-label SaaS platform — each restaurant feels like they own the system

DishDash is NOT:
- ❌ A basic WooCommerce storefront
- ❌ A visible "AI system" (users should feel fast/easy/natural, not "AI")
- ❌ A clone of Uber Eats or Glovo
- ❌ A generic WordPress admin — the backend must feel like a premium SaaS product

### Development Principles (STRICT)

1. **Every feature must answer:** "How does this help understand user behavior?"
2. **No feature without tracking.** Every user action must be recorded.
3. **AI is invisible.** Users feel fast, easy, natural — never "AI system."
4. **Mobile-first always.** Africa is mobile, not desktop.
5. **Keep architecture clean.** Follow the modular system. No shortcuts.
6. **Speed is addictive.** Optimize everything for performance. Fast = repeat usage.

### Architecture Flow
```
User
↓
UI (DishDash frontend)
↓
🧠 AI Layer (behavior tracking + rules engine)
↓
Cart
↓
WooCommerce (payment processing)
```

---

## 🗄️ Schema Changes — IMPORTANT

There is **one** installer file: `install.php` at the repo root. All `CREATE TABLE` definitions live there. `dishdash-core/class-dd-install.php` was deleted in v3.4.97 — it had been deprecated since v3.4.92 (renamed `DD_Install` → `DD_Schema_Upgrader`, no live schema declarations). The canonical installer is `install.php` exclusively.

### How to add a new table or column

1. Edit `install.php` — add the column to the `CREATE TABLE` block or append a new `CREATE TABLE` block inside `create_tables()`
2. Bump `DD_VERSION` in `dish-dash.php` (both the header comment and the constant)
3. Commit, tag, release as usual

**No WP-CLI step needed.** The auto-migration guard in `dish-dash.php` runs `dbDelta()` on the next admin page load after a version mismatch is detected, and updates `dd_db_version` to match.

### What auto-migration can and can't do

- ✅ Add new tables
- ✅ Add new columns to existing tables
- ✅ Add new indexes
- ❌ Drop columns (dbDelta limitation — never drops anything)
- ❌ Change column types in destructive ways (dbDelta is conservative)
- ❌ Rename columns (must be a manual ALTER TABLE via WP-CLI)

For drops/renames, use a manual migration step and document it in the release notes.

---

## 📌 Current State

| Field | Value |
|---|---|
| **Deployed version** | v3.10.50 |
| **Current phase** | Phase 7 — Role Cleanup & Access Control |
| **Current sub-phase** | Order Delivery Modes + Manual MoMo QR (6-release track, R1–R6 = v3.10.50–55) |
| **Next task** | Release 2 (v3.10.51) — gate the admin notification system (polling script enqueue + `ajax_poll_notifications()`) behind `dish_dash_order_notify_dashboard` (default '1', so existing installs unchanged). Then R3 WhatsApp handoff button (opt-in), R4 manual-MoMo up-front placement, R5 QR render, R6 single-tap "I have paid". PARKED (separate track): R4c customer_id attribution (conflict-order decision); write-path truncation (8 orders share +2507865340) |
| **Last working state** | v3.10.50 (Order Delivery Modes R1 — settings fields only, NO behavior change): added an "Order Handling" card to the Settings admin page (`admin/pages/settings.php`) with three new options following the `dd_fees_enabled` worked example (save handler → checkbox → markup). Save block after Pricing & Fees (:97+): `dish_dash_order_notify_dashboard` (`isset?'1':'0'`, default '1' — preserves current dashboard-notify behavior), `dish_dash_order_handoff_whatsapp` (`isset?'1':'0'`, default '0'), `dish_dash_momo_merchant_code` (text, digits-only via `preg_replace('/\D/','',…)`, no default). Two checkboxes use `.dd-check-label`/`checked()`; merchant code is a `dd-input--medium` numeric text field. Helper text per the brief. FIELDS PERSIST TO wp_options ONLY — nothing reads them for behavior yet (that starts R2: `dish_dash_order_notify_dashboard` gates the notification system; R3 reads handoff; R4/R5 read merchant code). No schema change, no gateway/order-flow/notification code touched. PREVIOUS (v3.10.49, R2 pill polish): list-row status pills are one brand color (`var(--brand, #65040d)`, white text) and no longer clip; `.dd-track__order-link` grid→flex (num ellipsizes, pill+timestamp `flex:0 0 auto`). Timeline + sidebar unchanged. PREVIOUS (v3.10.48, R2 polish): fixed the empty status pills + added the profile sidebar to the Track page (Option 2, isolated). PILLS: the pill was white text on `var(--dd-track-accent)`, whose fallback chain (`--dd-accent`/`--dd-brand`/`--dd-primary`) misses the frontend brand token `--brand` (those --dd-* live only in admin.css) and fell to `currentColor` = the pill's own white → white-on-white. Fixed order-tracking.css `--dd-track-accent: var(--brand, var(--dd-accent, var(--dd-brand, #65040d)))` (concrete hex end, never currentColor — also makes timeline dots/number/cancel badge brand-colored), and added color-by-status pills (`.dd-status--pending` #C77700 amber / `--confirmed` #2563EB blue / `--ready` #157A46 green, white text). SIDEBAR: `templates/orders/track.php` restructured — logged-in states (list/ok/notfound/empty) now render inside a `.dd-account` wrapper with the profile sidebar (nav built from `wc_get_account_menu_items()`, hrefs via `wc_get_account_endpoint_url()`, Track Order href resolved directly via `dd_track_url()` + marked `is-active`) and a `.dd-account__content` column holding the existing `.dd-track` card(s); the guest state stays a centered `.dd-track-wrap` message (no sidebar). Option 2 isolation: profile.css sidebar/layout selectors extended to also target `.dd-account` (no `.woocommerce-account` body class → no WC style bleed); profile.css now enqueued on the track page (shortcode_track list branch + render_single_track). The `?order_id=` timeline card is visually unchanged (same `.dd-track` markup/CSS, just nested in the content column); order-tracking.js still polls it. No query/attribution/ownership-gate/guest changes. PREVIOUS (v3.10.47, R2 styling fix): added `if ( is_page( 'track-order' ) ) return true;` to `is_dishdash_page()` (template-module.php:182) so `/track-order/` is recognized as a DishDash frontend page. Previously the track page matched none of the entries (front_page / restaurant-menu / cart / checkout / birthday / my-account / page-dishdash|page-simple template), so `enqueue_frontend_assets()` early-returned and the page rendered with NO DishDash base CSS/JS — only `order-tracking.css` (enqueued late by the shortcode) and `tracking.js` (enqueued globally by the tracking module) loaded. Now the base frontend bundle enqueues on the track page: theme.css + frontend.css (base styles), menu.css, cart.css, reservations.css, intl-tel-input, and menu.js/cart.js/search.js/frontend.js/reservations.js — so `.dd-track`/`.dd-btn`/list styling resolve against the brand tokens. Matched the existing slug-string pattern (`is_page('slug')`); slug `track-order` is from install.php:611. No render-logic change, no other page's detection touched. PREVIOUS (v3.10.46, R2 fix): in the Track Order list, only orders the user OWNS are clickable. The list SELECT now also returns `customer_id`; the template compares it to the passed `current_user_id` — owned rows (`customer_id === current user`) link to `?order_id=` (per-order live tracker, unchanged), while phone-only rows (customer_id NULL or another id) render `number · status · time · "In progress"` as a NON-clickable `<div class="dd-track__order-link--static">` (hover/pointer disabled in order-tracking.css). This stops anyone reaching the customer_id-only ownership gate → "Order not found". No gate change, no customer_id write; full attribution still deferred to R4c. PREVIOUS (v3.10.45, R2): Track Order page now shows a phone-anchored LIST of the logged-in user's ACTIVE orders (Option A, snapshot-on-load). `shortcode_track()` (orders-module.php:1645) refactored: the `?order_id=`/`?order=` branches are extracted UNCHANGED into a new private `render_single_track()` (same fetch, same ownership gate `customer_id !== uid`, same order-tracking.js live poll) so the per-order tracker is untouched; the DEFAULT branch (no param) now runs the R4b OR-block — `( customer_id = %d OR customer_phone IN (<canonical E.164>) ) AND is_test = 0 AND status IN ('pending','confirmed','ready') ORDER BY id DESC` — phone set from `get_customer_for_user()->whatsapp` (array_filter empties; empty → `customer_id = %d` only, never `IN ()`; `%s` placeholders via `$wpdb->prepare(…, array_merge([$uid],$phones))`, no concatenation), and renders a new `state='list'`. `templates/orders/track.php` gains a `list` branch: rows (order number · status label · time) each linking to `?order_id=<id>` via `dd_track_url()`; empty → clean "No active orders" state. List view enqueues `order-tracking.css` (style only, no poll); appended list-row CSS to that file (brand-token/rgba, no new file, no hex). Sidebar (S1): `DD_Profile_Module::add_menu_item()` appends a `track-order` item after Order History, and a new `woocommerce_get_endpoint_url` filter `track_order_menu_url()` remaps its href to `dd_track_url()` (the standalone `/track-order/` page) so WC doesn't build a dead `/my-account/track-order/`. NOT TOUCHED (per brief): `ajax_get_order()` gate (unchanged), no guest tracking, no `customer_id` writes/R4c. KNOWN v1 LIMIT: phone-only active orders (customer_id null/mismatched) appear in the list but their detail page hits the customer_id-only gate → "not found"; resolves with R4c. PREVIOUS (v3.10.44, R1): retired the vestigial `[dish_dash_account]` shortcode. The shortcode was double-registered (menu-module.php:54 → `woocommerce_account_content()`; orders-module.php:110 → custom `orders/account.php` list); Orders won by load order, so page 11 `/my-restaurant-account/` silently rendered the DD orders list. That page is in no menu and the real account experience lives at `/my-account/` (DD_Profile_Module: My Profile + Order History). FIX: removed BOTH `add_shortcode('dish_dash_account', …)` registrations (menu-module.php:54, orders-module.php:110); replaced each with a one-line breadcrumb comment and corrected the menu-module header docblock. `[dish_dash_account]` now renders as literal text if ever placed → page 11 to be trashed manually in WP admin by the developer (reversible; NOT deleted in code). SCOPE: registrations only — the two orphaned `shortcode_account()` handler METHODS (menu-module:459, orders-module:1714) left in place (dead, harmless), install.php:615 page-creation seed left as-is (option `dish_dash_account_page_id` already set so create_pages() skips it — no fresh-install recreation of a live page here), docs (ARCHITECTURE.md/MODULE_CONTRACT.md still describe the old double-registration) left for a later docs pass. No tracker/ownership-gate/schema change. PREVIOUS (v3.10.43, R4b): read-side phone-anchored order resolution. The three customer-facing history/aggregation queries now resolve a user's orders by `( customer_id = %d OR customer_phone IN (<their canonical E.164>) )`, tying previously-guest orders (placed under the user's whatsapp) to their logged-in identity. Queries changed: favorites (class-dd-customer-profile.php:106), recent-orders (:126), order-history (class-dd-profile-module.php:142). Known-phone set = canonical-only (customers.whatsapp via get_customer_for_user(); user_id is UNIQUE so exactly one per user); built in PHP, empties dropped, and if empty the query FALLS BACK to `customer_id = %d` unchanged (never emits IN ()/IN (NULL)). Phones bound via generated `%s` placeholders through $wpdb->prepare(array_merge([$user_id],$phones)) — never concatenated. GROUP BY on favorites is item-level so the wider order set folds MORE orders into the same item buckets (higher counts), no cardinality change; no double-count on any of the three (no DISTINCT needed). READ-SIDE ONLY: ownership/IDOR gates (orders-module ~1159/1631/1687/1718) UNTOUCHED (stay strict customer_id = get_current_user_id()); no customer_id write, no schema change, 7 conflict orders untouched. PREVIOUS (v3.10.42, R4a): data-only order-phone E.164 normalization. — normalized 6 legacy `wp_dishdash_orders.customer_phone` values to `+250…` E.164 so the order-side match key aligns with the R3 customers table (E.164). Migrated ids 1, 25, 26, 35, 44, 57. Intentionally excluded + documented: 8 truncated/unrecoverable rows (ids 38, 43, 88, 90, 91, 110, 111, 112 — `+2507865340`/`+25078562304`, missing digits, left untouched per R3 malformed precedent) and 1 foreign preserved (id 116 `+674069873633` Nauru, NOT coerced to +250). Verified on live: non-clean count 15→9; distribution 113 clean RW E.164 + 8 truncated + 1 foreign = 122 total. Backup taken (~/dd-backups/pre-r4a-20260706-155534.sql, 7.4M). NO customer_id/attribution/schema change; the 7 conflict orders (3,52,81,83,97,98,109) untouched. FLAG: the 8 truncated rows all share `+2507865340` → likely a write-path truncation bug at insert, not typos — parked for the write-path/guest-linkage investigation. PREVIOUS (v3.10.41, R3-fix): normalize_phone() no longer coerces foreign numbers into the RW keyspace. BUG (caught by R3 dry-run): id 18 `674069873633` (a foreign number that lost its + before storage) → `+250674069873633` because `parse($phone,'RW')` forces region RW on bare digits and libphonenumber prepends cc 250 to anything not starting with 250. FIX (region-selection + validity gate inside the library block): a value with a leading `+` is parsed INTERNATIONAL (`parse($phone, null)`) so foreign numbers stay foreign (+674… → +674…, trusted as-declared, no validity gate); a BARE value is parsed `RW` and accepted ONLY if `isValidNumber()` — invalid bare (foreign-that-lost-its-+) returns '' (junk, per locked decision #3), NOT coerced. `<9→''` gate + class_exists + try/catch intact; digit fallback now only runs when the library is unavailable. Migration inherits the fix (no migration-script change) → id 18 now junk-flagged not coerced; the 4 real RW clusters unchanged. Mode-aware harness (11 R2.5 parity + foreign +674/+1 + junk, both modes) confirmed ALL PASS on server. STILL DORMANT: flag defaults bare; migration not yet committed. PREVIOUS (v3.10.40, R3): flag-gated E.164 flip + backfill/dedupe migration. CODE DEPLOYED IS BEHAVIOR-NEUTRAL until the migration's --commit runs. (1) New `dd_phone_format` option (bare|e164), default `bare`. normalize_phone() gated on it: under e164 the library path returns `format(E164)` WITH + and the fallback returns `'+'.$digits` (both honor the flag — a library miss can't re-fragment the column), guard becomes `/^\+\d{9,15}$/`; under bare it's exactly today's behavior. The `<9→''` gate + class_exists + try/catch safety layers intact in both modes. Gating the one normalizer covers all 7 store/match sites (they all route through it); orders.customer_phone stores raw (unaffected); all wa.me builders strip non-digits (unaffected). (2) Migration `scripts/dd-r3-migrate.php` (ops script — NOT autoloaded, ships in zip, run via `wp eval-file`): DRY-RUN by default (writes nothing), `commit` token performs writes. MANDATORY commit order (UNIQUE KEY whatsapp demands it): re-point children (birthday_tokens/reservations/orders customer_id) → DELETE non-survivors → merge stats onto survivor + set whatsapp=E.164 → singleton normalizations → FINAL step `update_option(dd_phone_format,e164)` (activates flip after all keys migrated → zero mismatch window). Survivor = linked user_id (oldest) else oldest; user_id-conflict clusters skipped+flagged; junk left untouched. Whole commit is ONE transaction (rollback on any Throwable); reuses shipped normalizer via in-memory option filter; idempotent re-run. orders.customer_id place-order:1142 semantics UNTOUCHED (deferred to R4). Backup + dry-run review mandatory before commit. PREVIOUS (v3.10.39, R2.5): normalize_phone() routes through libphonenumber with byte-identical bare 250… output. — proving the vendored library parses correctly in production ahead of R3's E.164 format flip. Structure (in class-dd-customer-manager.php, ~:324): the historical digit logic runs FIRST and its `< 9 → return ''` is the parity gate (junk stays rejected before the library sees it — stops case-11 coercion); for accepted inputs, `PhoneNumberUtil::getInstance()->parse($phone,'RW')` → `format(E164)` → `ltrim('+')` yields the same bare 250… key. THREE fail-safes so it can never fatal or regress: `class_exists('\libphonenumber\PhoneNumberUtil')` guard (missing vendor tree → fallback), `try/catch(\libphonenumber\NumberParseException)` (malformed → fallback), and `ctype_digit($bare)` (unexpected output → fallback to today's $digits). Output stays bare (NO +, NO E.164 stored) — stored customer keys unchanged, zero orphaning. No caller change, no isValidNumber() rejection, one file touched. QA: WP-CLI parity harness (11-row matrix, OLD vs NEW) run on the server confirmed full parity before ship (harness is a scratch artifact, not committed). BEHAVIOR-NEUTRAL. PREVIOUS (v3.10.38, R1.6-fix-2): made the country-dropdown z-index fix actually take effect. v3.10.37 added `.iti--container { z-index: 10050 }` to cart.css, but the vendored `intlTelInput.min.css` sets `.iti--container { z-index: 1060 }` at identical specificity and is enqueued AFTER cart.css (class-dd-template-module.php L201 vs L226), so the vendored rule won the tie by load order and the dropdown kept opening at z-1060 — behind the drawer (desktop 9200 / mobile 10001 + overlay 10000), invisible/unclickable ("renders but won't open"). FIX: added `!important` → `.iti--container { z-index: 10050 !important; }`. !important beats a non-!important rule regardless of specificity or load order, so 10050 now deterministically wins over the vendored 1060 and the body-attached dropdown renders above the drawer on desktop AND mobile. One-word CSS change; value unchanged (10050 clears mobile 10001). dropdownContainer kept on all three inits; 16px anti-zoom untouched. BEHAVIOR-NEUTRAL SERVER-SIDE: no normalize_phone()/getNumber()/storage change. PREVIOUS (v3.10.37, R1.6-fix): fixed the buried country dropdown regression from v3.10.36. ROOT CAUSE: `dropdownContainer: document.body` (added v3.10.36) detaches the country list to `.iti--container`, whose vendored z-index is 1060 — BELOW the cart drawer (desktop z-9200; mobile `z-10001 !important`, overlay 10000). So the list rendered behind the drawer (in-DOM, positioned, click handler live, but visually buried/unclickable) on BOTH desktop and mobile. Verified in vendored intlTelInput.min.js that v25 AUTO-attaches the fullscreen popup to <body> on mobile regardless (`useFullscreenPopup && !dropdownContainer && (dropdownContainer=document.body)`), so mobile was actually buried all along even pre-v3.10.36 — the user only ever saw the iOS zoom side-effect masking a buried list. FIX (Option B, CSS-only): kept `dropdownContainer: document.body` on all three inits; added a single global, NON-media-scoped rule `.iti--container { z-index: 10050; }` in cart.css (enqueued on every DishDash page). 10050 chosen to clear BOTH the desktop drawer (9200) and the mobile drawer (10001) + overlay (10000) — the brief's suggested 9999 would have stayed buried on mobile. Desktop now shows the dropdown above the drawer; mobile fullscreen list is visible for the first time. 16px anti-zoom from v3.10.36 fully retained. BEHAVIOR-NEUTRAL SERVER-SIDE: no normalize_phone()/getNumber()/storage change. PREVIOUS (v3.10.36, R1.6): mobile phone-picker zoom fix on all three intl-tel-input surfaces. TWO confirmed causes, two minimal frontend fixes. (1) iOS auto-zoom — iOS Safari zooms any focused control < 16px; the country-search box `.iti__search-input` (v25 auto-focuses it when the dropdown opens) had no font-size, and the cart tel input was 15px / profile input 0.95rem. Added `font-size:16px` on mobile (inside existing ≤768px media blocks): cart tel input + `.iti__search-input` in cart.css (cart.css loads on every DishDash page via is_dishdash_page(), so the search-box rule covers all three surfaces globally — no new CSS file), and `.dd-profile__input` in profile.css. Reservations input already 16px. (2) Fullscreen overlay trapped in the cart drawer — the `.iti--fullscreen-popup` (position:fixed) stayed nested inside `.dd-cart-drawer` (transform: translateX(0)) + `#ddPanelCheckout` (overflow:hidden), so a transformed ancestor became its containing block and it was clipped instead of covering the viewport. Added `dropdownContainer: document.body` (verified correct v25 option name against vendored min.js) to all three inits (cart.js required; profile + reservations for consistency) so the popup re-parents to <body>. VIEWPORT UNTOUCHED (already correct width=device-width, initial-scale=1.0 — no maximum-scale). BEHAVIOR-NEUTRAL SERVER-SIDE: no normalize_phone()/getNumber()/storage change; desktop unchanged (16px rules mobile-scoped). PREVIOUS (v3.10.35, R2): Composer + vendored libphonenumber introduced. Defensive autoloader in dish-dash.php (`vendor/autoload.php` require guarded by `file_exists()` — a missing vendor/ degrades gracefully, never fatals; placed after the CONSTANTS block, before the DD_ SPL autoloader which is untouched). `.gitignore` gains a `!vendor/` + `!vendor/**` whitelist (parity/insurance). Release workflow gains a CI presence-guard step (after zip, before upload) that hard-fails the build if `dish-dash/vendor/autoload.php` is missing from the zip. BEHAVIOR-NEUTRAL: nothing calls libphonenumber this release — the autoloader is present-but-idle; `normalize_phone()` untouched (still bare-250… normalization); no format flip, no query/capture/validation change. Only observable effects: larger plugin (~23 MB vendor tree, giggsey/libphonenumber-for-php committed) and an idle autoloader. Format flip + dedupe backfill deferred to R3. PREVIOUS (v3.10.34, R1.5): phone UX polish on all three picker surfaces (cart #ddFieldWhatsapp, profile #ddProfilePhone, reservations #dd-res-whatsapp). (1) Removed duplicate +250 from placeholders — now a clean static "78 000 0000" (separateDialCode already renders the dial code). (2) Soft inline validity hint on input/blur when iti.isValidNumber() === false. (3) Hard-block on submit (Place Order / Connect / advance past reservation screen 3) when the number is invalid. FAIL-OPEN GUARD: every block is inside `if ( iti && iti.isValidNumber() === false )` — isValidNumber() returns null while utils.js is still loading and iti is undefined when the picker never inited, so neither case blocks; a picker glitch never stops ordering. Per-country validation (libphonenumber via intl-tel-input), not a digit count. No new CSS files (cart reuses .dd-cform-error, profile reuses .dd-profile__link-msg, reservations adds inline-styled #dd-res-phone-warn in modal.php). BEHAVIOR-NEUTRAL SERVER-SIDE: no normalize_phone()/storage change; getNumber() still emits the E.164 that collapses to 250…. Format flip + backfill still deferred to R2/R3. |
| **GitHub** | github.com/frisoftltd/dish-dash |
| **Live site** | dishdash.khanakhazana.rw |
| **Server** | cPanel at server372.web-hosting.com (user: imitjsiy) |
| **Plugin path** | /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ |
| **Theme** | dish-dash-theme (custom blank theme — NOT Astra, NOT any other theme) |
| **Stack** | WordPress 6+, WooCommerce, PHP 8.2, vanilla JS (no jQuery, no build step), MySQL, LiteSpeed Cache |

---

## 🎨 Brand Colors

Brand colors are **always dynamic** — set by each restaurant in Dish Dash → Brand
Identity and stored in wp_options. All code must read colors from wp_options or
CSS variables. **Never hardcode hex values anywhere in the codebase.**

| Option Key | Description |
|---|---|
| `dish_dash_primary_color` | Header, buttons, active states, CTAs |
| `dish_dash_dark_color` | Secondary elements, text accents |
| `dish_dash_background_color` | Page background |
| `dish_dash_font` | Typography (Inter default) |

**Admin CSS variables (set on `<body>` by PHP in every admin page):**
```css
--dd-brand:       [restaurant primary color];
--dd-brand-rgb:   [R,G,B components for rgba() usage];
--dd-brand-light: [10% opacity version];
--dd-brand-dark:  [darkened 15%];
```

**Rule:** Khana Khazana uses `#65040d` as primary — this is one restaurant's
config, not a hardcoded value. What you see in the mockup is a placeholder.
Always read from `get_option('dish_dash_primary_color')` in PHP.

---

## 🔄 Workflow — How We Work

### Roles

| Role | Responsibilities |
|---|---|
| **Claude** (claude.ai) | Planning, architecture, investigation briefs, fix briefs, release notes |
| **Claude Code** (CLI terminal) | Executes file edits based on briefs — never infers tasks, never acts without a brief |
| **Developer** (human) | GitHub releases, deployment, testing, feedback |

### The Loop

```
Claude writes Investigation Brief
↓
Claude Code investigates → reports findings (NO edits yet)
↓
Developer pastes findings to Claude
↓
Claude reviews → writes Fix Brief
↓
Claude Code edits files → reports complete
↓
Developer pastes report to Claude
↓
Claude gives release instructions (tag, title, description)
↓
Developer commits → creates GitHub release → deploys → tests
↓
Developer reports result with screenshot → Claude writes next brief
↓
Repeat
```

### Claude Code Session Setup

Every Claude Code session MUST start with:
> Read `CLAUDE.md` from the repo root at github.com/frisoftltd/dish-dash
> before doing any work. This file contains the full project context,
> rules, architecture, and current state.

### Release Process

1. All files committed to `main` branch (lowercase — **NEVER `Main`**)
2. Version bumped in `dish-dash.php` in **BOTH** locations:
   - `* Version: X.X.X` in the plugin header comment
   - `define('DD_VERSION', 'X.X.X');` constant
3. **CLAUDE.md updated in the same commit** — `Last updated` line + Current State table
4. Developer creates GitHub release with tag `vX.X.X` (**WITH the `v` prefix** — without it, Actions will not build the zip)
5. GitHub Actions builds `dish-dash.zip` automatically (~30 seconds)
6. Deploy via ONE of:

**Method A — cPanel Terminal:**
```bash
cd /tmp && wget https://github.com/frisoftltd/dish-dash/releases/latest/download/dish-dash.zip && unzip -o dish-dash.zip -d /tmp/dd-update && cp -r /tmp/dd-update/dish-dash/* /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ && rm -rf /tmp/dd-update /tmp/dish-dash.zip && echo "Done!"
```

**Method B — WordPress auto-update:**
WP Admin → Plugins → Check for Updates → Update Now

7. Purge LiteSpeed Cache: WP Admin → LiteSpeed Cache → Toolbox → Purge All
8. Test in incognito window
9. Verify: `grep "DD_VERSION" /home/imitjsiy/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/dish-dash.php`

---

## 🚨 Rules — NEVER Break These

### Claude Code Operating Rules

**Rule 0 — Version bump + CLAUDE.md update is mandatory on every release.**
Every brief that ships code MUST end with:
- Bump `* Version: X.X.X` in dish-dash.php header
- Bump `define( 'DD_VERSION', 'X.X.X' );` in dish-dash.php
- Update `Last updated` line and Current State table in CLAUDE.md
- `git add [all changed files] dish-dash.php CLAUDE.md`
- `git commit -m "release: vX.X.X — [description]"`
- `git push origin HEAD:main`

Never commit changed files without dish-dash.php and CLAUDE.md.
Never push without the version bumped and CLAUDE.md updated.

**Rule 1a — Scope is a hard wall, not a guideline.**
If a brief says "fix X in file Y", touch ONLY file Y, ONLY the lines
that fix X. If you notice another bug while reading the file — REPORT IT.
Do not fix it. Do not "clean it up". Do not refactor "while you're in there".
Write it in your report and wait for a new brief.

**Rule 1b — Never touch a file not listed in the brief.**
If fixing X requires understanding file Z, you may READ file Z.
You may NOT edit file Z unless it is explicitly listed.
If you believe file Z also needs changing, REPORT IT and stop.
Wait for explicit instruction before touching it.

**Rule 2 — Always start in Plan Mode.**
`claude --permission-mode plan`
Analyze first, never edit without approval.

**Rule 3 — Never infer a task.**
Wait for a brief. Never assume what comes next.

**Rule 4 — NEVER run git add, commit, or push without explicit instruction from the developer.**

**Rule 5 — Use @mentions for exact files.**
Never read the whole codebase. Target only the files you need.

**Rule 6 — Run /compact between tasks.**

**Rule 7 — Be concise in reports.**
Root cause, files changed, test steps only.

### Code Rules

- **Always provide complete files** — never partial snippets
- **Always include exact GitHub path** for every file
- **Always state CREATE new or EDIT existing** for each file
- **Never change code outside the scope of the current task** — scope creep causes regressions
- **Always check current file state before editing** — read the file first
- **Investigation findings BEFORE writing code** — always diagnose, then fix
- **Always check inline styles in PHP templates before CSS files** — past bugs caused by inline styles, not CSS
- **Verify which template renders a given URL before editing** — wrong file = wasted release
- **Push to `main` (lowercase)** — NEVER `Main` (capital M creates orphan branch)
- **Do NOT create release tags** — developer does that via GitHub UI

### Architecture Rules

Each module MUST be completely independent:
- Own folder: `modules/feature/`
- Own class: `class-dd-feature-module.php`
- Extends `DD_Module` base class
- Registers its own admin submenu independently
- Communicates with other modules ONLY via `do_action()` and `apply_filters()`
- NEVER directly calls another module's methods
- NEVER writes to another module's database table
- Template module uses `DD_PLUGIN_DIR` constant (not `plugin_dir_path(__FILE__)`)

### Data Access Rules

- **All NEW code** must use `DD_API::` for data access — no direct `wc_get_product()` or raw `$wpdb` calls in new features
- Existing code keeps working as-is — migrate gradually when files are touched
- `DD_API` returns normalized arrays, NOT WC_Product objects
- `DD_API` has built-in transient caching (5-min TTL, auto-invalidated on product save)

### DB Rules

- `dbDelta()` for all DB table creation — exclusively
- `dbDelta()` does NOT run on zip updates — any release adding new tables must manually call `DD_Install::create_tables()` via WP-CLI immediately after deploy

### Admin UI Rules (Phase 5 — enforced from v3.4.20 onward)

- No WP grey or WP blue (`#2271b1`) anywhere on any Dish Dash admin page
- No hardcoded hex colors — all colors from `var(--dd-brand)` CSS variable
- Restaurant logo must show in sidebar
- Inter font loaded on all admin pages
- `--dd-brand` and `--dd-brand-rgb` output on `:root` in `get_admin_styles()`
- Cards: 12px border-radius, `box-shadow: 0 1px 4px rgba(0,0,0,0.06)`, padding 24px

### Tracking Rules

- No feature without tracking — every user action must be recorded
- New tracking events MUST be added to `modules/tracking/event-schemas.php` FIRST
- `meta` JSON field contains ONLY metadata — dedicated DB columns are NOT listed in schema
- Validation mode: `warn` — events logged but not rejected

---

## 🏗 File Structure

```
dish-dash/
├── .github/workflows/release.yml
├── admin/
│   ├── pages/
│   │   ├── dashboard.php
│   │   ├── orders.php
│   │   ├── analytics.php
│   │   ├── customers.php
│   │   ├── reservations.php
│   │   ├── tables.php
│   │   ├── seating-sections.php
│   │   ├── settings.php
│   │   ├── brand-identity.php
│   │   ├── template.php
│   │   ├── homepage.php
│   │   ├── auth-login.php
│   │   ├── tools.php
│   │   ├── coming-soon.php
│   │   └── event-health.php
│   └── class-dd-admin.php
├── assets/
│   ├── css/ (admin, cart, menu, theme, frontend, menu-page)
│   └── js/  (admin, cart, menu, frontend, search, tracking, menu-page)
├── dishdash-core/
│   ├── class-dd-ajax.php
│   ├── class-dd-api.php               ← Normalized data facade (12 methods)
│   ├── class-dd-github-updater.php
│   ├── class-dd-helpers.php
│   ├── class-dd-hooks.php
│   ├── class-dd-install.php
│   ├── class-dd-loader.php
│   ├── class-dd-module.php
│   └── class-dd-settings.php
├── modules/
│   ├── menu/class-dd-menu-module.php
│   ├── orders/(class-dd-orders-module.php, class-dd-cart.php)
│   ├── template/class-dd-template-module.php
│   └── tracking/(class-dd-tracking-module.php, event-schemas.php)
├── templates/
│   ├── cart/cart.php
│   ├── checkout/checkout.php
│   ├── menu/grid.php                   ← Menu page content (shortcode)
│   ├── partials/product-card.php
│   ├── page-dishdash.php               ← Homepage template
│   └── themes/
│       └── khana-khazana/              ← Default frontend template (Phase 5D Part 2)
├── theme/dish-dash-theme/
│   ├── functions.php
│   ├── page.php
│   ├── singular.php
│   ├── index.php
│   └── style.css
├── ARCHITECTURE.md
├── CSS_REGISTRY.md
├── MODULE_CONTRACT.md
├── TRACKING_ROADMAP.md
├── TECHNICAL_ARCHITECTURE_VISION.md
├── CLAUDE.md                           ← THIS FILE — updated every release
└── dish-dash.php                       ← Main plugin file
```

---

## 🗺 URL → Template Mapping

| URL | Template | Primary CSS | Primary JS |
|---|---|---|---|
| `/` | `templates/page-dishdash.php` (via `template_include`) | `theme.css` | `frontend.js` |
| `/restaurant-menu/` | `theme/page.php` → `[dd_menu]` → `templates/menu/grid.php` | `menu-page.css` | `menu-page.js` |
| `/cart/` | `templates/cart/cart.php` | `cart.css` | `cart.js` |
| `/checkout/` | `templates/checkout/checkout.php` | — | — |

---

## 📆 Development Phases

| Phase | Status | Description |
|---|---|---|
| **Phase 1** | ✅ | Foundation — plugin, GitHub updater, WooCommerce integration |
| **Phase 2** | ✅ | Template system — header, hero, footer, branding, mobile 3-screen menu |
| **Phase 3** | ✅ | Cart, Orders, WhatsApp notifications, Opening Hours |
| **Phase 4** | ✅ | Reservations — table booking, notifications, tables, seating sections |
| **Phase 5A** | ✅ | Clean & Secure — WP noise removed, custom admin URL `/khazana`, `/wp-admin` → 404 |
| **Phase 5B** | ✅ | Admin layout shell — dark sidebar, top bar, brand injection |
| **Phase 5C** | ✅ | Brand Identity page, Template card picker |
| **Phase 5D** | ✅ | Full admin redesign + frontend template system |
| **Phase 6** | ⏳ | MoMo Payment Integration — MTN Mobile Money payment gateway, in-drawer payment flow ← CURRENT |
| **Phase 7** | ⏳ | User Access Control — customer profiles, roles, permissions, order history |
| **Phase 8** | ⏳ | Analytics + AI — Python microservice, behavior engine, recommendations |
| **Phase 9** | ⏳ | Loyalty & QR — points system, QR scan ordering |
| **Phase 10** | ⏳ | Testing + Optimization |
| **Phase 11** | ⏳ | SaaS Platform — multi-tenant, subscription billing, white-label |

---

## 🖥 Phase 5 — Backend Dashboard & Admin Transformation

### Vision

The WordPress admin is completely transformed into a professional SaaS product.
Each restaurant feels like they own the system — not a generic WordPress install.
Structure is universal. Colors, logo, fonts come from Brand Identity settings.

**What must NEVER appear in the admin:**
- WordPress logo anywhere
- WordPress blue (`#2271b1`) or default WP grey
- Plugin update badges or notification banners (except the Updates page)
- Any hint this is built on WordPress

---

### Dish Dash Admin Sidebar — Final Menu (in order)

| # | Item | Status |
|---|---|---|
| 1 | 📊 Dashboard | ✅ |
| 2 | 🧾 Orders | ✅ |
| 3 | 📈 Analytics | ✅ |
| 4 | 👥 Customers | ✅ |
| 5 | 📅 Reservations | ✅ |
| 6 | 🪑 Tables | ✅ |
| 7 | 🪟 Seating Sections | ✅ |
| 8 | ⚙️ Settings | ✅ |
| 9 | 🎨 Brand Identity | ✅ |
| 10 | 🖼 Template | ✅ |
| 11 | 🏠 Homepage | ✅ |
| 12 | 🔐 Auth & Login | ✅ |
| 13 | 🔧 Tools | ✅ |

**Removed from Dish Dash menu:** Menu Items, Delivery, Branches, POS Terminal

**WordPress native menus visible to restaurant owner:** Media, Pages, Users only.
Everything else hidden.

---

### Phase 5A — Clean & Secure ✅ Complete

| Release | What shipped |
|---|---|
| v3.4.16 | Removed all WP notification noise |
| v3.4.17 | Replaced WP logo with restaurant logo |
| v3.4.18 | Hidden irrelevant WP menus, removed DD submenus |
| v3.4.19 | Custom admin URL — `/wp-admin` → 404, recovery via email |

**Key implementation notes:**
- `admin_menu` (priority 999) strips update count bubbles from sidebar
- `remove_all_actions('admin_notices')` + `remove_all_actions('all_admin_notices')`
- Exception: `get_current_screen()->id === 'update-core'` — never suppress on Updates page
- `add_filter('woocommerce_helper_suppress_admin_notices', '__return_true')` for WC notices
- Custom path stored in `dd_admin_custom_path` wp_option, superadmin only

---

### Phase 5B — General Layout ✅ Complete

| Release | What shipped |
|---|---|
| v3.4.20 | Layout shell — sidebar (60px collapsed / 240px expanded), top bar (56px), content wrapper |
| v3.4.21 | Brand injection — restaurant logo + primary color as CSS variables |
| v3.4.22 | Branded login page — restaurant logo, primary color, zero WP styling |
| v3.4.23 | Global typography + card system — Inter font, spacing tokens |

**Layout specs (reference):**

*Sidebar:*
- Collapsed: 60px, background `#1a1a1a`, icon only, 48px hit area
- Active: 3px left border + icon in `var(--dd-brand)`
- Expanded: 240px, 200ms ease transition, full logo + label

*Top bar:*
- Height 56px, background `#ffffff`, border-bottom `1px solid #eeeeee`
- Left: page title (20px Inter semibold)
- Right: notification bell + admin avatar + restaurant name
- No WordPress toolbar

*Content area:*
- Background `#f8f8f8`, padding 32px, full width (no max-width on content)

*Cards:*
- Background `#ffffff`, border-radius 12px, box-shadow `0 1px 4px rgba(0,0,0,0.06)`, padding 24px

*Typography:*
- Font: Inter (Google Fonts)
- Page title: 20px 600 `#111111`
- Section title: 16px 600 `#111111`
- Body: 14px 400 `#444444`
- Label: 12px 500 `#888888`
- KPI numbers: 28–32px 700 `#111111`

---

### Phase 5C — New Pages ✅ Complete

| Release | What shipped |
|---|---|
| v3.4.24 | Brand Identity page — logo, color pickers, font, contact info, social media |
| v3.4.25 | Template page — card-based template library picker |

---

### Phase 5D — Full Admin Redesign + Frontend Template System ✅ Complete

#### Part 1 — Admin Pages Redesign

Every page before shipping must pass:
- ✅ No WP grey or WP blue anywhere
- ✅ Restaurant logo in sidebar
- ✅ Brand color on active states and CTAs (from `--dd-brand` — never hardcoded)
- ✅ Inter font loaded
- ✅ Spacious cards, 12px radius, soft shadows
- ✅ Dashboard content fills full width — no max-width cap killing right side

| Release | Status | What ships |
|---|---|---|
| v3.4.39–v3.4.43 | ✅ Done | Orders, Analytics, Customers, Reservations pages redesigned |
| v3.4.44 | ✅ Done | Dashboard — live KPIs, date filter, revenue chart, top items, customer tiers |
| v3.4.45 | ✅ Done | Fix dashboard Top Items column name and opening hours session keys |
| v3.4.46 | ✅ Done | Orders page redesign + remove global max-width constraint |
| v3.4.47 | ✅ Done | Orders status dropdown with POST handler, replaces dead GET links |
| v3.4.48 | ✅ Done | Friendly status names, kitchen WhatsApp notification, stale order warning |
| v3.4.49 | ✅ Done | 4-status order flow, button-based actions, rider management, customer on-the-way notification |
| v3.4.50 | ✅ Done | Clean WhatsApp message format, Mark Ready/Delivered locked until notified |
| v3.4.51 | ✅ Done | Fix WhatsApp line breaks (esc_url strips %0A), fix addon JSON in kitchen message |
| v3.4.52 | ✅ Done | Remove emoji from WhatsApp messages, fix variation JSON decode |
| v3.4.53 | ✅ Done | Fix variation JSON decode with stripslashes, strip stray braces from plain text |
| **v3.4.54** | ✅ **Done** | **Sort orders by urgency: Pending first, then Confirmed, Ready, Delivered, Cancelled** |
| **v3.4.55** | ✅ **Done** | **Fix revenue/AOV to use delivered orders, fix chart Y-axis integers, hide chart when empty** |
| **v3.4.56** | ✅ **Done** | **Fix order status forms: explicit action URL, nonce failure notice** |
| **v3.4.57** | ✅ **Done** | **Remove ghost admin-orders.css/js enqueues, remove debug logging** |
| **v3.4.58** | ✅ **Done** | **Order detail modal with AJAX status updates, remove form-based action buttons** |
| **v3.4.59** | ✅ **Done** | **Fix modal nonce: dish_dash_frontend for get_order, dish_dash_admin for update_status** |
| **v3.4.60** | ✅ **Done** | **Fix modal data rendering: unwrap double-nested AJAX response** |
| **v3.4.61** | ✅ **Done** | **Modal stays open on status change, Mark Ready lock fix, orders table full width** |
| **v3.4.62** | ✅ **Done** | **Add View button to order rows, fix WP right padding dead zone** |
| **v3.4.63** | ✅ **Done** | **Top Items all-time query, stale bulk deliver button, recent orders rows clickable** |
| **v3.4.64** | ✅ **Done** | **Test order flag (is_test), bulk status change, test orders excluded from all reports** |
| **v3.4.65** | ✅ **Done** | **Fix View button: type="button" + stopPropagation prevents modal disappearing** |
| **v3.4.66** | ✅ **Done** | **Fix View button calling openModal() directly with order ID** |
| **v3.4.67** | ✅ **Done** | **Expose openModal to global scope for View button inline onclick** |
| **v3.4.68** | ✅ **Done** | **Fix Pending Orders KPI to include confirmed and ready statuses** |
| **v3.4.69** | ✅ **Done** | **Fix modal re-render using local state instead of re-fetching** |
| **v3.4.70** | ✅ **Done** | **Rename Pending Orders KPI to Active Orders** |
| **v3.4.71** | ✅ **Done** | **Real-time notifications — opt-in banner, 30s polling, browser alerts, sidebar badge** |
| **v3.4.72** | ✅ **Done** | **Clean admin bar, bell notification icon with dropdown panel** |
| **v3.4.73** | ✅ **Done** | **Bell panel polish, click notification opens order modal, mark as read** |
| **v3.4.74** | ✅ **Done** | **Fix bell panel HTML rendering, notification click opens order modal** |
| **v3.4.75** | ✅ **Done** | **Remove stale banner, need-action badge link, server-side notification read persistence** |
| **v3.4.76** | ✅ **Done** | **Add status timestamp columns, kitchen prep time setting, record timestamps on status change** |
| **v3.4.77** | ✅ **Done** | **Bell pending-only, kitchen queue with live timer and Mark Ready, dd_kitchen_queue endpoint** |
| **v3.4.78** | ✅ **Done** | **Bell dedup — merge approach, remove accepted orders, badge from DOM state; remove need-action badge** |
| **v3.4.79** | ✅ **Done** | **Beep only on genuinely new bell items, not every poll cycle** |
| **v3.4.80** | ✅ **Done** | **Mark Ready navigates to order modal instead of silent AJAX change** |
| **v3.4.81** | ✅ **Done** | **Fix waUrls missing for cross-page modal opens, fix Mark Ready disabled when no kitchen phone** |
| **v3.4.82** | ✅ **Done** | **Reopen cancelled/delivered orders, modal stays open on terminal status, reverse transitions** |
| **v3.4.83** | ✅ **Done** | **Orders pagination — 25/50/75/All per-page, page number nav, "Showing X–Y of Z" info** |
| **v3.4.84** | ✅ **Done** | **Fix division by zero and undefined per_page on orders pagination** |
| **v3.4.85** | ✅ **Done** | **Remove Menu Items from admin sidebar** |
| **v3.4.86** | ✅ **Done** | **Remove Delivery, Branches, POS Terminal from admin sidebar** |
| **v3.4.87** | ✅ **Done** | **Analytics pages — Orders Analytics, Reservations Analytics, AI Insights engine (DD_Insights), Chart.js charts, speed metrics, customer breakdown** |
| **v3.4.88** | ✅ **Done** | **Fix oi.price DB error (line_total), insights horizontal scroll, remove orders tab, reservations KPI padding** |
| **v3.4.89** | ✅ **Done** | **Analytics padding overhaul — consistent 24px card spacing, KPI sizing, chart wrap, speed section, hbars, two-column gaps** |
| **v3.4.90** | ✅ **Done** | **Analytics merged into single page — Orders + Reservations tabs, sidebar sub-item removed, both JS loaded on one page, max-width removed** |
| **v3.4.91** | ✅ **Done** | **Settings page redesign (card layout, CSS grid); Pricing & Fees section (flat fee, minimum order advisory, payment method toggles, platform fee stamped on every order)** |
| **v3.4.92** | ✅ **Done** | **Installer consolidation: single canonical install.php (13 tables), class-name conflict resolved (DD_Schema_Upgrader), auto-migration guard in dish-dash.php, no more manual WP-CLI for schema updates** |
| **v3.4.93** | ✅ **Done** | **Billing page (💳 sidebar between Analytics and Settings, monthly history, status breakdown, all-time fees) + Fees This Month KPI card on dashboard** |
| **v3.4.94** | ✅ **Done** | **Fee enable/disable toggle in Settings, dashboard card fixed (dd-kpi-meta layout), Analytics fees KPI card, Billing disabled notice** |
| **v3.4.95** | ✅ **Done** | **Billing fixes: Monthly History 3-column (no Fee Per Order), Status Breakdown delivered+cancelled only, dashboard fees card layout fix (no RWF prefix in value)** |
| **v3.4.96** | ✅ **Done** | **Dashboard: fees KPI card replaced with inline line below revenue chart** |
| **v3.4.97** | ✅ **Done** | **Phase C — deleted dishdash-core/class-dd-install.php (deprecated DD_Schema_Upgrader), updated stale comments** |
| **v3.4.98** | ✅ **Done** | **Phase D — fee reversal on cancel/revert: recalculate_fee_for_status_change() helper wired into all 6 status-change sites** |
| **v3.4.99** | ✅ **Done** | **Orders page: recent-first sort, live search (order number/name/phone), date range filter, payment method filter** |
| **v3.5.00** | ✅ **Done** | **Orders search fix: order number only (removes name/phone false matches), debounce 350ms → 800ms** |
| **v3.5.01** | ✅ **Done** | **Order reopen 24h expiry — Reopen as Ready hidden after 24h, replaced with locked message** |
| **v3.5.02** | ✅ **Done** | **Billing paid/unpaid tracking: new dd_billing_payments table, Mark as Paid button on monthly history, dashboard fees line shows paid/unpaid badge** |
| **v3.5.03** | ✅ **Done** | **Customers page redesign: new header pattern, dd-kpi-grid--4 KPI cards, dd-card wrappers for tiers/filters/table, dead dd-cust-kpi-* CSS removed, hardcoded hex fixed** |
| **v3.5.04** | ✅ **Done** | **Customers page full width, per-page selector 25/50/75/All, pagination carries per_page param, inline count removed** |
| **v3.5.05** | ✅ **Done** | **Customers page: Orders/Reservations tabs, tab-filtered rows, paginate_links carries tab param** |
| **v3.5.06** | ✅ **Done** | **Customers — per-tab KPI cards, hide tiers on reservations tab** |
| **v3.5.07** | ✅ **Done** | **Fix: restore tier counts to orders-tab stats query (tier_new/regular/vip/champion/diamond)** |
| **v3.5.08** | ✅ **Done** | **Customers — date range filter today/7d/30d/90d/custom, KPIs range-aware, filter preserves tab+per_page** |
| **v3.5.09** | ✅ **Done** | **Fix: default range=All, orders tab filters on last_order_at, no prepare() with empty params** |
| **v3.5.10** | ✅ **Done** | **Fix: skip prepare() when no date params — conditional pattern on all 3 stats queries** |
| **v3.5.11** | ✅ **Done** | **Reservations admin redesign: DD design system, KPI cards, pill tabs, AJAX status updates, badge statuses, toast** |
| **v3.5.12** | ✅ **Done** | **Tab consolidation: Tables + Sections into page tabs, date range pills, per-page pills, action button refinement** |
| **v3.5.13** | ✅ **Done** | **Fix: CSS-only sidebar hiding replaces remove_submenu_page(), dashicons enqueued on Tables + Sections** |
| **v3.5.14** | ✅ **Done** | **Fix: add dashicons enqueue to reservations admin page** |
| **v3.5.15** | ✅ **Done** | **Fix: dashicons color #fff on dark reservation header** |
| **v3.5.16** | ✅ **Done** | **Fix: add 📅 emoji icon to Reservations sidebar menu item** |
| **v3.5.17** | ✅ **Done** | **Reservations table: remove Time/Session columns, explicit widths, overflow fix, responsive KPI font size** |
| **v3.5.18** | ✅ **Done** | **Fix: reservation notification slug, beep on new reservations, badge persistence on reload** |
| **v3.5.19** | ✅ **Done** | **Fix: persist notification panel items across page reloads (localStorage + panel rebuild)** |
| **v3.5.20** | ✅ **Done** | **Feat: reservation notification deep link + mark as read on click** |
| **v3.5.21** | ✅ **Done** | **Feat: focused single-reservation view when opening from notification** |
| **v3.5.22** | ✅ **Done** | **Bulk actions + test flag for reservations: is_test column + migration guard, checkbox column, bulk bar, Test tab, test badge, KPI queries exclude test rows** |
| **v3.5.23** | ✅ **Done** | **Fix: nonce field name in ajax_bulk_action — check_ajax_referer missing 'nonce' arg caused all bulk actions to return 403** |
| **v3.5.24** | ✅ **Done** | **Fix: exclude test reservations from all 21 analytics queries in reservations tab — AND is_test=0 added to every {$rt} query** |
| **v3.5.25** | ✅ **Done** | **Remove "Notify Restaurant" WhatsApp button from customer booking confirmation; remove auto-open WhatsApp from order confirmation** |
| **v3.5.26** | ✅ **Done** | **Fix: hide ghost "Confirming..." button + clean reservation confirmation screen (only ✅ message + Close)** |
| v3.5.27 | — | Skipped |
| **v3.5.28** | ✅ **Done** | **Fix: git rm cart.min.js + reservations.min.js — stale minified files were overriding source .js edits via asset_url() file_exists() check** |
| **v3.5.29** | ✅ **Done** | **Fix: quantity always 1 from product modal — PHP read 'quantity' but JS sent 'qty'; now accepts both** |
| **v3.5.30** | ✅ **Done** | **Fix: order notifications not removed from panel and localStorage on click — remove from array, persist, remove DOM element** |
| **v3.5.31** | ✅ **Done** | **Fix: tablet dead-zone — raise all mobile breakpoints to 1024px (theme.css + frontend.css + frontend.js) so iPad Air 5 gets full mobile layout** |
| **v3.5.32** | ✅ **Done** | **Fix: menu page tablet — raise menu-page.css breakpoints (767→1024, 768→1025 ×3, 901→1025) and menu-page.js >= 768 → >= 1025** |
| **v3.5.33** | ✅ **Done** | **Feat: mobile menu category search — filterCategories(), screen-aware input routing, back button clears search, no-results element** |
| **v3.5.34** | ✅ **Done** | **Feat: product search results on Screen 1 — searchProducts(), debounce helper, search results section in grid.php, back button clears results** |
| **v3.5.35** | ✅ **Done** | **Feat: style search results section — .dd-mobile-search-results CSS inside mobile media block in menu-page.css** |
| **v3.5.36** | ✅ **Done** | **Fix: Dishes heading updated to match FOOD CATEGORY label (13px/600/0.5px letter-spacing)** |
| **v3.5.37** | ✅ **Done** | **Fix: add .dd-mobile-cats-empty CSS rule — was unstyled since v3.5.33** |
| **v3.5.38** | ✅ **Done** | **Fix: search results click handler (delegated listener on searchResultsList) + iOS zoom (16px font-size on input + quick-add, touch-action: manipulation)** |
| **v3.5.39** | ✅ **Done** | **Fix: reservation page iOS zoom — 16px font-size + touch-action: manipulation on toggle btn, slot, back btn, select, input** |
| **v3.5.40** | ✅ **Done** | **Fix: stepper double-tap zoom — touch-action: manipulation on .dd-res-stepper container and .dd-res-stepper__btn** |
| **v3.5.41** | ✅ **Done** | **Feat: Dish Dash Simple Page template — page-simple.php + simple-page.css, registered + loaded + enqueued in template module** |
| **v3.5.42** | ✅ **Done** | **Feat: add Privacy Policy + Refund & Returns links to footer Explore column** |
| **v3.6.0** | ✅ **Done** | **Phase 6A: MoMo in-drawer payment — DD_MoMo API client, mtn_momo branch, dd_momo_check_status polling, #ddPanelMomo waiting UI, CSS styles** |
| **v3.6.1** | ✅ **Done** | **Fix MoMo request_to_pay accept HTTP 200 response in sandbox** |
| **v3.6.2** | ✅ **Done** | **Fix MoMo phone field visibility, add momo_phone validation, remove WhatsApp fallback in PHP** |
| **v3.6.3** | ✅ **Done** | **Phase 6B: IremboPay in-drawer modal + payment button logos (placeholders — replace with media URLs)** |
| **v3.6.4** | ✅ **Done** | **Fix payment button logo URLs — use bundled plugin assets** |
| **v3.6.5** | ✅ **Done** | **Fix payment method logo URLs — point to bundled plugin assets (assets/images/)** |
| **v3.6.6** | ✅ **Done** | **Replace Type column with Payment column in orders list; dd_format_payment_method() global helper; fix payment labels in notification panel** |
| **v3.6.7** | ✅ **Done** | **Fix notification panel — real payment labels, relative timestamps, badge reset on mark all read** |
| **v3.6.8** | ✅ **Done** | **MoMo Option B — create order in DB only after payment confirmed; ghost orders eliminated** |
| **v3.6.9** | ✅ **Done** | **Billing menu hidden when fee tracking disabled; remove Accepted Payment Methods from Settings** |
| **v3.7.0** | ✅ **Done** | **Phase 7A: role cleanup + capability-based access control — dd_restaurant_owner (full DD access), dd_restaurant_manager (ops only), admin menu lockdown, Billing/Revenue dashboard widgets gated by dd_view_billing** |
| **v3.7.1** | ✅ **Done** | **Fix custom-admin-path 404 gate blocking Restaurant Owner/Manager from wp-admin; gate now allows dd_manage_orders capability alongside manage_options** |
| **v3.7.2** | ✅ **Done** | **Redirect Owner/Manager to Dish Dash dashboard after login — login_redirect filter (priority 1) for wp-login.php path; redirect URL in ajax_login() response for modal path; JS uses href instead of reload** |
| **v3.7.3** | ✅ **Done** | **Fix login redirect — hook woocommerce_login_redirect (WC bypasses WP's login_redirect); Owner/Manager/Admin sent to Dish Dash dashboard; customers/subscribers unaffected** |
| **v3.7.4** | ✅ **Done** | **Grant manage_options to Owner/Manager roles; remove login_redirect filter workarounds; simplify maybe_block_wp_admin(); migrate_roles_v2() bumped to v3 to re-create roles on existing installs** |
| **v3.7.5** | ✅ **Done** | **Redirect staff roles (Owner/Manager) from /my-account/ to DD dashboard via template_redirect priority 5 (staff_frontend_redirect())** |
| **v3.7.6** | ✅ **Done** | **Emergency revert: remove staff_frontend_redirect() — template_redirect caused redirect loop** |
| **v3.7.7** | ✅ **Done** | **Fix login redirect via wp_login action priority 1 — staff roles sent to DD dashboard before WP/WC redirect logic runs** |
| **v3.7.8** | ✅ **Done** | **Wire wp_login redirect action registration; fix maybe_block_wp_admin() to allow dd_manage_orders through custom admin path gate** |
| **v3.7.9** | ✅ **Done** | **Fix login redirect — login_redirect sends staff to ?dd_staff_login=1 (safe frontend URL); template_redirect/staff_dashboard_bounce() forwards to DD dashboard** |
| **v3.8.0** | ✅ **Done** | **Fix login redirect via admin_init (standard WP pattern); redirects Restaurant Owner/Manager to DD dashboard on wp-admin load; Fri Soft admins pass through; removes all previous wp_login/login_redirect/template_redirect attempts** |
| **v3.8.1** | ✅ **Done** | **Fix wp-admin gate (allow dd_manage_orders); add staff_frontend_redirect to bounce Owner/Manager from frontend through custom admin path into DD dashboard** |
| **v3.8.2** | ✅ **Done** | **Fix staff_frontend_redirect — exclude administrator role and wp-login.php from redirect, preventing loops; redirect to admin_url() directly** |
| **v3.8.3** | ✅ **Done** | **Remove custom admin path feature entirely — delete maybe_block_wp_admin, register_admin_rewrite, handle_admin_redirect, staff_frontend_redirect, staff_dashboard_redirect; remove Security card from settings.php** |
| **v3.8.4** | ✅ **Done** | **Fix login redirect via woocommerce_login_redirect filter at priority 9999 — staff roles and administrator sent to DD dashboard after login** |
| **v3.8.5** | ✅ **Done** | **Fix WooCommerce prevent_admin_access blocking staff roles — grant manage_woocommerce + view_admin_dashboard to Owner/Manager; add woocommerce_prevent_admin_access filter; roles version bumped to '4'** |
| **v3.8.6** | ✅ **Done** | **Phase 7A polish — hide Payments/Marketing/Tools/Profile menus for staff; login_redirect sends Owner/Manager to DD dashboard; Dish Dash submenu auto-expands** |
| **v3.8.7** | ✅ **Done** | **Remove Settings, Tools, Template from Dish Dash submenu for Owner/Manager; pages remain URL-accessible** |
| **v3.8.8** | ✅ **Done** | **Restaurant Manager granted full Owner capability set (identical roles); roles version '5' forces re-migration** |
| **v3.8.9** | ✅ **Done** | **Phase 7B Brief 1 — Activity Log core: DD_Activity_Module, wp_dd_activity_log table, dd_log_activity hook, staff-only filter** |
| **v3.9.0** | ✅ **Done** | **Phase 7B Brief 2 — Activity Log capture hooks: login/logout, order_status_changed, order_confirmed, reservation_status_changed (both POST + AJAX paths), settings/template/homepage saves** |
| **v3.9.1** | ✅ **Done** | **Phase 7B Brief 3 (FINAL) — Activity Log viewer page: admin-only, filters by user/action/date, 50/page pagination, human-readable descriptions, hidden from Owner/Manager** |
| **v3.9.2** | ✅ **Done** | **Activity Log layout polish — emoji removed from H1, 24px top margin, tightened header spacing** |
| **v3.9.3** | ✅ **Done** | **Notification real timestamps — created_at in both poll queries, ddParseServerTime helper, orders + reservations both get accurate "X min ago"** |
| **v3.9.4** | ✅ **Done** | **Fix notification elapsed time via SQL TIMESTAMPDIFF — server-computed seconds_ago eliminates three-clock mismatch; remove ddParseServerTime helper; ddTimeAgo clamps negative diff to 0** |
| **v3.9.5** | ✅ **Done** | **Branded welcome/set-password email for new staff users — user_register hook + wp_send_new_user_notification_to_user filter in DD_Auth_Module; suppresses WP default plain email for Owner/Manager** |
| **v3.9.6** | ✅ **Done** | **Live notification count (same for all staff) — pending_count from server replaces per-browser localStorage accumulation; badge set authoritatively on every poll** |
| **v3.9.7** | ✅ **Done** | **Authoritative live notification worklist — ajax_poll_notifications returns all pending orders + reservations as pending_items; panel rebuilds from server on every poll; badge = exact count (99+ cap); "Mark all read" removed; items clear only when confirmed/cancelled** |
| **v3.9.8** | ✅ **Done** | **Customer Profile link foundation — user_id (nullable, UNIQUE) on wp_dishdash_customers; link_user_to_phone() identity model (one profile per phone, no stealing); get_customer_for_user(); on_resolve_customer_id back-fills user_id for logged-in customers placing orders** |
| **v3.9.9** | ✅ **Done** | **DD_Customer_Profile::get() unified read interface — commercial record + tier + birthday + favorites (from order history) + recent orders + restaurant WhatsApp; read-only; loaded from class-dd-orders-module.php** |
| **v3.10.0** | ✅ **Done** | **Phase 7C Brief 3 — My Profile UI tab: DD_Profile_Module, WC account endpoint, tier badge, stats, favorites, birthday editor, WhatsApp contact, phone-link prompt** |
| **v3.10.1** | ✅ **Done** | **My Account layout + menu cleanup: two-column branded sidebar/panel, trimmed menu (My Profile · Order History · Addresses · Account details · Log out)** |
| **v3.10.2** | ✅ **Done** | **Header "My Account" button + real order history: $account_url links header buttons to /my-account/my-profile/; render_order_history() hooks woocommerce_account_orders_endpoint (priority 5) to show real orders from dishdash_orders with number, date, total, status badge, and items** |
| **v3.10.3** | ✅ **Done** | **Fix My Profile endpoint: add woocommerce_get_query_vars filter (add_wc_query_var) — missing third registration caused WC to fall back to account dashboard instead of rendering My Profile** |
| **v3.10.4** | ✅ **Done** | **Header button → "My Profile" via wc_get_account_endpoint_url('my-profile'); both mobile drawer and desktop buttons updated** |
| **v3.10.5** | ✅ **Done** | **One-click reorder: Reorder buttons on favorites + Order History cards; dd_profile_reorder AJAX with stale-ID name resolver; print_reorder_script() static-guarded shared JS; DD_Cart::add() integration** |
| **v3.10.6** | ✅ **Done** | **PesaPal in-drawer iframe payment (Option B): DD_PesaPal class, dd_pesapal_check_status AJAX, #ddPanelPesaPal iframe panel, 5s polling, order created on COMPLETED; PesaPal label fix in PHP + admin JS** |
| **v3.10.7** | ✅ **Done** | **Fix PesaPal panel display (CSS classes not style.display), confirmation handler (res.data.order_number/status), add pesapal-logo.svg + JS logoMap** |
| **v3.10.8** | ✅ **Done** | **Fix payment logos (pluginUrl added to ddCartData, pesapal in $icon_urls, remove broken JS logoMap), fix &amp; label encoding, add checkout drawer scroll** |
| **v3.10.9** | ✅ **Done** | **Fix PesaPal — rewrite class-dd-pesapal.php with zero HTTP calls on instantiation (constructor reads wp_options only); IPN/status call timeouts reduced to 15s; fixes site timeout caused by API calls during paymentGateways closure** |
| **v3.10.10** | ✅ **Done** | **PesaPal logo PNG (replace SVG with official 789×210 PNG), panel padding (16px on dd-pesapal-waiting), label confirmed in PHP + JS maps, pesapal-logo.svg removed** |
| **v3.10.11** | ✅ **Done** | **Audit fixes — .gitignore for .min files, reservation_made tracking (DDTrack.track → DDTrack.event), ALTER TABLE is_test guard (DESCRIBE → SHOW COLUMNS LIKE), reorder tracking event** |
| **v3.10.12** | ✅ **Done** | **Automated Audit Dashboard + WP-CLI regression suite — DD_Audit_Module, DD_Audit_Runner (6 pillars), DD_Audit_CLI, admin/pages/audit.php, assets/css/audit.css, assets/js/audit.js** |
| **v3.10.13** | ✅ **Done** | **Complete audit remediation — tracking table migration in install.php, version-gated migration for existing installs, ALTER TABLE guards verified, audit runner self-scan false positives fixed, P5 unescaped query heuristic improved, remove_submenu_page() replaced with admin_head CSS-only hiding** |
| **v3.10.14** | ✅ **Done** | **Template output escaping — birthday.php, page-dishdash.php, grid.php, my-profile.php; .gitignore *.min + .DS_Store + *.php.save patterns** |
| **v3.10.15** | ✅ **Done** | **Skipped (version reserved)** |
| **v3.10.16** | ✅ **Done** | **Fix admin menu disappearing — replace :has() submenu selectors with .wp-submenu a[href*=...] targeting in hide_irrelevant_menu_items(); :has() matched parent <li> and collapsed entire Dish Dash menu for all users** |
| **v3.10.17** | ✅ **Done** | **Guard ALTER TABLE ADD COLUMN schema_version in class-dd-tracking-module.php with SHOW COLUMNS check; ADD KEY was already guarded** |
| **v3.10.18** | ✅ **Done** | **Fix audit scanner false positives — P3 accepts SHOW INDEX as valid ALTER TABLE guard; P5 variable-interpolation regex already correct from v3.10.13** |
| **v3.10.19** | ✅ **Done** | **Audit treats minification as intentional — P2 confirms .min presence, P4 measures production payload, P4 confirms .min files exist** |
| **v3.10.20** | ✅ **Done** | **Remove minification system — asset_url() serves source files directly, workflow no longer generates .min files, LiteSpeed handles compression; removed stray VERSION + dish-dash.php.save** |
| **v3.10.21** | ✅ **Done** | **Audit runner matches post-minification state — P2/P4 pass when no .min files exist, P4 measures source payload directly** |
| **v3.10.22** | ✅ **Done** | **Fix audit score color — (int) cast on round() makes $score === 100 strict comparison work; 100% pillars now green** |
| **v3.10.23** | ✅ **Done** | **P4 measures only frontend assets against realistic pre-compression thresholds (100KB/file, 300KB total); admin-only assets excluded** |
| **v3.10.24** | ✅ **Done** | **Add 🔍 emoji to Audit submenu label to match other Dish Dash menu items** |
| **v3.10.25** | ✅ **Done** | **Add CSS for Customer Tiers dashboard panel — stacked bar + two-column legend grid with colored dots, tier names, right-aligned counts** |
| **v3.10.26** | ✅ **Done** | **Redesign Customer Tiers as horizontal histogram — one bar per tier scaled to largest tier, replaces stacked bar + two-column legend** |
| **v3.10.27** | ✅ **Done** | **Add padding: 4px 20px 8px to .dd-tier-hist so histogram bars/labels have horizontal breathing room** |
| **v3.10.28** | ✅ **Done** | **Security fix — close IDOR on dd_get_order AJAX endpoint: ownership gate in ajax_get_order() (class-dd-orders-module.php), staff bypass via dd_manage_orders, customers restricted to own orders via customer_id === get_current_user_id(), guests refused** |
| **v3.10.29** | ✅ **Done** | **Remove orphaned unauthenticated dd_cancel_order AJAX endpoint (write-path IDOR — zero callers, deregistered)** |
| **v3.10.30** | ✅ **Done** | **Customer order-tracking (logged-in): [dish_dash_track] live self-refreshing status timeline (polls dd_get_order, stops on terminal status); resolved [dish_dash_track] double-registration (Orders module sole owner); track_order_view schema added** |
| **v3.10.31** | ✅ **Done** | **Fix order-history ownership-key bug (3 sites): order queries bind WP user ID (get_current_user_id()) not the customers-table PK — render_order_history() + DD_Customer_Profile::get() favorites/recent-orders now correct** |
| **v3.10.32** | ✅ **Done** | **normalize_phone() strips national trunk 0 (10-digit leading-0 → +250 keyspace); format-preserving bare 250…; 0788… and +250788… now one identity key — stops new duplicate customers + failed linking. Backfill of legacy 0788… rows deferred to full-international phase** |
| **v3.10.33** | ✅ **Done** | **R1 — self-hosted intl-tel-input v25.3.1 country-code picker on cart drawer, My Profile connect, and reservations; getNumber() E.164 with raw fallback; initialCountry rw, EAC neighbors prioritized, separateDialCode; vendored under assets/vendor/ (.gitignore whitelisted); behavior-neutral server-side (normalize_phone + stored format unchanged)** |
| **v3.10.34** | ✅ **Done** | **R1.5 — phone placeholder fix (removed duplicate +250 → clean "78 000 0000") + per-country validation on cart/profile/reservations: soft inline hint on input/blur + hard-block on submit, guarded `iti.isValidNumber() === false` so it fails open when the picker/utils.js isn't loaded; no new CSS files; server-side unchanged** |
| **v3.10.35** | ✅ **Done** | **R2 — Composer + vendored libphonenumber: defensive `vendor/autoload.php` require (file_exists-guarded, fails open) in dish-dash.php after constants/before DD_ SPL autoloader; `.gitignore` `!vendor/` whitelist; CI presence-guard in release.yml (fails build if vendor/autoload.php missing from zip). Behavior-neutral — library present-but-idle, nothing calls it, normalize_phone() untouched** |
| **v3.10.36** | ✅ **Done** | **R1.6 — mobile phone-picker zoom fix: (1) `font-size:16px` on mobile for `.iti__search-input` (v25 auto-focuses it → iOS zoom) + cart tel input (cart.css, ≤768px) + `.dd-profile__input` (profile.css, ≤768px); cart.css loads on all DishDash pages so the search-box rule is global. (2) `dropdownContainer: document.body` on all three inits (cart.js/my-profile.php/reservations.js) so the position:fixed fullscreen popup escapes the cart drawer's transform + overflow:hidden. Viewport untouched; server-side/desktop unchanged** |
| **v3.10.37** | ✅ **Done** | **R1.6-fix — country dropdown was buried behind the cart drawer after v3.10.36. `dropdownContainer: document.body` detaches the list to `.iti--container` (vendored z-index 1060), below the drawer (desktop 9200; mobile 10001 !important + overlay 10000). Fix (Option B, CSS-only): kept the container on all three inits, added global non-media-scoped `.iti--container { z-index: 10050 }` in cart.css to clear both drawers. v25 auto-bodies the fullscreen popup on mobile regardless (verified in min.js), so mobile was buried all along — now visible. 16px anti-zoom retained; no server change** |
| **v3.10.38** | ✅ **Done** | **R1.6-fix-2 — the v3.10.37 dropdown z-index fix was inert: vendored `intlTelInput.min.css` `.iti--container{z-index:1060}` (enqueued after cart.css, equal specificity) won the cascade tie by load order, so the dropdown still opened at z-1060 behind the drawer. Added `!important` → `.iti--container { z-index: 10050 !important; }` so it deterministically wins. Dropdown now renders above the drawer/overlay and is selectable on desktop + mobile. dropdownContainer + 16px CSS untouched; no JS/server change** |
| **v3.10.39** | ✅ **Done** | **R2.5 — normalize_phone() routes through libphonenumber with byte-identical bare 250… output. Historical digit logic runs first (its `<9 → ''` is the parity gate); accepted inputs get `parse($phone,'RW')→format(E164)→ltrim('+')`. Three fail-safes (class_exists + try/catch NumberParseException + ctype_digit) → never fatals/regresses. Output stays bare (no +, no E.164) — stored keys unchanged, zero orphaning. WP-CLI 11-row parity harness confirmed full parity on the server. No caller change; one file** |
| **v3.10.40** | ✅ **Done** | **R3 — flag-gated E.164 format flip + backfill/dedupe migration. `dd_phone_format` option (default bare → deploy behavior-neutral); normalize_phone() gated (e164: format(E164) with +, fallback `'+'.$digits`, guard `/^\+\d{9,15}$/`; bare: today's behavior). Migration `scripts/dd-r3-migrate.php` (dry-run default, `commit` writes): re-point children → DELETE non-survivors → merge+normalize survivor whatsapp → flag flip LAST (zero mismatch window); one transaction; survivor=linked user_id else oldest; conflicts/junk flagged not touched; idempotent. Backup+dry-run review mandatory before commit. Migration NOT yet run on server** |
| **v3.10.41** | ✅ **Done** | **R3-fix — normalize_phone() coercion bug: bare foreign number (id 18 `674069873633`) was coerced to `+250674069873633` by `parse($phone,'RW')`. Fix: leading `+` → parse international (region null, foreign preserved); bare → parse RW accepted only if `isValidNumber()`, else '' (junk, not coerced). Migration inherits the fix (id 18 now junk-flagged; 4 RW clusters unchanged). Harness ALL PASS. Flag still bare; migration not yet committed** |
| **v3.10.42** | ✅ **Done** | **R4a — data-only order-phone normalization (no plugin logic changed): rewrote 6 legacy `dishdash_orders.customer_phone` values to `+250…` E.164 (ids 1,25,26,35,44,57) so order-side matches the R3 customers key. 9 rows intentionally excluded + documented (8 truncated ids 38,43,88,90,91,110,111,112; 1 foreign preserved id 116 Nauru +674). Verified live: 15→9 non-clean, 113 RW E.164 + 8 + 1 = 122. Backup pre-r4a-20260706-155534.sql. No customer_id/attribution/schema change. FLAG: 8 truncated rows share +2507865340 → write-path truncation bug (parked)** |
| **v3.10.43** | ✅ **Done** | **R4b — read-side phone-anchored order resolution: favorites (customer-profile.php:106), recent (:126), order-history (profile-module.php:142) now match `( customer_id = %d OR customer_phone IN (<canonical E.164>) )`, tying guest orders to the logged-in user. Canonical-only phone set, built in PHP, empty→customer_id-only (no IN ()/IN (NULL)); generated %s placeholders via prepare (never concatenated); no double-count. Read-side only — ownership gates/customer_id/schema untouched** |
| **v3.10.44** | ✅ **Done** | **R1 — retire vestigial `[dish_dash_account]` shortcode: removed BOTH double-registrations (menu-module.php:54 + orders-module.php:110). Orders won by load order → page 11 `/my-restaurant-account/` (in no menu; real account is `/my-account/` via DD_Profile_Module) silently rendered the DD orders list. Shortcode now dead; page 11 to be trashed manually in WP admin (not deleted in code). Orphaned `shortcode_account()` handlers + install.php:615 seed + docs left as-is per scope. No tracker/gate/schema change.** |
| **v3.10.45** | ✅ **Done** | **R2 — Track Order page: active-orders LIST (Option A). `shortcode_track()` default branch now renders a phone-anchored snapshot list (R4b OR-block + `is_test=0` + `status IN (pending,confirmed,ready)`) via new `state='list'`; `?order_id=`/`?order=` per-order live tracker extracted UNCHANGED into `render_single_track()`. `track.php` gains a `list` branch (rows link to `?order_id=`) + "No active orders" empty state; list-row CSS appended to order-tracking.css. Sidebar S1: `add_menu_item()` appends `track-order` + `woocommerce_get_endpoint_url` href remap to `/track-order/`. No `ajax_get_order()` gate change, no guest tracking, no customer_id writes. v1 limit: phone-only orders list but 404 on detail (→ R4c).** |
| **v3.10.46** | ✅ **Done** | **R2 fix — Track list: only owned rows are clickable. List SELECT adds `customer_id`; template links a row to `?order_id=` only when `customer_id === current_user_id`. Phone-only rows render `number · status · time · "In progress"` as a non-clickable static div (no hover/pointer) so no one hits the customer_id-only gate → "Order not found". No gate change, no customer_id write.** |
| **v3.10.47** | ✅ **Done** | **R2 styling fix — added `is_page('track-order')` to `is_dishdash_page()` (template-module.php:182) so `/track-order/` enqueues the DishDash frontend bundle (theme.css/frontend.css base styles + menu/cart/reservations CSS + JS). Was rendering raw HTML because the track page matched none of the existing entries. Slug-string pattern matched; no render-logic change.** |
| **v3.10.48** | ✅ **Done** | **R2 polish — fixed empty status pills (order-tracking.css `--dd-track-accent` referenced the wrong tokens → fell to currentColor → white-on-white; now `var(--brand, …, #65040d)` + color-by-status pills amber/blue/green) and added the profile sidebar to the Track page (Option 2: `.dd-account` wrapper, profile.css selectors extended to `.dd-account`, no `.woocommerce-account` body class). track.php restructured: logged-in states render sidebar (`wc_get_account_menu_items()`, Track Order active) + content column with the existing `.dd-track` card; guest stays a centered message. Timeline (`?order_id=`) visually unchanged.** |
| **v3.10.49** | ✅ **Done** | **R2 pill polish — all list-row status pills use one brand color (`var(--brand, #65040d)`, white text; dropped per-status amber/blue/green) and the row is now flex (num ellipsizes, pill+timestamp `flex:0 0 auto` so nothing clips at the card edge). Timeline + sidebar unchanged.** |
| **v3.10.50** | ✅ **Done** | **Order Delivery Modes R1 — settings fields only (no behavior wired). "Order Handling" card in settings.php: `dish_dash_order_notify_dashboard` (default '1'), `dish_dash_order_handoff_whatsapp` (default '0'), `dish_dash_momo_merchant_code` (digits-only text). Persist to wp_options only; nothing reads them yet.** |
| v3.10.51 | ⏳ **NEXT** | Order Delivery Modes R2 — gate the dashboard notification system (enqueue + `ajax_poll_notifications()`) behind `dish_dash_order_notify_dashboard` |
| v3.10.52 | ⏳ | R3 — customer WhatsApp handoff button on confirmation (opt-in, JS re-wire; reuse server `whatsapp_url`) |
| v3.10.53 | ⏳ | R4 — Manual MoMo method: up-front `place_order()` (COD-style, `payment_status='claimed_pending'`), separate from Option B |
| v3.10.54 | ⏳ | R5 — render dynamic MoMo QR (`tel:*182*8*1*{merchant}*{amount}%23`) + iOS copy fallback |
| v3.10.55 | ⏳ | R6 — single-tap "I have paid" → flip `payment_status='claimed'` + open WhatsApp ticket |
| v3.10.5x | ⏳ | R4c — customer_id attribution (parked; needs conflict-order decision) |

**Dashboard v3.4.44 spec (agreed design):**
- Header: page title + open/closed status dot + date range filter (Today/7d/30d/All)
- KPI row: 6 cards with colored left accent strips — Orders (indigo), Revenue (emerald), Pending (amber), AOV (blue), New Customers (purple), Reservations Today (rose)
- Each KPI card: icon + label + big number + delta badge (↑↓%)
- Revenue chart: bar chart, brand color bars, Chart.js, range-aware (hourly for Today, daily for 7d/30d)
- Left column (60%): Recent Orders list + Today's Reservations list
- Right column (40%): Top Menu Items (ranked + progress bars) + Customer Tiers (stacked bar)
- Quick Actions bar: Add Menu Item · View Orders · Preview Menu · Settings
- All colors from `--dd-brand` — zero hardcoded hex
- Content fills 100% available width

#### Part 2 — Frontend Template System (v3.4.48+)

- DishDash pages registered as proper WordPress page templates
- Folder: `templates/themes/khana-khazana/` (SaaS-ready — multiple templates post-MVP)
- Active template controlled by `dd_active_template` wp_option
- Specific page decisions (keep/delete/redirect) made at implementation time

---

### Phase 5E — Template Library (Post-MVP)

| Item | Status |
|---|---|
| Khana Khazana template | ✅ Default — built |
| Additional templates | ⏳ Post-MVP |

---

## 🗄 Key Database Tables

| Table | Key Columns |
|---|---|
| `wp_dishdash_orders` | id, wc_order_id, customer_name, customer_phone, total, status, payment_status, payment_method, order_type, created_at |
| `wp_dishdash_order_items` | order_id, product_name, quantity, price |
| `wp_dishdash_customers` | whatsapp (primary identity), name, total_orders, total_spent, first_order_at, last_order_at, birthday, delivery_address, dd_birthday_asked |
| `wp_dishdash_reservations` | date, time, guests, name, whatsapp, status, session |
| `wp_dishdash_user_events` | event_type, product_id, category_id, meta JSON, schema_version, created_at |
| `wp_dishdash_user_profiles` | Built in Phase 6 |
| `wp_dishdash_birthday_tokens` | token, customer_id, used, expires_at |
| `wp_dishdash_delivery_zones` | Future — created now, not yet used |

**Customer tier thresholds:**
| Tier | Condition |
|---|---|
| New | 0 orders |
| Regular | ≥1 order, total_spent < RWF 100,000 |
| VIP | total_spent ≥ RWF 100,000 |
| Champion | total_spent ≥ RWF 250,000 |
| Diamond | total_spent ≥ RWF 500,000 |

---

## ⚙️ wp_options Keys Reference

**Brand / Template:**
`dish_dash_restaurant_name`, `dish_dash_logo_url`, `dish_dash_primary_color`,
`dish_dash_dark_color`, `dish_dash_background_color`, `dish_dash_font`,
`dish_dash_hero_title`, `dish_dash_hero_subtitle`, `dish_dash_hero_image`,
`dish_dash_address`, `dish_dash_phone`, `dish_dash_contact_email`,
`dish_dash_opening_hours`, `dish_dash_facebook`, `dish_dash_instagram`,
`dish_dash_whatsapp`, `dish_dash_tiktok`

**Delivery:**
`dd_free_delivery_threshold` (10000), `dd_delivery_fee` (1500), `dd_delivery_eta`

**WhatsApp:**
`dd_whatsapp_admin`

**Hours:**
`dd_opening_hours`, `dd_closing_soon_minutes` (30), `dd_timezone` (Africa/Kigali)

**Pricing & Fees (Phase 5D v3.4.91+):**
`dd_per_order_fee` (750, INT) — flat fee (RWF) charged per confirmed order for Dish Dash invoicing
`dd_minimum_order_amount` (10000, INT) — advisory minimum shown to customers at checkout
`dd_payment_card_enabled` ('1', '0'|'1') — whether Pesapal card is offered
`dd_payment_momo_enabled` ('1', '0'|'1') — whether MTN MoMo Pay is offered
`dd_payment_cod_enabled` ('1', '0'|'1') — whether Cash on Delivery is offered

**Admin (Phase 5):**
`dd_admin_custom_path` — custom admin URL path, superadmin only

**Frontend:**
`dd_active_template` — active frontend template slug (default: khana-khazana)

---

## 🧠 AI Core Systems (Build in Phase 6)

### 1. Behavior Tracking Engine ✅ Already Live
- Table: `wp_dishdash_user_events`
- Events tracked: view_product, view_category, search, add_to_cart, page_view, order, reorder
- Validation: runtime schema enforcement — 0% failure rate
- Health check: WP Admin → Dish Dash → Tools

### 2. User Profile Engine (Phase 6)
- Table: `wp_dishdash_user_profiles` (exists in DB, not yet populated)

### 3. AI Rules Engine (Phase 6)
- Simple IF/THEN rules first — no ML yet
- Module: `modules/ai/class-dd-ai-module.php`

### 4. Smart Nudges System (Phase 6)
- Module: `modules/nudges/class-dd-nudges-module.php`

---

## 📊 Tracking Status

| Event | Source | Status |
|---|---|---|
| `view_product` | tracking.js (IntersectionObserver) | ✅ Live |
| `view_category` | tracking.js + menu-page.js | ✅ Live |
| `search` | tracking.js | ✅ Live |
| `add_to_cart` | tracking.js | ✅ Live |
| `page_view` | tracking.js | ✅ Live |
| `order` | DDTrack.order() | ✅ Schema defined |
| `reorder` | PHP only | ✅ Schema defined |
| `remind_me_open` | frontend.js | ⏳ Phase 6 |

**Health Check:** 0 failures / 189 events sampled. Validation mode: `warn`.

---

## 🧠 Key Lessons Learned (Hard-Won)

| Lesson | Context |
|---|---|
| Always check inline styles in PHP templates before CSS files | 800px width was an inline style in `grid.php` — caused 4 wrong fixes |
| `page-dishdash.php` is the HOMEPAGE template, not the menu page | Shortcode in `grid.php` renders `/restaurant-menu/` |
| `dish-dash-theme` is the active theme, NOT Astra | Only `dish-dash-theme` exists on server |
| `display: flex !important` overrides HTML `hidden` attribute | Use `.dd-cat-row:not([hidden])` instead |
| Unchecked HTML checkboxes don't submit in forms | Must use `isset($_POST[$key]) ? '1' : '0'` |
| LiteSpeed Cache masks frontend changes | Always purge explicitly when debugging UI |
| `git push origin Main` creates an orphan branch | Always lowercase `main` |
| Functions inside containing functions cause JS scope conflicts | Extract into independent modules |
| `dbDelta()` does not run on zip updates | New DB tables need manual `DD_Install::create_tables()` via WP-CLI post-deploy |
| `remove_all_actions('admin_notices')` suppresses all plugin banners | Exception: check `get_current_screen()->id === 'update-core'` first |
| Dashboard content width was capped — right side dead zone | Check for `max-width` in admin.css AND inline styles in dashboard.php |
| Google Reviews `(array) $r` deep-cast bug | Only converts outer level — fix requires recursive `dd_to_array()` |
| WhatsApp notifications use `window.location.href` not `window.open` | Avoids mobile browser popup blocking |
| `woocommerce_payment_complete` hook wired to `DD_Notifications` | Any future gateway fires notifications automatically |
| OPcache/auto-update race condition causes fatal errors | Mitigated with `class_exists` guard + `opcache_reset()` on `upgrader_process_complete` |
| Minification removed in v3.10.20 | `asset_url()` now returns source files directly — no `.min` lookup. GitHub Actions no longer generates `.min` files. LiteSpeed Cache handles production compression. The minifier was failing silently (copying originals), so `.min` files provided no benefit. |

---

## 🚀 Multi-Tenant Deploy Checklist

Run before handing any site to a restaurant:

1. AJAX smoke test:
```bash
curl -s -X POST https://[site]/wp-admin/admin-ajax.php \
  -d "action=dd_cart_get" | grep -q "success" \
  && echo "AJAX ✅" || echo "AJAX ❌ BROKEN"
```
2. Confirm response is not 404
3. Set up UptimeRobot monitor on `https://[site]/wp-admin/admin-ajax.php`
   — POST method, 5-min interval, SMS + email alert on failure
4. Only hand site to restaurant after both checks pass

---

## 📋 Related Documentation

| Document | Purpose |
|---|---|
| `ARCHITECTURE.md` | URL → file mapping, module dependency graph |
| `CSS_REGISTRY.md` | Every `dd-` CSS class: where defined, where used |
| `MODULE_CONTRACT.md` | Module isolation rules, hooks registered/fired |
| `TRACKING_ROADMAP.md` | Tracking expansion plan |
| `TECHNICAL_ARCHITECTURE_VISION.md` | PHP → Python hybrid migration roadmap |
| `modules/tracking/event-schemas.php` | Living schema contract for event metadata |

---

## 📝 Session History

| Date | Versions | What was accomplished |
|---|---|---|
| 2026-04-13/14 | v3.1.9 → v3.1.13 | Menu page fixes, GitHub Actions restored, `main` branch discipline |
| 2026-04-14 | docs only | Architecture docs (5 files), file headers (56 files) |
| 2026-04-14/16 | v3.1.14 → v3.1.17 | DD_API, schema versioning, validation, health check |
| 2026-04-20/21 | v3.2.5 → v3.2.12 | Mobile 3-screen UI complete, cart AJAX wired |
| 2026-05-20 | v3.4.15 | Phase 5 full plan written, admin transformation design system established |
| 2026-05-20/24 | v3.4.16 → v3.4.43 | Phase 5A/5B/5C complete. Orders, Analytics, Customers, Reservations redesigned. |
| **NEXT** | **v3.4.44** | **Dashboard full redesign — live KPIs, chart, top items, customer tiers** |
