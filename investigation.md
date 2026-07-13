# Sidebar "Track Order" href — where is it built? (READ-ONLY)

**Symptom:** footer Track Order link (real permalink) works; the **account-sidebar** item
(WC account menu, `track-order` key, S1 filter remap) does not reach `/track-order/`.

**Repo verdict:** in this codebase the sidebar is **stock WooCommerce navigation**, and the Track Order
href is built by `wc_get_account_endpoint_url('track-order')` → the `woocommerce_get_endpoint_url` filter →
our `track_order_menu_url()` → `dd_track_url()` (the real permalink). That path *should* work. Since it
doesn't on live, the actual render must **diverge from the repo** — almost certainly a **server-side account
navigation override that bypasses the filter** (see §4). Read-only; no fix.

---

## 1. Does DD build its own account menu, or only filter the stock one?

**Only filters the stock one.** `DD_Profile_Module` touches the account menu in exactly two places, both
WooCommerce filters — it never renders a menu itself:

- `class-dd-profile-module.php:28` — `add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] )`
  → produces the item **list** (My Profile / Order History / **Track Order** / Addresses / Account details /
  Log out). The `track-order` entry is `class-dd-profile-module.php:93`:
  ```php
  $clean['track-order'] = __( 'Track Order', 'dish-dash' );
  ```
- `class-dd-profile-module.php:31` — `add_filter( 'woocommerce_get_endpoint_url', [ $this, 'track_order_menu_url' ], 10, 4 )`
  → remaps the **href** for the `track-order` key.

**There is NO custom menu rendering anywhere in the repo.** Grep for `wc_get_account_menu_items`,
`MyAccount-navigation`, `navigation-link`, `woocommerce_account_navigation`, any `foreach … account_menu`,
and any `woocommerce/` template directory (theme or plugin) → **all return nothing** (only the header/footer,
which are unrelated — see §3). No `templates/**/myaccount/**`, no `theme/**/woocommerce/**`. So the profile
sidebar you see ("My Profile / Order History / Track Order / Addresses / Account details") is rendered by
**WooCommerce's own `templates/myaccount/navigation.php`**, styled into two columns by CSS (the v3.10.1
layout is CSS, not a template override in this repo).

## 2. How stock WC builds each item's href — and where our filter sits

WooCommerce's `myaccount/navigation.php` renders every item as:
```php
<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>"><?php echo esc_html( $label ); ?></a>
```
`wc_get_account_endpoint_url('track-order')` → (not `dashboard`/`customer-logout`) →
`wc_get_endpoint_url( 'track-order', '', <myaccount permalink> )`, whose final line is
`return apply_filters( 'woocommerce_get_endpoint_url', $url, $endpoint, $value, $permalink )`. `track-order`
isn't a registered WC query var, so the endpoint stays `'track-order'` and **our filter matches**:

`class-dd-profile-module.php:115-119` — **the exact line that produces the Track Order href:**
```php
public function track_order_menu_url( $url, $endpoint, $value, $permalink ) {
    if ( 'track-order' === $endpoint && function_exists( 'dd_track_url' ) ) {
        return dd_track_url();          // ← the sidebar href, per the repo
    }
    return $url;
}
```
`dd_track_url()` (`class-dd-helpers.php:177-183`) = `get_permalink( get_option('dish_dash_track_page_id') )`,
falling back to `home_url('/track-order/')` — i.e. **the same real permalink the working footer/menu link
uses.** Helpers is always loaded (`class-dd-loader.php:58` requires `class-dd-helpers`), so
`function_exists('dd_track_url')` is true and the guard passes. Only **one** filter is attached to
`woocommerce_get_endpoint_url` (ours) — no competition.

**So, per the repo, the sidebar href = `dd_track_url()` = the real `/track-order/` permalink, and should
work.** That it doesn't is the tell that the live render isn't taking this path.

## 3. The footer/header "Track Order" is a different link (not the sidebar)

