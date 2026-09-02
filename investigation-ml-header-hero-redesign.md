# Investigation: Minimal Light Header/Hero — Current Implementation (Phase 1)

**Status: read-only investigation. No code changed. No design opinions —
facts only, per the brief.**

---

## 1. Current header — markup, IDs, and the two places it lives

Minimal Light's header is **not one file** — it's duplicated in two places,
deliberately, because one is a page-template file (`page-home.php`) and the
other is a hook callback that fires on the Menu page. Both render byte-for-
byte equivalent markup:

| Context | Function | File |
|---|---|---|
| Homepage | Inline markup, no function wrapper | `templates/layouts/minimal-light/page-home.php:299–370` |
| Menu page | `DD_Template_Module::render_minimal_light_header()` (private) | `modules/template/class-dd-template-module.php:1186–1286` |

Both are called from different mechanisms: the homepage writes its own
`<body>` directly (it does **not** call `wp_body_open()`, so the shared
`inject_global_header()` hook never fires there — see file docblock,
`page-home.php:21–34`). The Menu page goes through
`inject_global_header()` (hooked on `wp_body_open`), which branches to
`render_minimal_light_header()` specifically when
`active_template() === 'minimal-light'` **and** the current request is the
Menu page (`class-dd-template-module.php:1339–1355`). Any *other* page
(Cart, Checkout, Track Order, Profile, Birthday, Reserve Table) still gets
`render_global_header()` — the **Khana Khazana shared header** — even when
Minimal Light is the active template. Confirmed no third `render_*_header`
variant exists for those pages.

### Structure (identical in both copies)

```
<header class="dd-ml-header">
  <div class="dd-container dd-ml-header__inner">
    <a class="dd-ml-header__logo">...logo or badge+name...</a>
    <div class="dd-ml-header__actions">
      <a class="dd-ml-icon-btn">          <!-- maps pin, only if address is set -->
      <button id="ddCartTopBtn" class="dd-ml-icon-btn dd-ml-header__cart">
        <span id="ddCartCount" class="dd-cart-badge">
      <button id="ddMenuToggle" class="dd-menu-toggle">  <!-- hamburger, 3 bars -->
    </div>
  </div>
</header>

<div id="ddDrawerOverlay" class="dd-drawer-overlay"></div>
<aside id="ddNavDrawer" class="dd-nav-drawer">
  <div class="dd-nav-drawer__header">...logo + #ddDrawerClose...</div>
  <nav class="dd-nav-drawer__nav">
    <?php wp_nav_menu( ['theme_location' => 'dd-primary', ...] ) ?>
    <!-- falls back to 3 hardcoded links if no WP menu assigned — see §3 -->
  </nav>
  <div class="dd-nav-drawer__footer">
    <!-- logged in: #ddLogoutBtn + "My Profile" link -->
    <!-- guest: #ddOpenRegister + #ddOpenLogin -->
  </div>
</aside>
```

**There is no horizontal nav bar anywhere in this markup.** Nav items only
exist inside the slide-out drawer. This matches the design intent stated in
`minimal-light.css`'s own header comment (`:16–30`): Minimal Light was
built specifically to **not** reuse Khana Khazana's shared header because
that one "has a visible horizontal nav + search bar + login/signup
buttons... not the icon-driven, nav-bar-free header this design calls
for." That was a deliberate v3.18.6 decision, not an oversight — worth
knowing before treating "add a nav bar" as a small tweak.

### Element IDs/classes that MUST be preserved (JS depends on them)

| ID/class | Consumed by |
|---|---|
| `#ddMenuToggle`, `.dd-menu-toggle` | `frontend.js` / `theme.css` — hamburger → drawer toggle + bar animation |
| `#ddNavDrawer`, `.dd-nav-drawer` | Same — slide-in drawer, off-canvas positioning (CSS in `theme.css`, not scoped to which PHP rendered it) |
| `#ddDrawerOverlay`, `.dd-drawer-overlay` | Same — backdrop click-to-close |
| `#ddDrawerClose` | Same — explicit close button |
| `#ddCartTopBtn` | `cart.js` — opens cart drawer |
| `#ddCartCount` | `cart.js` — cart badge count, updated live on add/remove |
| `#ddOpenLogin` / `#ddOpenRegister` | Auth module JS — opens login/register modal |
| `#ddLogoutBtn` | Auth module JS — logs out |
| `.js-open-reservation` (on any link) | `reservations.js:166–174` — intercepts click, opens the reservation modal instead of navigating |

