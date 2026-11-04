<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'WP_CLI' ) ) return;

/**
 * WP-CLI command for recovering historical PesaPal orders stuck 'failed'
 * that later completed at PesaPal (v3.18.61 — see DD-00262 and
 * DD_Orders_Module::promote_pesapal_order()/recheck_failed_pesapal_orders()).
 *
 * The recurring reconcile sweep only looks back 48h; this is a one-off pass
 * over everything still marked 'failed' regardless of age.
 *
 * Usage:
 *   wp dishdash pesapal recheck-failed --path=... --allow-root
 *   wp dishdash pesapal recheck-failed --dry-run --path=... --allow-root
 */
class DD_PesaPal_CLI {

    /**
     * Re-check every failed PesaPal order against PesaPal's own API and
     * promote any that are now COMPLETED.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List matches without promoting them.
     *
     * ## EXAMPLES
     *   wp dishdash pesapal recheck-failed --dry-run
     *   wp dishdash pesapal recheck-failed
     *
     * @subcommand recheck-failed
     */
    public function recheck_failed( array $args, array $assoc_args ): void {
        $dry_run = isset( $assoc_args['dry-run'] );

        require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-orders-module.php';
        $orders_module = new DD_Orders_Module();
        $results       = $orders_module->recheck_failed_pesapal_orders( $dry_run );

        if ( ! $results ) {
            WP_CLI::success( 'No failed PesaPal orders found.' );
            return;
        }

        $completed = 0;

        foreach ( $results as $r ) {
            $line = "Order #{$r['order_number']} (id={$r['order_id']}) tracking={$r['tracking_id']} pesapal_status={$r['pesapal_status']}";

            if ( 'COMPLETED' !== $r['pesapal_status'] ) {
                WP_CLI::log( $line );
                continue;
            }

            $completed++;

            if ( $dry_run ) {
                WP_CLI::success( $line . ' — would promote' );
            } elseif ( $r['promoted'] ) {
                WP_CLI::success( $line . ' — PROMOTED' );
            } else {
                WP_CLI::warning( $line . ' — promote skipped (' . $r['skip_reason'] . ')' );
            }
        }

        WP_CLI::log( '' );
        WP_CLI::log( sprintf(
            'Checked %d failed order(s), %d now COMPLETED at PesaPal.',
            count( $results ),
            $completed
        ) );
    }
}