`class-dd-template-module.php:856` (footer Explore column):
```php
<li><a href="<?php echo esc_url( $orders_url ); ?>">Track Order</a></li>
```
`$orders_url` (`:592-594`) = `wc_get_account_url('orders')` → `/my-account/orders/` — this footer item points
at **Order History**, not `/track-order/`. So the "footer link that works with the real permalink" the brief
refers to is a **different** link (a `dd-primary` nav-menu item or a manual permalink), not this one. Either
way it's unrelated to the sidebar/WC-account-menu path.

## 4. Why the sidebar diverges on live — leading hypothesis

Because the repo has **no** custom account navigation and the stock path routes through our filter, a sidebar
href that ISN'T the real permalink means **the live account navigation is not the stock template** — i.e. the
active theme (or a child theme / mu-plugin) ships a **`woocommerce/myaccount/navigation.php` override that
builds hrefs without `wc_get_account_endpoint_url`** (e.g. hardcoded `/my-account/{$endpoint}/`,
`wc_get_endpoint_url()` called directly, or `home_url()` concatenation). Such an override would:
- render our filtered item list (so "Track Order" still appears), **but**
- build the href **without firing `woocommerce_get_endpoint_url`**, so our S1 remap never runs → the item
  lands on WC's default `/my-account/track-order/` (an unregistered endpoint → account dashboard fallback).

This override is the most likely home of the v3.10.1 "two-column branded sidebar," and it would live on the
**server, outside this repo** (nothing here overrides the account nav).

**Secondary (less likely, but check):** if view-source shows the href already IS the real permalink, then the
filter is working and "doesn't reach" is a different problem (page/permalink/caching) — redirect the hunt.

---

## Decisive checks (in order) — [RUN ON SERVER]
1. **Read the actual rendered href.** View source on `/my-account/`, find the `Track Order` `<a>`:
   - `href=".../my-account/track-order/"` → filter bypassed → **theme nav override** (step 2 finds it).
   - `href=".../track-order/"` (real permalink) → filter works; the issue is elsewhere (caching/permalinks).
2. **Look for a server-side account-nav override** (not in this repo):
   ```bash
   ls -la wp-content/themes/*/woocommerce/myaccount/navigation.php 2>/dev/null
   ls -la wp-content/themes/*/woocommerce/myaccount/ 2>/dev/null
   ```
   If a `navigation.php` exists, open it and confirm whether it uses `wc_get_account_endpoint_url( $endpoint )`
   (filter fires) or builds the href another way (filter bypassed → the bug).
3. **Prove the filter itself works** (independent of the template):
   ```bash
   wp eval 'echo apply_filters("woocommerce_get_endpoint_url","SENTINEL","track-order","",home_url("/my-account/"));'
   ```
   - prints the `/track-order/` permalink → our filter is correct; the template isn't calling it (→ step 2).
   - prints `SENTINEL` → the filter isn't registered/firing (registration/timing) — but note the repo
     registers it in `init()`, so this would be surprising.

---

## Bottom line
- **The sidebar is stock WC (repo), not a custom DD menu.** DD only filters the item list
  (`add_menu_item`, profile-module.php:28/93) and the endpoint href
  (`track_order_menu_url` on `woocommerce_get_endpoint_url`, profile-module.php:31/115-119 → `dd_track_url()`).
- **Per the repo, the sidebar href = the real permalink and should work.** The live failure means the account
  navigation is being rendered by a **server-side override that bypasses `wc_get_account_endpoint_url`**
  (hence the `woocommerce_get_endpoint_url` filter never runs on it). Step 1 (view-source) + step 2
  (`ls theme woocommerce/myaccount/navigation.php`) confirm it in two commands.
- If confirmed, the fix options are: (a) also filter `woocommerce_account_menu_item_classes`/the override's
  href source, or (b) switch S1 to a hook the override *does* honor, or (c) register `track-order` as a real
  WC endpoint (S2). Pick after the override is located — no fix yet.

*No files modified beyond this report. Read-only.*
