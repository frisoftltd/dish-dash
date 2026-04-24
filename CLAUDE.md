markdown# 🧠 Dish Dash — Session Context & Workflow

> **This file is the single source of truth for every AI coding session.**
> Read this ENTIRELY before doing any work. Updated after every release.
>
> Last updated: v3.2.12 (2026-04-21)

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

DishDash is NOT:
- ❌ A basic WooCommerce storefront
- ❌ A visible "AI system" (users should feel fast/easy/natural, not "AI")
- ❌ A clone of Uber Eats or Glovo

### Development Principles (STRICT)

1. **Every feature must answer:** "How does this help understand user behavior?"
2. **No feature without tracking.** Every user action must be recorded.
3. **AI is invisible.** Users feel fast, easy, natural — never "AI system."
4. **Mobile-first always.** Africa is mobile, not desktop.
5. **Keep architecture clean.** Follow the modular system. No shortcuts.
6. **Speed is addictive.** Optimize everything for performance. Fast = repeat usage.

### Architecture Flow
User
↓
UI (DishDash frontend)
↓
🧠 AI Layer (behavior tracking + rules engine)
↓
Cart
↓
WooCommerce (payment processing)

---

## 📌 Current State

| Field | Value |
|---|---|
| **Deployed version** | v3.2.12 |
| **Current phase** | Phase 3 — Cart, Orders & Delivery |
| **Current sub-phase** | 3A — The Cart (starting now) |
| **Next task** | v3.2.13 — Cart panel UI + floating button |
| **Last working state** | Phase 2 complete. Mobile 3-screen menu live. Desktop menu polished. Deep links working. Tracking validated. DD_API live. Schema enforcement at 0% failure. |
| **GitHub** | github.com/frisoftltd/dish-dash |
| **Live site** | dishdash.khanakhazana.rw |
| **Server** | cPanel at server347.web-hosting.com (user: khansqtg) |
| **Plugin path** | /home/khansqtg/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ |
| **Theme** | dish-dash-theme (custom theme, NOT Astra) |
| **WooCommerce** | Active — products used as menu items |

---

## 🎨 Brand Colors

| Color | Hex | Where used |
|---|---|---|
| **Primary (brand)** | `#65040d` | Homepage, header, logo background, hero |
| **Accent (menu)** | `#E8832A` | Menu page prices, Add to Cart buttons, category active states |
| **Dark/Secondary** | `#000000` | Text, secondary elements |
| **Background** | `#F5EFE6` | Page background (warm cream) |
| **Surface** | `#FBF7F1` | Card backgrounds, filter card |
| **Gold** | `#C9A24A` | Accent highlights, premium elements |

**Rule:** Homepage uses `#65040d` as primary. Menu page `/restaurant-menu/` uses `#E8832A` for prices and buttons. Do NOT mix them.

---

## 🔄 Workflow — How We Work

### Roles

| Role | Responsibilities |
|---|---|
| **Claude** (claude.ai) | Architecture, root cause analysis, writing briefs |
| **Claude Code** (terminal) | Investigation, file edits, git add + commit + push |
| **Developer** (human) | Paste briefs to Claude Code, create GitHub release, deploy, test, report back |

### The Loop — NEVER SKIP STEPS

1. **Claude writes Investigation Brief** → developer pastes to Claude Code
2. **Claude Code investigates** → reports findings (no edits yet)
3. **Developer pastes findings to Claude** → Claude diagnoses root cause
4. **Claude writes Fix Brief** → developer pastes to Claude Code
5. **Claude Code edits files** → runs `git add + commit + push origin HEAD:main`
6. **Developer creates GitHub release** tag `vX.X.X` → deploys → tests
7. **Developer reports result to Claude** (screenshot or description)
8. **Claude writes next brief** → repeat from step 1

### Claude Code Session Setup — ALWAYS START WITH

### Release Process

1. All files committed to `main` branch (lowercase, NEVER `Main`)
2. Version bumped in `dish-dash.php` in BOTH locations:
   - `* Version: X.X.X` in the header comment
   - `define('DD_VERSION', 'X.X.X');` constant
3. Developer creates GitHub release with tag `vX.X.X` (WITH the `v` prefix)
4. GitHub Actions builds `dish-dash.zip` automatically (~30 seconds)
5. Deploy via ONE of two methods:

