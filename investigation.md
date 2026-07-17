# INVESTIGATION — Special instructions (`#ddPmNotes`) not captured

**Phase 1, read-only.** Plugin facts carry `file:line`; live-DB facts are **PENDING (server)** with exact SQL.

---

## TL;DR

- **The column already exists** — `dishdash_order_items.special_note TEXT` (`install.php:155`), per-item. **No
  schema change needed.**
- **The write path already works** — `dd_cart_add` reads `$_POST['note']` (`class-dd-cart.php:242`) → cart session
  (`:99`) → `insert_order_items` writes `special_note` (`orders-module.php:447`).
- **Capture is broken on every surface:**
  - **Desktop/homepage modal** (`frontend.js`): the `#ddPmNotes` textarea **exists** (`:963-964`) but the Add
    handler (`:1011-1025`) **never reads it** — no `note` in the POST. (Same handler that dropped `variation`
    until v3.10.80; v3.10.80 wired `variation`, left `note`.)
  - **Mobile `/restaurant-menu/` app** (`menu-page.js` + `grid.php`): there is **no notes field at all** in the
    mobile single-product screen (`grid.php:288-320`), and `menu-page.js:719` sends `note` **hardcoded to `''`**.
- **One reader already shows it** — the **kitchen WhatsApp** (`class-dd-notifications.php:433-434`). The admin
  modal, admin WhatsApp, and order email **ignore it** (same trap as spice R1).
- **Slash-escaping bites here too:** `sanitize_textarea_field` does **not** stripslashes, so a captured note like
  `it's spicy` would store as `it\'s spicy`; the existing kitchen reader prints it **raw** (`:434`) → a stray
  backslash. Any note display needs `stripslashes()` (the same root cause fixed for `variation` in v3.10.79).
- Notes are **plain text** — **no decode helper** (unlike `variation`), but they **do** need `stripslashes` +
  per-surface escaping.

---

## 1. Both platforms

**Desktop / homepage modal** (`frontend.js` `#ddProductModal`):
- Textarea markup: `frontend.js:963-964` — `<textarea id="ddPmNotes" placeholder="Add special instructions
  (optional)..." rows="2" …>` inside `.dd-pm__notes-wrap`.
- Read in Add handler: **none.** The Add POST (`:1011-1017`) sends `action, nonce, product_id, quantity,
  variation` — **no `note`** (grep for `note`/`ddPmNotes` in `frontend.js` returns only the textarea markup).
  **BREAK = capture: the textarea value is never read into the POST.**
- *Note:* this modal is what appears on the homepage on **both** desktop and phones (it's the `frontend.js`
  component, responsive). The "special instructions" field seen in the mobile screenshot is **this** textarea, not
  a separate mobile component.

**Mobile `/restaurant-menu/` app** (`menu-page.js` + `templates/menu/grid.php`):
- Field: **none.** The mobile single-product screen (`grid.php:288-320`) has attributes, description, add-to-cart,
  related — **no notes textarea**.
- Send: `menu-page.js:719` `formData.append('note', '')` — **hardcoded empty.** So even without a field, it
  actively sends an empty note. **BREAK = no field + hardcoded ''.**

**Reference implementation?** Unlike spice (mobile worked), **neither JS surface captures notes** — there is no
working client reference. The intended design is proven instead by the **server** (write column + `dd_cart_add`
acceptance) and the **kitchen reader** (§4).

## 2. Where it breaks — full path per platform

**Write path (shared, works):** `dd_cart_add` → `sanitize_textarea_field($_POST['note'] ?? '')`
(`class-dd-cart.php:242`) → `DD_Cart::add()` stores `'note'` (`:99`) → `place_order` → `insert_order_items`
writes `'special_note' => sanitize_textarea_field($item['note'] ?? '')` (`orders-module.php:447`) →
`dishdash_order_items.special_note`.

| Platform | DOM read? | In POST? | Cart stores? | Order writes? | Lands in |
|---|---|---|---|---|---|
| Desktop modal | ❌ never read (`frontend.js:1011-1017`) | ❌ | would (`:99`) | would (`:447`) | `special_note` |
| Mobile app | ❌ no field (`grid.php:288-320`) | sends `''` (`menu-page.js:719`) | stores `''` | writes `''` | `special_note`='' |

**Exact break — capture only, on the client:**
- Desktop: `frontend.js:1011-1017` (POST omits `note`; `#ddPmNotes.value` never read).
- Mobile: `menu-page.js:719` (`note` hardcoded `''`) + no field in `grid.php`.
Everything server-side (cart store, order write, column) is ready.

## 3. Is there anywhere to put it? — **Yes, already.**

- **`dishdash_order_items.special_note TEXT DEFAULT NULL`** (`install.php:155`) — **per-item**, exactly matching
  the modal's per-item textarea. Already written by `orders-module.php:447`. **No schema change.**
- Per-order alternative exists but is **not** this: `dishdash_orders.special_instructions TEXT` (`install.php:122`)
  — an order-level field (grep shows no writer/reader wired to the modal notes; it's a separate, currently-unused
  order-level slot). The modal note is per-item → `special_note` is the correct home. **Do not** repurpose the
  per-order column.
- Data model supports per-item: confirmed — the cart keys items individually and `special_note` is on
  `order_items`, one row per line.

## 4. Does anything already read it?

