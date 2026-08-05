<?php
/**
 * dd-b-dryrun.php — Order↔Customer link migration, Stage 1 dry-run.
 *
 * READ-ONLY. No writes, no schema changes. Reports whether each EXISTING
 * order can be resolved to a real wp_dishdash_customers.id, using the SAME
 * phone normalizer the live app already runs on every checkout/reservation
 * (DD_Customer_Manager::normalize_phone() — libphonenumber parsing, national
 * trunk-prefix handling, bare/e164 mode) — NOT a raw SQL string match, which
 * would undercount (see investigation-b.md §2 for why a plain
 * `customer_phone = whatsapp` join is unreliable here).
 *
 * This is Stage 1 of the Order↔Customer Link Fix (Release B). Its output —
 * specifically the orphan count — is the gate for Stage 2 (add
 * orders.dd_customer_id, backfill it, rewrite the 6 broken analytics/insights
 * JOINs). Do not proceed to Stage 2 until these numbers have been reviewed
 * and orphan handling has been decided.
 *
 * OPS / SCRATCH SCRIPT. Not loaded by the plugin, not in the autoloader.
 * Ships in the release zip only so it can be run on the server. Execute via
 * WP-CLI, same convention as scripts/dd-r3-migrate.php and
 * scripts/dd-r15-reservation-fee-backfill.php:
 *
 *     wp eval-file wp-content/plugins/dish-dash/scripts/dd-b-dryrun.php
 *
 * No `commit` mode — this script never writes, by design. Stage 2 (a
 * separate script, written only after these numbers come back) does the
 * actual column-add + backfill.
 */

global $wpdb;

if ( ! class_exists( 'DD_Customer_Manager' ) ) {
    echo "ABORT: DD_Customer_Manager not loaded — run via `wp eval-file` in WP context.\n";
    return;
}

$ot = $wpdb->prefix . 'dishdash_orders';
$ct = $wpdb->prefix . 'dishdash_customers';

echo "=== Order<->Customer Link — Stage 1 DRY-RUN (read-only, no writes) ===\n";
echo "orders table:    {$ot}\n";
echo "customers table: {$ct}\n\n";

// ── Load every order's id, customer_id (WP user id — NOT touched by this
// migration), and customer_phone (the raw value stored at checkout). ──
$orders = $wpdb->get_results(
    "SELECT id, customer_id, customer_phone FROM {$ot}",
    ARRAY_A
);
$total_orders = count( $orders );

if ( $total_orders === 0 ) {
    echo "No orders found — nothing to report.\n";
    return;
}

// ── Pre-load every customer once, keyed by the SAME normalizer output the
// live app already produces for that row (customers.whatsapp is written
// through normalize_phone() at upsert()/on_resolve_customer_id() time, but
// re-normalizing here costs nothing and protects against any row that
// predates a normalizer change — never trust a stored value is still
// canonical under today's rules without re-checking it). ──
$customers = $wpdb->get_results(
    "SELECT id, whatsapp, total_orders FROM {$ct}",
    ARRAY_A
);
$customer_by_key = [];
foreach ( $customers as $c ) {
    $key = DD_Customer_Manager::normalize_phone( (string) $c['whatsapp'] );
    if ( '' !== $key ) {
        $customer_by_key[ $key ] = $c;
    }
}
printf(
    "Loaded %d customer rows (%d with a normalizable whatsapp).\n\n",
    count( $customers ),
    count( $customer_by_key )
);

// ── Walk every order, resolving through the real normalizer ──
$guest_count               = 0;
$logged_in_count           = 0;
$resolved_count            = 0;
$orphan_count               = 0;
$orphan_empty_phone        = 0;
$orphan_no_match           = 0;
$resolved_repeat_customer  = 0; // free proxy — see summary note below

foreach ( $orders as $o ) {
    if ( empty( $o['customer_id'] ) ) {
        $guest_count++;
    } else {
        $logged_in_count++;
    }

    $raw_phone = (string) ( $o['customer_phone'] ?? '' );
    $key       = DD_Customer_Manager::normalize_phone( $raw_phone );

    if ( '' === $key ) {
        // NULL, empty, or too malformed for the normalizer to accept at all
        // (its own parity gate — see normalize_phone()'s doc comment).
        $orphan_count++;
        $orphan_empty_phone++;
        continue;
    }

    if ( isset( $customer_by_key[ $key ] ) ) {
        $resolved_count++;
        if ( (int) $customer_by_key[ $key ]['total_orders'] > 1 ) {
            $resolved_repeat_customer++;
        }
    } else {
        // Normalizer accepted the phone, but no customers row has this key —
        // a real orphan, not a formatting artifact.
        $orphan_count++;
        $orphan_no_match++;
    }
}

echo "-- RESULTS --\n";
printf( "Total orders:                                    %d\n", $total_orders );
printf( "  Guest orders (customer_id NULL):                %d\n", $guest_count );
printf( "  Logged-in orders (customer_id NOT NULL):        %d\n", $logged_in_count );
echo "\n";
printf( "Resolve to a real customer (normalized match):   %d\n", $resolved_count );
printf( "Orphans (no match even after normalization):     %d\n", $orphan_count );
printf( "  - NULL / empty / unparseable phone:            %d\n", $orphan_empty_phone );
printf( "  - Has a usable phone, but no matching customer: %d\n", $orphan_no_match );
echo "\n";
printf(
    "Free proxy — resolved orders whose customer has total_orders > 1: %d\n",
    $resolved_repeat_customer
);
echo "  (Rough sense of how much the old broken JOIN (c.id = o.customer_id)\n";
echo "   was undercounting 'returning customers' — NOT an exact measurement.\n";
echo "   Not something Stage 2 needs to match; just useful context.)\n\n";

// Interpretive context only — both sides of every match above already ran
// through the SAME normalize_phone() call regardless of this option's value,
// so it does not change the numbers, but it explains WHY a raw SQL string
// match (what analytics.php/class-dd-insights.php effectively rely on today,
// via the WRONG customer_id column) would diverge from what this script found.
$phone_format = get_option( 'dd_phone_format', 'bare' );
echo "dd_phone_format option (context only, doesn't affect the counts above): {$phone_format}\n";

echo "\n=== SUMMARY ===\n";
printf(
    "total=%d guest=%d logged_in=%d resolved=%d orphan=%d (empty_phone=%d no_match=%d) repeat_customer_proxy=%d\n",
    $total_orders,
    $guest_count,
    $logged_in_count,
    $resolved_count,
    $orphan_count,
    $orphan_empty_phone,
    $orphan_no_match,
    $resolved_repeat_customer
);
echo "\nDRY-RUN complete — NOTHING written, no schema touched.\n";
echo "Paste this full output back to decide orphan handling before Stage 2 is written.\n";