**Method A — cPanel Terminal (when WP updater times out):**
```bash
cd /tmp && wget https://github.com/frisoftltd/dish-dash/releases/latest/download/dish-dash.zip && unzip -o dish-dash.zip -d /tmp/dd-update && cp -r /tmp/dd-update/dish-dash/* /home/khansqtg/dishdash.khanakhazana.rw/wp-content/plugins/dish-dash/ && rm -rf /tmp/dd-update /tmp/dish-dash.zip && echo "Done!"
```

**Method B — WordPress auto-update:**
WP Admin → Plugins → Check for Updates → Update Now

6. Purge LiteSpeed Cache: WP Admin → LiteSpeed Cache → Toolbox → Purge All
7. Test in incognito window
8. Verify: `grep "DD_VERSION" /home/khansqtg/.../dish-dash.php`

---

## 🚨 Rules — NEVER Break These

### Code Rules

- **Always provide complete files** — never partial snippets
- **Always include exact GitHub path** for every file
- **Always state CREATE new or EDIT existing** for each file
- **Never change code outside the scope of the current task** — scope creep causes regressions
- **Always check current file state before editing** — read the file first
- **Investigation findings BEFORE writing code** — always diagnose, then fix
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

### Data Access Rules (as of v3.1.15)

- **All NEW code** must use `DD_API::` for data access (not direct `wc_get_product()` or `$wpdb` calls)
- Existing code keeps working as-is — migrate gradually when files are touched
- `DD_API` returns normalized arrays, NOT WC_Product objects
- `DD_API` has built-in transient caching (5-min TTL, auto-invalidated on product save)

### Tracking Rules (as of v3.1.16)

- **No feature without tracking** — every user action must be recorded
- Any new tracking events MUST be added to `modules/tracking/event-schemas.php` FIRST
- `meta` JSON field contains ONLY metadata — dedicated DB columns (`product_id`, `category_id`) are NOT listed in schema
- Validation mode is currently `'warn'` — events are logged but not rejected
- Event Health Check: WP Admin → Dish Dash → Tools → should show 0% failures

---

## 🏗 File Structure
dish-dash/
├── .github/workflows/release.yml      ← GitHub Actions zip builder
├── admin/
│   ├── pages/ (dashboard, orders, settings, coming-soon, event-health)
│   └── class-dd-admin.php
├── assets/
│   ├── css/ (admin, cart, menu, theme, frontend, menu-page)
│   └── js/ (admin, cart, menu, frontend, search, tracking, menu-page)
├── dishdash-core/
│   ├── class-dd-ajax.php
│   ├── class-dd-api.php               ← Normalized data facade (12 methods)
│   ├── class-dd-github-updater.php
│   ├── class-dd-helpers.php
│   ├── class-dd-hooks.php
│   ├── class-dd-loader.php
│   ├── class-dd-module.php
│   ├── class-dd-settings.php
│   └── class-dd-install.php
├── modules/
│   ├── menu/class-dd-menu-module.php
│   ├── orders/ (class-dd-orders-module.php, class-dd-cart.php)
│   ├── template/class-dd-template-module.php
│   └── tracking/ (class-dd-tracking-module.php, event-schemas.php)
├── templates/
│   ├── cart/cart.php
│   ├── checkout/checkout.php
│   ├── menu/grid.php                   ← Menu page content (shortcode)
│   ├── partials/product-card.php
│   └── page-dishdash.php               ← Homepage template
├── theme/dish-dash-theme/
│   ├── functions.php
│   ├── page.php                        ← WP page template wrapper
│   ├── singular.php
│   ├── index.php
│   └── style.css
├── ARCHITECTURE.md
├── CSS_REGISTRY.md
├── MODULE_CONTRACT.md
├── TRACKING_ROADMAP.md
├── TECHNICAL_ARCHITECTURE_VISION.md
├── CLAUDE.md                           ← THIS FILE
└── dish-dash.php                       ← Main plugin file

---

## 🗺 URL → Template Mapping

| URL | Template | Wrapper | Primary CSS | Primary JS |
|---|---|---|---|---|
| `/` (homepage) | `templates/page-dishdash.php` (via `template_include` filter) | `.dd-page` | `theme.css` | `frontend.js` |
| `/restaurant-menu/` | WP page → `theme/page.php` → `[dd_menu]` shortcode → `templates/menu/grid.php` | `.dd-menu-page.dd-menu-page--desktop` | `menu-page.css` | `menu-page.js` |
| `/cart/` | `templates/cart/cart.php` | — | `cart.css` | `cart.js` |
| `/checkout/` | `templates/checkout/checkout.php` | — | — | — |

