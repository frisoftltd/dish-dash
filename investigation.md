# INVESTIGATION — PesaPal ghost order: payment taken, no order created

**Read-only. No code changes.** Plugin: dish-dash (universal). Surfaced on: nyarutarama install, v3.10.83.

Root cause is **confirmed from source** below (exact `file:line`, all in
`modules/orders/class-dd-orders-module.php` unless noted). The server baseline + the two emulation results are the
**developer's to run** — I am on the local repo checkout (`C:\dish-dash`, Windows, no wp-cli, no server path), so
I cannot query the live nyarutarama DB; those sections are marked **PENDING (server)** with exact commands and are
**not** fabricated.

---

## Root cause — confirmed from source

Checkout (`ajax_place_order()`, `:772`) branches on `payment_method`. The **`pesapal` branch (`:971-1014`)** is
"Option B": it takes payment first and writes **no DB row**, deferring order creation to a client-side poll.

1. **Total computed, ref generated, payment submitted — no DB write:**
   - `:979` `$totals = $this->calculate_totals( $summary['items'], $delivery_fee );`
   - `:981` `$ref = 'DD-' . strtoupper( substr( md5( $whatsapp . microtime() ), 0, 12 ) );`
   - `:983` `$pesapal->submit_order( … )`
2. **Cart stashed in a transient, not the DB:**
   - `:998` `set_transient( 'dd_pesapal_pending_' . $result['order_tracking_id'], [ … 'items' => $summary['items'] … ], 2 * HOUR_IN_SECONDS );`
3. **Returns to the iframe and STOPS — before any order is created:**
   - `:1007-1013` `wp_send_json_success([...]); ` then **`:1013 return;`** — this `return` is **before** the
     `if ( $is_online )` block at **`:1016`** that would create the order. So the pesapal branch **never reaches**
     `place_order()` (`:1019`) **or** `create_wc_order()` (`:1036`).
4. **The order row is written ONLY by the client status poll:**
   - `ajax_pesapal_check_status()` (`:1337`, AJAX `dd_pesapal_check_status`, registered `:86`, called from
     `cart.js`) reads the transient (`:1346`), calls `get_transaction_status()` (`:1353`), and **only if
     `$status === 'COMPLETED'`** (`:1355`) calls `place_order()` (`:1356`) + stamps `payment_status='paid'`
     (`:1373-1379`) + `delete_transient` (`:1381`). This is the **sole** order-creation path for pesapal.

**Consequence (the ghost order):** if the client poll never fires or never sees `COMPLETED` — tab closed,
redirect-back fails, mobile backgrounds the browser, connection drops — `ajax_pesapal_check_status()` never runs,
so `place_order()` never runs. **Money is taken at PesaPal; no DishDash order, no WC order.** The
`dd_pesapal_pending_{tracking_id}` transient sits until its **2-hour** expiry (`:1005`), then vanishes — the cart
data is gone and the payment is unreconcilable from the app.

### Two corroborating facts

**(a) No WC order is ever created for pesapal — even on the happy path.**
The pesapal branch returns at `:1013`, before `if ( $is_online )` (`:1016`), which is the **only** caller of
`create_wc_order()` (`:1036`). And `place_order()` (`:321`) inserts into `dishdash_orders` **without** a
`wc_order_id` (it is not in the insert; the column stays `NULL`). So even when the poll succeeds, the DD row is
created but **`wc_order_id` is NULL** — matching the observed `wc_order_count = 0` and every
`dishdash_orders.wc_order_id IS NULL`.