This is the exact same ID set the shared Khana Khazana header uses — by
design (see `minimal-light.css:20–27`), so `frontend.js`/`cart.js`/
`reservations.js` need **zero changes** regardless of what the new header
looks like, as long as these IDs/classes stay attached to elements with the
same roles.

**One existing correctness note, not a header-visual issue but adjacent**:
Minimal Light's own drawer-nav fallback link for "Reserve a Table" already
uses `href="#reserve" class="js-open-reservation"` (correct — opens the
working modal). Khana Khazana's shared-header fallback instead points at
`/reserve-table/` (the broken standalone page — see
`investigation-frontend-pages.md` Observations §1). Minimal Light doesn't
inherit that bug in its fallback; only relevant if a future nav rebuild
copies from the wrong source.

---

## 2. Current hero — structure, tokens, Homepage Settings mapping

`page-home.php:372–430`, styled by `minimal-light.css:235–314` (base) +
`:676–677` (mobile).

### Markup

```
<section class="dd-ml-hero" id="top">
  <div class="dd-container dd-ml-hero__grid">     <!-- 2-col CSS grid, 1fr 1fr -->
    <div class="dd-ml-hero__content">
      <span class="dd-ml-pill">...</span>          <!-- optional, single pill -->
      <h1 class="dd-ml-hero__title">...</h1>        <!-- wp_kses_post, allows <span class="dd-gold"> -->
      <p class="dd-ml-copy">...</p>                 <!-- optional subtitle -->
      <div class="dd-ml-hero__links">                <!-- NOT buttons — see below -->
        <a class="dd-ml-text-link">Label 1</a> · <a class="dd-ml-text-link js-open-reservation">Label 2</a> · <a class="dd-ml-text-link">Label 3</a>
      </div>
      <div class="dd-ml-hero__chips">                <!-- optional, up to 4 -->
        <div class="dd-ml-hero__chip">• Text</div>
      </div>
    </div>
    <img class="dd-ml-hero__photo">                  <!-- single plain <img>, no wrapper/frame -->
  </div>
</section>
```

### Homepage Settings → field mapping

| Setting | wp_option | Renders as |
|---|---|---|
| Pill toggle | `dd_hero_pill_show` | Shows/hides `.dd-ml-pill` |
| Pill text | `dd_hero_pill_text` | `.dd-ml-pill` text |
| Hero title | `dish_dash_hero_title` | `<h1>`, `wp_kses_post()` — admin can embed `<span class="dd-gold">...</span>` inline for a colored word/phrase |
| Hero subtitle | `dish_dash_hero_subtitle` | `.dd-ml-copy` |
| Hero card image | `dish_dash_hero_image` | Primary source for `.dd-ml-hero__photo` |
| Hero background image | `dd_hero_bg_image` | **Fallback** if no hero image — used as the same plain `<img>`, not an actual background/overlay treatment |
| (no image at all) | — | Falls back to the first "best-selling" product's image, or WooCommerce's placeholder |
| Btn 1/2/3 label+link | `dd_hero_btn{1,2,3}_label/link` | All 3 rendered together as **text links** separated by `·`, in configured order — **not 2 buttons** (see below) |
| Chips toggle | `dd_hero_show_chips` | Shows/hides chip row |
| Chips 1–4 | `dd_hero_chip_{1..4}` | Up to 4 plain text items, bullet-dot prefix via `::before` |

### Gaps vs. the reference (facts only, no design opinion)

- **No split-diagonal layout, no dashed-circle photo frame, no floating
  review/product card.** The hero is a plain 2-column CSS grid; the "photo"
  side is one `<img>` with a rounded-rectangle border-radius
  (`--ml-radius-lg`, 28px) and a drop shadow — nothing else. No overlay
  component markup exists in this section at all.
- **The 3 CTAs are intentionally text-links, not buttons.** The code
  comment at `page-home.php:384–389` records an explicit developer decision
  (dated 2026-08-07): keep all 3 configured Settings fields visible as
  understated links rather than dropping one to match a 2-button mockup.
  Any redesign toward "2 solid/outlined CTA buttons" is a reversal of that
  prior decision, not a pure visual tweak — flagging so it's a deliberate
  call this round, not an accidental regression.