---

## 📆 Development Phases

| Phase | Status | Description | AI Tracking to Add |
|---|---|---|---|
| **Phase 1** | ✅ | Foundation (plugin, GitHub updater, WooCommerce integration) | — |
| **Phase 2** | ✅ | Template System (header, hero, footer, branding, mobile 3-screen menu) | Track: viewed products, clicked categories, search queries |
| **Phase 3** | 🔄 | Cart, Orders, Delivery & WhatsApp — broken into 4 sub-phases (see Phase 3 Sub-Phases section below) | Save: order content, time, frequency, user WhatsApp identity, delivery preferences, cart behavior, open-hour visit patterns |
| **Phase 4** | ⏳ | Reservations (table booking, notifications) | Track: booking time patterns, group size → future: suggest time slots |
| **Phase 5** | ⏳ | Backend Dashboard (admin analytics, insights) | Show: top products, peak hours, repeat customers, suggested combos |
| **Phase 6** | ⏳ | Analytics + AI (Python microservice, behavior engine, recommendations) | AI Rules Engine, User Profile Engine, Smart Nudges |
| **Phase 7** | ⏳ | Loyalty & QR (points system, QR scan ordering) | Reward frequent users, promote favorite items |
| **Phase 8** | ⏳ | Testing + Optimization (performance, mobile UX, edge cases) | — |
| **Phase 9** | ⏳ | SaaS Platform (multi-tenant hosting, subscription billing, white-label branding for other restaurants) | — |

**Note:** POS Terminal (in-restaurant ordering) comes AFTER MVP is tested with real customers and we know exactly what restaurants need. Not scheduled as a fixed phase yet.

---

## 📦 Phase 3 Sub-Phases — Cart, Orders & Delivery

Phase 3 is built in 4 independent sub-phases. Each sub-phase leaves the site more functional than before — never broken, never half-working.

---

### Sub-Phase 3A — The Cart (v3.2.13 → v3.2.15)
**Goal:** Customers can build a cart on any page. Real cart behavior data starts flowing.

| Release | What ships |
|---|---|
| **v3.2.13** | Cart panel UI + floating button + delivery progress bar nudge |
| **v3.2.14** | Cart AJAX — add/remove/update quantities, live totals |
| **v3.2.15** | Cart persistence + reconciliation (server cart = single source of truth) |

**Tracking events added:** `remove_from_cart`, `cart_open`, `cart_abandon`, `cart_quantity_change`

**Cart source of truth rule (STRICT):**
- UI (JS) = local convenience cache only
- Server (WooCommerce session) = only real truth
- Every cart panel open MUST reconcile against server cart
- Never trust local state alone — always verify against server on open

**Delivery progress bar nudge (in cart panel):**
When order total is below free delivery threshold, show live progress:
🛵 Add 3,500 RWF more for FREE delivery
[████████░░] 6,500 / 10,000 RWF
When threshold is crossed, update instantly:
✅ You unlocked FREE delivery!

---

### Sub-Phase 3B — Orders (v3.2.16 → v3.2.17)
**Goal:** Restaurant can take real orders. End-to-end order flow without leaving the menu page.

| Release | What ships |
|---|---|
| **v3.2.16** | Checkout panel (name, WhatsApp, address, payment method selection) |
| **v3.2.17** | Order creation + threshold-based delivery fee calculation |

**Checkout form fields:**
- Full Name (required)
- WhatsApp Number (required — primary customer identity)
- Delivery Address (required)
- Payment Method: Pay Now / Pay on Delivery (radio)

**Customer identity rule:**
WhatsApp number is the primary customer identity in DishDash. On every order:
1. Check `wp_dishdash_customers` for that WhatsApp number
2. If found → link order to existing customer, update stats
3. If not found → create new customer record

**Delivery fee logic (threshold-based, no geocoding):**
Order total < dd_free_delivery_threshold  →  charge dd_delivery_fee
Order total ≥ dd_free_delivery_threshold  →  free delivery

**Payment flow rules (STRICT):**
- Pay on Delivery: WC order created → tracking fired → notifications sent → confirmation shown
- Pay Now: WC order created → payment gateway → SUCCESS: notifications sent → confirmation shown / FAILED: show retry, NO notification sent
- Notifications NEVER fire before WC order is fully created and confirmed

**Tracking events added:** `checkout_start`, `order` (already schema-defined)

