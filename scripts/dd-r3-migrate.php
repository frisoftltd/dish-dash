<?php
/**
 * dd-r3-migrate.php — R3 phone backfill + dedupe migration (v3.10.40).
 *
 * OPS / SCRATCH SCRIPT. Not loaded by the plugin, not in the autoloader. It ships in
 * the release zip only so it can be run on the server. Execute via WP-CLI:
 *
 *     DRY-RUN (default, writes NOTHING):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-r3-migrate.php
 *
 *     COMMIT (performs writes, flips dd_phone_format to 'e164' as the LAST step):
 *       wp eval-file wp-content/plugins/dish-dash/scripts/dd-r3-migrate.php commit
 *
 * MANDATORY before commit: take a DB backup (see report.md / runbook). The commit runs
 * inside a single transaction — any error rolls the whole thing back (all-or-nothing).
 *
 * Locked decisions:
 *   - Survivor of a dupe cluster: the row with a linked user_id (oldest, if several),
 *     else the oldest row (min first_order_at, tie-break min id).
 *   - Junk / unparseable phones: left untouched, flagged in the report. Never nulled.
 *   - A cluster with 2+ DISTINCT user_ids is a CONFLICT: skipped (not merged), flagged
 *     for manual resolution — we never delete a row that owns a different account link.
 */

global $wpdb;

if ( ! class_exists( 'DD_Customer_Manager' ) ) {
    echo "ABORT: DD_Customer_Manager not loaded — run via `wp eval-file` in WP context.\n";
    return;
}

// ── Mode ── (WP-CLI eval-file exposes extra tokens in $args; accept `commit` or `--commit`).
$tokens = isset( $args ) ? (array) $args : [];
$commit = in_array( 'commit', $tokens, true ) || in_array( '--commit', $tokens, true );
$mode   = $commit ? 'COMMIT (WRITING)' : 'DRY-RUN (no writes)';

// ── Force normalize_phone() into E.164 mode for THIS run only, without touching the
// stored option (which flips last, in commit mode). Both hooks so it works whether or
// not the option row exists yet. This reuses the shipped normalizer so the migration's
// keys are exactly what live code will produce post-flip.
$force_e164 = static function () { return 'e164'; };
add_filter( 'option_dd_phone_format', $force_e164 );
add_filter( 'default_option_dd_phone_format', $force_e164 );

$C = $wpdb->prefix . 'dishdash_customers';
$O = $wpdb->prefix . 'dishdash_orders';
$R = $wpdb->prefix . 'dishdash_reservations';
$T = $wpdb->prefix . 'dishdash_birthday_tokens';

// Oldest-first comparator: min first_order_at, tie-break min id.
$cmp_age = static function ( $a, $b ) {
    $fa = $a->first_order_at ?: '9999-12-31 23:59:59';
    $fb = $b->first_order_at ?: '9999-12-31 23:59:59';
    if ( $fa !== $fb ) return strcmp( $fa, $fb );
    return ( (int) $a->id ) <=> ( (int) $b->id );
};

echo "=== R3 MIGRATION — {$mode} ===\n";
echo "customers table: {$C}\n";

// ── Load + bucket all customers by their E.164 key ──
$rows     = $wpdb->get_results( "SELECT * FROM {$C} ORDER BY id ASC" );
$clusters = [];   // e164_key => [ rows ]
$junk     = [];   // rows whose value does not normalize
foreach ( (array) $rows as $r ) {
    $key = DD_Customer_Manager::normalize_phone( (string) $r->whatsapp );
    if ( '' === $key ) { $junk[] = $r; continue; }
    $clusters[ $key ][] = $r;
}

// ── Normalization plan (stored value != its E.164 key) ──
echo "\n-- NORMALIZATION PLAN --\n";
$norm_count = 0;
foreach ( $clusters as $key => $members ) {
    foreach ( $members as $m ) {
        if ( (string) $m->whatsapp !== $key ) {
            printf( "  id %-5d  '%s'  ->  '%s'\n", $m->id, $m->whatsapp, $key );
            $norm_count++;
        }
    }
}
echo "  ({$norm_count} rows to normalize)\n";

// ── Begin transaction (commit mode) — all-or-nothing ──
if ( $commit ) {
    $wpdb->query( 'START TRANSACTION' );
}

