# Investigation — v3.10.29 ajax_cancel_order write-path IDOR

READ ONLY. No PHP edited, no version bump.

## Raw command output

```
--- ajax_cancel_order body ---
    public function ajax_cancel_order(): void {
        DD_Ajax::verify_nonce();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $result   = $this->update_status( $order_id, 'cancelled' );

        if ( ! $result ) {
            $this->json_error( __( 'Cannot cancel this order.', 'dish-dash' ) );
        }

        $this->json_success( null, __( 'Order cancelled.', 'dish-dash' ) );
    }
--- how dd_cancel_order is registered (nonce + nopriv) ---
modules/orders/class-dd-orders-module.php:19: *   - assets/js/cart.js (calls dd_place_order, dd_get_order, dd_cancel_order)
modules/orders/class-dd-orders-module.php:29: *   dd_cancel_order (public), dd_update_status (admin only)
modules/orders/class-dd-orders-module.php:78:        DD_Ajax::register( 'dd_cancel_order',      [ $this, 'ajax_cancel_order' ] );
modules/orders/class-dd-orders-module.php:1350:    public function ajax_cancel_order(): void {
--- update_status signature (does it check status before cancelling?) ---
    public function update_status( int $order_id, string $new_status ): bool {
        global $wpdb;

        $order = $this->get_order( $order_id );
        if ( ! $order ) return false;

        $old_status = $order->status;

        // Validate transition
        $allowed = dd_order_status_transitions()[ $old_status ] ?? [];
        if ( ! in_array( $new_status, $allowed, true ) ) {
            return false;
        }
        ... (timestamp column selection + $wpdb->update) ...
```

### Registration / nonce / transition map (supporting)

```
DD_Ajax::register( string $action, callable $callback, bool $nopriv = true )   // default nopriv = TRUE
verify_nonce( string $field = 'nonce', string $action = 'dish_dash_frontend' ) // default action = dish_dash_frontend

Line 78:  DD_Ajax::register( 'dd_cancel_order', [ $this, 'ajax_cancel_order' ] );        // 3rd arg omitted -> nopriv = true
Line 79:  DD_Ajax::register( 'dd_update_status', [ $this, 'ajax_update_status' ], false ); // admin path, nopriv = false

dd_order_status_transitions():
    'pending'   => [ 'confirmed', 'cancelled' ],
    'confirmed' => [ 'ready', 'cancelled' ],
    'ready'     => [ 'delivered', 'cancelled' ],
    'delivered' => [ 'ready' ],
    'cancelled' => [ 'pending' ],
```

### Frontend caller search (repo-wide)

```
--- repo-wide search for dd_cancel_order string (excluding class-dd-orders-module.php) ---
(no matches)
--- 'cancel' in cart.js / admin.js ---
assets/js/cart.js:69   '<button class="dd-momo-cancel" id="ddMomoCancel" ...>Cancel</button>'   (payment polling cancel)
assets/js/cart.js:93   '<button class="dd-momo-cancel" id="ddIremboCancel" ...>Cancel</button>'  (payment polling cancel)
assets/js/cart.js:113  '<button class="dd-momo-cancel" id="ddPesaPalCancel" ...>Cancel</button>' (payment polling cancel)
assets/js/cart.js:239-250, 749-778  -> stop polling / return to checkout panel; NONE post to dd_cancel_order
assets/js/admin.js:123 comment only
```

## The 4 answers

**1. Does `ajax_cancel_order` have ANY `current_user_can()` check? Any ownership check?**
No to both. The method body is: verify a shared frontend nonce, read `order_id` from `$_POST`, call `update_status( $order_id, 'cancelled' )`, return. There is **no `current_user_can()` capability check** and **no ownership check** (no comparison of `$order->customer_id` to `get_current_user_id()`). This is the same IDOR class as the v3.10.28 `dd_get_order` bug, but on a **write path** (it mutates order state), which is more severe than a read.

**2. What nonce does it verify — `dish_dash_frontend` or `dish_dash_admin`? Is it registered nopriv (guests reachable)?**
It calls `DD_Ajax::verify_nonce()` with no arguments, so it verifies the **`dish_dash_frontend`** action (the method default), field `nonce`. `dish_dash_frontend` is a shared, non-user-specific nonce rendered on public pages, so it does not bind the request to any identity. Registration at line 78 omits the third `$nopriv` argument, which **defaults to `true`** → `wp_ajax_nopriv_dd_cancel_order` is registered, so **guests are reachable** (any visitor holding a valid public frontend nonce can call it).

**3. Where is Cancel called from in the frontend — legitimate customer cancel path, or admin-only UI?**
**Neither — there is no caller at all.** A repo-wide search for `dd_cancel_order` across `.js`, `.php`, and `.html` returns zero matches outside the handler/its own doc comments. The only "Cancel" buttons in the frontend (`cart.js` lines 69/93/113 — `ddMomoCancel`, `ddIremboCancel`, `ddPesaPalCancel`) are **payment-polling cancel buttons**: their handlers stop the status-polling interval and return the user to the checkout panel; none POST to `dd_cancel_order`. Admin order cancellation goes through the separate, properly-gated **`dd_update_status`** endpoint (registered `nopriv=false`, and `ajax_update_status` checks `current_user_can('dd_manage_orders')`). So `dd_cancel_order` is an **orphaned/dead public endpoint** — there is no legitimate customer cancel path wired to it, yet it remains registered and reachable (including nopriv) by anyone who submits an `order_id`.

**4. Does `update_status` restrict which statuses can transition to cancelled?**
Yes — partially. `update_status()` validates the transition against `dd_order_status_transitions()` and returns `false` (no change) if `new_status` is not in the allowed set for the current status. For `cancelled`, the allowed source states are **`pending`, `confirmed`, and `ready`**. A **`delivered` order cannot be cancelled** (delivered only allows → `ready`), and an already-`cancelled` order cannot be re-cancelled (only → `pending`). So the transition map limits the blast radius to non-terminal orders — but it provides **no authorization**: any pending/confirmed/ready order can be cancelled by **anyone** (guest or any logged-in user) who knows or guesses its numeric `id`. This is a live, unauthenticated order-cancellation IDOR.

## Recommendation (for the fix brief — not applied here)

Apply the same ownership gate pattern as v3.10.28 `ajax_get_order`, OR — since there is **no legitimate frontend caller** — consider deregistering the endpoint entirely (remove line 78) and, if a real customer-cancel feature is ever needed, add it deliberately with an ownership check. At minimum: fetch the order, then `if ( ! current_user_can('dd_manage_orders') ) { require $uid && (int)$order->customer_id === $uid, else json_error }`, and set `nopriv=false` on registration.
