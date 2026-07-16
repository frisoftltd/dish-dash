# INVESTIGATION — notification + orders hardcode scoping (v3.10.72 pre-work)

**Phase 1, read-only.** Every claim carries `file:line`. Live-DB facts I cannot read are marked
**PENDING (server)** with the exact command.

---

## TL;DR

- The brief's 7 sites are **all confirmed** and are the **only** restaurant-name hardcodes in the order/
  notification path: `class-dd-notifications.php:183, 207, 227, 233, 263, 293` + `class-dd-orders-module.php:151`.
  Nothing missed; every **other** name usage in these modules already reads `dish_dash_restaurant_name`.
- They split into **two atomic fixes**: (R1) the **order notification** name — 6 sites in one file, one logical
  change — and (R2) the **birthday WhatsApp** name — 1 site in a different file/flow.
- **Beyond the name:** the order email's From-**address** is already dynamic (WC option); only the From-**name**
  is hardcoded. Two things are hardcoded but are a **different fix class**, not the name: the email **brand hex
  `#65040d`** (2 sites) and the product word **"Dish Dash"** in email footers (white-label decision).
- **Separate bug found (not a hardcode, flag only):** the customer `order-confirmation` and `status-update`
  emails route through `templates/emails/*.php` files that **do not exist in the repo** → they send empty bodies.
  `notify_restaurant()` is **dead code** (never called). Out of scope for this white-label track — its own ticket.

---

## 1. Every restaurant-name hardcode site (confirmed)

| # | `file:line` | Literal | Surface | Where in the message |
|---|---|---|---|---|
| 1 | `class-dd-notifications.php:183` | `[Khana Khazana] New Order %s — %s RWF` | **Order admin email** | **Subject** |
| 2 | `class-dd-notifications.php:207` | `Khana Khazana &mdash; {date}` | Order admin email | Body — header sub-line |
| 3 | `class-dd-notifications.php:227` | `Dish Dash &mdash; Khana Khazana ordering system` | Order admin email | Body — footer strip |
| 4 | `class-dd-notifications.php:233` | `From: Khana Khazana <…>` | Order admin email | **From name** (address is `get_option`) |
| 5 | `class-dd-notifications.php:263` | `✅ Order Confirmed! — Khana Khazana` | **Order customer WhatsApp** | First line |
| 6 | `class-dd-notifications.php:293` | `🔔 New Order {num} — Khana Khazana` | **Order admin WhatsApp** | First line |
| 7 | `class-dd-orders-module.php:151` | `— Khana Khazana 🍽` | **Birthday-ask WhatsApp** | Sign-off |

**No missed sites in the order/notification path.** Full-repo grep for `Khana Khazana` shows every *other*
occurrence is either a `get_option('dish_dash_restaurant_name', 'Khana Khazana')` **default fallback** (correct —
a shortened live value flows through) or admin-UI/template display text:

- Correct reads (option, only the *default* is "Khana Khazana"): notifications `:44` (reservation WA), `:328`
  (kitchen WA), `:401` (rider WA), `:446` (on-the-way WA); reservations-module `:204`/`:300` (reservation email);
  orders-module `:986` (PesaPal order description); menu `:321`; template `:630`/`:855`; hooks `:184`/`:324`;
  page-dishdash `:87`; grid `:175`; auth-module (multiple).