try {
    // ── Dedupe plan / execution ──
    echo "\n-- DEDUPE PLAN --\n";
    $merged_clusters = 0;
    $skipped_conflict = 0;
    $repoint_total    = 0;

    foreach ( $clusters as $key => $members ) {
        if ( count( $members ) < 2 ) {
            continue; // singletons handled in the normalization pass below
        }

        // Conflict guard: 2+ distinct non-empty user_ids → do not merge, flag.
        $uids = array_values( array_unique( array_filter( wp_list_pluck( $members, 'user_id' ) ) ) );
        if ( count( $uids ) >= 2 ) {
            printf( "  cluster '%s': ids[%s] — CONFLICT (user_ids %s) — SKIPPED, manual review\n",
                $key, implode( ',', wp_list_pluck( $members, 'id' ) ), implode( ',', $uids ) );
            $skipped_conflict++;
            continue;
        }

        // Survivor: linked user_id (oldest of them) else oldest overall.
        $linked = array_values( array_filter( $members, static function ( $m ) { return ! empty( $m->user_id ); } ) );
        $pool   = ! empty( $linked ) ? $linked : $members;
        usort( $pool, $cmp_age );
        $survivor = $pool[0];
        $rule     = ! empty( $linked ) ? 'linked user_id' : 'oldest';

        $deleted = array_values( array_filter( $members, static function ( $m ) use ( $survivor ) {
            return (int) $m->id !== (int) $survivor->id;
        } ) );

        // Merged stats.
        $sum_orders = 0; $sum_spent = 0.0;
        $first = $survivor->first_order_at; $last = $survivor->last_order_at;
        foreach ( $members as $m ) {
            $sum_orders += (int) $m->total_orders;
            $sum_spent  += (float) $m->total_spent;
            if ( $m->first_order_at && ( ! $first || $m->first_order_at < $first ) ) $first = $m->first_order_at;
            if ( $m->last_order_at  && ( ! $last  || $m->last_order_at  > $last  ) ) $last  = $m->last_order_at;
        }

        printf( "  cluster '%s': ids[%s]  SURVIVOR=%d (%s)  DELETE=[%s]  orders=%d spent=%.2f\n",
            $key,
            implode( ',', wp_list_pluck( $members, 'id' ) ),
            $survivor->id, $rule,
            implode( ',', wp_list_pluck( $deleted, 'id' ) ),
            $sum_orders, $sum_spent
        );

        // Child re-point (count for report; perform in commit).
        foreach ( $deleted as $d ) {
            $t  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$T} WHERE customer_id = %d", $d->id ) );
            $rv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$R} WHERE customer_id = %d", $d->id ) );
            $od = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$O} WHERE customer_id = %d", $d->id ) );
            printf( "     del %d -> survivor %d : tokens=%d reservations=%d orders=%d\n", $d->id, $survivor->id, $t, $rv, $od );
            $repoint_total += $t + $rv + $od;

            if ( $commit ) {
                // 1) Re-point children FIRST (before deleting the row).
                $wpdb->update( $T, [ 'customer_id' => $survivor->id ], [ 'customer_id' => $d->id ], [ '%d' ], [ '%d' ] );
                $wpdb->update( $R, [ 'customer_id' => $survivor->id ], [ 'customer_id' => $d->id ], [ '%d' ], [ '%d' ] );
                $wpdb->update( $O, [ 'customer_id' => $survivor->id ], [ 'customer_id' => $d->id ], [ '%d' ], [ '%d' ] );
            }
        }

        if ( $commit ) {
            // 2) DELETE non-survivors BEFORE touching survivor.whatsapp (UNIQUE KEY).
            foreach ( $deleted as $d ) {
                $wpdb->delete( $C, [ 'id' => $d->id ], [ '%d' ] );
            }
            // 3) Merge stats + normalize survivor whatsapp (now the only row for this key).
            $wpdb->update(
                $C,
                [
                    'total_orders'   => $sum_orders,
                    'total_spent'    => $sum_spent,
                    'first_order_at' => $first,
                    'last_order_at'  => $last,
                    'whatsapp'       => $key,
                ],
                [ 'id' => $survivor->id ],
                [ '%d', '%f', '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }
        $merged_clusters++;
    }
    echo "  ({$merged_clusters} clusters merged, {$skipped_conflict} conflict-skipped, {$repoint_total} children re-pointed)\n";

    // ── Normalize singleton (non-dupe) rows whose stored value != E.164 key ──
    if ( $commit ) {
        foreach ( $clusters as $key => $members ) {
            if ( count( $members ) === 1 ) {
                $m = $members[0];
                if ( (string) $m->whatsapp !== $key ) {
                    $wpdb->update( $C, [ 'whatsapp' => $key ], [ 'id' => $m->id ], [ '%s' ], [ '%d' ] );
                }
            }
        }
    }

    // ── Junk (left untouched) ──
    echo "\n-- JUNK / UNNORMALIZABLE (left untouched, review manually) --\n";
    foreach ( $junk as $j ) {
        printf( "  id %-5d  '%s'\n", $j->id, $j->whatsapp );
    }
    echo "  (" . count( $junk ) . " junk rows flagged)\n";

    // ── FINAL STEP (commit only): flip the flag — activates the format atomically ──
    if ( $commit ) {
        update_option( 'dd_phone_format', 'e164' );
        echo "\n>>> dd_phone_format = 'e164' — E.164 format flip is now ACTIVE.\n";
        $wpdb->query( 'COMMIT' );
    }
} catch ( \Throwable $e ) {
    if ( $commit ) {
        $wpdb->query( 'ROLLBACK' );
    }
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo $commit ? "ROLLED BACK — no changes written.\n" : "(dry-run) — no changes anyway.\n";
    return;
}

// ── Summary ──
echo "\n=== SUMMARY ({$mode}) ===\n";
printf( "normalized=%d | clusters_merged=%d | conflicts_skipped=%d | children_repointed=%d | junk_flagged=%d\n",
    $norm_count, $merged_clusters, $skipped_conflict, $repoint_total, count( $junk ) );
echo $commit
    ? "COMMIT complete. Verify with the B1/B3 queries; re-run this with `commit` to confirm 0 changes (idempotency).\n"
    : "DRY-RUN complete — NOTHING written. Review the above, take a backup, then re-run with `commit`.\n";
