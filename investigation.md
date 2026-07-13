# R2 polish — Phase 1 confirm: empty status pills + profile sidebar reuse (READ-ONLY)

Version target 3.10.47 → 3.10.48. Read-only; no edits. Two items: (A) the empty status pills bug,
(B) reusing the profile sidebar on the standalone `/track-order/` page.

---

## A. Empty status pills — ROOT CAUSE FOUND (CSS variable miss → white-on-white)

### How the pill renders (track.php:113 / :119)
```php
$label = function_exists( 'dd_order_status_label' ) ? dd_order_status_label( (string) $o->status ) : ucfirst( (string) $o->status );
…
<span class="dd-track__order-status dd-status--<?php echo esc_attr( $o->status ); ?>"><?php echo esc_html( $label ); ?></span>
```
**The label text IS present.** The list query filters `status IN ('pending','confirmed','ready')`, so
`$o->status` is always non-empty and `dd_order_status_label()` returns "Pending"/"Confirmed"/"Ready". The
markup echoes it. So the data/markup is fine — **the text is invisible, not missing.**

### Why it's invisible — CSS (order-tracking.css)
```css
.dd-track {
    --dd-track-accent: var(--dd-accent, var(--dd-brand, var(--dd-primary, currentColor)));
}
.dd-track__order-status {
    color: rgba(255, 255, 255, 0.96);   /* white text */
    background: var(--dd-track-accent);  /* pill fill */
}
```
The pill paints **white text on `var(--dd-track-accent)`**. But the accent's fallback chain references
`--dd-accent`, `--dd-brand`, `--dd-primary` — **none of which exist on the frontend.** The frontend brand
token is **`--brand`** (injected into `:root` by the global header — template-module.php:311-313:
`--brand: <primary>; --brand-dark: <dark>;`). `--dd-brand`/`--dd-primary` live only in **admin.css**
(`:root` at admin.css:25-26), not on public pages.

So on `/track-order/` the chain misses every variable and resolves to the terminal **`currentColor`**. On the
pill, `currentColor` = the element's own `color` = **white** → `background: white` under **white text** =
**a blank white pill.** Confirmed.

### Why the timeline (image 3) is unaffected
The timeline dots use the same accent: `.dd-track__step.is-done .dd-track__dot { background:
var(--dd-track-accent); }` — but the dot sets **no `color`**, so its `currentColor` inherits the dark body
text → dots render dark and visible. Only the **white-on-accent pill** collapses when the accent falls to
`currentColor`. Matches the screenshots exactly (timeline fine, pills blank).

### Fix direction (Phase 2)
In `order-tracking.css`, make `--dd-track-accent` resolve to the real frontend brand token and **never fall
back to `currentColor`** (which can equal the text color). e.g.
`--dd-track-accent: var(--brand, var(--dd-accent, var(--dd-brand, #65040d)));` — put `--brand` first, end in a
concrete brand-default hex (same pattern admin.css already uses, e.g. `var(--dd-brand, #65040d)`), so the pill
always has a real fill distinct from its white text. This also makes the timeline dots correctly
brand-colored. (Optionally give each status its own color via the existing `dd-status--pending/confirmed/ready`
classes for a colored-by-status look — a design choice for Phase 2.)

---

## B. Reusing the profile sidebar on `/track-order/`

### How the profile sidebar is rendered + styled
- **Markup:** stock WooCommerce. `DD_Profile_Module` only *filters* the item list
  (`add_menu_item` on `woocommerce_account_menu_items` → My Profile / Order History / Track Order / Addresses /
  Account details / Log out). WooCommerce's own `myaccount/my-account.php` + `navigation.php` render the
  two-column `.woocommerce` container (nav + content). No theme override exists in the repo.
- **Styling:** `assets/css/profile.css:176-286` — the two-column layout, the 240px sticky sidebar, item links,
  hover, `is-active` (`background: var(--brand); color:#fff`), and the content panel. **Every selector is
  prefixed `.woocommerce-account …`** (profile.css:176/186/200/213/225…).