- **No open-hours line anywhere in the hero.** Hours state
  (`DD_Hours::get_state()`) is computed and used elsewhere (closed banner,
  `window.DD.hours_state` for gating Add-to-Cart), but nothing in
  `page-home.php`'s hero prints it as copy.
- Chips are plain text + dot, not pill/badge chips.

### Token variables in play

`minimal-light.css:38–69` — full `:root` token block, all prefixed `--ml-*`.
Hero-relevant ones: `--ml-accent` (color), `--ml-sp-*` (spacing scale),
`--ml-radius-lg`, `--ml-shadow-lift`, `--ml-font-heading`/`--ml-font-body`,
`--ml-ink`/`--ml-ink-soft`/`--ml-ink-faint`/`--ml-line`. See §4 for exactly
where `--ml-accent` gets its value and the full ripple map.

---

## 3. Nav-bar feasibility — real vs. decorative per link target

Cross-checked against `investigation-frontend-pages.md`'s exhaustive page
inventory (9 confirmed real customer-facing views, traced through every
shortcode registration and template file — not re-derived here, just
applied to the reference's proposed nav items):

| Reference nav item | Target today | Status |
|---|---|---|
| **Home** | `/` (homepage) | ✅ Real |
| **Menu** | `/restaurant-menu/` | ✅ Real |
| **Reservation** | Global modal (`.js-open-reservation` intercept) — **not** the dedicated `/reserve-table/` page, which is confirmed broken (zero JS wired to its form fields) | ✅ Real, but must link via the modal-intercept pattern, not a plain href to `/reserve-table/` |
| **Contact** | — | ❌ Does not exist. No page, no template, no shortcode anywhere in the codebase. |
| **About** | — | ❌ Does not exist. Same. |
| **Blog** | — | ❌ Does not exist. No blog/posts listing template anywhere; WordPress core `post` content type isn't used by any Dish Dash template. |

**Contact/About/Blog would need to be created from scratch** — either as
real WordPress Pages (Contact/About, straightforward — standard WP pages
with `page-simple.php` or a new template) or, for Blog, a materially larger
addition (an actual posts archive/listing view, which nothing in this
codebase currently renders — Dish Dash has no blog feature at all today).
Confirming which of these three is wanted, and whether Blog is in scope for
this phase or a placeholder link, is a real scope decision, not something
resolvable by reading code further.

**No horizontal nav bar (decorative or otherwise) exists on any page
today** — Khana Khazana's shared header and both Minimal Light header
variants are hamburger+drawer only. There's no dormant/hidden top-nav
markup to repurpose; a horizontal nav bar would be new markup in both
places (§1).

**Whether a WP menu is currently assigned to the `dd-primary` theme
location** (Appearance → Menus, standard WordPress feature) is **not
determinable from code** — `register_nav_menus()` only declares the
location exists; nothing in the codebase programmatically assigns items to
it. If unassigned, both header variants fall back to their hardcoded
3-link default (Home / Our Menu / Reserve a Table). This needs an on-server
check (wp-admin → Appearance → Menus), not a code read.

---

## 4. Search / wishlist reality check

### Search — real and already wired, just not present in Minimal Light's markup

- `assets/js/search.js` is a genuine AJAX-backed implementation:
  `admin-ajax.php?action=dd_get_search_products` (product search) and
  `dd_get_recent_searches` (recent-search history), confirmed via the
  file's own header comment and the actual AJAX call at
  `search.js:114`.
- It's enqueued **unconditionally, site-wide**
  (`class-dd-template-module.php:345`, no template gating), with
  `frontend.js` declared dependent on it (`:346`) — meaning the JS engine
  is already loaded and running on every Minimal Light page today, even
  though there's no search input in the DOM for it to attach to.
- The actual `#ddSearch`/`#ddSearchDropdown`/`#ddMobileSearch` input markup
  only exists in `render_global_header()` (Khana Khazana's shared header,
  `:1080–1144`). **Neither Minimal Light header variant has any search
  markup at all** — not even hidden.
- **Practical implication**: adding a working search icon to Minimal
  Light's header is a markup-only change (reuse the existing IDs so
  `search.js`'s existing `isSearchInput` check, `tracking.js:172–175`,
  picks it up automatically) — not new backend/JS work.

