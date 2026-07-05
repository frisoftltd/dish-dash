# Investigation — `render_order_history()` Ownership-Key Bug (READ-ONLY)

Date: 2026-07-05. Working tree at v3.10.30 (`7198b98`). All findings from raw
file reads / grep of the working tree.

**Bug in one line:** customer-facing order queries filter
`dishdash_orders.customer_id` (which stores the **WP user ID**) against
`$customer->id` (the **`dishdash_customers` auto-increment PK**). Wrong key →
wrong/empty results except by coincidence.

---

## 1. The buggy query, verbatim

**File:** `modules/profile/class-dd-profile-module.php:122-146`
```php
public function render_order_history(): void {
    // Stop WooCommerce's default "no orders" output for this endpoint.
    remove_all_actions( 'woocommerce_account_orders_endpoint' );

    global $wpdb;
    $user_id  = get_current_user_id();
    $customer = DD_Customer_Manager::get_customer_for_user( $user_id );

    echo '<div class="dd-order-history">';
    echo '<h2 class="dd-order-history__title">Order history</h2>';

    if ( ! $customer ) {
        echo '<p class="dd-order-history__empty">Add your phone number on the ... My Profile ... page ...</p>';
        echo '</div>';
        return;
    }

    $orders = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, order_number, total, status, order_type, payment_method, created_at
         FROM {$wpdb->prefix}dishdash_orders
         WHERE customer_id = %d AND is_test = 0
         ORDER BY id DESC
         LIMIT 50",
        (int) $customer->id            //  ← BUG: customers-table PK, not WP user ID
    ) );
    ...
```

**How the filter value is derived.**
`DD_Customer_Manager::get_customer_for_user( $user_id )`
(`modules/orders/class-dd-customer-manager.php:312-319`):
```php
public static function get_customer_for_user( int $user_id ) {
    global $wpdb;
    if ( ! $user_id ) return null;
    $table = $wpdb->prefix . 'dishdash_customers';
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id
    ) );
}
```
Returns the **`dishdash_customers` row**. So `$customer->id` is that table's
auto-increment PK; the WP user ID is a **different** column, `$customer->user_id`.
The query binds `$customer->id` → the wrong key.

**Implication.** The bound value (`$customer->id`) is the commercial-record row PK,
but `orders.customer_id` holds `get_current_user_id()`. Correct binding is
`$user_id` (already computed at line 127) or `$customer->user_id`.

---

## 2. The key mismatch, concretely (from `install.php`)

**`dishdash_orders`** (`install.php:101-141`):
```
customer_id          BIGINT UNSIGNED          DEFAULT NULL,   (line 106)
```
Populated at order creation (`class-dd-orders-module.php:357`):
```php
'customer_id' => get_current_user_id() ?: null,
```
→ **holds the WP user ID** (or NULL for guests). Confirmed a second way by the
`dd_get_order` ownership gate (`:1631`): `(int) $order->customer_id === get_current_user_id()`.

**`dishdash_customers`** (`install.php:345-362`):
```
id       BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,   (line 346)  ← table PK
user_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,         (line 347)  ← the WP user ID
...
PRIMARY KEY  (id),
UNIQUE KEY   uniq_user_id (user_id)
```

**Unambiguous fix target:** compare `dishdash_orders.customer_id` against the
**WP user ID** — i.e. `get_current_user_id()` / `$customer->user_id` — **never**
`$customer->id`. The two coincide only when a user's customers-row PK happens to
equal their WP user ID (early rows on a fresh install), which masks the bug in
light testing.

---

## 3. Every instance of this specific bug

Three query sites bind a customers-PK to `orders.customer_id`. All three are the
same bug and are the full fix scope:

| # | File:line | Method | Query (WHERE) | Bound value |
|---|---|---|---|---|
| A | `modules/profile/class-dd-profile-module.php:139-146` | `render_order_history()` | `dishdash_orders … WHERE customer_id = %d AND is_test = 0 ORDER BY id DESC LIMIT 50` | `(int) $customer->id` |
| B | `modules/orders/class-dd-customer-profile.php:100-112` | `DD_Customer_Profile::get()` — favorites | `… order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.customer_id = %d AND o.is_test = 0 AND o.status NOT IN ('cancelled') …` | `$customer_id = (int) $customer->id` (`:99`) |
| C | `modules/orders/class-dd-customer-profile.php:122-131` | `DD_Customer_Profile::get()` — recent orders | `dishdash_orders WHERE customer_id = %d AND is_test = 0 AND status NOT IN ('cancelled') ORDER BY id DESC LIMIT 5` | `$customer_id = (int) $customer->id` (`:99`) |

B and C share the single wrong assignment at `class-dd-customer-profile.php:99`:
```php
$customer_id = (int) $customer->id;   // ← should be the WP user ID
```

**Correct / NOT part of this bug (do not touch):**
- `class-dd-orders-module.php:519` `get_orders()` generic `AND customer_id = %d` — its
  callers all pass `get_current_user_id()` (`:1718` `shortcode_account`; the v3.10.30
  tracker uses its own `$uid = get_current_user_id()` query at `:1679`). Correct.
