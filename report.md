# Fix Report — v3.10.28: IDOR ownership gate on `ajax_get_order`

## Final `ajax_get_order()` body (exactly as saved in the file)

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

File: `modules/orders/class-dd-orders-module.php` (function begins at line 1143)

## Version lines from `dish-dash.php`

```
* Version:           3.10.28
define( 'DD_VERSION',         '3.10.28' );
```

Both already at 3.10.28 — no bump needed.

## Gate confirmation

- ✅ Gate contains `current_user_can( 'dd_manage_orders' )` (staff bypass).
- ✅ Gate contains `(int) $order->customer_id !== $uid` (customer restricted to own orders).
- ✅ Guests (`$uid === 0`) are refused via the `! $uid` check.

## Push result

Push **succeeded** — `git push origin HEAD:main` completed.

## Notes

The code fix, version bump (already 3.10.28), and CLAUDE.md release-log row were
committed in a prior release commit (`a250760`). This commit adds the completed
`report.md`; no source or version files changed.
