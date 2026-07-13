# R2 polish — Empty status pills fixed + profile sidebar on Track page — Build Report

**Version:** 3.10.48 (bumped in `dish-dash.php` header + `DD_VERSION`, and CLAUDE.md).
**Approach:** Option 2 (isolated `.dd-account` wrapper — no `.woocommerce-account` body class).
**Status:** built, committed, pushed. **Not released** — developer creates the GitHub release.

---

## 1. Empty status pills — fixed (`assets/css/order-tracking.css`)

**Cause:** the pill is white text on `var(--dd-track-accent)`, whose fallback chain referenced
`--dd-accent`/`--dd-brand`/`--dd-primary` — none of which exist on the frontend (the frontend brand token is
`--brand`; the `--dd-*` ones live only in admin.css). The chain fell to `currentColor` = the pill's own white
text → **white-on-white**.

**Fix:**
- `--dd-track-accent: var(--brand, var(--dd-accent, var(--dd-brand, #65040d)))` — real frontend token first,
  **concrete hex end, never `currentColor`**. (Also makes the timeline dots / order-number / cancel badge
  brand-colored, since they share this accent.)
- **Color-by-status pills** (readable white text on distinct semantic fills):
  `.dd-status--pending` `#C77700` (amber) · `.dd-status--confirmed` `#2563EB` (blue) ·
  `.dd-status--ready` `#157A46` (green).

## 2. Profile sidebar on the Track page — added (Option 2, isolated)

**`templates/orders/track.php`** restructured:
- **Guest** state → unchanged centered `.dd-track-wrap` message (no sidebar).
- **Logged-in** states (`list` / `ok` / `notfound` / `empty`) → wrapped in `.dd-account`:
  - `<nav class="woocommerce-MyAccount-navigation">` built from `wc_get_account_menu_items()` (the same
    filtered list as the profile page: My Profile / Order History / Track Order / Addresses / Account details /
    Log out). Item hrefs via `wc_get_account_endpoint_url()`; **Track Order** resolved directly with
    `dd_track_url()` and marked `is-active` (bulletproof, independent of the endpoint-URL filter).
  - `.dd-account__content` column holds the existing `.dd-track` card(s) — **list and timeline markup
    unchanged**.

**`assets/css/profile.css`** — Option 2 isolation: the two-column layout + sidebar selectors
(`.woocommerce`, `.woocommerce-MyAccount-navigation`, `-navigation ul`, `-navigation-link a`, `:hover`,
`.is-active`, `--customer-logout`, and the ≤768px responsive block) now **also target `.dd-account`**. No
`.woocommerce-account` body class is added, so there's no WooCommerce style bleed onto the track page.

**`assets/css/order-tracking.css`** — added `.dd-account__content { flex:1 1 auto; min-width:0 }` (plain
column so the inner `.dd-track` card keeps its own look) and `.dd-account__content .dd-track { margin:0 }`.

**`modules/orders/class-dd-orders-module.php`** — `profile.css` is now enqueued on the track page
(both the list branch and `render_single_track()`), since the profile module only enqueues it on
`is_account_page()`.

## 3. Timeline (`?order_id=`) — not regressed
The single-order view still renders the exact same `.dd-track` timeline card (same markup + order-tracking.css),
now sitting inside `.dd-account__content` with the sidebar beside it. `order-tracking.js` still polls and
re-renders `.dd-track[data-order-id]` until terminal — unchanged. The accent fix additionally makes its dots
render in the brand color.

---

## Files changed
- `assets/css/order-tracking.css` — accent chain fix + status pill colors + account content column.
- `assets/css/profile.css` — sidebar/layout selectors extended to `.dd-account` (+ responsive).
- `templates/orders/track.php` — sidebar layout for logged-in states; guest unchanged.
- `modules/orders/class-dd-orders-module.php` — enqueue `profile.css` on the track page.
- `dish-dash.php` (×2) + `CLAUDE.md` — version 3.10.48.

## Out of scope (untouched)
No query/attribution changes, no `ajax_get_order()` / ownership-gate change, no guest tracking, no
`customer_id` writes.

## Verify after deploy (LiteSpeed purge, logged in as user 14)
1. `/track-order/` → **status pills are colored and readable** (amber/blue/green), sidebar on the left
   identical to the profile page, **Track Order highlighted** as active, list on the right.
2. Click an owned order → **timeline view** with the sidebar beside it; steps/dots render in the brand color;
   card look unchanged from before.
3. Sidebar links (My Profile / Order History / Addresses / Account details / Log out) navigate to the WC
   account pages; **Track Order** points to `/track-order/`.
4. Eyeball for **no stray WooCommerce styling** bleeding onto the page (Option 2 avoids the
   `.woocommerce-account` body class specifically to prevent this).
5. Mobile ≤768px: sidebar stacks above the content (chips row).

## Note
- PHP not available locally → no `php -l`; edited regions verified by re-reading (template branch/brace
  structure and the nav loop confirmed). Smoke-load `/track-order/` right after deploy.
