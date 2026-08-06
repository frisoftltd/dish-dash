<?php
/**
 * dd-billing-backfill.php — one-time backfill for wp_dishdash_billing_ledger
 * (v3.17.0), which only started logging events going forward from its
 * go-live. Populates it with the ~2 months of real billing history that
 * predate the ledger (the Billing page went live v3.4.93, 2026-06-03).
 *
 * Scans the 3 billable triggers using the SAME criteria the live triggers
 * use — not reinvented here:
 *   - Orders:              status = 'delivered'
 *                          (mirrors DD_Orders_Module::recalculate_fee_for_status_change())
 *   - Deposit reservations: deposit_status = 'paid' — bills regardless of
 *                          CURRENT status, including a later no_show, per the
 *                          locked rule. Never checks `status`.
 *                          (mirrors DD_Reservations_Module::assign_reservation_fee_if_zero())
 *   - No-deposit reservations: deposit_required = 0 AND status = 'confirmed',
 *                          past the SAME grace period the live reconciliation
 *                          sweep uses (dd_billing_reconcile_grace_hours option,
 *                          falls back to DD_Billing_Ledger_Module's own
 *                          DEFAULT_RECONCILE_GRACE_HOURS constant — read
 *                          directly from the class, not duplicated here).
 *                          (mirrors DD_Billing_Ledger_Module::run_reconcile_sweep())
 *
 * Writes go through DD_Billing_Ledger_Module::log() — the exact same entry
 * point the live triggers call (via the dd_log_billing_event action) — not a
 * hand-rolled INSERT, so validation, INSERT IGNORE, and the amount>0 guard
 * are guaranteed identical between backfilled and go-forward rows. The only
 * difference from a live call: billable_month is passed explicitly as the
 * event's own historical month (delivered_at / deposit_paid_at / reservation
 * date), not "now" — log()'s default — so history lands in the right invoice
 * bucket instead of all piling into the month the backfill happens to run in.
 *
 * Read-only against orders/reservations — this script only ever SELECTs from
 * them. The only writes are INSERT IGNORE into the ledger itself, and the
 * UNIQUE KEY(source_type, source_id) means any row already logged by a live
 * trigger since v3.17.0 shipped is silently skipped, never duplicated.
 *
 * Test rows (is_test = 1) are NOT excluded from scanning or insertion — they
 * insert into the ledger exactly like the live triggers would (is_test is
 * snapshotted onto the row for invoice queries to filter on later, same as
 * every other billing query in this codebase; it was never meant to gate
 * whether a row exists in the table at all).
 *
 * OPS / SCRATCH SCRIPT. Not loaded by the plugin, not in the autoloader.
 * Ships in the release zip only so it can be run on the server. Execute via
 * WP-CLI, same convention as scripts/dd-r15-reservation-fee-backfill.php:
 *
 *     DRY-RUN (default, writes NOTHING):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-billing-backfill.php
 *
 *     COMMIT (writes via DD_Billing_Ledger_Module::log(), inside a single
 *     transaction — any error rolls the whole thing back, all-or-nothing):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-billing-backfill.php commit
 *
 * MANDATORY before commit: take a DB backup.
 *
 * Idempotent: safe to re-run any number of times, in dry-run or commit mode.
 * The UNIQUE KEY is the real guarantee; a re-run after a successful commit
 * finds every candidate already present and inserts nothing new.
 */

global $wpdb;

if ( ! function_exists( 'get_option' ) ) {
    echo "ABORT: not running in WP context — run via `wp eval-file`.\n";
    return;
}

if ( ! class_exists( 'DD_Billing_Ledger_Module' ) ) {
    echo "ABORT: DD_Billing_Ledger_Module not loaded — run via `wp eval-file` in full WP context (plugins must be booted).\n";
    return;
}

$tokens = isset( $args ) ? (array) $args : [];
$commit = in_array( 'commit', $tokens, true ) || in_array( '--commit', $tokens, true );
$mode   = $commit ? 'COMMIT (WRITING)' : 'DRY-RUN (no writes)';

