# INVESTIGATION — `templates/page-dishdash.php`: dead or live?

**Phase 1, read-only.** Every claim carries `file:line`. Live-DB / live-file facts I cannot see from
the repo are marked **PENDING (server)** with the exact command to run.

---

## TL;DR

- `page-dishdash.php` lives in the **plugin** (`templates/`), not the theme. It is **not** part of the
  WordPress theme template hierarchy by filename.
- It is reachable by **exactly one** mechanism: a page whose `_wp_page_template` meta equals the literal
  `page-dishdash.php`, routed by the plugin's `template_include` filter
  (`class-dd-template-module.php:149-166`). It is offered as a selectable "Dish Dash Full Page" template via
  `theme_page_templates` (`:143-144`).
- **It is the only hero renderer in the entire repo.** No shortcode, no other template, and no code in the
  homepage module produces a `.dd-hero` / `.dd-pill` / hero-chips block. So *if the live homepage shows that
  hero, page-dishdash.php is what renders it* — and then the hardcoded pill **would** show.
- Whether it is currently **assigned** to any page (including the front page) is a DB fact I cannot read.
  **This is the single question that decides the verdict.** Commands are in §1 and §3.
- The footer/social/hours variables the file reads (`:94-96, :160-167, :232`) are **provably dead**: the file
  contains no footer markup — the footer is injected globally by `inject_global_footer()`.

---

## 1. Is the file reachable via the WP template hierarchy?

