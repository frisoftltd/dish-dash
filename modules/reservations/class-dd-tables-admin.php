<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Tables_Admin {

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_submenu' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Tables', 'dish-dash' ),
            __( 'Tables', 'dish-dash' ),
            'manage_options',
            'dd-tables',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        echo '<div class="wrap"><h1>Tables</h1><p>Coming in v3.2.91.</p></div>';
    }
}
