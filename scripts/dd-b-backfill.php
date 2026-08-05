<?php
/**
 * dd-b-backfill.php — Order↔Customer link migration, Stage 2 backfill.
 *
 * Populates orders.dd_customer_id for EXISTING orders, resolved through the
 * exact same phone normalizer as Stage 1's dry-run
 * (DD_Customer_Manager::normalize_phone() — libphonenumber, national
 * trunk-prefix handling, bare/e164 mode) — both sides (order phone, customer
 * whatsapp) re-normalized here identically, so this produces the SAME
 * resolution the dry-run already reported. Never touches orders.customer_id
 * (WP user ID, untouched — see investigation-b.md), order status, or payment
 * state. Orphans (no resolvable match) are left NULL — confirmed on live:
 * 1 orphan, benign.
 *
 * OPS / SCRATCH SCRIPT. Not loaded by the plugin, not in the autoloader.
 * Ships in the release zip only so it can be run on the server. Execute via
 * WP-CLI, same convention as scripts/dd-r3-migrate.php and
 * scripts/dd-r15-reservation-fee-backfill.php:
 *
 *     DRY-RUN (default, writes NOTHING):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-b-backfill.php
 *
 *     COMMIT (writes dd_customer_id on resolvable orders, inside a single
 *     transaction — any error rolls the whole thing back, all-or-nothing):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-b-backfill.php commit
 *
 * Idempotent: the commit UPDATE only ever targets rows where dd_customer_id
 * IS NULL, so re-running after a successful commit finds nothing left to do
 * and writes nothing.
 *
 * MANDATORY before commit: take a DB backup.
 */

global $wpdb;

if ( ! class_exists( 'DD_Customer_Manager' ) ) {
    echo "ABORT: DD_Customer_Manager not loaded — run via `wp eval-file` in WP context.\n";
    return;
}

$tokens = isset( $args ) ? (array) $args : [];
$commit = in_array( 'commit', $tokens, true ) || in_array( '--commit', $tokens, true );
$mode   = $commit ? 'COMMIT (WRITING)' : 'DRY-RUN (no writes)';

$ot = $wpdb->prefix . 'dishdash_orders';
$ct = $wpdb->prefix . 'dishdash_customers';

echo "=== Order<->Customer Link — Stage 2 Backfill — {$mode} ===\n";
echo "orders table:    {$ot}\n";
echo "customers table: {$ct}\n\n";

// ── Only orders that still need it — already-idempotent at the read stage
// too, not just the write. Re-running never re-touches a resolved row. ──
$orders = $wpdb->get_results(
    "SELECT id, customer_phone FROM {$ot} WHERE dd_customer_id IS NULL",
    ARRAY_A
);
$to_check = count( $orders );

if ( $to_check === 0 ) {
    echo "No orders with dd_customer_id IS NULL — nothing to backfill (already complete, or column not migrated in yet).\n";
    return;
}

echo "{$to_check} order(s) currently have dd_customer_id = NULL and will be checked.\n\n";

// ── Same customer lookup map as the Stage 1 dry-run — every whatsapp
// re-normalized here too, not trusted as already-canonical. ──
$customers = $wpdb->get_results(
    "SELECT id, whatsapp FROM {$ct}",
    ARRAY_A
);
$customer_by_key = [];
foreach ( $customers as $c ) {
    $key = DD_Customer_Manager::normalize_phone( (string) $c['whatsapp'] );
    if ( '' !== $key ) {
        $customer_by_key[ $key ] = (int) $c['id'];
    }
}

$resolved = []; // order_id => dishdash_customers.id
$orphans  = 0;

foreach ( $orders as $o ) {
    $key = DD_Customer_Manager::normalize_phone( (string) ( $o['customer_phone'] ?? '' ) );
    if ( '' !== $key && isset( $customer_by_key[ $key ] ) ) {
        $resolved[ (int) $o['id'] ] = $customer_by_key[ $key ];
    } else {
        $orphans++;
    }
}

echo "-- PLAN --\n";
printf( "Would resolve:  %d order(s)\n", count( $resolved ) );
printf( "Would orphan:   %d order(s) (left NULL — no matching customer even after normalization)\n", $orphans );
echo "\n";

if ( $commit ) {
    $wpdb->query( 'START TRANSACTION' );
    try {
        $written = 0;
        foreach ( $resolved as $order_id => $customer_id ) {
            // Raw SQL, not $wpdb->update()'s WHERE array — that method has no
            // way to express "IS NULL" (a null value there is coerced by the
            // %d format spec to the literal 0, producing "dd_customer_id = 0",
            // which is not the same condition and would never match). The
            // upstream SELECT already filtered to dd_customer_id IS NULL, but
            // re-checking it here too, correctly, keeps a single write from
            // ever clobbering a value another process set in between.
            $result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$ot} SET dd_customer_id = %d WHERE id = %d AND dd_customer_id IS NULL",
                $customer_id,
                $order_id
            ) );
            if ( false === $result ) {
                throw new \RuntimeException( "UPDATE failed for order_id={$order_id}: " . $wpdb->last_error );
            }
            if ( $result > 0 ) {
                $written++;
            }
        }
        $wpdb->query( 'COMMIT' );
        echo "COMMIT complete. {$written} order(s) updated with dd_customer_id. {$orphans} left NULL (no match).\n";
    } catch ( \Throwable $e ) {
        $wpdb->query( 'ROLLBACK' );
        echo "\nERROR: " . $e->getMessage() . "\nROLLED BACK — no changes written.\n";
        return;
    }
} else {
    echo "DRY-RUN complete — NOTHING written. Review the plan above, take a backup, then re-run with `commit`.\n";
}

echo "\n=== SUMMARY ({$mode}) ===\n";
printf(
    "checked=%d resolved=%d orphaned=%d\n",
    $to_check,
    count( $resolved ),
    $orphans
);
