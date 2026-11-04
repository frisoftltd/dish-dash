<?php
/**
 * Standalone verification harness for v3.18.62 (self-healing PesaPal
 * reconciliation). Run with plain `php`, no WordPress/MySQL required:
 *
 *   php tests/manual/test-v3.18.62-pesapal-self-healing.php
 *
 * This is NOT a WordPress integration test — there is no WP/MySQL instance
 * in the authoring environment (same constraint as every release note in
 * RELEASE.md). What this script actually does, honestly:
 *
 *  PART A — Real execution of the shipped DD_Notifications email-reliability
 *           code (send_mail_reliably / process_email_retry_queue) against
 *           hand-written stubs for the handful of WordPress functions it
 *           calls (get_option/update_option/delete_option/add_action/
 *           remove_action/wp_mail/current_time/error_log). This is the
 *           actual production file, actually executed.
 *
 *  PART B — Pure-PHP mirrors of the two pieces of SQL this release depends
 *           on most (the reconcile sweep's tiered recheck-eligibility
 *           window, and promote_pesapal_order()'s WHERE-clause semantics).
 *           These are re-implementations, not the live SQL — the string
 *           literals are cross-checked against the real file by this script
 *           itself (see assert_source_contains calls) so a mirror can't
 *           silently drift from what actually shipped, but running MySQL's
 *           own query planner against them is NOT what this tests.
 *
 *  PART C — explicitly NOT covered here (see the final printed summary):
 *           the real reconcile-sweep SQL against a live DB, the admin
 *           in_admin_header notice rendering, and the daily watchdog email
 *           cadence. These were verified by reading the code, not by
 *           running it — same as every prior release in this repo.
 */

$failures = 0;
$passed   = 0;

function check( string $label, bool $ok ): void {
    global $failures, $passed;
    if ( $ok ) {
        $passed++;
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n";
    }
}

function assert_source_contains( string $file, string $needle, string $label ): void {
    $src = file_get_contents( $file );
    check( "source check: {$label}", $src !== false && str_contains( $src, $needle ) );
}

$root = dirname( __DIR__, 2 );

echo "=== PART A — DD_Notifications email reliability (real code, stubbed WP) ===\n";

// ── Minimal WP stubs ────────────────────────────────────────────────────
$GLOBALS['__options']        = [];
$GLOBALS['__wp_mail_fail']   = false;
$GLOBALS['__wp_mail_calls']  = [];
$GLOBALS['__mail_failed_cb'] = [];

function get_option( $key, $default = false ) {
    return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = true ) {
    $GLOBALS['__options'][ $key ] = $value;
    return true;
}
function delete_option( $key ) {
    unset( $GLOBALS['__options'][ $key ] );
    return true;
}
function add_action( $hook, $cb ) {
    $GLOBALS['__mail_failed_cb'][ $hook ][] = $cb;
}
function remove_action( $hook, $cb ) {
    foreach ( $GLOBALS['__mail_failed_cb'][ $hook ] ?? [] as $i => $existing ) {
        if ( $existing === $cb ) {
            unset( $GLOBALS['__mail_failed_cb'][ $hook ][ $i ] );
        }
    }
}
function current_time( $type ) {
    return gmdate( 'Y-m-d H:i:s' );
}
function esc_html( $s ) { return $s; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }

class FakeWpError {
    public function __construct( private string $message ) {}
    public function get_error_message(): string { return $this->message; }
}

function wp_mail( $to, $subject, $body, $headers = '' ) {
    $GLOBALS['__wp_mail_calls'][] = [ 'to' => $to, 'subject' => $subject ];
    if ( $GLOBALS['__wp_mail_fail'] ) {
        foreach ( $GLOBALS['__mail_failed_cb']['wp_mail_failed'] ?? [] as $cb ) {
            $cb( new FakeWpError( 'Fake SMTP failure' ) );
        }
        return false;
    }
    return true;
}

define( 'ABSPATH', $root . '/' );
require_once $root . '/modules/orders/class-dd-notifications.php';

