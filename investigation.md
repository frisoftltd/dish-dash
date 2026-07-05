# Investigation — Identity Unification (libphonenumber + intl-tel-input) — READ-ONLY plan

Date: 2026-07-05. Working tree v3.10.32 (`0fea04a`). No edits, no installs. Survey + sequenced
build plan only. All facts from raw reads/grep.

---

## Part A — Capture surfaces (every place a phone is entered)

**A1 — Order capture (two surfaces).**
- **`/checkout/` page** — `templates/checkout/checkout.php:100-102`:
  `<input type="tel" name="customer_phone" required />` (no pattern/picker). Bound by
  `assets/js/cart.js` (header comment `checkout.php:12`).
- **Cart drawer** — field `#ddFieldWhatsapp` (`cart.js:631`), read `:644`
  `wa = waEl.value.trim()`, non-empty-validated `:654`, submitted as `whatsapp` in the
  `dd_place_order` payload (`:682-687`).
- **Server landing:** `DD_Orders_Module::place_order()` — `class-dd-orders-module.php:359`
  `'customer_phone' => sanitize_text_field( $data['customer_phone'] )` → stored **raw** on the
  order row (no normalization). The identity key is derived separately by `upsert()` (post-order).

**A2 — Profile "connect your phone".**
- Markup `templates/profile/my-profile.php:23`:
  `<input type="tel" id="ddProfilePhone" placeholder="07XX XXX XXX" inputmode="numeric">`.
- JS `:123-139` → POST `dd_profile_link_phone` (`phone`, nonce).
- Handler `DD_Profile_Module::ajax_link_phone()` — `class-dd-profile-module.php:399-413`:
  `check_ajax_referer('dd_profile','nonce')` → `DD_Customer_Manager::link_user_to_phone($user_id,$phone,$name)`.

**A3 — Other surfaces (grepped).**
- **Reservations modal (THIRD capture surface)** — `templates/reservations/modal.php:98-100`:
  `<input type="tel" id="dd-res-whatsapp" name="dd_whatsapp" placeholder="+250 78 000 0000" required>`.
  Submits to the reservations module, which resolves identity via
  `apply_filters('dd_resolve_customer_id', 0, $whatsapp, $name)`
  (`class-dd-reservations-module.php:82`) → `on_resolve_customer_id()` → `normalize_phone()`.
- **MoMo payment sub-field** — `#ddMomoPhone` (`cart.js:574`, placeholder `e.g. 0788 123 456`),
  read `:667`, sent as `momo_phone`; used by the MoMo gateway (`class-dd-orders-module.php:818`),
  **not** an identity key. (Should still get the picker for UX consistency, but is out of the
  identity path.)
- **Display-only (no capture)** — `wa.me` links in `class-dd-notifications.php:252,:472`,
  `my-profile.php:100`, and a `tel:` link in `class-dd-template-module.php:843`. These strip to
  digits for URLs; unaffected by identity normalization but should be re-derived from the E.164
  key post-migration for consistency.
- **No admin manual order-entry surface** (POS module is commented out in the loader).

**Capture inventory for intl-tel-input:** checkout `customer_phone`, cart `#ddFieldWhatsapp`,
reservations `#dd-res-whatsapp`, profile `#ddProfilePhone` (+ optional MoMo `#ddMomoPhone`).
**Server normalize-on-submit points:** `place_order()` (add normalization of `customer_phone`),
`upsert()`, `on_resolve_customer_id()`, `link_user_to_phone()` — all already funnel through
`normalize_phone()` except the raw order-row store at `:359`.

---

## Part B — intl-tel-input integration (front end)