| Surface | `file:line` | Reads `special_note`? |
|---|---|---|
| **Kitchen WhatsApp** | `class-dd-notifications.php:382` (SELECT) + `:433-434` (`'   Note: ' . $item['special_note']`) | **YES** — but printed **raw** (no `stripslashes`, no rawurlencode issue since it's a wa.me line) |
| **Admin order modal** | `admin/pages/orders.php` | **NO** (grep: none — renders qty/name/variation_lines/price only) |
| **Admin order WhatsApp** | `class-dd-notifications.php` `build_admin_whatsapp_url` | **NO** — and its producer `$notification_data['items']` (`orders-module.php:1123-1129`) carries name/qty/price/variation, **not** note |
| **Order email** | `class-dd-notifications.php` `notify_admin_email` | **NO** — same producers omit note |
| **Producers** | `orders-module.php:1123-1129` (offline) · `build_from_wc_order` `notifications.php:~155` (online) | carry name/qty/price/**variation** (added v3.10.78) — **not `special_note`** |

**Same split as spice:** the value is captured-able and one reader (kitchen) shows it, but the admin surfaces
don't — and their item arrays don't even carry it (producers strip it, exactly like `variation` pre-v3.10.78).
The admin modal is the exception: it reads `get_order_items()` (`SELECT *`, `orders-module.php:494`), so
`special_note` **is** in its payload already — it just isn't rendered.

## 5. Live data — **PENDING (server)**

```sql
SELECT COUNT(*)                                              AS total,
       SUM(special_note IS NOT NULL AND special_note <> '')  AS non_empty
FROM {$P}dishdash_order_items;

-- if any non-empty, eyeball them (did it EVER work, and are they slash-escaped?)
SELECT id, order_id, special_note
FROM {$P}dishdash_order_items
WHERE special_note IS NOT NULL AND special_note <> ''
LIMIT 30;
```
**Expectation:** `non_empty` ≈ 0 (capture broken on both surfaces since inception). Any non-empty rows would be
test data or a historical path; the sample also reveals whether stored notes carry `\'` slash-escaping (§6).

## 6. Reuse & escaping

- **The spice template applies partially.** Capture is the same shape as v3.10.80 (read a modal value → add to the
  Add POST) — literally the same handler. But notes are **plain text**, so **no `variation_lines()`-style decode
  helper** is needed (no JSON, no key/value parsing).
- **Slash-escaping — same root cause, still present:** `sanitize_textarea_field($_POST['note'])`
  (`class-dd-cart.php:242`) does **not** strip the slashes that `wp_magic_quotes()` adds to `$_POST`. So a note
  with a quote/apostrophe stores as `\'`/`\"`. **Every reader must `stripslashes()` on display** — including the
  **existing kitchen reader**, which currently prints raw (`notifications.php:434`) and would show a stray
  backslash the moment notes are actually captured. (This is latent today only because no note is ever stored.)
- **Escaping per surface** (free text is higher-risk than the controlled attribute terms):
  - **Kitchen / admin WhatsApp:** `stripslashes()`, then raw into the `\n`-joined message (`rawurlencode`d
    downstream). No HTML escaping (plain wa.me text).
  - **Order email:** `stripslashes()` + **`esc_html()`** (HTML body). A free-text note **must** be escaped here —
    unlike attribute terms, a customer can type `<`.
  - **Admin modal:** `stripslashes()` server-side (mirror v3.10.79's approach — have PHP hand the modal a clean
    value, e.g. attach a decoded/`stripslashes`d `special_note` in `ajax_get_order`) **and** JS-escape on insert.
    The modal currently injects `item.item_name` unescaped; a free-text note is riskier, so escape it.
- **Producers:** to show notes on admin WhatsApp/email, the two down-maps (`orders-module.php:1123-1129`,
  `build_from_wc_order`) must additively carry `special_note` — same additive change v3.10.78 made for `variation`.

## 7. Release decomposition (ranked by what the customer loses)

The customer types a note and **nothing captures it** — the primary loss is **capture**. One reader (kitchen)
already displays notes, so capture immediately delivers value **if** the display is clean.

- **Schema:** none (column exists). Not a release.

- **R1 — Capture + clean the existing display (the money fix).**
  Wire the desktop/homepage modal's Add handler to read `#ddPmNotes` and send `note` (identical to the v3.10.80
  `variation` wiring, same handler), **and** `stripslashes()` the kitchen reader (`notifications.php:434`) so the
  captured note shows without a backslash. This is a coherent shippable unit — capture **with** a working, clean
  display (kitchen). Not "capture without display."
  - *Scope note:* this fixes the `frontend.js` modal, which serves the homepage on desktop **and** phones. The
    `/restaurant-menu/` mobile app has **no** notes field — see R3.

- **R2 — Admin display parity.**
  Carry `special_note` through the two notification producers (additive, like v3.10.78) and render it — with
  `stripslashes()` + per-surface escaping (§6) — on the **admin order WhatsApp**, **order email**, and **admin
  modal** (the modal already has the value via `SELECT *`, just needs rendering). For restaurants that work off
  the admin surfaces rather than the kitchen message.

- **R3 — Mobile `/restaurant-menu/` notes field (feature add, optional).**
  The mobile app has no textarea at all; adding one (`grid.php` markup + read it in `menu-page.js` instead of the
  hardcoded `''`) is an additive feature, separable from R1/R2. Only needed if that surface should offer notes.

**Must ship together:** capture must land with at least one **clean** display. Because the kitchen reader already
exists, **R1 = capture + kitchen `stripslashes`** satisfies that on its own. R2 and R3 are independent extensions.
**Do not** ship capture while leaving the kitchen reader printing raw slashes (that regresses the one surface that
works).

---

## Pending server checks (consolidated)
1. §5 — `special_note` non-empty count + sample (did it ever work; are stored notes slash-escaped?).
2. (context) confirm `dishdash_orders.special_instructions` has no live writer/reader tied to the modal (grep shows none in-repo) — so R1 correctly targets the per-item `special_note`, not the per-order field.