**Location.** `templates/page-dishdash.php` — plugin root `templates/` dir (Glob), **not** the theme
(`theme/dish-dash-theme/` contains only `footer.php, functions.php, header.php, index.php, page.php,
singular.php` — no `front-page.php`, no `page-dishdash.php`). Because it is not in the active theme, WordPress
core never discovers it through the normal hierarchy or the `Template Name:` scan (core scans only the active
theme's directory). Its `Template Name: DishDash` header (`page-dishdash.php:4`) is therefore inert on its own —
registration is done explicitly by the plugin instead.

**How it is registered + routed (plugin, not core):**
- `register_page_template()` adds it to the Page Attributes → Template dropdown:
  `class-dd-template-module.php:143-144` → `$templates['page-dishdash.php'] = 'Dish Dash Full Page'`.
  (Filter hooked at `:59`, `add_filter('theme_page_templates', …)`.)
- `load_page_template()` swaps in the plugin file on `template_include`:
  `class-dd-template-module.php:149-166` — **only** when `is_page()` **and**
  `get_post_meta( get_the_ID(), '_wp_page_template', true ) === 'page-dishdash.php'` (`:150-152`), returning
  `DD_TEMPLATES_DIR . 'page-dishdash.php'` if the file exists (`:153-156`). (Filter hooked at `:60`.)

So it is a **template-meta-matched, assignable page template** — *not* slug-matched. The `page-dishdash.php`
filename prefix does **not** cause WP to auto-apply it to a page with slug `dishdash`; that convention only works
for files inside the active theme, and this file is in the plugin. Routing is 100% driven by the
`_wp_page_template` meta value, not by any slug (verified: no `is_page('dishdash')`, no `get_page_by_path`, no
slug comparison anywhere in `load_page_template`).

**Does a page with slug `dishdash` exist / is any page assigned it?** — **PENDING (server).** Cannot be read
from code. Run:

```bash
# Every page currently assigned the Full Page template:
wp post list --post_type=page --post_status=any \
  --meta_key=_wp_page_template --meta_value=page-dishdash.php \
  --fields=ID,post_title,post_status,post_name
```

An empty result ⇒ the template is assigned to nothing ⇒ **the file never loads** (wholly dead).
A non-empty result ⇒ it renders for those page(s).

---

## 2. Is it reachable via code (include / require / locate_template / filters)?

Full-repo grep for `page-dishdash`, `get_template_part`, `locate_template`, `include`, `require`,
`template_include`, `page_template`:

- The **only** executable references that route to the file are the two filters above:
  `class-dd-template-module.php:59-60`, implemented at `:143-166`.
- `template_include` also carries `maybe_load_birthday_template` (`:88`, impl near `:1000-1001`) — it matches
  `_wp_page_template === 'page-dishdash.php'` only to decide the **birthday** template; it does not add a second
  route to page-dishdash.php.
- No `include`/`require`/`get_template_part`/`locate_template` anywhere targets `page-dishdash.php`. It is never
  pulled in as a partial.
- No `page_template` filter and no other `template_include` handler forces it.
- Remaining hits are documentation (`CLAUDE.md`, `ARCHITECTURE.md`, `MODULE_CONTRACT.md`, `CSS_REGISTRY.md`,
  `AUDIT.md`), asset-file header comments (`assets/js/frontend.js:4,35`, `assets/js/search.js:37`,
  `assets/css/theme.css:4,11`, `dishdash-core/class-dd-helpers.php:17,235`), and the file's own header — none
  are executable routes.

**Conclusion:** one and only one code path can load this file — `load_page_template()` on a meta-matched page.

---

## 3. What does the live homepage actually render?

**What serves `/` depends on three options I cannot read — PENDING (server):**

```bash
wp option get show_on_front      # 'page' (static) or 'posts'
wp option get page_on_front      # the front page's post ID (if static)
wp post meta get <page_on_front_id> _wp_page_template   # '' | page-dishdash.php | page-simple.php
```

**Reasoning from code:**
- The theme has **no `front-page.php`** (Glob), so WordPress would normally fall to the theme's `page.php`
  (`theme/dish-dash-theme/page.php`, renders only `the_content()`) for a static front page.
- But the plugin's `load_page_template` (`:149-166`) fires on `template_include` for **any** page including the
  front page (`is_page()` is true for a static front page), so if the front page's meta is `page-dishdash.php`,
  the plugin serves `templates/page-dishdash.php` for `/`.
- **page-dishdash.php is the only place in the repo that emits the hero** the user describes: the hero title
  (`:293`, `wp_kses_post($dd_h_title)` ← `dish_dash_hero_title`) and the feature chips (`:300-306` ←
  `dd_hero_chip_1..4`). Searched the whole repo: no shortcode (`dish_dash_menu/cart/checkout/reserve/track` are
  the only ones — `menu-module.php:49-52`, `orders-module.php:110`; **none render a hero**), and the homepage
  module (`class-dd-homepage-module.php`) is an **admin settings form only** — it registers no frontend
  shortcode and injects no hero.

**Therefore, from code alone:** if the live `/` shows that hero, `/` is being served by page-dishdash.php, and
the file is **LIVE**. The `file:line` that produces the hero the user sees is
**`templates/page-dishdash.php:288-346`** (title `:293`, chips `:300-306`).

### The pill contradiction — flagged, not resolved

The brief's premise is that the pill literal **"Authentic Indian Dining"** (`page-dishdash.php:292`) does **not**
appear live. But `:292` is **unconditional** (`<span class="dd-pill">Authentic Indian Dining</span>` — no `if`
guard) and `.dd-pill` is visible CSS (`theme.css:603-615`, `display:inline-flex`, not hidden). So **if
page-dishdash.php renders, the pill renders.**

That yields a contradiction that code cannot settle:
- **Either** the live `/` is **not** page-dishdash.php (then *what* renders the hero? nothing in the repo does —
  which would imply live content/templates that aren't in the repo: a page-builder, pasted hero HTML in the page
  body, or a server-only `front-page.php`), **UNPROVEN**;
- **or** the live `/` **is** page-dishdash.php and the pill is in fact present (overlooked), or the deployed file
  is stale vs. the repo.

**Resolve with one command** (definitive, cheap):

```bash
curl -s https://dishdash.khanakhazana.rw/ | grep -o 'dd-pill[^<]*</span>\|dd-hero__title[^<]*'
```

- Pill string present ⇒ page-dishdash.php **is** the live homepage (verdict: **LIVE**, and the brief's premise
  was mistaken).
- Pill absent but a hero is on screen ⇒ the hero comes from **outside the repo** — escalate before any deletion.

---

## 4. Partial vs total death

**Gate:** the whole file only executes if §1's command returns an assigned page. Assuming it does, the
section-level breakdown is:

| Section | `file:line` | Verdict | Basis |
|---|---|---|---|
| PHP setup: WC guard, URL/option reads, product queries | `39-253` | **LIVE** (when file loads) | Feeds the rendered sections below |
| Social-link vars `$dd_fb/$dd_ig/$dd_wa` | `94-96` | **DEAD** | Each used exactly once (definition only); no social markup in this file — header is injected |
| Footer toggle + text vars `$dd_footer_*`, `$dd_footer_desc` | `160-165` | **DEAD** | Read, never rendered; no `<footer>`/`dd-footer` markup in file (grep: 0) |
| Opening-hours vars `$dd_hours` → `$dd_hours_lines`, `$dd_tiktok` | `166-167, 232` | **DEAD** | `$dd_hours_lines` defined at `:232`, never rendered; `$dd_tiktok` definition-only |
| `<head>` + `wp_head()` | `254-268` | **LIVE** | Document head |
| `<body>` open + `wp_body_open()` (header injected here) | `269-276` | **LIVE** | Header comes from `inject_global_header()`, not in-file (`:276` comment) |
| **HERO** (incl. pill `:292`, title `:293`, chips `:300-306`) | `288-346` | **LIVE** | Unconditional render; the user-visible hero |
| Mobile category list | `362-406` | **LIVE** | Rendered section |
| Categories | `410-447` | **LIVE** | Rendered section |
| Menu / Featured dishes | `451-502` | **LIVE** | Rendered section |
| Reserve band | `504-516` | **LIVE** | Rendered section |
| Selected-category | `520-564` | **LIVE** | Rendered section |
| Google Reviews | `821-1125` (debug `819`) | **LIVE** | Rendered section |
| `wp_footer()` (footer + cart + modals injected here) | `1127` | **LIVE** | Fires `inject_global_footer`, `inject_cart_sidebar`, `inject_product_modal`, `inject_reservation_modal` |

**Provably dead even when the file loads:** the footer/social/hours variable block
(`94-96, 160-167, 232`). These are leftovers from an era when this template rendered its own footer inline; the
footer is now supplied by `inject_global_footer()` (`class-dd-template-module.php:852+`, hooked to `wp_footer`
at `:75`), which reads its **own** copies of these options. Removing the dead reads from page-dishdash.php would
change nothing on screen. **The hero pill (`:292`) is LIVE-within-the-file** — it is only "dead" in the sense of
being a non-editable hardcoded literal, not unreachable. Do **not** conflate the two.

---

## 5. Blast radius of deletion

**If §1 + §3 prove the file is assigned to nothing (wholly dead):**
- Nothing `include`/`require`s it (§2) → no PHP fatal from a dangling include.
- If it is genuinely unassigned, no page routes to it → deleting it changes no rendered page.
- Fallback safety net exists: any page that *were* assigned it would, after deletion, fall through
  `load_page_template`'s `file_exists()` guard (`:154`) back to the passed-in `$template` (theme `page.php`) —
  so even a mis-assigned page degrades to the theme page, not a white screen. (The dropdown entry would still
  list "Dish Dash Full Page" via `:144` until that line is also removed — cosmetic dangling option.)
- Admin UI: it **is** listed as a selectable template (`:143-144`), so a wholesale delete should also drop that
  registration line to avoid offering a template that no longer exists.

**If §3 proves it is the live homepage (partly live):** the removable-without-touching-the-live-path parts are
exactly the §4 **DEAD** rows — the footer/social/hours variable reads (`94-96, 160-167, 232`). Everything from
`254` onward is on the live render path and must not be touched in a "delete dead code" pass. The hero pill
(`:292`) is on the live path; making it editable is a *feature* change, not dead-code removal, and is explicitly
out of scope here.

---

## 6. Same-class check (report only — NOT investigated deeply)

Other templates that use the **same** assignment-dependent mechanism and could be unrouted if unassigned:

- **`templates/page-simple.php`** — registered (`class-dd-template-module.php:145`, "Dish Dash Simple Page")
  and routed identically (`:158-163`) via `_wp_page_template === 'page-simple.php'`. Same smell: loads only if a
  page is assigned it. Assignment is **PENDING (server)** —
  `wp post list --post_type=page --meta_key=_wp_page_template --meta_value=page-simple.php --fields=ID,post_title,post_status`.
  (It renders a plain title + `the_content()` via the theme header/footer — `page-simple.php:1-25` — so unlike
  page-dishdash.php it carries no dead footer block.)
- **`theme/dish-dash-theme/singular.php`** — standard hierarchy fallback; reachable only if neither `page.php`
  nor `single.php` applies. Low-priority "possibly never hit" candidate; not verified.

Standard theme files `index.php`, `page.php`, `header.php`, `footer.php` are normal hierarchy/`get_header`/
`get_footer` targets and are **not** in this suspicion class.

---

## What needs a server check before any follow-up (consolidated)

1. `wp post list --post_type=page --post_status=any --meta_key=_wp_page_template --meta_value=page-dishdash.php --fields=ID,post_title,post_status,post_name` — is the Full Page template assigned to anything?
2. `wp option get show_on_front` / `wp option get page_on_front` / `wp post meta get <id> _wp_page_template` — what serves `/`?
3. `curl -s https://dishdash.khanakhazana.rw/ | grep -o 'dd-pill[^<]*</span>\|dd-hero__title[^<]*'` — is the pill actually on the live homepage? (settles §3's contradiction)
4. (same-class) `wp post list … --meta_value=page-simple.php …` — is the Simple Page template assigned to anything?

**Verdict is deferred to those results.** From code: page-dishdash.php is reachable by exactly one route, is the
repo's sole hero renderer, and carries a provably-dead footer/social/hours variable block regardless of whether
the file as a whole is live. The follow-up remains one release — **either** delete the whole template (if the
server proves it is assigned to nothing) **or** delete only the §4 DEAD rows from a live template — decided by
the checks above, not a theme-wide sweep.