**B1 — Current enqueue pattern.** Frontend JS/CSS is enqueued via `wp_enqueue_script/style` with
`DD_ASSETS_URL . "js|css/{file}"` — see `DD_Module::enqueue_script()`
(`dishdash-core/class-dd-module.php:99-107`), `DD_Frontend::enqueue_assets()`
(`frontend/class-dd-frontend.php:50`), and `DD_Template_Module::enqueue_frontend_assets()`.
No build step, no jQuery, no `asset_url()` (that helper was removed in v3.10.20 — minification is
gone; sources are served directly). **Slot-in for intl-tel-input v17+:** vendor the UMD build into
`assets/vendor/intl-tel-input/` (`intlTelInput.min.js`, `intlTelInput.css`, `utils.js`, and the flag
`img/` or CSS sprite), and enqueue them from the same module that enqueues `cart.js` (Template
module) + wherever profile/reservations assets load. **The `utils.js` (formatting/validation, which
embeds libphonenumber-js) CAN be self-hosted** — pass `loadUtils`/`utilsScript` to the vendored file
instead of the CDN. No runtime CDN dependency; consistent with LiteSpeed/self-host posture.
⚠️ `.gitignore` currently ignores `*.min.js`/`*.min.css` (lines 2-5) — **the vendored
`intlTelInput.min.*` files would be gitignored and silently excluded**; the enqueue must reference
non-min filenames, or `.gitignore` must whitelist `assets/vendor/**`. Flag for the build phase.

**B2 — E.164 hand-off on submit.** intl-tel-input exposes `iti.getNumber()` →
full E.164 string (e.g. `+250788123456`), and `iti.isValidNumber()`. Wiring (no build step): on
each form's submit/place-order handler, before building the AJAX payload, read `iti.getNumber()`
and write it into the value that currently gets sent (`wa`, `customer_phone`, `dd_whatsapp`,
`ddProfilePhone`), e.g. via a hidden field or by replacing the variable read at `cart.js:644`.
Plain `addEventListener` on the existing submit path — no bundler.

