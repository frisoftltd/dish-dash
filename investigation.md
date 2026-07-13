# R2 sidebar bug — "Track Order" resolves to home, not /track-order/ (READ-ONLY findings)

**Symptom:** the S1 sidebar item renders but its href/landing is the homepage, not the `/track-order/`
page. Read-only trace; no fix. Live version `3.10.46`.

---

## 1. The `add_menu_item()` append — what key the item is registered under

`modules/profile/class-dd-profile-module.php:91-93`:
```php
// R2 — Track Order (links to the standalone /track-order/ page; href remapped
// by track_order_menu_url() since this is not a WC endpoint).
$clean['track-order'] = __( 'Track Order', 'dish-dash' );
```
**Key/slug = `track-order`** (not a registered WC endpoint — no `add_rewrite_endpoint`, no query var).

## 2. The `woocommerce_get_endpoint_url` filter — key checked + how it looks up the URL

Registration — `class-dd-profile-module.php:31`:
```php
add_filter( 'woocommerce_get_endpoint_url', [ $this, 'track_order_menu_url' ], 10, 4 );
```
Handler — `class-dd-profile-module.php:115-120`:
```php
public function track_order_menu_url( $url, $endpoint, $value, $permalink ) {
    if ( 'track-order' === $endpoint && function_exists( 'dd_track_url' ) ) {
        return dd_track_url();
    }
    return $url;
}
```
Checks `$endpoint === 'track-order'`; returns `dd_track_url()` (no order number → the **base** track URL).

## 3. Is the href actually built through `woocommerce_get_endpoint_url`?

**Yes — by the standard WC path, and nothing in the repo overrides it:**
- WooCommerce's `myaccount/navigation.php` renders each menu item as
  `<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>">`.
- `wc_get_account_endpoint_url('track-order')` → (not `dashboard`/`customer-logout`) →
  `wc_get_endpoint_url( 'track-order', '', <myaccount permalink> )`, whose **last line is**
  `return apply_filters( 'woocommerce_get_endpoint_url', $url, $endpoint, $value, $permalink );`.
  `track-order` is not in WC's query vars, so the endpoint→queryvar remap leaves `$endpoint` as
  `'track-order'` → **our filter matches and its return value is the final href.**
- **No competing filters:** grep shows exactly one `woocommerce_get_endpoint_url` and one
  `woocommerce_account_menu_items` filter in the codebase — both ours.
- **No theme override:** no `myaccount/navigation.php` (or any `myaccount/` template) exists under
  `theme/` in the repo. The header nav in `class-dd-template-module.php:592-597` uses
  `wc_get_account_url()`/`wc_get_account_endpoint_url('my-profile')` — that's the **site header**, not
  the account sidebar, and is unrelated.

⚠️ **The one thing code can't prove from here:** whether the *deployed* active theme
(`dish-dash-theme`) ships its own `woocommerce/myaccount/navigation.php` override on the server that
builds hrefs differently (bypassing `wc_get_account_endpoint_url`). None is in the repo, but confirm on
live (Diagnostic C).

## 4. The `/track-order/` lookup — page ID (option) or slug? Valid on live?

`dishdash-core/class-dd-helpers.php:177-183`:
```php
function dd_track_url( string $order_number = '' ): string {
    $page_id = get_option( 'dish_dash_track_page_id' );
    $base    = $page_id ? get_permalink( $page_id ) : home_url( '/track-order/' );
    return $order_number ? add_query_arg( 'order', urlencode( $order_number ), $base ) : $base;
}
```
- **Primary: by page ID** in option `dish_dash_track_page_id` → `get_permalink( $page_id )`.
- **Fallback: by slug** → `home_url( '/track-order/' )` when the option is empty/falsy.
- Auto-create seeds this page (install.php:608-612): title "Track Your Order", `[dish_dash_track]`,
  **slug `track-order`**, and stores the new ID in `dish_dash_track_page_id`.