### Wishlist — does not exist anywhere

Repo-wide search for `wishlist`/`Wishlist` (any case) turned up **zero
matches** in any PHP, JS, or CSS file — no database table, no AJAX action,
no icon, no button, no partial implementation anywhere. A wishlist icon in
the redesign would be **fully decorative unless built from scratch**:
schema (new table or customer-profile field), AJAX add/remove endpoints,
persistence per logged-in customer (or guest — a real design decision),
and UI. This is not a small addition on top of existing plumbing the way
search is.

---

## 5. Log in button vs. hamburger/drawer

Confirmed identical in both Minimal Light header variants (`page-home.php`
and `render_minimal_light_header()`): account access today is **entirely**
inside the slide-out drawer's footer, gated on `is_user_logged_in()`:

- **Logged in**: "My Profile" link (→ `wc_get_account_endpoint_url(
  'my-profile' )`, i.e. `/my-account/my-profile/`) + `#ddLogoutBtn` ("Log
  out" button).
- **Guest**: `#ddOpenRegister` ("Create Account") + `#ddOpenLogin` ("Log
  in") — both buttons, presumably opening an auth modal (auth module JS
  not read in this pass — out of scope for header/hero markup).

There is **no persistent, always-visible "Log in" button** anywhere in
Minimal Light's current header — it only becomes reachable after opening
the hamburger drawer. This matches what was already established in prior
Menu-page investigations (My Profile/Log out reachable via the drawer,
confirmed again here directly from the header source).

**Overlap consideration for the reference's visible "Log in" button**: if
a nav-bar "Log in" button is added, it would be a **third** place auth
state shows up conceptually (drawer footer already has full login/logout/
register), unless the drawer's account section is explicitly removed/
simplified in the same change. Whether to keep both, remove the drawer's
version, or make the nav-bar button and the drawer button the same trigger
(both opening the identical auth modal, just two entry points) is a design
decision — not resolvable from the code, but worth deciding explicitly
rather than ending up with two divergent "log in" UIs by accident.

---

## 6. Color system — full token ripple map

### The naming in the brief doesn't quite match what exists

There is no `--ml-gold` variable anywhere. What exists:

- **`--ml-accent`** (`minimal-light.css:46`) — the only accent-like token
  in Minimal Light's own `:root` block.
- **`.dd-gold`** — a CSS **class** (not a variable) reused from Khana
  Khazana's naming convention, but re-pointed in `minimal-light.css:193,262`
  to `color: var(--ml-accent)`. It has nothing to do with Khana Khazana's
  own `--dd-gold` variable (see below) — same class name, completely
  different color source, easy to conflate.

### Current values and where they actually come from

```css
--ml-accent:      var(--brand, #6B1D1D);       /* minimal-light.css:46 */
--ml-accent-dark: var(--brand-dark, #160F0D);  /* minimal-light.css:47 */
```

**`--ml-accent` is not an independent Minimal-Light-specific color — it's
a direct alias of `--brand`, which is the exact same global CSS variable
Khana Khazana, the admin CSS system, and every other surface in the site
read.** `--brand`/`--brand-dark` are populated from
`dish_dash_primary_color`/`dish_dash_dark_color` (Brand Identity page) via
`DD_Template_Module::build_root_tokens()` — the single source of truth for
all frontend color tokens, called from two places that both fire on every
page load regardless of template:

1. `inject_global_header_styles()` — hooked on `wp_head`, gated only by
   `is_global_header_page()`, which unconditionally `return true`s on every
   non-admin frontend page (`:808–813`) — this fires on Minimal Light's
   homepage and Menu page exactly the same as everywhere else.
2. `wp_add_inline_style('dish-dash-theme', ...)` (`:448`) — same token
   block, attached to the shared theme stylesheet handle.

`page-home.php` (`:277–281`) **also** writes its own redundant inline
`:root{--brand;--brand-dark}` block directly in `<head>` — same two values,
same source options, harmless duplication, not a second source of truth.

### Where changing `--ml-accent`'s color would ripple

**If the change is "override `--ml-accent`'s definition inside
`minimal-light.css` only"** (e.g., hardcode it to a fixed orange instead of
aliasing `--brand`): **zero ripple outside Minimal Light.** All 19 usages
of `--ml-accent` are confined to `minimal-light.css` itself (buttons, tab
active state, price text, scrollbar thumb, gold-class text, add-to-cart
hover, etc. — full line list: `:165,182,193,252,262,286,390,440,488,494,
514,525,529,530,542,602,634,656`). This file is only enqueued when Minimal
Light is the active template (registry-driven CSS override,
`class-dd-template-module.php:644`), so Khana Khazana, the admin UI, cart,
checkout, and every other surface reading `--brand` directly are
completely unaffected.