---

### Sub-Phase 3C — Notifications (v3.2.18 → v3.2.19)
**Goal:** Admin and customer both notified instantly after every confirmed order. Birthday data collection begins.

| Release | What ships |
|---|---|
| **v3.2.18** | WhatsApp notifications — admin message + customer confirmation (Mode A) |
| **v3.2.19** | Birthday link flow — second WhatsApp message sent 2 min after first order only |

**Notification sequence (STRICT ORDER — never deviate):**

WooCommerce order created → order ID confirmed
Tracking event fired
Admin WhatsApp notification sent (wa.me)
Customer WhatsApp confirmation sent (wa.me)
Confirmation screen shown to customer


**Admin WhatsApp message format:**
🔔 New Order #1042 — Khana Khazana
──────────────────────────────
1× Chicken Tikka       4,500 RWF
2× Naan                1,000 RWF
──────────────────────────────
Subtotal:              5,500 RWF
Delivery:              1,500 RWF
TOTAL:                 7,000 RWF
Payment: Pay on Delivery
📍 Kacyiru, Kigali
📞 +250 78 000 0000
👤 Jean Pierre

**Customer WhatsApp message format:**
✅ Order Confirmed! — Khana Khazana
──────────────────────────────
Order #1042
Estimated time: 30–45 minutes
Payment: Pay on Delivery
Questions? Call us: +250 78 000 0000

**Birthday flow (first order only — never repeats):**
- 2 minutes after order confirmation, send second WhatsApp:
🎁 One more thing, [Name]!
We'd love to surprise you on your birthday.
Share it here (10 sec):
👉 dishdash.khanakhazana.rw/birthday/?c=TOKEN
— Khana Khazana 🍽
- TOKEN = unique, single-use, expires in 30 days
- Birthday page: two dropdowns (month + day), one button — no login required
- On submit: save to `wp_dishdash_customers.birthday`, mark token as used
- Flag `dd_birthday_asked = true` on customer record so message never sends twice

**WhatsApp Mode A now, Mode B later:**
- Mode A = wa.me links (current)
- Mode B = WhatsApp Business API (future Phase 3.5, requires Meta verification)
- Architecture must make Mode B a drop-in swap — no restructuring required

---

### Sub-Phase 3D — Hours & Polish (v3.2.20 → v3.2.21)
**Goal:** Restaurant controls when it accepts orders. Customers always know what to do, even when closed.

| Release | What ships |
|---|---|
| **v3.2.20** | Open/closed hours system — settings + all 3 UI states |
| **v3.2.21** | "Remind me when open" data capture + reorder flow |

**Hours settings (per-day, with optional split sessions):**
Each day has:
- Open / Closed toggle
- Session 1: open time → close time
- Optional "+ Add break" → Session 2: open time → close time

Example (split day):
Tuesday  [✅ Open]  11:00–15:00   +   17:00–22:00

**Three UI states:**

State 1 — Open (normal): No banner. Full ordering available.

State 2 — Closing soon (within `dd_closing_soon_minutes`, default 30 min):
⏰ We close at 10:00 PM — Order now to avoid missing out

State 3 — Closed (outside all sessions):
    🌙  We're Closed Right Now
    Khana Khazana is open:
    Monday – Sunday  11:00 AM – 10:00 PM
    We reopen in  6h 42m
    [Browse the Menu]   [📲 Message Us]

State 3B — Between split sessions (mid-day break):
    😴  We're on a break
    Back open at 5:00 PM — in 1h 23m
    [Browse the Menu]   [📲 Message Us]

**"Add to Cart" button when closed → changes to:**
[🔔 Remind me when you open]
Tapping saves: product_id + customer WhatsApp if available. Seeds Phase 6 re-engagement.

**Tracking events added:** `remind_me_open` (product_id, scheduled_open_time)

---

### Phase 3 Settings Reference

All new wp_options keys added in Phase 3:

**Delivery:**
| Key | Description | Default |
|---|---|---|
| `dd_free_delivery_threshold` | Order total for free delivery (RWF) | 10000 |
| `dd_delivery_fee` | Flat delivery fee below threshold (RWF) | 1500 |
| `dd_delivery_eta` | Estimated delivery time shown to customer | "30–45 minutes" |

**WhatsApp:**
| Key | Description |
|---|---|
| `dd_whatsapp_admin` | Restaurant WhatsApp number (receives order notifications) |

