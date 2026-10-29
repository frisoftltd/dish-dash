<?php
/**
 * File:    modules/whatsapp/class-dd-whatsapp-module.php
 * Module:  DD_WhatsApp_Module (extends DD_Module)
 * Purpose: WhatsApp Cloud API foundation, Phase 9A Step 1 only — a
 *          per-restaurant credentials settings screen and a test-send
 *          action. No webhook, no message-log tables, and no outbound hook
 *          into orders/reservations yet (those are later Phase 9A steps).
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - DD_Ajax (dishdash-core/class-dd-ajax.php)
 *   - modules/whatsapp/class-dd-whatsapp-client.php (DD_WhatsApp_Client)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates DD_WhatsApp_Module)
 *
 * Zero cross-module dependencies — per the Phase 9A brief's architecture
 * rule, this module does not call into any other module's class, and no
 * other module calls into this one yet. Later Phase 9A steps will fire
 * do_action('dd_whatsapp_message_received', ...) from the webhook handler
 * (not yet built) so Phase 9E's inbox module can listen without either
 * module depending on the other.
 *
 * Admin page owned: dish-dash-whatsapp (admin/pages/whatsapp-settings.php)
 * WP options written: dd_whatsapp_phone_number_id, dd_whatsapp_waba_id,
 *   dd_whatsapp_token, dd_whatsapp_verify_token, dd_whatsapp_app_secret
 * Nonce actions: dd_whatsapp_settings_save (page save),
 *   dd_whatsapp_test_send (AJAX)
 *
 * Last modified: v3.18.48
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_WhatsApp_Module extends DD_Module {

    protected string $id = 'whatsapp';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Admin-only — no customer-facing path exists yet, so no nopriv handler.
        DD_Ajax::register( 'dd_whatsapp_test_send', [ $this, 'ajax_test_send' ], false );
    }

    public function register_menu(): void {
        add_submenu_page(
            'dish-dash',
            __( 'WhatsApp', 'dish-dash' ),
            __( '💬 WhatsApp', 'dish-dash' ),
            'dd_manage_settings',
            'dish-dash-whatsapp',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'dish-dash_page_dish-dash-whatsapp' ) return;

        $this->enqueue_script( 'whatsapp-settings', 'whatsapp-settings.js' );
        wp_localize_script( 'dish-dash-whatsapp-settings', 'ddWhatsAppAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dd_whatsapp_test_send' ),
        ] );
    }

    public function render_page(): void {
        require_once DD_PLUGIN_DIR . 'admin/pages/whatsapp-settings.php';
    }

    /**
     * AJAX: send one approved template message to an admin-entered number.
     * Exists purely to let an admin confirm their credentials work before
     * any real outbound message is wired up (Phase 9A Steps 3-5).
     */
    public function ajax_test_send(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dd_whatsapp_test_send' );

        if ( ! current_user_can( 'dd_manage_settings' ) ) {
            $this->json_error( __( 'Unauthorized.', 'dish-dash' ), 403 );
            return;
        }

        $to            = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) );
        $template_name = sanitize_text_field( wp_unslash( $_POST['template_name'] ?? '' ) );
        $language_code = sanitize_text_field( wp_unslash( $_POST['language_code'] ?? 'en_US' ) );

        if ( ! $to || ! $template_name ) {
            $this->json_error( __( 'Phone number and template name are required.', 'dish-dash' ) );
            return;
        }

        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-client.php';
        $client = new DD_WhatsApp_Client();

        if ( ! $client->is_configured() ) {
            $this->json_error( __( 'WhatsApp is not configured — fill in Phone Number ID and Token first.', 'dish-dash' ) );
            return;
        }

        $result = $client->send_template( $to, $template_name, $language_code ?: 'en_US' );

        if ( ! $result['success'] ) {
            $this->json_error( $result['error'] ?? __( 'Send failed.', 'dish-dash' ) );
            return;
        }

        $this->json_success( [ 'message_id' => $result['message_id'] ], __( 'Test message sent.', 'dish-dash' ) );
    }
}