$ot     = $wpdb->prefix . 'dishdash_orders';
$rt     = $wpdb->prefix . 'dishdash_reservations';
$ledger = $wpdb->prefix . 'dishdash_billing_ledger';

$grace_hours = absint( get_option(
    'dd_billing_reconcile_grace_hours',
    DD_Billing_Ledger_Module::DEFAULT_RECONCILE_GRACE_HOURS
) );

echo "=== Billing ledger historical backfill — {$mode} ===\n";
echo "orders table:       {$ot}\n";
echo "reservations table:  {$rt}\n";
echo "ledger table:        {$ledger}\n";
echo "no-deposit grace period: {$grace_hours}h (dd_billing_reconcile_grace_hours)\n\n";

// ── Existing ledger rows, for dry-run "already present" reporting ──────────
// (Not needed for correctness during commit — INSERT IGNORE + the UNIQUE KEY
// already guarantee no duplicates — only used here to report accurate
// dry-run numbers without writing anything.)
$existing_pairs = [];
foreach ( $wpdb->get_results( "SELECT source_type, source_id FROM {$ledger}" ) as $row ) {
    $existing_pairs[ $row->source_type . ':' . $row->source_id ] = true;
}

// ── 1. Orders — status = 'delivered' ────────────────────────────────────────
$order_rows = $wpdb->get_results(
    "SELECT id, branch_id, is_test, platform_fee,
            COALESCE(delivered_at, updated_at, created_at) AS event_date
     FROM {$ot}
     WHERE status = 'delivered' AND platform_fee > 0"
);

// ── 2. Deposit reservations — deposit_status = 'paid' (never checks status,
// so a later no_show still bills, per the locked rule) ─────────────────────
$deposit_rows = $wpdb->get_results(
    "SELECT id, branch_id, is_test, platform_fee,
            COALESCE(deposit_paid_at, created_at) AS event_date
     FROM {$rt}
     WHERE deposit_required = 1 AND deposit_status = 'paid' AND platform_fee > 0"
);

// ── 3. No-deposit reservations — confirmed, past the same grace period the
// live sweep uses ───────────────────────────────────────────────────────────
$nodeposit_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, branch_id, is_test, platform_fee, date AS event_date
     FROM {$rt}
     WHERE deposit_required = 0
       AND status = 'confirmed'
       AND platform_fee > 0
       AND TIMESTAMP(date, time) < DATE_SUB(NOW(), INTERVAL %d HOUR)",
    $grace_hours
) );

$triggers = [
    'order'                => [ 'label' => 'Orders (delivered)',              'rows' => $order_rows ],
    'reservation_deposit'  => [ 'label' => 'Reservations (deposit paid)',      'rows' => $deposit_rows ],
    'reservation_nodeposit' => [ 'label' => 'Reservations (no-deposit, confirmed, past grace)', 'rows' => $nodeposit_rows ],
];

// ── Build the candidate list ─────────────────────────────────────────────
// source_type stored in the ledger is always 'order' or 'reservation' — the
// two reservation triggers above are split for reporting clarity only, they
// both write source_type='reservation'.
$candidates  = [];
$by_month    = []; // "source_type|month|branch" => [ found, would_insert, already_present ]

foreach ( $triggers as $trigger_key => $t ) {
    $ledger_source_type = ( $trigger_key === 'order' ) ? 'order' : 'reservation';

    foreach ( $t['rows'] as $r ) {
        $month     = date( 'Y-m', strtotime( $r->event_date ) );
        $key       = $ledger_source_type . ':' . $r->id;
        $is_new    = ! isset( $existing_pairs[ $key ] );
        $bkey      = "{$ledger_source_type}|{$month}|{$r->branch_id}";

        if ( ! isset( $by_month[ $bkey ] ) ) {
            $by_month[ $bkey ] = [ 'found' => 0, 'would_insert' => 0, 'already_present' => 0, 'amount_new' => 0 ];
        }
        $by_month[ $bkey ]['found']++;
        if ( $is_new ) {
            $by_month[ $bkey ]['would_insert']++;
            $by_month[ $bkey ]['amount_new'] += (float) $r->platform_fee;
        } else {
            $by_month[ $bkey ]['already_present']++;
        }

        $candidates[] = [
            'trigger_key' => $trigger_key,
            'source_type' => $ledger_source_type,
            'source_id'   => (int) $r->id,
            'branch_id'   => (int) $r->branch_id,
            'is_test'     => (int) $r->is_test,
            'amount'      => (float) $r->platform_fee,
            'month'       => $month,
            'is_new'      => $is_new,
        ];
    }
}

