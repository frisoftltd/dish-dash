# Investigation — v3.10.28 dd_get_order ownership verification

## 1. `get_customer_for_user()` call form

File: `modules/orders/class-dd-customer-manager.php:312`

```php
public static function get_customer_for_user( int $user_id ) {
```

- **Static method** on `DD_Customer_Manager`. Call form: `DD_Customer_Manager::get_customer_for_user( $user_id )`.
- Not an instance method, no property/accessor exists for it (`class-dd-orders-module.php` holds no `$customer_manager` property). Not a global function.
- Confirmed callers elsewhere in the codebase use this exact static form:
  - `modules/profile/class-dd-profile-module.php:128` and `:379`
  - `modules/orders/class-dd-customer-profile.php:73`

**Not relevant to `ajax_get_order()`:** this method looks up the `wp_dishdash_customers` row by `user_id` (a customer-profile lookup), it does not take or check an `order_id`. It plays no role in the order ownership gate.

## 2. `get_customer_for_user()` return shape

```php
return $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id
) );
```

- Returns **object|null** (`$wpdb->get_row()` default output type is `OBJECT`).
- If it were used, the owning WP user id would be accessed as `->user_id` (the customers table column), not `->id` — `id` is the row's own primary key, `user_id` is the linked WP user. Moot for this task per point 1.

## 3. `get_order()` return shape

File: `modules/orders/class-dd-orders-module.php:479`

```php
public function get_order( int $order_id ): ?object {
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
            $order_id
        )
    );
}
```

- Returns **object|null** (typed return, `$wpdb->get_row()` default OBJECT mode).
- Owning-customer field is accessed as `$order->customer_id` (not `$order['customer_id']`). `dishdash_orders.customer_id` stores the WP user ID of the placing customer (confirmed via `order_permission()` at line 1628: `(int) $order->customer_id === get_current_user_id()`, and via `get_orders()`'s `customer_id` filter at line 515-518).

## 4. Current `ajax_get_order()` — as it stands right now

File: `modules/orders/class-dd-orders-module.php:1143-1165`

```php
public function ajax_get_order(): void {
    DD_Ajax::verify_nonce();
    $order_id = absint( $_POST['order_id'] ?? 0 );
    $order    = $this->get_order( $order_id );

    if ( ! $order ) {
        $this->json_error( __( 'Order not found.', 'dish-dash' ) );
    }

    // Ownership gate — staff may read any order; a customer may read only their own.
    // Mirrors order_permission() below: dishdash_orders.customer_id stores the WP user ID.
    if ( ! current_user_can( 'dd_manage_orders' ) ) {
        $uid = get_current_user_id();
        if ( ! $uid || (int) $order->customer_id !== $uid ) {
            $this->json_error( __( 'You are not authorized to view this order.', 'dish-dash' ) );
        }
    }

    $this->json_success( [
        'order' => $order,
        'items' => $this->get_order_items( $order_id ),
    ] );
}
```

**Note:** This ownership gate is already present in the file at HEAD (shipped in commit `5f9cbcd`, tagged v3.10.28 in `dish-dash.php`/`CLAUDE.md`). It does NOT call `get_customer_for_user()` and has no `function_exists`/`class_exists` fallback — there is nothing of that shape anywhere in this file to remove. The gate compares `$order->customer_id` directly against `get_current_user_id()`, identical in form to `order_permission()` (line 1625-1629), the existing REST permission callback for the same table.

**"Not found" check:** guard clause at top, before the ownership gate — `if ( ! $order ) { $this->json_error(...); }`. `json_error()` (defined in `DD_Module` base class) calls `wp_send_json_error()` and does not `return`/`exit` itself in this snippet's visible scope, but `wp_send_json_error()` internally calls `wp_die()`, which halts execution — so control never reaches the ownership gate or `json_success()` for a missing order.

**`json_success` line:** `$this->json_success( [ 'order' => $order, 'items' => $this->get_order_items( $order_id ) ] );` — unchanged, sits after the gate.

## 5. Other IDOR/unauthorized-read AJAX handlers noticed (names only, NOT fixed)

- **`ajax_cancel_order()`** (`modules/orders/class-dd-orders-module.php:1350`) — registered via `DD_Ajax::register( 'dd_cancel_order', [ $this, 'ajax_cancel_order' ] )` with default `$nopriv = true` (guest-accessible). Takes `order_id` from `$_POST`, calls `$this->update_status( $order_id, 'cancelled' )` directly — **no ownership check and no capability check at all**. Worse class than the read-only IDOR fixed in `ajax_get_order()`, since it's a write/state-mutation, not just a read.

No other order/reservation AJAX handlers reviewed in this pass showed a gap — `ajax_update_status`, `ajax_toggle_test`, `ajax_kitchen_queue`, `ajax_mark_notifications_read`, `ajax_mark_month_paid` all gate on `current_user_can( 'dd_manage_orders' )` or `manage_options` before touching data.
