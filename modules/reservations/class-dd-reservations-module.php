<?php
/**
 * DD_Reservations_Module
 * Handles reservation submission, availability checks, and admin status updates.
 *
 * @package DishDash
 * @since   3.2.90
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-dd-reservations-admin.php';
require_once __DIR__ . '/class-dd-tables-admin.php';

class DD_Reservations_Module extends DD_Module {

    protected string $id = 'reservations';

    public function init(): void {
        // Admin UI submodules
        ( new DD_Reservations_Admin() )->init();
        ( new DD_Tables_Admin() )->init();

        // AJAX — public (guests + logged-in)
        DD_Ajax::register( 'dd_submit_reservation',      [ $this, 'ajax_submit_reservation' ] );
        DD_Ajax::register( 'dd_reservation_availability', [ $this, 'ajax_check_availability' ] );

        // AJAX — admin only
        DD_Ajax::register( 'dd_reservation_update_status', [ $this, 'ajax_update_status' ], false );

        // Localize nonce to reservations.js
        add_action( 'wp_enqueue_scripts', [ $this, 'localize_nonce' ] );
    }

    public function localize_nonce(): void {
        // reservations.js is enqueued by DD_Template_Module — localize data on top of it
        wp_localize_script( 'dd-reservations', 'ddReservations', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dish_dash_frontend' ),
        ] );
    }

    // ── AJAX handlers (stubs — logic added in v3.2.92) ────────────────────

    public function ajax_submit_reservation(): void {
        DD_Ajax::verify_nonce();
        wp_send_json_error( [ 'message' => 'Not yet implemented' ] );
    }

    public function ajax_check_availability(): void {
        DD_Ajax::verify_nonce();
        wp_send_json_error( [ 'message' => 'Not yet implemented' ] );
    }

    public function ajax_update_status(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dish_dash_admin' );
        wp_send_json_error( [ 'message' => 'Not yet implemented' ] );
    }
}