**Hours:**
| Key | Description | Default |
|---|---|---|
| `dd_opening_hours` | JSON: per-day schedule with optional split sessions | — |
| `dd_closing_soon_minutes` | Minutes before close to show warning banner | 30 |
| `dd_timezone` | Timezone for hours calculation | Africa/Kigali |

---

### Phase 3 New DB Columns

**`wp_dishdash_customers` additions:**
- `whatsapp` VARCHAR(20) — primary identity field
- `birthday` DATE NULL — collected via post-order WhatsApp flow
- `dd_birthday_asked` TINYINT(1) DEFAULT 0 — prevents duplicate birthday messages
- `delivery_address` TEXT NULL — last used address (pre-fill on next order)

**New table: `wp_dishdash_birthday_tokens`**
```sql
id          INT AUTO_INCREMENT PRIMARY KEY
token       VARCHAR(64) UNIQUE
customer_id INT
used        TINYINT(1) DEFAULT 0
expires_at  DATETIME
created_at  DATETIME
```

**`wp_dishdash_delivery_zones` (future-proofed, created now):**
```sql
id          INT AUTO_INCREMENT PRIMARY KEY
name        VARCHAR(100)
zone_type   VARCHAR(20) DEFAULT 'radius'  ← keeps door open for polygons later
radius_km   DECIMAL(8,2)
fee         INT
eta_minutes INT
is_active   TINYINT(1)
created_at  DATETIME
```
Note: threshold-based delivery is used in Phase 3. This table is created now but not used until complex zone delivery is needed.

---

## 🧠 AI Core Systems (Build in Phase 6)

These are the 4 systems that make DishDash "smart." They are NOT built yet — we are collecting data for them NOW.

### 1. Behavior Tracking Engine ✅ (ALREADY LIVE)
- Table: `wp_dishdash_user_events`
- Fields: id, user_id, session_id, event_type, product_id, category_id, meta (JSON), schema_version, created_at
- Events: view_product, view_category, search, add_to_cart, page_view
- Validation: runtime schema enforcement (0% failure rate as of v3.1.17)
- Health check: WP Admin → Dish Dash → Tools

### 2. User Profile Engine (Phase 6)
- Table: `wp_dishdash_user_profiles` (already exists in DB)
- Will store: favorite_items, favorite_categories, avg_order_value, order_times, last_orders
- Updated automatically from behavior events

### 3. AI Rules Engine (Phase 6 — START SIMPLE)
- NO complex ML yet. Simple IF/THEN rules:
  - IF ordered item 3x → mark as favorite
  - IF time = lunch → prioritize quick meals
  - IF cart < threshold → suggest add-ons
- Module: `modules/ai/class-dd-ai-module.php`

### 4. Smart Nudges System (Phase 6)
- "Add drink for 1,000 RWF"
- "Most people add naan with this"
- Module: `modules/nudges/class-dd-nudges-module.php`

### Personalized Homepage Sections (Phase 6)
- 🔁 Order Again (returning users)
- ⭐ Recommended (based on behavior)
- 🔥 Popular (trending items)
- ⚡ Quick Order ("Reorder last meal" — 1-click add to cart)

---

## 📊 Tracking Status

**Current event types being tracked:**

| Event | Source | Status |
|---|---|---|
| `view_product` | tracking.js (IntersectionObserver) | ✅ Live, validated |
| `view_category` | tracking.js + menu-page.js | ✅ Live, validated |
| `search` | tracking.js (keydown) | ✅ Live, validated |
| `add_to_cart` | tracking.js (click) | ✅ Live, validated |
| `page_view` | tracking.js (setupPageView) | ✅ Live, validated |
| `remove_from_cart` | cart.js | ⏳ Phase 3A |
| `cart_open` | cart.js | ⏳ Phase 3A |
| `cart_abandon` | cart.js (beforeunload) | ⏳ Phase 3A |
| `cart_quantity_change` | cart.js | ⏳ Phase 3A |
| `checkout_start` | checkout.js | ⏳ Phase 3B |
| `remind_me_open` | frontend.js | ⏳ Phase 3D |
| `order` | DDTrack.order() | ✅ Schema defined |
| `reorder` | PHP only | ✅ Schema defined |

**Health Check (v3.1.17):** 0 failures / 189 events sampled. Validation mode: `warn`.

**Baseline (April 14, 2026):** 3,157 view_product / 204 view_category / 38 search / 16 add_to_cart

---

## 🧠 Key Lessons Learned (Hard-Won)

