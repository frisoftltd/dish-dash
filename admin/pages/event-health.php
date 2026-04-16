<?php
/**
 * File:    admin/pages/event-health.php
 * Purpose: Event Health Check — admin diagnostic tool that reports on
 *          tracking event quality for the last 24 hours.
 *          Validates event metadata against schemas in event-schemas.php
 *          and surfaces counts of failing events and common errors.
 *
 * Rendered by: DD_Admin::render_tools()
 * Access:      manage_options capability required
 *
 * Metrics shown:
 *   1. Total events in last 24h
 *   2. Events by type
 *   3. Schema version mismatches
 *   4. Events failing strict validation (sample of up to 500)
 *   5. Most common validation errors (top 5)
 *
 * Last modified: v3.1.16
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'dish-dash' ) );
}

$results = null;

if ( isset( $_POST['dd_run_health_check'] ) ) {
    check_admin_referer( 'dd_event_health_check' );
    $results = DD_Tracking_Module::health_check();
}
?>
<div class="dd-admin-wrap">

    <div class="dd-admin-header">
        <div class="dd-admin-header__logo">
            <div class="dd-logo-icon">🔧</div>
            <div>
                <h1><?php esc_html_e( 'Dish Dash Tools', 'dish-dash' ); ?></h1>
                <span class="dd-version">v<?php echo esc_html( DD_VERSION ); ?></span>
            </div>
        </div>
    </div>

    <div class="dd-settings-card">

        <h2>🩺 <?php esc_html_e( 'Event Health Check', 'dish-dash' ); ?></h2>

        <p style="color:#666;margin-bottom:20px;">
            <?php esc_html_e( 'Validates tracking event metadata against the schemas defined in', 'dish-dash' ); ?>
            <code>modules/tracking/event-schemas.php</code>.
            <?php esc_html_e( 'Covers the last 24 hours. Validation sample is capped at 500 events.', 'dish-dash' ); ?>
        </p>

        <p style="color:#888;font-size:12px;margin-bottom:20px;">
            <?php
            $mode = defined( 'DD_EVENT_VALIDATION_MODE' ) ? DD_EVENT_VALIDATION_MODE : 'warn';
            printf(
                /* translators: %s: validation mode */
                esc_html__( 'Current validation mode: %s', 'dish-dash' ),
                '<strong>' . esc_html( $mode ) . '</strong>'
            );
            ?>
        </p>

        <form method="post" style="margin-bottom:24px;">
            <?php wp_nonce_field( 'dd_event_health_check' ); ?>
            <input type="hidden" name="dd_run_health_check" value="1">
            <button type="submit" class="button button-primary">
                <?php esc_html_e( 'Run Health Check', 'dish-dash' ); ?>
            </button>
        </form>

        <?php if ( $results !== null ) : ?>

            <hr style="border:none;border-top:1px solid #f0f0f0;margin:0 0 20px;">

            <h3 style="font-size:13px;font-weight:700;color:#1a1a1a;margin:0 0 12px;">
                <?php esc_html_e( 'Summary — Last 24 Hours', 'dish-dash' ); ?>
            </h3>

            <table class="widefat striped" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Metric', 'dish-dash' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'dish-dash' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Total events', 'dish-dash' ); ?></td>
                        <td><strong><?php echo esc_html( number_format( $results['total'] ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Schema version mismatches', 'dish-dash' ); ?></td>
                        <td>
                            <?php if ( $results['schema_mismatches'] > 0 ) : ?>
                                <span style="color:#c0392b;font-weight:700;">
                                    <?php echo esc_html( number_format( $results['schema_mismatches'] ) ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#27ae60;">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <?php
                            printf(
                                /* translators: %s: sample size */
                                esc_html__( 'Events failing strict validation (sample of %s)', 'dish-dash' ),
                                '<strong>' . esc_html( number_format( $results['sample_size'] ) ) . '</strong>'
                            );
                            ?>
                        </td>
                        <td>
                            <?php if ( $results['validation_failures'] > 0 ) : ?>
                                <span style="color:#e67e22;font-weight:700;">
                                    <?php echo esc_html( number_format( $results['validation_failures'] ) ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#27ae60;">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h3 style="font-size:13px;font-weight:700;color:#1a1a1a;margin:0 0 12px;">
                <?php esc_html_e( 'Events by Type', 'dish-dash' ); ?>
            </h3>

            <?php if ( ! empty( $results['by_type'] ) ) : ?>
                <table class="widefat striped" style="margin-bottom:24px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Event Type', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'dish-dash' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results['by_type'] as $type => $count ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $type ); ?></code></td>
                                <td><?php echo esc_html( number_format( $count ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color:#999;font-style:italic;margin-bottom:24px;">
                    <?php esc_html_e( 'No events recorded in the last 24 hours.', 'dish-dash' ); ?>
                </p>
            <?php endif; ?>

            <?php if ( ! empty( $results['top_errors'] ) ) : ?>
                <h3 style="font-size:13px;font-weight:700;color:#1a1a1a;margin:0 0 12px;">
                    <?php esc_html_e( 'Most Common Validation Errors (top 5)', 'dish-dash' ); ?>
                </h3>
                <table class="widefat striped" style="margin-bottom:24px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Error', 'dish-dash' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'dish-dash' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results['top_errors'] as $error => $count ) : ?>
                            <tr>
                                <td><?php echo esc_html( $error ); ?></td>
                                <td><?php echo esc_html( number_format( $count ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="font-size:11px;color:#bbb;margin:0;">
                <?php
                printf(
                    /* translators: %s: validation mode name */
                    esc_html__( 'Validation failures use strict mode rules regardless of the current DD_EVENT_VALIDATION_MODE setting (%s). Flip to \'strict\' in dish-dash.php once no legitimate events appear above.', 'dish-dash' ),
                    esc_html( $mode )
                );
                ?>
            </p>

        <?php endif; ?>

    </div>

</div>
