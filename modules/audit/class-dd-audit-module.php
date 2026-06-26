<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Audit_Module extends DD_Module {

    protected string $id = 'audit';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Register AJAX handler
        DD_Ajax::register( 'dd_run_audit', [ $this, 'ajax_run_audit' ], true );

        // WP-CLI
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once DD_PLUGIN_DIR . 'modules/audit/class-dd-audit-cli.php';
            WP_CLI::add_command( 'dishdash audit', 'DD_Audit_CLI' );
        }
    }

    public function register_menu(): void {
        add_submenu_page(
            'dish-dash',
            'Audit',
            'Audit',
            'manage_options',
            'dish-dash-audit',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'dish-dash_page_dish-dash-audit' ) return;
        wp_enqueue_style(
            'dd-audit',
            DD_PLUGIN_URL . 'assets/css/audit.css',
            [],
            DD_VERSION
        );
        wp_enqueue_script(
            'dd-audit',
            DD_PLUGIN_URL . 'assets/js/audit.js',
            [],
            DD_VERSION,
            true
        );
        wp_localize_script( 'dd-audit', 'DD_Audit', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dd_run_audit' ),
        ] );
    }

    public function render_page(): void {
        require_once DD_PLUGIN_DIR . 'admin/pages/audit.php';
    }

    public function ajax_run_audit(): void {
        check_ajax_referer( 'dd_run_audit', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        require_once DD_PLUGIN_DIR . 'modules/audit/class-dd-audit-runner.php';
        $runner  = new DD_Audit_Runner();
        $results = $runner->run_all();

        wp_send_json_success( $results );
    }
}
