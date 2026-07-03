# Investigation — v3.10.28 dd_get_order ownership findings

## 1. `ajax_get_order()` — full current method body, verbatim

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

## 2. `get_order()` return type

File: `modules/orders/class-dd-orders-module.php:479-487`

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

- Return type is **`?object`** — an explicit PHP return-type declaration, backed by `$wpdb->get_row()` in its default `OUTPUT_ARRAY_A`-less mode, which is `OBJECT`. Not an associative array.
- The owning-customer field is **`customer_id`**, accessed as **`$order->customer_id`** (property access, not `$order['customer_id']`).
- Confirmed independently by `order_permission()` (same file, line 1625-1629), the REST permission callback for this same table, which does `(int) $order->customer_id === get_current_user_id()` — same object-property access pattern.
- The `$order['id']` / array-style usage seen elsewhere in the codebase (e.g. the WhatsApp message builder) operates on a differently-shaped array built up by hand from POST data / `place_order()`'s own return array — not on the return value of `get_order()`. The two are not the same data structure; `get_order()` itself is unambiguously object-typed.

## 3. `get_customer_for_user()` — call form and return

File: `modules/orders/class-dd-customer-manager.php:305-319`

```php
/**
 * Get the customers-table row for a logged-in user, if linked.
 * Returns null if the user hasn't linked a phone yet.
 *
 * @param int $user_id
 * @return object|null
 */
public static function get_customer_for_user( int $user_id ) {
    global $wpdb;
    if ( ! $user_id ) return null;
    $table = $wpdb->prefix . 'dishdash_customers';
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id
    ) );
}
```

- Confirmed **static** call form: `DD_Customer_Manager::get_customer_for_user( $user_id )`.
- Returns **object|null** (`$wpdb->get_row()`, no `ARRAY_A` argument, default `OBJECT` mode). On no match, `$wpdb->get_row()` itself returns `null`.
- This method looks up the `wp_dishdash_customers` row by `user_id` — it does not take or touch an `order_id`, and its result is a customer-profile row (columns like `name`, `whatsapp`, `total_orders`, `birthday`, etc.), not an order. If used, the row's own primary key would be `$customer->id`; there is no `id` field carrying the WP user id — `user_id` is the WP user id column on that row, `id` is the customers-table row id. **Not applicable to the order-ownership gate** — `ajax_get_order()`'s gate correctly uses `get_order()`'s own `customer_id` field directly, with no call to `get_customer_for_user()` needed or present.

## 4. Nonce + registration

File: `modules/orders/class-dd-orders-module.php:77`

```php
DD_Ajax::register( 'dd_get_order',         [ $this, 'ajax_get_order' ] );
```

- Third argument (`$nopriv`) omitted → defaults to `true` per `DD_Ajax::register()` signature (`dishdash-core/class-dd-ajax.php:42`: `public static function register( string $action, callable $callback, bool $nopriv = true )`). Confirms **guests can reach `dd_get_order`** — `wp_ajax_nopriv_dd_get_order` is registered alongside `wp_ajax_dd_get_order`.
- `ajax_get_order()` calls `DD_Ajax::verify_nonce();` with no arguments, so it uses the method's defaults: field `'nonce'`, action `'dish_dash_frontend'` (`dishdash-core/class-dd-ajax.php:58`: `verify_nonce( string $field = 'nonce', string $action = 'dish_dash_frontend' )`).
- This confirms the existing ownership gate (current_user_can + customer_id match) is the only thing standing between a guest/any logged-in customer and reading an arbitrary order — the nonce alone does not restrict by identity, since `dish_dash_frontend` is a shared, non-user-specific nonce action.

## 5. Other unauthorized-read AJAX handlers noticed (names only — NOT fixed)

- **`ajax_cancel_order()`** (`modules/orders/class-dd-orders-module.php:1350`) — registered via `DD_Ajax::register( 'dd_cancel_order', [ $this, 'ajax_cancel_order' ] )`, also defaulting `$nopriv = true`. Takes `order_id` from `$_POST` and calls `$this->update_status( $order_id, 'cancelled' )` with **no ownership check and no capability check at all**. A write-path issue (state mutation, not just read), left unfixed per scope of this brief.

No other gaps found in this file on this pass — `ajax_update_status`, `ajax_toggle_test`, `ajax_kitchen_queue`, `ajax_mark_notifications_read`, `ajax_mark_month_paid` all gate on `current_user_can( 'dd_manage_orders' )` or `manage_options` before touching data.
