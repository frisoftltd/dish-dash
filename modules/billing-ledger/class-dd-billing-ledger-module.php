<?php
/**
 * File:    modules/billing-ledger/class-dd-billing-ledger-module.php
 * Module:  DD_Billing_Ledger_Module (extends DD_Module)
 * Purpose: Append-only, per-branch billable-event log — one row per order
 *          that reaches 'delivered' and per reservation whose deposit is
 *          confirmed 'paid', plus a scheduled reconciliation sweep for
 *          no-deposit reservations (which have no single write-site event —
 *          see investigation-billing-ledger.md §3). Feeds future per-branch,
 *          per-month invoicing. Fully decoupled from the orders/reservations
 *          modules (module-isolation rule) — they fire 'dd_log_billing_event',
 *          they never call this class directly. Pattern mirrors the existing
 *          Activity Log module's 'dd_log_activity' entry point exactly, with
 *          one deliberate difference: this table enforces
 *          UNIQUE KEY(source_type, source_id) because it's an aggregation
 *          billing will SUM, not an audit trail — see
 *          investigation-billing-ledger.md §7 for why Activity Log's
 *          insert-every-time shape was NOT copied here.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - WordPress: global $wpdb, wp_schedule_event/wp_next_scheduled
 *   - wp_dishdash_billing_ledger table (created in install.php)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates this module)
 *   - modules/orders/class-dd-orders-module.php (fires dd_log_billing_event
 *     from recalculate_fee_for_status_change())
 *   - modules/reservations/class-dd-reservations-module.php (fires
 *     dd_log_billing_event from assign_reservation_fee_if_zero())
 *
 * Hooks registered:
 *   - dd_log_billing_event        → self::log() (decoupled entry point)
 *   - dd_billing_reconcile_sweep  → self::run_reconcile_sweep() (daily cron)
 *
 * Usage from any other module (fully decoupled, mirrors dd_log_activity):
 *   do_action( 'dd_log_billing_event', [
 *       'source_type' => 'order',   // or 'reservation'
 *       'source_id'   => 123,
 *       'branch_id'   => 1,
 *       'is_test'     => 0,
 *       'amount'      => 750,
 *   ] );
 *
 * Depends on (modules): NONE — architecture rule
 *
 * Last modified: v3.17.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'DD_Billing_Ledger_Module' ) ) return;

class DD_Billing_Ledger_Module extends DD_Module {

    protected string $id = 'billing_ledger';

    /**
     * Default grace period (hours after a no-deposit reservation's date/time)
     * before an un-reversed 'confirmed' booking is treated as final and
     * honored — see investigation-billing-ledger.md §3: there is no single
     * write-site event for this, only a state that can still reverse (a
     * confirmed booking can be marked no_show at any point, with no time
     * restriction enforced elsewhere in the codebase). Overridable via the
     * dd_billing_reconcile_grace_hours option — change the option, not this
     * constant, to adjust in production; this default is only the fallback.
     */
    const DEFAULT_RECONCILE_GRACE_HOURS = 48;

    public function init(): void {
        add_action( 'dd_log_billing_event', [ __CLASS__, 'log' ], 10, 1 );

        add_action( 'dd_billing_reconcile_sweep', [ $this, 'run_reconcile_sweep' ] );
        if ( ! wp_next_scheduled( 'dd_billing_reconcile_sweep' ) ) {
            wp_schedule_event( time(), 'daily', 'dd_billing_reconcile_sweep' );
        }
    }

    // ─────────────────────────────────────────
    //  LOGGER
    // ─────────────────────────────────────────

    /**
     * Record a billing ledger entry.
     *
     * INSERT IGNORE, not $wpdb->insert() — the UNIQUE KEY(source_type,
     * source_id) is EXPECTED to reject a repeat call (a reopened-then-
     * redelivered order, a reconciliation sweep re-matching a row it already
     * logged, a racing double-trigger) and that must be a silent no-op, not
     * a surfaced DB error. Callers are not expected to check "have I already
     * logged this" themselves — the table does that.
     *
     * billable_month is stamped here (current month at log time), not
     * accepted from the caller — both trigger points can fire from a write
     * path where the source row's own timestamp isn't reliably set (e.g.
     * dashboard.php's stale-bulk-deliver path never stamps delivered_at at
     * all — see investigation-billing-ledger.md §1 Observations), so "when
     * this was actually logged" is the only value guaranteed to always exist.
     *
     * @param array $args {
     *     @type string $source_type    'order' | 'reservation'
     *     @type int    $source_id
     *     @type int    $branch_id
     *     @type int    $is_test
     *     @type float  $amount         Must be > 0 — zero/negative amounts are not logged.
     *     @type string $billable_month Optional, 'YYYY-MM'. Defaults to the current
     *                                  month (the moment-of-event month, correct for
     *                                  every live trigger). A historical backfill
     *                                  (scripts/dd-billing-backfill.php) passes the
     *                                  event's own historical month here instead, so
     *                                  old rows land in their real invoice bucket
     *                                  rather than the month the backfill happens to
     *                                  run in. Silently ignored if not 'YYYY-MM'.
     * }
     */
    public static function log( array $args ): void {
        global $wpdb;

        $source_type = sanitize_key( $args['source_type'] ?? '' );
        $source_id   = absint( $args['source_id'] ?? 0 );
        if ( ! in_array( $source_type, [ 'order', 'reservation' ], true ) || ! $source_id ) {
            return;
        }

        $amount = (float) ( $args['amount'] ?? 0 );
        if ( $amount <= 0 ) {
            return;
        }

        $billable_month = ( isset( $args['billable_month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $args['billable_month'] ) )
            ? $args['billable_month']
            : current_time( 'Y-m' );

        $table = $wpdb->prefix . 'dishdash_billing_ledger';

        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table}
                (source_type, source_id, branch_id, is_test, amount, billable_month, created_at)
             VALUES (%s, %d, %d, %d, %f, %s, %s)",
            $source_type,
            $source_id,
            absint( $args['branch_id'] ?? 1 ),
            empty( $args['is_test'] ) ? 0 : 1,
            $amount,
            $billable_month,
            current_time( 'mysql' )
        ) );
    }

    // ─────────────────────────────────────────
    //  NO-DEPOSIT RESERVATION RECONCILIATION SWEEP
    // ─────────────────────────────────────────

    /**
     * Daily cron callback. Finds no-deposit reservations that are still
     * 'confirmed' (never reversed to no_show/cancelled), whose date/time
     * passed the grace period, that haven't already been logged, and logs
     * them. Mirrors run_autocancel()'s pattern in
     * class-dd-reservations-module.php (same "read-only guard clause, then
     * write" shape) — but that job is a single wp_schedule_single_event
     * fired once per booking; this one is a genuine recurring
     * wp_schedule_event, since it has to re-check every not-yet-final
     * no-deposit booking on an ongoing basis, not just one booking once.
     */
    public function run_reconcile_sweep(): void {
        global $wpdb;

        $rt     = $wpdb->prefix . 'dishdash_reservations';
        $ledger = $wpdb->prefix . 'dishdash_billing_ledger';

        $grace_hours = absint( get_option(
            'dd_billing_reconcile_grace_hours',
            self::DEFAULT_RECONCILE_GRACE_HOURS
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.branch_id, r.is_test, r.platform_fee
             FROM {$rt} r
             WHERE r.deposit_required = 0
               AND r.status = 'confirmed'
               AND r.platform_fee > 0
               AND TIMESTAMP(r.date, r.time) < DATE_SUB(NOW(), INTERVAL %d HOUR)
               AND NOT EXISTS (
                   SELECT 1 FROM {$ledger} l
                   WHERE l.source_type = 'reservation' AND l.source_id = r.id
               )",
            $grace_hours
        ) );

        foreach ( $rows as $row ) {
            self::log( [
                'source_type' => 'reservation',
                'source_id'   => (int) $row->id,
                'branch_id'   => (int) $row->branch_id,
                'is_test'     => (int) $row->is_test,
                'amount'      => (float) $row->platform_fee,
            ] );
        }
    }
}