### Why the track page has no sidebar
`.woocommerce-account` is a **body class WooCommerce adds only on the `/my-account/` page.** The track page is
a **standalone WP page** (`[dish_dash_track]` shortcode) — it is NOT inside the WC account template and does
NOT get the `.woocommerce-account` body class, so:
1. no navigation markup is rendered, and
2. even if we output the same markup, **none of the `profile.css` rules would match** (all scoped to
   `.woocommerce-account`).
Also, `profile.css` is currently enqueued only on `is_account_page()` (DD_Profile_Module::enqueue_assets), so
it isn't even loaded on the track page today.

### Cleanest way to get the identical sidebar (recommendations)
The sidebar does NOT depend on WC endpoint routing — it's just markup + CSS. Two viable approaches:

**Option 1 (recommended — DRY, pixel-identical, minimal CSS):**
1. On the track page, add the `woocommerce-account` class to `<body>` via a `body_class` filter (guarded to
   the track page only).
2. Enqueue `profile.css` on the track page.
3. In `shortcode_track()`, wrap the output in the **stock WC account markup**, building the nav from
   `wc_get_account_menu_items()` (already our filtered list) with `wc_get_account_endpoint_url($key)` hrefs
   (Track Order → our existing filter → `/track-order/`), and add `is-active` to the `track-order` item:
   ```html
   <div class="woocommerce">
     <nav class="woocommerce-MyAccount-navigation"><ul>
       <li class="woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--{key} [is-active]">
         <a href="{url}">{label}</a></li> …
     </ul></nav>
     <div class="woocommerce-MyAccount-content"> …existing track list/timeline… </div>
   </div>
   ```
   The existing `profile.css` then styles it **identically with zero CSS duplication**.
   - *Risk:* the `woocommerce-account` body class could pull in other WC styles keyed on it. Mitigated by DD's
     `remove_theme_conflicts()` (already dequeues woocommerce-layout/smallscreen on DD pages) — but confirm no
     visual regression on the track page after adding it.

**Option 2 (most isolated — no body class, small CSS add):**
Same markup, but wrap in a NEW class (e.g. `.dd-account`) and extend the `profile.css` selectors to also
target it (e.g. `.woocommerce-account .woocommerce-MyAccount-navigation, .dd-account .woocommerce-MyAccount-navigation { … }`).
No body-class side effects; costs a handful of duplicated/extended selectors. Still enqueue `profile.css` on
the track page.

**Recommendation:** Option 1 — it reuses `profile.css` untouched and guarantees an identical sidebar. If the
body-class side-effects are a concern, fall back to Option 2. Either way: (a) render the WC nav markup inside
the shortcode from `wc_get_account_menu_items()`, (b) mark `track-order` active, (c) enqueue `profile.css` on
the track page, (d) put the existing `list`/`ok` track output inside `.woocommerce-MyAccount-content`.

Keep the `?order_id=` timeline (`state='ok'`) rendering exactly as now — just nested inside the content panel;
its own card styling (order-tracking.css) is unchanged, so image-3 does not regress.

---

## Summary
- **Pills:** data is fine; `--dd-track-accent` misses the real frontend token `--brand` and falls to
  `currentColor` → white pill fill under white text → invisible. Fix = reference `--brand` (+ concrete hex
  fallback, never `currentColor`) in `order-tracking.css`.
- **Sidebar:** profile sidebar = stock WC markup styled by `profile.css`, all scoped to the `.woocommerce-account`
  body class the standalone track page lacks. Cleanest reuse = render the stock WC nav markup in the shortcode +
  add the `woocommerce-account` body class on the track page + enqueue `profile.css` (Option 1), marking Track
  Order active. Out of scope untouched: no query/attribution/ownership-gate/guest changes.

*No files modified. Read-only. Awaiting "proceed" for Phase 2.*