**Key observation:** `dd_track_url()` **never returns the bare homepage** (`home_url('/')`). Its worst case
is `home_url('/track-order/')`. So a "lands on the homepage" symptom is best explained by one of:

- **(Most likely) the option is empty on live** → fallback `home_url('/track-order/')`, **and no page
  actually resolves at that slug** (auto-create never ran on this install, or the page's real slug is
  different — e.g. WP appended `-2` on a slug collision so the page lives at `/track-order-2/`). A pretty
  URL with no matching page/route 404s, and many themes/redirect setups canonical-redirect an unresolved
  URL to `/` → looks like "goes to home."
- **(Also possible) the option points to a trashed/deleted page** → `get_permalink()` returns `false`
  → filter returns `false` → `esc_url(false) === ''` → an **empty href** (clicking reloads the current
  page). This presents slightly differently from "home," so Diagnostic C disambiguates.
- **(If the href is literally `home_url('/')`)** then the filter is **not firing** and something upstream
  is producing home — which would point at a theme `navigation.php` override or a load-order/registration
  issue, not `dd_track_url()`.

---

## Ranked hypotheses + [RUN ON SERVER] diagnostics

The wiring in code is internally correct and, absent a theme override, the filter is the final word on the
href. So the fault is almost certainly in **what `dd_track_url()` resolves to on live** (option empty and/or
the `/track-order/` page missing/mis-slugged). Three quick checks pin it down decisively:

**Diagnostic A — is the filter firing, and what does it return?**
```bash
wp eval 'echo apply_filters("woocommerce_get_endpoint_url", "SENTINEL", "track-order", "", home_url("/my-account/"));'
```
- Prints a `/track-order/`-style URL → filter fires; go to Diagnostic B (URL/page is the issue).
- Prints `SENTINEL` → filter is **not** firing (registration/timing/theme override) → that's the bug.

**Diagnostic B — what does `dd_track_url()` return, is the option set, does the page exist?**
```bash
wp option get dish_dash_track_page_id
wp eval 'echo dd_track_url();'
wp post list --post_type=page --name=track-order --post_status=any --field=ID
# if the option has an ID:
wp post get <id> --field=post_status
wp post get <id> --field=post_name
```
- Option empty + no page with slug `track-order` → the fallback URL is dead → **root cause = missing page /
  unset option**. (Likely one-line fix in `dd_track_url()`: resolve by slug via
  `get_page_by_path('track-order')` before falling back — or re-run page auto-create so the option is set.)
- Option set to a **trashed** page (`post_status=trash`) → `get_permalink` breaks → empty href.
- Option set, page published, slug `track-order` → `dd_track_url()` is correct; re-check Diagnostic C.

**Diagnostic C — what is the literal rendered href, and does the theme override the nav?**
- View source on `/my-account/` and read the `Track Order` link's `href`:
  - `href="https://site/"` (bare home) → filter not firing / theme override → check the active theme dir on
    the server for `woocommerce/myaccount/navigation.php`.
  - `href="https://site/track-order/"` but it bounces to home → page-existence/slug problem (Diagnostic B).
  - `href=""` (empty) → option points to a trashed/invalid page (Diagnostic B).

---

## Summary for the fix
- **Append key:** `track-order`. **Filter:** matches `track-order`, returns `dd_track_url()`. Wiring is
  code-correct; only our filter touches this href; no theme override in the repo.
- **`dd_track_url()` resolves by page ID (`dish_dash_track_page_id`) with a slug-based
  `home_url('/track-order/')` fallback, and never emits bare home.** The homepage symptom therefore points
  at the **live page/option state** (empty option and/or missing/mis-slugged `/track-order/` page), or —
  only if the href is literally bare home — the filter not firing (theme nav override).
- Diagnostics A/B/C above identify which, and the one-line fix follows from that (most likely: harden
  `dd_track_url()`'s fallback to look up the page by slug, or restore the `dish_dash_track_page_id` option /
  the `/track-order/` page on live).

*No files modified beyond this report. Read-only.*
