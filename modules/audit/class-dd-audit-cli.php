<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'WP_CLI' ) ) return;

/**
 * WP-CLI commands for Dish Dash audit and regression testing.
 *
 * Usage:
 *   wp dishdash audit regression --path=/home/imitjsiy/dishdash.khanakhazana.rw --allow-root
 *   wp dishdash audit pillar2 --path=/home/imitjsiy/dishdash.khanakhazana.rw --allow-root
 *   wp dishdash audit all --path=/home/imitjsiy/dishdash.khanakhazana.rw --allow-root
 */
class DD_Audit_CLI {

    /**
     * Run all pillar scans + regression tests.
     *
     * ## EXAMPLES
     *   wp dishdash audit all
     *
     * @subcommand all
     */
    public function all( array $args, array $assoc_args ): void {
        WP_CLI::log( '=== Dish Dash Full Audit ===' );
        $this->run_pillars();
        $this->run_regression_tests();
    }

    /**
     * Run regression HTTP tests only.
     *
     * @subcommand regression
     */
    public function regression( array $args, array $assoc_args ): void {
        WP_CLI::log( '=== Dish Dash Regression Tests ===' );
        $this->run_regression_tests();
    }

    /**
     * Run P2 architecture checks only.
     *
     * @subcommand pillar2
     */
    public function pillar2( array $args, array $assoc_args ): void {
        WP_CLI::log( '=== P2: Architecture ===' );
        require_once DD_PLUGIN_DIR . 'modules/audit/class-dd-audit-runner.php';
        $runner = new DD_Audit_Runner();
        $result = $runner->run_all()['pillars']['p2'];
        $this->print_pillar( $result );
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function run_pillars(): void {
        require_once DD_PLUGIN_DIR . 'modules/audit/class-dd-audit-runner.php';
        $runner  = new DD_Audit_Runner();
        $results = $runner->run_all();

        foreach ( $results['pillars'] as $pillar ) {
            $this->print_pillar( $pillar );
        }
    }

    private function run_regression_tests(): void {
        WP_CLI::log( '' );
        WP_CLI::log( 'Running AJAX regression tests...' );

        $ajax_url = admin_url( 'admin-ajax.php' );

        $tests = [
            'dd_cart_get'             => [],
            'dd_poll_notifications'   => [],
            'dd_momo_check_status'    => [ 'order_id' => 0 ],
            'dd_pesapal_check_status' => [ 'order_id' => 0 ],
        ];

        foreach ( $tests as $action => $extra_data ) {
            $nonce    = wp_create_nonce( $action );
            $body     = array_merge( [ 'action' => $action, 'nonce' => $nonce ], $extra_data );
            $response = wp_remote_post( $ajax_url, [
                'body'    => $body,
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                WP_CLI::warning( "❌ {$action}: " . $response->get_error_message() );
                continue;
            }

            $code     = wp_remote_retrieve_response_code( $response );
            $body_raw = wp_remote_retrieve_body( $response );
            $json     = json_decode( $body_raw, true );

            if ( $code === 200 && $json !== null ) {
                WP_CLI::success( "✅ {$action}: HTTP {$code} — " . ( $json['success'] ? 'success' : 'handled (no data)' ) );
            } else {
                WP_CLI::warning( "❌ {$action}: HTTP {$code} — " . substr( $body_raw, 0, 80 ) );
            }
        }

        // Version consistency check
        $plugin_file    = DD_PLUGIN_DIR . 'dish-dash.php';
        $plugin_content = file_get_contents( $plugin_file );
        $const_v        = '';
        $header_v       = '';
        if ( preg_match( "/define\s*\(\s*'DD_VERSION'\s*,\s*'([^']+)'/", $plugin_content, $m ) ) $const_v  = $m[1];
        if ( preg_match( "/\*\s+Version:\s+([^\n]+)/", $plugin_content, $m ) ) $header_v = trim( $m[1] );

        if ( $const_v && $const_v === $header_v ) {
            WP_CLI::success( "✅ DD_VERSION consistency: {$const_v}" );
        } else {
            WP_CLI::warning( "❌ DD_VERSION mismatch: header={$header_v} constant={$const_v}" );
        }

        // .min file check on disk
        $min_count = count( glob( DD_PLUGIN_DIR . 'assets/js/*.min.js' ) ?: [] )
                   + count( glob( DD_PLUGIN_DIR . 'assets/css/*.min.css' ) ?: [] );
        if ( $min_count === 0 ) {
            WP_CLI::success( '✅ No .min files on disk' );
        } else {
            WP_CLI::warning( "❌ {$min_count} .min file(s) found on disk — delete them" );
        }
    }

    private function print_pillar( array $pillar ): void {
        $icon = match( $pillar['status'] ) {
            'green'  => '🟢',
            'yellow' => '🟡',
            'orange' => '🟠',
            'red'    => '🔴',
            default  => '⚪',
        };
        WP_CLI::log( '' );
        WP_CLI::log( "{$icon} {$pillar['id']}: {$pillar['name']} — {$pillar['score']}% ({$pillar['passed']}/{$pillar['total']})" );
        foreach ( $pillar['checks'] as $check ) {
            $mark = $check['pass'] ? '  ✅' : '  ❌';
            WP_CLI::log( "{$mark} {$check['label']}" );
            if ( ! $check['pass'] && ! empty( $check['detail'] ) ) {
                WP_CLI::log( "      ↳ {$check['detail']}" );
            }
        }
    }
}