**B3 — UX / edge concerns.** Default country = Rwanda (`initialCountry:"rw"`,
`countrySearch`, and geo-IP lookup optional). Invalid input → `isValidNumber()` false; must show a
field error and block submit (coexists with the existing vanilla non-empty checks — extend them,
don't replace). Mobile: `type="tel"` keypad stays; intl-tel-input adds the flag dropdown and live
formatting. It must not double-submit both the formatted display value and the E.164 — always send
`getNumber()`. Keep graceful degradation: if the vendored JS fails to load, fall back to the raw
input + server-side normalization so checkout never blocks.

---

## Part C — libphonenumber integration (server)

**C1 — Introducing Composer.** No `composer.json` exists (confirmed — Glob `**/composer.json` →
none). Plan: add `composer.json` + `composer.lock` at repo root; run `composer install` **locally**;
**commit the resulting `vendor/`**; `require_once __DIR__ . '/vendor/autoload.php';` guarded in
`dish-dash.php` before modules load. Because the release workflow does not run Composer (Part C3),
`vendor/` **must** be committed — nothing installs on the server. Package choice: **use
`giggsey/libphonenumber-for-php-lite`** — it drops geocoder/carrier/timezone metadata and keeps
`PhoneNumberUtil::parse/isValidNumber/format`, which is all we need (E.164 in/out + validity). Full
package is several MB; `-lite` is substantially smaller but still the heaviest thing in the repo.
Report/decision point: confirm `-lite` includes core parse+format for all regions (it does — it
only strips the auxiliary metadata), so it covers our needs.

**C2 — Reworking `normalize_phone()` — the format flip.** New body: `parse($input, $regionHint)`
where `$regionHint` comes from the picker's selected country (default `RW`), then
`format(E164)` → `+250788123456`; on `NumberParseException`/invalid, return `''`. **This CHANGES the
canonical output from bare `250…` to `+250…`.** Every identity site is affected because each both
*stores* and *matches* on this output:
- `upsert()` `:33/:40/:69`, `on_resolve_customer_id()` `:183/:188/:196`,
  `link_user_to_phone()` `:244/:255/:289` — all write `customers.whatsapp` and look up
  `WHERE whatsapp = %s` using the function's return value.
- Existing stored keys are bare `250…` (and some broken `0788…`). The moment the function emits
  `+250…`, **`WHERE whatsapp = %s` stops matching every existing row** until those rows are rewritten.
  → **The format flip and the Part D backfill MUST ship in the same coordinated release** (or behind
  the same migration guard). You cannot flip the format without simultaneously migrating stored keys.

**C3 — GitHub Actions / vendor-in-zip (LOUD FLAG).** `.github/workflows/release.yml` zips the
**checked-out git tree** via `rsync` and has **no `composer install` step**. Its excludes are
`.git`, `.github`, `.gitignore`, `node_modules`, `*.md`, `tests` — **`vendor/` is NOT excluded**, so
a committed `vendor/` **will** be bundled. Consequences:
- **`vendor/` must be committed** (the workflow can't generate it). If it's missing or gitignored,
  the zip ships without it and the plugin **fatals on load** at `require vendor/autoload.php`.
- The `--exclude='*.md'` and `--exclude='tests'` rules will strip markdown/test dirs *inside*
  vendor packages — harmless for runtime, but ensure no package needs a `*.md` at runtime (none do)
  and that Apache-2.0 **LICENSE** files (extensionless) are retained (they are — not `*.md`).
- Add a CI guard: fail the build if `vendor/autoload.php` is absent, so a bad release can't publish.

---

## Part D — Backfill / dedupe migration

**D1 — Scope (not written).** One-time migration must:
1. **Normalize** existing `dishdash_customers.whatsapp` and `dishdash_orders.customer_phone` to the
   new E.164 form.
2. **Collapse duplicate customer rows** that are the same subscriber under different formats
   (e.g. `250788…`, `0788…`).
Decisions the collapse must make: **which row survives** (recommend oldest `first_order_at` /
lowest `id`, or the one carrying `user_id`); **merge `total_orders` (sum), `total_spent` (sum),
`first_order_at` (min), `last_order_at` (max), `birthday`/`user_id`/`dd_birthday_asked` (prefer
non-null / the linked row)**; **re-point** `dishdash_birthday_tokens.customer_id` and anything keyed
to the losing rows; **UNIQUE-key handling** — `customers.whatsapp` and `uniq_user_id` are UNIQUE, so
the merge must delete losers *before/at* the moment the survivor takes the canonical `whatsapp`.
**Do NOT touch `dishdash_reservations.customer_id`** — that column is the customers-table **PK by
design** (`reservations:82→:113`); after customer rows merge, reservation `customer_id` values that
point at a losing row must be **re-pointed to the surviving PK** (this is the one place reservations
intersect the dedupe). Order rows use `customer_id = WP user ID` (v3.10.31) — independent of this
dedupe, but `orders.customer_phone` still gets normalized for the phone-anchored resolution (Part E).

Read-only impact counts (run before designing — NOT run here):
```sql
SELECT COUNT(*) FROM wp_dishdash_customers;                    -- rows to normalize
SELECT COUNT(*) FROM wp_dishdash_orders;                       -- customer_phone to normalize
-- duplicate groups after digits-only collapse:
SELECT RIGHT(REGEXP_REPLACE(whatsapp,'[^0-9]',''),9) tail9, COUNT(*) n, GROUP_CONCAT(id) ids
FROM wp_dishdash_customers GROUP BY tail9 HAVING n>1 ORDER BY n DESC;
```

**D2 — Dry-run + rollback (mandatory).** Structure the migration as a WP-CLI command with a
`--dry-run` default that **only SELECTs and prints a report**: per duplicate group, the survivor,
the losers, the merged stat totals, and every row that would be re-pointed/deleted — with a final
"X customers merged, Y orders/reservations re-pointed, Z keys rewritten" summary. A human reviews
that report; a `--commit` flag (or second run) performs writes inside a transaction where the engine
allows. **Rollback = a full DB backup taken immediately before the `--commit` run.** On this host
(cPanel/`server372`), that's a `mysqldump` via cPanel Terminal or phpMyAdmin export of the `wp_`
tables before running; document the exact dump command in the release notes. No schema change is
required by the backfill (it's data-only), so rollback is a restore of the dumped tables.

---

## Part E — Phone-anchored resolution (the original goal)

**E1 — Layer on v3.10.31, don't replace it.** Today favorites/history/recent resolve by
`orders.customer_id = get_current_user_id()` (v3.10.31). The identity phase adds a **second axis**:
a customer's **set of known E.164 numbers**. Post-dedupe, "known numbers for this user" =
`customers.whatsapp` for every row where `customers.user_id = $user_id` (normally one after dedupe,
but the query should tolerate several). Resolution becomes:
```
orders WHERE customer_id = :wp_user_id
   OR  customer_phone IN (:known_e164_numbers)   -- normalized both sides
```
This recovers **guest orders** (NULL `customer_id`) that the person placed under a phone they later
linked — the current WP-user-ID-only match can never surface those. Requires `orders.customer_phone`
to be normalized (Part D) so the `IN (...)` compares like-for-like. The "known numbers" set comes
from the (now deduped, E.164) `customers` rows linked to the user via `user_id`.

---

## Proposed release sequence (single-fix releases, in order)

1. **R1 — Vendor intl-tel-input (front-end capture only, no format change).** Add the picker to all
   capture surfaces; on submit send `getNumber()` E.164 into the *existing* fields; server still runs
   the **current** bare-`250…` `normalize_phone()` (E.164 input like `+250788…` already normalizes to
   `250788…` today, so no format break). Pure UX + cleaner input. *Depends on: nothing.* Also resolve
   the `.gitignore` `*.min` exclusion for `assets/vendor/**`.
2. **R2 — Introduce Composer + committed `vendor/` + CI guard, NO behavior change.** Add
   `composer.json`, commit `vendor/` (`-lite`), `require autoload.php`, and a CI step that fails if
   `vendor/autoload.php` is missing. `normalize_phone()` still returns bare `250…` (don't flip yet).
   *Depends on: R2 must land and be confirmed in a deployed zip before R3, to prove `vendor/` ships.*
3. **R3 — Format flip + backfill, SAME release.** Rework `normalize_phone()` to emit `+250…` via
   libphonenumber **and** run the D1/D2 migration (dry-run reviewed first, DB backup taken) that
   rewrites all stored keys and dedupes — atomically paired. *Depends on: R1, R2. MUST be one release.*
4. **R4 — Phone-anchored resolution (Part E).** Extend favorites/history/recent to match
   `customer_id = user_id OR customer_phone IN (known E.164 set)`. *Depends on: R3 (needs E.164
   everywhere + deduped known-number set).*
5. **R5 — Display cleanup (optional).** Re-derive `wa.me`/`tel:` links from the E.164 key. *Depends on: R3.*

## Top risks

1. **`vendor/`-missing-in-zip → fatal on load (C3).** The workflow runs no `composer install`; if
   `vendor/` isn't committed (or is gitignored), every install fatals at `require autoload.php`.
   Mitigate: commit `vendor/`, add a CI presence-guard, and verify a built zip contains it before R3.
2. **Format-flip / backfill coupling (C2 + D1).** Flipping `normalize_phone()` to `+250…` without
   simultaneously migrating stored keys breaks `WHERE whatsapp = %s` for **every** existing customer
   → mass linking/upsert failure and duplicate creation. They **must** ship together (R3), behind a
   reviewed dry-run and a fresh DB backup.
3. **`.gitignore` swallowing vendored min assets (B1).** `*.min.js`/`*.min.css` are ignored;
   intl-tel-input's `.min` files would be silently excluded from git → missing on deploy. Whitelist
   `assets/vendor/**` or ship non-min filenames.
4. **Dedupe correctness / UNIQUE-key collisions (D1).** Merging rows that share the soon-to-be
   canonical `whatsapp` must delete losers before the survivor claims the key, and re-point
   reservations (`customer_id` = customers PK) and birthday tokens — or the migration half-completes.
   Dry-run must surface every collision first.
5. **Repo/zip weight (C1/C3).** Committing `vendor/` (even `-lite`) markedly enlarges the repo and the
   release zip; confirm acceptable and that the `*.md`/`tests` excludes don't strip anything runtime.

**No code. No installs. Awaiting scoping.**