**(b) The robust IPN path exists but is not wired to DishDash order creation.**
`class-dd-pesapal.php` registers an IPN callback with PesaPal —
`get_or_register_ipn()` (`:50-81`) and `submit_order()` (`:115`) both use
`$callback_url = home_url( '/wc-api/wc_pesapal_gateway/' )`. **But nothing in the plugin handles that route.** A
repo-wide grep for `wc_pesapal_gateway` / `wc-api` / `woocommerce_api_*` / IPN handlers finds **only the two
`home_url('/wc-api/wc_pesapal_gateway/')` strings that build the URL** — there is **no**
`woocommerce_api_wc_pesapal_gateway` action, no REST route, and DD_PesaPal is **not** a `WC_Payment_Gateway` (it's
a custom API client), so WooCommerce's `/wc-api/{gateway}/` dispatcher has nothing to route to either. PesaPal
calls that URL server-to-server on completion, but **DishDash listens on nothing** — order creation depends
entirely on the fragile client poll. The correct fix direction (later, not now) is to create the DD order from a
**server-side IPN/callback** keyed on the tracking id, independent of the browser.

---

## Emulation / reproduction — **PENDING (developer, on the nyarutarama server)**

I cannot run these from the repo checkout (no wp-cli, no server access — verified: `wp` not present,
`/home/imitjsiy/nyarutarama.khanakhazana.rw` not present, host `DESKTOP-DCNACQ6`). Run on the server and paste the
outputs back here.

### Baseline (run now, before testing)
```bash
cd /home/imitjsiy/nyarutarama.khanakhazana.rw
wp db query "SELECT option_name FROM wp_options WHERE option_name LIKE '%dd_pesapal_pending%';"
```
**Baseline result:** _PENDING — paste output._ (Any surviving `_transient_dd_pesapal_pending_*` rows here are
already-stranded orders — payment likely taken, no DD row. Note: expired transients may linger in `wp_options`
until GC, so a hit is a strong signal but confirm against PesaPal's dashboard for that tracking id.)

### Test A — interrupted poll (the ghost order)
1. Place a test PesaPal order through normal checkout.
2. Pay in the iframe, then **deliberately close the tab / kill the browser BEFORE** the confirmation screen
   returns (simulates the interrupted client poll).
3. Verify (read-only):
   ```bash
   wp db query "SELECT COUNT(*) FROM wp_dishdash_orders;"   # expect: NO new row vs before
   wp db query "SELECT COUNT(*) FROM wp_wc_orders;"          # expect: still 0
   wp db query "SELECT option_name FROM wp_options WHERE option_name LIKE '%dd_pesapal_pending%';"  # expect: a pending transient = order stranded
   ```
**Test A result:** _PENDING — paste the three counts/outputs._ (Expected: `dishdash_orders` unchanged,
`wc_orders` 0, a `dd_pesapal_pending_*` transient present → confirms payment taken, order stranded.)

### Test B — happy path (proves WC order still never created)
1. Repeat **without** closing the tab; let the confirmation return normally.
2. Verify:
   ```bash
   wp db query "SELECT id, wc_order_id, payment_method, payment_status FROM wp_dishdash_orders ORDER BY id DESC LIMIT 1;"
   wp db query "SELECT COUNT(*) FROM wp_wc_orders;"
   ```
**Test B result:** _PENDING — paste output._ (Expected: a new `dishdash_orders` row **with `wc_order_id` NULL**
and `payment_status='paid'`; `wc_orders` still 0 → proves the WC order is never created even on success — fact (a).)

---

## Scope notes (flagged, not acted on this phase)

- **Card checkout remains live** and continues to lose interrupted payments while this is open — developer's call
  to leave on; flagged, per the brief.
- **PesaPal keys leaked in `debug.log`** — out-of-band; rotate + truncate when ready. Not addressed here.

## What a fix must do (direction only — NOT this phase, awaiting "proceed")
- Create the DD order from a **server-side** signal (PesaPal IPN / callback keyed on `order_tracking_id`), so an
  interrupted browser can't strand a paid order — the client poll becomes a UX nicety, not the source of truth.
- Decide the WC-order question: either link a WC order on completion (via the online flow / `create_wc_order()`)
  or accept DD-only orders deliberately — but stop leaving `wc_order_id` NULL by omission.
- Idempotency: IPN + poll + any retry must converge on **one** order per `order_tracking_id` (guard on the
  tracking id / ref).

**STOP — awaiting "proceed" before any implementation brief.**