**If the change is instead "make `--brand` itself orange"** (editing the
`dish_dash_primary_color` value, or changing `build_root_tokens()`'s
default): this **would** ripple everywhere — Khana Khazana's entire theme
(hundreds of `var(--brand)`/`var(--dd-brand)` usages across `theme.css`,
`frontend.css`, `cart.css`, `order-tracking.css`), the admin dashboard's
`--dd-brand` CSS variable system (documented in `CLAUDE.md`'s Admin UI
Rules), and both Minimal Light header variants' cart/hamburger accents —
because `--brand` is the one truly global brand color, not template-scoped.
**This path is explicitly wrong for an "orange accent for Minimal Light
only" goal** — it would recolor the live Khana Khazana client site too.

### A cleaner existing option already sitting unused

`dish_dash_accent_color` is a **real, already-admin-editable** Brand
Identity field (`admin/pages/brand-identity.php:98,270–276` — color picker
+ text input, saved via the standard options-save loop at `:38`), with a
**default of `#e8832a`** — already a warm orange, coincidentally close to
what the reference calls for. It feeds `--accent`/`--dd-accent` through the
same `build_root_tokens()` call, meaning **it's already present as a live
CSS variable on every Minimal Light page today** (via the `wp_head`
injection in §above) — just never read by `minimal-light.css`, which uses
`--brand` instead for `--ml-accent`.

Repointing `minimal-light.css:46` from `var(--brand, #6B1D1D)` to
`var(--accent, #e8832a)` would:
- Give Minimal Light a genuinely independent accent color, decoupled from
  Khana Khazana's primary brand color.
- Reuse an existing, already-admin-configurable option rather than adding a
  new one.
- Have **zero ripple** to Khana Khazana or admin (Minimal Light's
  `--ml-accent` usages are file-scoped, as established above; `--accent`/
  `--dd-accent` already exist and are already used elsewhere — e.g.
  `frontend.css:70,71,137,181,217,234` — independently of this change).

This is a factual option surfaced during the investigation, not a design
recommendation to act on without sign-off — flagged because the brief
specifically asked where a token change would ripple, and "reuse the
already-existing accent option" is materially different in scope from
"introduce a brand-new orange constant."

### Khana Khazana's `--dd-gold` — unrelated, do not confuse with the above

`theme.css` separately defines `--dd-gold: #C9A24A` / `--dd-gold-soft:
#E6C77A` (`:97–98,123–124`) as **fixed hardcoded design constants**, not
tied to any wp_option, used extensively across Khana Khazana's own
`.dd-gold` class, buttons, headings, and footer links (15+ usages,
`theme.css:165,239,243,258,264,447,604,744,1263,1481,1568,1591,2125`).
These are completely independent of Minimal Light's `--ml-accent`/`.dd-gold`
re-use — same class name (`.dd-gold`), two unrelated color systems, one per
template file. Not in scope to change for this task, flagged only so
"gold" in the brief isn't mistaken for this variable.

---

## Summary for planning

- Header IDs/classes are fully mapped and must be preserved verbatim (§1) —
  any redesign is additive/restructuring around them, not a JS rewrite.
- Hero is currently a plain 2-col grid with text-link CTAs, no frame/card/
  hours-line — every one of those reference elements is new markup (§2).
- Home/Menu/Reservation are real, linkable targets today; Contact/About/
  Blog do not exist and need an explicit scope decision (§3).
- Search is a markup-only gap (backend/JS already live); wishlist is a
  from-scratch feature (§4).
- A nav-bar "Log in" button would duplicate the drawer's existing auth UI
  unless that overlap is explicitly resolved (§5).
- The lowest-ripple path to an orange accent is repointing `--ml-accent` to
  the already-existing, already-orange-by-default `--accent`/
  `dish_dash_accent_color` option — not touching `--brand` (§6).