| Lesson | Context |
|---|---|
| `.dd-menu-page { max-width: 800px }` was in an inline `<style>` in `grid.php`, NOT in CSS files | Caused 4 versions of wrong fixes. Always check inline styles in PHP templates. |
| `page-dishdash.php` is the HOMEPAGE template, not the menu page | The shortcode in `grid.php` renders `/restaurant-menu/`. |
| `dish-dash-theme` is the active theme, NOT Astra | Only `dish-dash-theme` exists on server. |
| `display: flex !important` overrides the HTML `hidden` attribute | Use `.dd-cat-row:not([hidden])` instead. |
| Unchecked HTML checkboxes don't submit in forms | Must use `isset($_POST[$key]) ? '1' : '0'`. |
| LiteSpeed Cache masks frontend changes | Always purge explicitly when debugging UI. |
| `git push origin Main` creates an orphan branch | GitHub branch names are case-sensitive. Always lowercase `main`. |
| Functions inside containing functions cause JS scope conflicts | Extract into independent modules (like `search.js`). |
| Dedicated DB columns (product_id) are NOT meta fields | Schema `required` only lists keys inside the `meta` JSON, not row columns. |

---

## 🎯 Template Settings (wp_options)
dish_dash_restaurant_name, dish_dash_logo_url
dish_dash_primary_color (#65040d), dish_dash_dark_color (#000000)
dish_dash_hero_title, dish_dash_hero_subtitle, dish_dash_hero_image
dish_dash_address, dish_dash_phone, dish_dash_contact_email
dish_dash_opening_hours
dish_dash_facebook, dish_dash_instagram, dish_dash_whatsapp, dish_dash_tiktok

---

## 🏁 Final Product Vision

**DishDash = A system that learns what customers want and helps them order faster every time.**

The plugin and website must be **optimized for speed** — fast = addictive = repeat usage.

**Real Impact:**
- Customers order faster (reduced time-to-order)
- Restaurants get more repeat orders (behavior-driven personalization)
- Average order value increases (smart nudges + suggestions)

**Competitive Positioning:**
- NOT a 30%-commission foreign platform (Uber Eats, Glovo)
- NOT a generic WooCommerce template
- The only locally-built, intelligent, commission-free ordering platform in East Africa

**Long-term architecture:** PHP/WordPress frontend (Phases 1-5) → Hybrid PHP + Python microservice (Phase 6+). See `TECHNICAL_ARCHITECTURE_VISION.md`.

---

## 📋 Related Documentation

| Document | Purpose |
|---|---|
| `ARCHITECTURE.md` | URL → file mapping, module dependency graph |
| `CSS_REGISTRY.md` | Every `dd-` CSS class: where defined, where used |
| `MODULE_CONTRACT.md` | Module isolation rules, hooks registered/fired |
| `TRACKING_ROADMAP.md` | 4-release tracking expansion plan (Releases A-D) |
| `TECHNICAL_ARCHITECTURE_VISION.md` | PHP → Python hybrid migration roadmap |
| `modules/tracking/event-schemas.php` | Living schema contract for event metadata |

---

## 📝 Session History

| Date | Versions | What was accomplished |
|---|---|---|
| 2026-04-13/14 | v3.1.9 → v3.1.13 | Menu page fixes (width, arrows, padding, cards, deep links), GitHub Actions restored, `main` branch discipline |
| 2026-04-14 | docs only | Architecture docs (5 files), file headers (56 files) |
| 2026-04-14/16 | v3.1.14 → v3.1.17 | Python-migration foundation (schema versioning, DD_API, validation, health check, schema alignment) |
| 2026-04-20/21 | v3.2.5 → v3.2.12 | Mobile 3-screen UI complete: category list, product list, single product, branded headers, product images fixed, attribute pills interactive, related products, cart AJAX wired (items add successfully), bottom nav unified |
| 2026-04-16/21 | v3.2.0 → v3.2.12 | Phase 2 complete: mobile 3-screen menu live, cart badge UI |
| **NEXT** | **v3.2.13** | **Sub-Phase 3A begins: Cart panel UI + floating button** |


## ⚡ Claude Code Operating Rules

1. Always start in Plan Mode: `claude --permission-mode plan`
2. Analyze first, never edit without approval
3. Use @mentions for exact files — never read whole codebase
4. Run /compact between tasks
5. After every task: git add + commit + push origin HEAD:main
6. Be concise — root cause, files changed, test steps only