// ── Report: per-trigger totals ───────────────────────────────────────────
echo "-- PER-TRIGGER TOTALS --\n";
foreach ( $triggers as $trigger_key => $t ) {
    $found   = count( $t['rows'] );
    $new     = 0;
    foreach ( $candidates as $c ) {
        if ( $c['trigger_key'] === $trigger_key && $c['is_new'] ) $new++;
    }
    printf( "  %-46s found=%-5d would_insert=%-5d already_present=%d\n",
        $t['label'], $found, $new, $found - $new );
}

// ── Report: per-month, per-branch breakdown ──────────────────────────────
echo "\n-- PER-MONTH / PER-BRANCH BREAKDOWN --\n";
ksort( $by_month );
foreach ( $by_month as $bkey => $stats ) {
    [ $source_type, $month, $branch_id ] = explode( '|', $bkey );
    printf(
        "  %-12s month=%-8s branch=%-3s found=%-4d would_insert=%-4d already_present=%-4d new_amount=RWF %s\n",
        $source_type, $month, $branch_id,
        $stats['found'], $stats['would_insert'], $stats['already_present'],
        number_format( $stats['amount_new'] )
    );
}

$total_found        = count( $candidates );
$total_new           = count( array_filter( $candidates, fn( $c ) => $c['is_new'] ) );
$total_already       = $total_found - $total_new;
$total_new_amount    = array_sum( array_map( fn( $c ) => $c['is_new'] ? $c['amount'] : 0, $candidates ) );
$total_test_new      = count( array_filter( $candidates, fn( $c ) => $c['is_new'] && $c['is_test'] ) );

echo "\n-- GRAND TOTAL --\n";
printf(
    "found=%d  would_insert=%d  already_present=%d  new_amount=RWF %s  (of which is_test=%d)\n",
    $total_found, $total_new, $total_already, number_format( $total_new_amount ), $total_test_new
);

if ( $total_found === 0 ) {
    echo "\nNo candidate rows found in any trigger — nothing to backfill.\n";
    return;
}

if ( $commit ) {
    $wpdb->query( 'START TRANSACTION' );
    try {
        $inserted = 0;
        foreach ( $candidates as $c ) {
            if ( ! $c['is_new'] ) continue; // INSERT IGNORE would no-op anyway; skip the call, cheaper.

            do_action( 'dd_log_billing_event', [
                'source_type'    => $c['source_type'],
                'source_id'      => $c['source_id'],
                'branch_id'      => $c['branch_id'],
                'is_test'        => $c['is_test'],
                'amount'         => $c['amount'],
                'billable_month' => $c['month'],
            ] );

            if ( (int) $wpdb->rows_affected > 0 ) {
                $inserted++;
            }
        }
        $wpdb->query( 'COMMIT' );
        echo "\nCOMMIT complete. {$inserted} row(s) actually inserted (of {$total_new} attempted — a difference here would mean a race with a live trigger between dry-run and commit, both landing on the same row, which is expected and harmless).\n";
    } catch ( \Throwable $e ) {
        $wpdb->query( 'ROLLBACK' );
        echo "\nERROR: " . $e->getMessage() . "\nROLLED BACK — no changes written.\n";
        return;
    }
} else {
    echo "\nDRY-RUN complete — NOTHING written. Review the numbers above, take a backup, then re-run with `commit`.\n";
}

echo "\n=== SUMMARY ({$mode}) ===\n";
printf(
    "found=%d new=%d already_present=%d new_amount=%d\n",
    $total_found, $total_new, $total_already, (int) $total_new_amount
);
