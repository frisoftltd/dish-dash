# Fix Report — v3.10.29: Remove orphaned unauthenticated `dd_cancel_order` endpoint (write-path IDOR)

## What changed

`modules/orders/class-dd-orders-module.php` — deregistered the `dd_cancel_order`
AJAX action (deleted the `DD_Ajax::register( 'dd_cancel_order', ... )` line at ~78).
The `ajax_cancel_order()` method is left in place as unreachable dead code (minimal,
reversible). Two inaccurate header doc comments were corrected (removed the cart.js
"calls dd_cancel_order" claim and the `dd_cancel_order (public)` listing; noted removal
in v3.10.29). No other file touched.

## `grep -n "dd_cancel_order" modules/orders/class-dd-orders-module.php` (after the edit)

```
30: *   (dd_cancel_order removed in v3.10.29 — write-path IDOR, zero callers)
79:        // dd_cancel_order deregistered in v3.10.29 — orphaned, guest-reachable,
```

Both remaining hits are documenting comments. **No `DD_Ajax::register( 'dd_cancel_order', ... )`
live registration remains.** (The `ajax_cancel_order()` method definition does not contain
the string `dd_cancel_order`, so it does not appear here — it remains as dead code, as specified.)

## Version lines from `dish-dash.php`

```
6: * Version:           3.10.29
47:define( 'DD_VERSION',         '3.10.29' );
```

Both bumped 3.10.28 → 3.10.29.

## Push result

Push **succeeded** — `git push origin HEAD:main` completed. (See reply for live output.)