- Display-only literals (not sent to customers, not restaurant #2 blockers): template-settings/brand-identity
  card mock-ups (`template-module.php:474/484`, `template-settings.php:81/92`, `brand-identity.php:120`
  placeholder).

So the offending pattern is confined to **one module pair** (orders): the **order** email + **order/birthday**
WhatsApp builders never read the option, while the **reservation** and **kitchen/rider/on-the-way** builders
already do. The fix = make the order path do what the reservation path already does.

---

## 2. What each email is (inventory)

| Sender `file:line` | Trigger | Recipient | Subject | Name in it? |
|---|---|---|---|---|
| `DD_Notifications::notify_admin_email` `:179` (`wp_mail` `:236`) | Order created (offline via `on_order_created`; online via `on_payment_complete`) | **Restaurant/admin** (`dd_admin_email` ⁄ `admin_email`) | `[Khana Khazana] New Order …` (`:183`, **hardcoded**) | Subject `:183`, body `:207`, footer `:227`, From-name `:233` — all **hardcoded** |
| `DD_Reservations_Module::send_admin_email` `:198` (`wp_mail` `:302`) | Reservation submitted | Restaurant/admin | `[{restaurant}] New Reservation — {ref}` (`:209`, **reads option ✓**) | From `:300` ✓, footer `:289` ✓ — **dynamic** (the good model) |
| `DD_Orders_Module::send_order_confirmation` `:705` (`wp_mail` `:717`, called `:410`) | Order confirmed | **Customer** (`customer_email`) | `Order Confirmed — {num}` (generic, no name) | Body ← `emails/order-confirmation.php` **← FILE MISSING (empty body)** |
| `DD_Orders_Module::send_status_update` `:741` (`wp_mail` `:756`, called `:586`) | Status change | Customer | `Your Order {num} is now {status}` (generic) | Body ← `emails/order-status-update.php` **← FILE MISSING** |
| `DD_Orders_Module::notify_restaurant` `:725` | — | — | — | **DEAD — never called** (admin email goes via `notify_admin_email`) |
| `DD_Auth_Module` welcome/test `:1032/1107` | New staff user / SMTP test | Staff | reads options | dynamic (SMTP + name options) |

**Order path has exactly one working email** — the admin one (`notify_admin_email`), and it is the one carrying
4 of the 7 hardcodes. The two *customer* order emails are a separate, apparently-broken system (§6 note).

---

## 3. Beyond the name — other restaurant-specific data in the email path

| Concern | `file:line` | State |
|---|---|---|
| **From address** (order email) | `class-dd-notifications.php:233` | `get_option('woocommerce_email_from_address', $admin_email)` — **dynamic** (WC option). NOT the DD SMTP option. |
| **From address** (reservation email) | `class-dd-reservations-module.php:297` | Same WC option — dynamic. |
| **From address** (auth emails) | `class-dd-auth-module.php:982/1025/1101` | `get_option('dd_smtp_from_email', 'noreply@khanakhazana.rw')` — **different system**, dynamic, restaurant-specific *default*. |
| **From name** (order email) | `class-dd-notifications.php:233` | **Hardcoded** "Khana Khazana" (site #4). |
| **From name** (reservation/auth) | reservations `:300`, auth `:983/1024/1100` | Dynamic (`$restaurant` / `dd_smtp_from_name`). |
| **Reply-To** | — | None set anywhere → WP default. Not restaurant-specific. |
| **Signature / footer** | notifications `:227`, reservations `:289` | Both read `"Dish Dash — {restaurant} …"`. Reservation's restaurant part is dynamic; order's is hardcoded. **Both hardcode the product word "Dish Dash".** |
| **URL → khanakhazana.rw** | none in email **bodies** | Only appears as SMTP **defaults** (`noreply@`/`mail.khanakhazana.rw`, auth `:186/196/982/1025/1101`) and admin-UI copy (`:172/177/179/971`). Not baked into sent mail unless SMTP is left unconfigured. |
| **Logo / image in email** | — | None. Order email uses a text header, no logo. |
| **Phone / address in body** | notifications `:96/222/223/301`, etc. | Dynamic (order data / `dish_dash_phone`). |
| **Brand hex `#65040d`** | notifications `:205, :215`; reservations `:207` | **Hardcoded brand color** (violates CLAUDE.md "never hardcode hex"). Separate fix class from the name. |

**SMTP-default cross-reference:** `dd_smtp_from_email` (`noreply@khanakhazana.rw`), `dd_smtp_host`
(`mail.khanakhazana.rw`), `dd_smtp_from_name` (defaults to the restaurant-name option) are all
`get_option($k, 'default')` — functional but seeded with **khanakhazana-specific defaults**, so a fresh restaurant
#2 install defaults to those addresses until the SMTP page is filled in. **The order email's From-address (`:233`)
is SEPARATE from these** — it uses `woocommerce_email_from_address`, not `dd_smtp_from_email`. So fixing the SMTP
defaults would *not* touch the order email, and fixing the order email would *not* touch SMTP. They are independent.

---

## 4. Is `dish_dash_restaurant_name` the right source?

**Yes.** It is already the established source everywhere else in these modules (reservation email `:204`, kitchen
`:328`, rider `:401`, on-the-way `:446`, PesaPal `:986`). The order path is the lone outlier that hardcodes
instead of reading it. **The order email currently reads the name option ZERO times** (`:179-236` is all
literals) — that is the whole defect.

**Tagline (v3.10.70):** none of the 7 sites should use the tagline. Subject `[{name}]`, From-name `{name}`,
WhatsApp sign-off `— {name}`, and the footer `{name} ordering system` all want the **bare name**. After the
v3.10.70 split, `dish_dash_restaurant_name` is exactly that bare name → correct source, tagline not wanted here.
**No email needs the tagline.**

**PENDING (server)** — confirm the live value the fix will render, and the from-address wiring:
```bash
wp option get dish_dash_restaurant_name           # expect the shortened "Khana Khazana" post-v3.10.70
wp option get woocommerce_email_from_address       # what the order/reservation From address resolves to
wp option get dd_admin_email ; wp option get admin_email
```

---

## 5. `orders-module.php:151` — what it is

Inside `DD_Orders_Module::send_birthday_whatsapp()` (`:123`), fired by **WP-Cron ~2 min after a customer's first
order** (guarded by `dd_birthday_asked`, fires once per customer). It builds a **customer-facing** wa.me message
inviting them to share their birthday, signed `— Khana Khazana 🍽` (`:151`). Stored as a transient (`:157`), opened
client-side. **Same fix class** as the email/WhatsApp name hardcodes (swap literal → `get_option
('dish_dash_restaurant_name', …)`), but a **different file, different module, different trigger** (birthday flow,
not order notification) → cleanly **separable** from the order-notification fix.

---

## 6. Release decomposition (ranked by how hard it blocks restaurant #2)

**R1 — Order-notification restaurant name (HIGHEST blocker). One atomic fix, one file.**
`class-dd-notifications.php` sites #1–#6 (`:183, 207, 227, 233, 263, 293`). Read `dish_dash_restaurant_name` once
per method (mirroring the reservation builder at `:204`) and substitute at all six points. **Why atomic:** it is a
single logical change — "order notifications must show the restaurant's own name" — and every order to restaurant
#2 *right now* emails `[Khana Khazana]` and WhatsApps `— Khana Khazana`. Splitting the 6 sites would ship a
half-branded email (e.g. correct subject, wrong footer). Model already proven in the same file.
- **Sub-decision inside R1 (flag, don't decide):** site #3 (`:227`) is `Dish Dash — Khana Khazana ordering
  system`. Replace the **"Khana Khazana"** part with the option now; whether to also drop/rename the **product
  word "Dish Dash"** is a white-label product call (see R4). Recommend R1 changes only the restaurant-name token
  and leaves "Dish Dash" for R4, to keep R1 strictly a name fix.

**R2 — Birthday WhatsApp restaurant name (MEDIUM). One site, separate file.**
`orders-module.php:151`. Same swap. Lower blocker: fires once per customer, ~2 min after first order, and is a
softer touchpoint than the order confirmation itself. Separable from R1 (different file/flow).

**R3 — Email brand color (LOW, optional, DIFFERENT fix class). Own release.**
Replace hardcoded `#65040d` in the order email (`:205, :215`) and reservation email (`:207`) with the brand
option (`dish_dash_primary_color`). Not a name issue; emails render fine, just off-brand for restaurant #2. Kept
separate because it touches color, not text, and drags in a second file (reservations) — don't bundle with R1.

**R4 — Product white-label in email footers (LOW, DECISION-GATED). Defer.**
The literal **"Dish Dash"** at notifications `:227` and reservations `:289`. Only actionable once there's a
product decision on whether the SaaS product name should be hidden/renamed per tenant. Not a restaurant-#2
blocker (it's the *platform* name, not the wrong restaurant). Defer until that decision exists.

**NOT in this track (separate ticket, flag only):** customer `send_order_confirmation` (`:705`, called `:410`)
and `send_status_update` (`:741`, called `:586`) render bodies from `templates/emails/order-confirmation.php` /
`order-status-update.php`, which **do not exist in the repo** — `DD_Module::render_template()` returns silently
when the file is missing (`class-dd-module.php:146-148`), so these customer emails send with an **empty body**
(subject only). `notify_restaurant()` (`:725`) is **dead** (no caller). This is a functional email bug, unrelated
to hardcodes — recommend its own investigation, not folded into the white-label releases.

**Summary:** not one atomic fix, not five — it is **two** name fixes (R1 order notifications, R2 birthday),
plus two clearly-separate optional tracks (R3 email color, R4 product white-label) and one out-of-band bug
(missing customer-email templates). Do **R1 first** — it is the one every restaurant-#2 order hits.

---

## Pending server checks (consolidated)
1. `wp option get dish_dash_restaurant_name` — the value R1/R2 will render.
2. `wp option get woocommerce_email_from_address` — resolves the order/reservation email From address (independent of `dd_smtp_from_email`).
3. `wp option get dd_admin_email` / `wp option get admin_email` — the order-email recipient.
4. (context) `wp option get dd_smtp_from_email` / `dd_smtp_from_name` — confirms the auth-email From system is separate and already dynamic.
