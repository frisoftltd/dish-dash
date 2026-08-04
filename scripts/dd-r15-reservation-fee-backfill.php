<?php
/**
 * dd-r15-reservation-fee-backfill.php — one-time backfill for reservations
 * whose platform_fee is stuck at 0 (v3.15.1).
 *
 * Root cause: v3.15.0 only snapshots platform_fee inside
 * ajax_submit_reservation() at INSERT time. Any reservation created before
 * v3.15.0 shipped predates that logic entirely — the platform_fee column
 * was added to existing rows via dbDelta() with DEFAULT 0, and dbDelta()
 * never backfills computed/business values, only schema. v3.15.1 also
 * widened recalculate_fee_for_reservation_status_change() so this can never
 * recur for NEW status/deposit_status transitions going forward — this
 * script is only for rows that are already stuck and won't self-heal
 * without a status change (a reservation sitting statically at
 * status='confirmed' or deposit_status='paid' never fires that hook again).
 *
 * OPS / SCRATCH SCRIPT. Not loaded by the plugin, not in the autoloader.
 * Ships in the release zip only so it can be run on the server. Execute via
 * WP-CLI, same convention as scripts/dd-r3-migrate.php:
 *
 *     DRY-RUN (default, writes NOTHING):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-r15-reservation-fee-backfill.php
 *
 *     COMMIT (performs writes, inside a single transaction — any error
 *     rolls the whole thing back, all-or-nothing):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-r15-reservation-fee-backfill.php commit
 *
 * Uses TODAY's dd_per_reservation_fee rate for every backfilled row — there
 * is no way to know what the rate was at the actual moment each reservation
 * was confirmed/paid (that's the whole reason the value is normally
 * snapshotted in the moment, not looked up after the fact). Same limitation
 * any backfill of a point-in-time value has.
 *
 * MANDATORY before commit: take a DB backup.
 *
 * Locked decisions:
 *   - Only touches rows currently at platform_fee = 0 that are ALREADY in a
 *     billable state (no-deposit confirmed, or deposit paid) — mirrors the
 *     exact billable test billing.php and the recalc functions use.
 *   - Skipped entirely (0 rows touched) if dd_fees_enabled is not '1' —
 *     backfilling fees while tracking is deliberately paused would
 *     contradict the pause.
 *   - Idempotent: the commit UPDATE re-checks platform_fee = 0 in its WHERE
 *     clause, so re-running this after a successful commit finds and
 *     changes nothing.
 */

global $wpdb;

if ( ! function_exists( 'get_option' ) ) {
    echo "ABORT: not running in WP context — run via `wp eval-file`.\n";
    return;
}

$tokens = isset( $args ) ? (array) $args : [];
$commit = in_array( 'commit', $tokens, true ) || in_array( '--commit', $tokens, true );
$mode   = $commit ? 'COMMIT (WRITING)' : 'DRY-RUN (no writes)';

$table = $wpdb->prefix . 'dishdash_reservations';

echo "=== Reservation platform_fee backfill — {$mode} ===\n";
echo "table: {$table}\n\n";

$fees_enabled = get_option( 'dd_fees_enabled', '1' ) === '1';
if ( ! $fees_enabled ) {
    echo "Fee tracking is currently DISABLED (dd_fees_enabled != '1') — nothing to backfill.\n";
    echo "Enable it in Settings -> Pricing & Fees first if you want this script to find rows.\n";
    return;
}

$rate = absint( get_option( 'dd_per_reservation_fee', 750 ) );
echo "Current dd_per_reservation_fee rate: RWF {$rate}\n\n";

$rows = $wpdb->get_results(
    "SELECT id, booking_ref, status, deposit_required, deposit_status, created_at
     FROM {$table}
     WHERE platform_fee = 0
       AND (
           ( deposit_required = 0 AND status = 'confirmed' )
           OR ( deposit_required = 1 AND deposit_status = 'paid' )
       )
     ORDER BY id ASC"
);

if ( ! $rows ) {
    echo "No affected rows found — nothing to backfill.\n";
    return;
}

echo "-- AFFECTED ROWS --\n";
foreach ( $rows as $r ) {
    printf(
        "  id %-5d  %-16s  status=%-14s  deposit_required=%d  deposit_status=%-10s  created_at=%s\n",
        $r->id, $r->booking_ref, $r->status, (int) $r->deposit_required, $r->deposit_status, $r->created_at
    );
}

$count = count( $rows );
$total = $count * $rate;
echo "\n({$count} rows, RWF " . number_format( $total ) . " total to be added)\n";

if ( $commit ) {
    $wpdb->query( 'START TRANSACTION' );
    try {
        $updated = 0;
        foreach ( $rows as $r ) {
            $result = $wpdb->update(
                $table,
                [ 'platform_fee' => $rate ],
                [ 'id' => $r->id, 'platform_fee' => 0 ], // re-checked here too — idempotency guard
                [ '%d' ],
                [ '%d', '%d' ]
            );
            if ( $result ) {
                $updated++;
            }
        }
        $wpdb->query( 'COMMIT' );
        echo "\nCOMMIT complete. {$updated} row(s) updated, RWF " . number_format( $updated * $rate ) . " added to platform_fee.\n";
    } catch ( \Throwable $e ) {
        $wpdb->query( 'ROLLBACK' );
        echo "\nERROR: " . $e->getMessage() . "\nROLLED BACK — no changes written.\n";
        return;
    }
} else {
    echo "\nDRY-RUN complete — NOTHING written. Review the rows above, take a backup, then re-run with `commit`.\n";
}

echo "\n=== SUMMARY ({$mode}) ===\n";
printf( "rows_found=%d | rate=%d | total_fee_delta=%d\n", $count, $rate, $total );