- `class-dd-orders-module.php:1631` `order_permission()` — uses `get_current_user_id()`. Correct.
- `class-dd-profile-module.php:388` birthday save `$wpdb->update( ... [ 'id' => (int) $customer->id ] )`
  — correctly targets the **customers** table by its own PK. Not an order query. Correct.
- `class-dd-customer-manager.php` `[ 'id' => $existing->id ]` updates — operate on the
  customers table by its PK. Correct.

**Implication.** Fix exactly 3 query sites via 2 edits (the `$customer->id` bind in
`render_order_history()`, and the single `$customer_id` assignment feeding B+C). Nothing
else carries this bug.

---

## 4. The correct fix shape

- **Filter value:** `orders.customer_id = get_current_user_id()` (WP user ID). In
  `render_order_history()`, `$user_id` is already in scope (`:127`) — bind that. In
  `DD_Customer_Profile::get()`, the method param `$user_id` is in scope — set
  `$customer_id = $user_id;` (or bind `$user_id` directly).
- **`is_test = 0`:** already present in all three queries — preserve.
- **`ORDER BY … DESC` (newest first):** already present (`ORDER BY id DESC`) in A and C;
  B is a favorites aggregate ordered by `times_ordered DESC` — preserve as-is.
- **`status NOT IN ('cancelled')`:** present in B and C — preserve (favorites/recent-reorder
  intentionally exclude cancelled). A (full history) intentionally includes all statuses —
  preserve.
- **Guest handling (`get_current_user_id() === 0`):** `render_order_history()` currently
  gates on `if ( ! $customer )`. `DD_Customer_Profile::get()` already returns the empty
  `$profile` early when `! $user_id` (`:70`). Note for the fix brief: once the queries key
  off the WP user ID, they no longer *need* a linked `dishdash_customers` row to return the
  user's own orders (orders are stamped with the WP user ID at creation regardless of phone
  linking, v3.9.8). Whether to keep the "add your phone" gate as a precondition for showing
  history, or show orders independent of phone-linking, is a **fix-brief design decision** —
  flagged, not decided here.

**Implication.** Minimal, safe change: swap the bound key. Behavior otherwise identical.
The `if ( ! $customer )` / link gates can stay for v1 (smallest diff) or be revisited.

---

## 5. Regression surface

- **`render_order_history()`** is hooked at `class-dd-profile-module.php:38`:
  `add_action( 'woocommerce_account_orders_endpoint', [ $this, 'render_order_history' ], 5 )`,
  and calls `remove_all_actions( 'woocommerce_account_orders_endpoint' )` to suppress WC's
  default. Its output is **echoed HTML only** — no downstream data consumer. The only
  interactive element is the `.dd-reorder-btn` (reads `menu_item_id` from order items).
  No code reads a return value or adapts to the (currently broken) result set.
- **`DD_Customer_Profile::get()`** is consumed by `class-dd-profile-module.php:110`
  (`$profile = DD_Customer_Profile::get( $user_id )`) and rendered by
  `templates/profile/my-profile.php`. Crucially, the **stats shown on My Profile**
  (`total_orders`, `total_spent`, `tier`, `member_since`) are read **directly from the
  `dishdash_customers` row** (`class-dd-customer-profile.php:79-87`), **not** from the buggy
  order queries — so those are already correct and **will not change**. Only `favorites`
  and `recent_orders` derive from the buggy queries B/C; today they are effectively
  empty/mismatched, and the fix will start populating them correctly.
- **No compensating code found.** Nothing was written to expect the wrong key or the empty
  favorites/recent lists; the independent stats path means no caller "adapted" to the bug.
  Fixing it should only *add* correct data, not surprise an existing consumer.

**Implication.** Low regression risk. Expected visible change post-fix: My-Account order
history populates for logged-in customers who previously saw an empty/wrong list; My Profile
favorites + reorder list populate. Profile stat tiles are unaffected (already correct).

---

## Open questions / discrepancies

1. **The bug exists in more places than the brief named.** Beyond
   `render_order_history()` (site A), `DD_Customer_Profile::get()` carries it **twice**
   (sites B favorites, C recent-orders), both from one `$customer->id` assignment
   (`class-dd-customer-profile.php:99`). Recommend fixing all three in one release, as the
   brief scopes ("all instances of THIS bug, only this bug").
2. **Coincidental masking.** On a young single-restaurant install, early `customers.id`
   values can equal their `user_id`, so the bug may appear to "work" for the first few users
   and fail for later ones — consistent with intermittent reports.
3. **Guest / phone-link gate is a design decision.** Post-fix, orders can be listed by WP
   user ID without a linked `dishdash_customers` row. Keep the existing "add your phone"
   gate (smallest diff) or decouple history from phone-linking? Fix brief to decide.
4. **Do NOT over-fix.** `class-dd-profile-module.php:388` (birthday UPDATE by
   `$customer->id`) and the `class-dd-customer-manager.php` customers-table updates correctly
   use the customers PK — they are not this bug and must be left alone.

**No code written. Not committed. Awaiting Phase 2 fix brief.**
