# Report — Order Delivery Modes + Manual MoMo QR

Rolling report. One section per release. Do not push until told.

---

## Release 1 — v3.10.50 — Settings fields only (no behavior change) ✅

**Root cause / goal:** Add three persisted settings so later releases can branch on them.
No behavior is wired this release.

**Files changed:**
- `admin/pages/settings.php`
  - Save handler (after the Pricing & Fees block): persist three new options.
    - `dish_dash_order_notify_dashboard` — `isset($_POST[...]) ? '1' : '0'`, checkbox default **'1'** (on).
    - `dish_dash_order_handoff_whatsapp` — `isset($_POST[...]) ? '1' : '0'`, checkbox default **'0'** (off).
    - `dish_dash_momo_merchant_code` — text, **digits-only** via `preg_replace('/\D/','', …)`, no default.
  - Markup: new **"📦 Order Handling"** `dd-settings-card` inserted after the Pricing & Fees card
    (before the hidden opening-hours field). Two `.dd-check-label` checkboxes + one `dd-input--medium`
    numeric text field, each with the brief's helper text. Follows the `dd_fees_enabled` pattern verbatim
    (`checked()` for state, `get_option()` for read).
- `dish-dash.php` — version bumped to `3.10.50` (header comment + `DD_VERSION` constant).
- `CLAUDE.md` — `Last updated`, Current State (Deployed version / sub-phase / Next task / Last working
  state), and release table rows for v3.10.50–v3.10.55.

**Scope guard:** Only the three `update_option()` calls + one settings card were added. No consumer reads
these options yet. No gateway, order-flow, notification, or schema code touched. The `DD_MoMo` Collections
path, PesaPal, and `dd_momo_check_status` are untouched (Option B boundary intact).

**Test steps (developer, after deploy):**
1. WP Admin → Dish Dash → Settings → scroll to **📦 Order Handling**. Confirm three fields render:
   Dashboard Notifications (checked), WhatsApp Handoff (unchecked), MoMo Merchant Code (empty).
2. Enter a merchant code with spaces/letters (e.g. `12 34ab5`) → Save → reopen → value is digits only
   (`12345`).
3. Toggle WhatsApp Handoff on → Save → box stays checked on reload.
4. Verify persistence:
   - `wp option get dish_dash_order_notify_dashboard` → `1`
   - `wp option get dish_dash_order_handoff_whatsapp` → `1` (after toggling on) / `0` (default)
   - `wp option get dish_dash_momo_merchant_code` → the digits saved
5. Confirm no visible behavior change anywhere else (dashboard notifications, checkout, WhatsApp all
   exactly as before).

**Status:** Implemented, committed (not pushed). Awaiting developer publish + deploy before Release 2.

---

## Release 2 — v3.10.51 — Gate dashboard notifications
_Pending — starts after Release 1 is deployed._

## Release 3 — v3.10.52 — Customer WhatsApp handoff button
_Pending._

## Release 4 — v3.10.53 — Manual MoMo up-front placement
_Pending._

## Release 5 — v3.10.54 — Dynamic QR + iOS copy fallback
_Pending._

## Release 6 — v3.10.55 — Single-tap "I have paid"
_Pending._
