# Track page "redirects to home" — render-path redirect hunt (READ-ONLY)

**Symptom reported:** Page 10 (`/track-order/`, `[dish_dash_track]`) is published, href is correct, but
loading it lands on the homepage. Hypothesis in the brief: a redirect inside the render path.

**Result: there is NO redirect in the track render path.** No `wp_redirect`, `wp_safe_redirect`,
`header('Location')`, `exit`, or client-side `location=` fires on a plain `GET /track-order/`. The most
likely real cause is a **template swap** (`template_include`), not a redirect — see §5. Read-only; no fix.

---

## 1. Redirects in `shortcode_track()` / `render_single_track()` / the list branch — NONE

`shortcode_track()` (orders-module.php:1645-1712) and `render_single_track()` (:1720-1748) contain **zero**
redirects/exits. Every return path is `return $this->get_template( 'orders/track.php', [...] )` — which
returns a **string** (`get_template()` = `ob_start()` → `render_template()` → `ob_get_clean()`,
class-dd-module.php:161-165). A shortcode returns markup into `the_content()`; it cannot cleanly redirect
(output has already started — a `wp_redirect` there would only emit a "headers already sent" warning).

`templates/orders/track.php` — the `guest`/`notfound`/`empty`/`list`/`ok` branches only echo HTML. No PHP
redirect, no `<meta http-equiv=refresh>`, no inline `location=` script.

`assets/js/order-tracking.js` — grep for `location.*=` / `window.location` / `redirect`: **no matches**.
It polls `dd_get_order` and re-renders DOM; it never navigates. (And note: the **list** branch does **not**
enqueue `order-tracking.js` at all — only the single-order `ok` path does.)

## 2. Every redirect that exists near the flow — and why none fire here

Full-codebase grep for `wp_redirect|wp_safe_redirect|header('Location')|template_redirect`:

| Location | Condition that triggers it | Fires on `/track-order/`? |
|---|---|---|
| `auth-module.php:1141` `wp_redirect(home_url('/'))` | only if `isset($_GET['dd_google_auth'])` **and** no client id | No — needs `?dd_google_auth=1` |
| `auth-module.php:1158/1168/1190/1198/1208/1218/1233/1258` | Google OAuth **callback** (`?dd_google_callback=1&code&state`) | No — needs OAuth params |
| `auth-module.php:879/892` `home_url('/')` + `dd_verify_status` | email-verification token flow | No — needs verify token |
| `template-module.php:388` | admin **Save Template** POST (`dd_template_save` + nonce + cap) | No — admin only |
| `admin/pages/orders.php:49/108`, `dashboard.php:37`, `class-dd-admin.php:566`, `theme-installer.php:211`, `dish-dash.php:187`, `homepage-module.php:208`, `auth-module.php:301` | admin actions / plugin upgrade | No — admin only |

**There are NO `template_redirect`, `wp`, `send_headers`, `wp_loaded`, `parse_request`, or `pre_get_posts`
action registrations anywhere in the codebase** (grep returned nothing). So nothing hooks early request
processing to bounce a front-end page. The only bare-`home_url('/')` redirects are OAuth-gated and never run
on a plain track-page load.

## 3. Did R2 introduce a redirect / change the default branch?

- **No redirect introduced.** R2 added the `list` branch, which only returns a string.
- **The default branch DID change** (this is the real R2 behavior change):
  - **Pre-R2:** logged-in, no `?order_id=`/`?order=` → fetch the single most-recent order → render `state='ok'`
    (and enqueue `order-tracking.js`). Empty → `state='empty'`.
  - **Post-R2:** logged-in, no param → build the phone-anchored **active-orders list** → render `state='list'`
    (enqueue `order-tracking.css` only, **no JS**).
- Neither default redirects. The change is *what content the string contains*, not navigation.

## 4. Logged-in user + active orders + no `?order_id=` → which branch, does it redirect?

Runs the **default (list) branch** (orders-module.php:1678-1711): `get_customer_for_user()` → build
`( customer_id = %d OR customer_phone IN (…) ) AND is_test=0 AND status IN ('pending','confirmed','ready')`
→ `get_results()` → `enqueue_style('order-tracking')` → `return $this->get_template('orders/track.php',
['state'=>'list', …])`. **It returns a string. It does not redirect and does not `exit`.**

## 5. So what actually sends the user to "home"? — the template layer (primary hypothesis)

Because no redirect exists, the symptom is almost certainly one of these — **all render-path, none a redirect:**

### H1 (most likely) — Page 10's template is "Dish Dash Full Page" → homepage renders in place
`load_page_template()` (template-module.php:149-165, hooked on `template_include` at :60) swaps the template
to the plugin's `templates/page-dishdash.php` whenever the page's `_wp_page_template` meta is
`page-dishdash.php`. **`page-dishdash.php` is the HOMEPAGE template** — it builds hero/featured markup and
does **not** run the page's `[dish_dash_track]` content. If Page 10 was assigned the "Dish Dash Full Page"
template, `/track-order/` returns **HTTP 200 with homepage content, URL unchanged** — which reads as
"redirects to home" but is a template swap, not a redirect. (Corroborating: `is_dishdash_page()` at :177-188
would then return true via the `page-dishdash.php` meta, loading full DD home styling — reinforcing the
"looks exactly like home" impression.)

### H2 — no page actually resolves at the slug / canonical bounce
If the served URL is genuinely a **302 to `home_url('/')`**, it is NOT coming from this plugin (nothing here
redirects front-end pages). Candidates outside the plugin: a static-front-page/permalink/rewrite collision,
a security/redirect plugin, or `.htaccess`. Would need to be confirmed at the server/host level.

### Note (not the cause, but related): `/track-order/` is NOT in `is_dishdash_page()`
`is_dishdash_page()` (template-module.php:176-188) lists front_page, `restaurant-menu`, `cart`, `checkout`,
`birthday`, `my-account`, or the `page-dishdash.php`/`page-simple.php` templates — **not `track-order`**. So a
*normal* track page gets no DD frontend CSS/JS (the list would render largely unstyled). This is a separate
polish gap, not the redirect.

---

## The one decisive check — is it a 302 or a 200?
```bash
curl -sI 'https://dishdash.khanakhazana.rw/track-order/'
```
- **`HTTP/1.1 302` + `Location: …/` (home)** → a genuine redirect → **H2** (outside this plugin; check
  static front page, redirect/security plugins, `.htaccess`). Not in the track code.
- **`HTTP/1.1 200`** (and the body is homepage hero/featured markup, URL stays `/track-order/`) → **H1** →
  Page 10 is using the **"Dish Dash Full Page"** template. Fix = set Page 10's template back to **Default**
  so `the_content()` (theme `page.php`) runs the `[dish_dash_track]` shortcode.

**[RUN ON SERVER] to confirm H1 directly:**
```bash
wp post list --post_type=page --name=track-order --field=ID --post_status=any
wp post meta get <id> _wp_page_template     # expect '' or 'default'; if 'page-dishdash.php' → that's it
```

---

## Bottom line
- **No redirect line exists in the track render path** (`shortcode_track` / `render_single_track` / list
  branch / `track.php` / `order-tracking.js`). R2 changed the default branch's *content* (single-order → list),
  never navigation.
- The "home" symptom is a **template swap** (H1: Page 10 assigned the `page-dishdash.php` homepage template,
  served via `template_include`), or — only if the response is a real 302 — something **outside this plugin**
  (H2). The `curl -sI` above tells you which in one shot.

*No files modified beyond this report. Read-only.*