// Scenario (e): SMTP failure → queued → retried successfully.
$GLOBALS['__wp_mail_fail'] = true;
$sent = DD_Notifications::send_mail_reliably( 'admin@example.test', 'Test 1', 'body', [], [ 'kind' => 'test' ] );
check( 'scenario (e): first send reports failure', $sent === false );

$queue = get_option( 'dd_email_retry_queue', [] );
check( 'scenario (e): failed send is queued exactly once', count( $queue ) === 1 && $queue[0]['attempts'] === 0 );

// Force the queued retry to be "due" (simulate the backoff window elapsing)
// and let the next retry succeed.
$queue[0]['next_retry_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
update_option( 'dd_email_retry_queue', $queue, false );
$GLOBALS['__wp_mail_fail'] = false;
DD_Notifications::process_email_retry_queue();

check( 'scenario (e): queue empties once the retry succeeds', get_option( 'dd_email_retry_queue', [] ) === [] );
check( 'scenario (e): no broken-delivery flag after a successful retry', get_option( 'dd_email_delivery_broken', '' ) === '' );

// Exhaustion: force every attempt (original + 5 retries) to fail, confirm
// the 5th retry marks delivery broken and the queue is finally emptied
// (stops retrying forever).
delete_option( 'dd_email_retry_queue' );
delete_option( 'dd_email_delivery_broken' );
$GLOBALS['__wp_mail_fail'] = true;
DD_Notifications::send_mail_reliably( 'admin@example.test', 'Test 2', 'body', [], [] );

for ( $i = 0; $i < 5; $i++ ) {
    $queue = get_option( 'dd_email_retry_queue', [] );
    if ( ! $queue ) break;
    $queue[0]['next_retry_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
    update_option( 'dd_email_retry_queue', $queue, false );
    DD_Notifications::process_email_retry_queue();
}

check( 'scenario (e) exhaustion: queue is empty after 5 failed retries (stops retrying)', get_option( 'dd_email_retry_queue', [] ) === [] );
check( 'scenario (e) exhaustion: broken-delivery flag is set with the real error', get_option( 'dd_email_delivery_broken', '' ) === 'Fake SMTP failure' );

echo "\n=== PART B — Boundary-logic mirrors (pure PHP, cross-checked against source) ===\n";

$orders_module_file = $root . '/modules/orders/class-dd-orders-module.php';

// B1 — reconcile sweep tiered eligibility window. Mirrors the SQL in
// run_pesapal_reconcile_sweep(); cross-checked against the literal interval
// values actually shipped.
assert_source_contains( $orders_module_file, 'INTERVAL 72 HOUR', 'sweep uses a 72h outer window' );
assert_source_contains( $orders_module_file, 'INTERVAL 1 HOUR', 'sweep has a <1h always-due tier' );
assert_source_contains( $orders_module_file, 'INTERVAL 24 HOUR', 'sweep has a 24h tier boundary' );
assert_source_contains( $orders_module_file, 'INTERVAL 30 MINUTE', 'sweep re-checks the 1-24h tier every 30 min' );
assert_source_contains( $orders_module_file, 'INTERVAL 3 HOUR', 'sweep re-checks the 24-72h tier every 3h' );

function is_due( int $age_seconds, ?int $last_reconciled_seconds_ago ): bool {
    $HOUR = 3600;
    if ( $age_seconds > 72 * $HOUR ) {
        return false; // outside the outer window entirely
    }
    if ( $age_seconds < 1 * $HOUR ) {
        return true; // tier 1 — every run
    }
    if ( $age_seconds < 24 * $HOUR ) {
        return $last_reconciled_seconds_ago === null || $last_reconciled_seconds_ago >= 30 * 60;
    }
    // tier 3 — 24h to 72h (72h cutoff already enforced above)
    return $last_reconciled_seconds_ago === null || $last_reconciled_seconds_ago >= 3 * $HOUR;
}

check( 'B1: 30s-old order is always due', is_due( 30, 5 ) === true );
check( 'B1: 2h-old order checked 10 min ago is NOT due (30 min gate)', is_due( 2 * 3600, 10 * 60 ) === false );
check( 'B1: 2h-old order checked 31 min ago IS due (30 min gate)', is_due( 2 * 3600, 31 * 60 ) === true );
check( 'B1: 30h-old order checked 2h ago is NOT due (3h gate)', is_due( 30 * 3600, 2 * 3600 ) === false );
check( 'B1: 30h-old order checked 3h5min ago IS due (3h gate)', is_due( 30 * 3600, 3 * 3600 + 300 ) === true );
check( 'B1: 80h-old order is never due (outside 72h window)', is_due( 80 * 3600, null ) === false );
check( 'B1: never-reconciled order within window is always due', is_due( 50 * 3600, null ) === true );

// B2 — promote_pesapal_order() / promote_cancelled_but_paid_order() WHERE
// semantics, mirrored as a pure decision table. Cross-checked against the
// literal WHERE fragments actually shipped.
assert_source_contains( $orders_module_file, "payment_status IN ( 'unpaid', 'failed' )", "promote WHERE accepts unpaid/failed only" );
assert_source_contains( $orders_module_file, "AND status = 'cancelled'", 'cancelled-but-paid path requires status=cancelled' );
assert_source_contains( $orders_module_file, "'cancelled' === ( \$order->status ?? null )", 'promote routes cancelled orders to the special path' );

function simulate_promote( string $payment_status, string $order_status ): array {
    $promotable = in_array( $payment_status, [ 'unpaid', 'failed' ], true );
    if ( ! $promotable ) {
        return [ 'promoted' => false, 'path' => 'none' ];
    }
    if ( 'cancelled' === $order_status ) {
        return [ 'promoted' => true, 'path' => 'cancelled_alert' ];
    }
    return [ 'promoted' => true, 'path' => 'normal' ];
}

check( 'B2 scenario (a): failed + not cancelled → normal promote', simulate_promote( 'failed', 'pending_payment' ) === [ 'promoted' => true, 'path' => 'normal' ] );
check( 'B2 scenario (b): unpaid + not cancelled → normal promote (sweep catches a missed IPN the same way)', simulate_promote( 'unpaid', 'pending_payment' ) === [ 'promoted' => true, 'path' => 'normal' ] );
check( 'B2 scenario (c): failed + cancelled → cancelled-alert path, order stays cancelled', simulate_promote( 'failed', 'cancelled' ) === [ 'promoted' => true, 'path' => 'cancelled_alert' ] );
check( 'B2 scenario (d): reversed is never promotable regardless of order status', simulate_promote( 'reversed', 'pending_payment' )['promoted'] === false );
check( 'B2 scenario (d): reversed + cancelled is still never promotable', simulate_promote( 'reversed', 'cancelled' )['promoted'] === false );
check( 'B2 scenario (g) race idempotency: already-paid is never re-promotable', simulate_promote( 'paid', 'pending_payment' )['promoted'] === false );

echo "\n=== Summary ===\n";
echo "Passed: {$passed}, Failed: {$failures}\n";
echo <<<TXT

NOT exercised by this script (traced by reading the shipped code instead,
same convention as every prior release note in RELEASE.md — no WordPress/
MySQL/SMTP instance exists in this authoring environment):
  - scenario (b) "IPN missed, sweep promotes it" against a REAL MySQL
    instance running the actual sweep SQL (only the PHP-mirrored boundary
    logic above was executed, not the live query).
  - scenario (f) "cron stopped → notice appears" — DD_Admin::render_health_notices()
    reading dd_pesapal_last_sweep_at and rendering on in_admin_header requires
    a real wp-admin page load.
  - the daily watchdog email throttle and the PesaPal check_auth() sandbox/
    live detection against a real PesaPal sandbox account.

TXT;

exit( $failures > 0 ? 1 : 0 );
