<?php
/**
 * File:    modules/whatsapp/class-dd-whatsapp-module.php
 * Module:  DD_WhatsApp_Module (extends DD_Module)
 * Purpose: WhatsApp Cloud API foundation — Phase 9A Step 1 (credentials
 *          settings screen + test-send) and Step 2 (the two message-log
 *          tables, created in install.php) are done. Step 3 (this release)
 *          wires up the public webhook endpoint. No outbound-message hook
 *          into orders/reservations yet (Step 4).
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - DD_Ajax (dishdash-core/class-dd-ajax.php)
 *   - modules/whatsapp/class-dd-whatsapp-client.php (DD_WhatsApp_Client)
 *   - modules/whatsapp/class-dd-whatsapp-webhook.php (DD_WhatsApp_Webhook)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates DD_WhatsApp_Module)
 *
 * Zero cross-module dependencies — per the Phase 9A brief's architecture
 * rule, this module does not call into any other module's class, and no
 * other module calls into this one yet. DD_WhatsApp_Webhook (Step 3) fires
 * do_action('dd_whatsapp_message_received', $conversation_id, $log_id) on
 * every stored inbound message, so Phase 9E's inbox module (not built yet)
 * can listen without either module depending on the other.
 *
 * Admin page owned: dish-dash-whatsapp (admin/pages/whatsapp-settings.php)
 * REST route owned: dish-dash/v1/whatsapp-webhook (GET verify, POST events —
 *   public, permission_callback => '__return_true'; see
 *   class-dd-whatsapp-webhook.php for the signature/idempotency handling)
 * WP options written: dd_whatsapp_phone_number_id, dd_whatsapp_waba_id,
 *   dd_whatsapp_token, dd_whatsapp_verify_token, dd_whatsapp_app_secret
 * Nonce actions: dd_whatsapp_settings_save (page save),
 *   dd_whatsapp_test_send (template test-send AJAX),
 *   dd_whatsapp_test_webhook (webhook test AJAX, v3.18.53),
 *   dd_whatsapp_cleanup_test (delete-test-messages AJAX, v3.18.53)
 *
 * v3.18.53 — "Send test webhook" tool (this module + the settings page
 * only; class-dd-whatsapp-webhook.php's handling logic is untouched). Lets
 * an admin exercise the real public webhook route end to end — real HTTP
 * POST, real HMAC signature, real DB writes — without Meta's dashboard.
 * ajax_test_webhook() builds a sample inbound-text payload shaped exactly
 * like Meta's real one (fake sender TEST_WA_NUMBER, name "Dish Dash test",
 * a fresh ddtest_-prefixed wa_message_id per click so the webhook's own
 * idempotency check never skips a repeat click), signs it with the saved
 * App Secret the same way signature_matches() in the webhook class does,
 * and POSTs it to rest_url('dish-dash/v1/whatsapp-webhook') — the same URL
 * Meta would call. ajax_delete_test_messages() removes only the rows this
 * tool created (looked up by TEST_WA_NUMBER, a number no real customer can
 * have), from both tables.
 *
 * Last modified: v3.18.53
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_WhatsApp_Module extends DD_Module {

    protected string $id = 'whatsapp';

    // Clearly-fake sender number used only by the webhook test tool —
    // no real customer can have this number, so every lookup/delete scoped
    // to it is guaranteed to never touch a real conversation.
    const TEST_WA_NUMBER = '250000000000';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-webhook.php';
        $webhook = new DD_WhatsApp_Webhook();
        add_action( 'rest_api_init', [ $webhook, 'register_routes' ] );

        // Admin-only — no customer-facing path exists yet, so no nopriv handler.
        DD_Ajax::register( 'dd_whatsapp_test_send', [ $this, 'ajax_test_send' ], false );
        DD_Ajax::register( 'dd_whatsapp_test_webhook', [ $this, 'ajax_test_webhook' ], false );
        DD_Ajax::register( 'dd_whatsapp_delete_test_messages', [ $this, 'ajax_delete_test_messages' ], false );
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
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'dd_whatsapp_test_send' ),
            'webhookNonce' => wp_create_nonce( 'dd_whatsapp_test_webhook' ),
            'cleanupNonce' => wp_create_nonce( 'dd_whatsapp_cleanup_test' ),
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

    /**
     * AJAX: exercise the real public webhook end to end — build a sample
     * inbound-message payload shaped like Meta's real one, sign it with
     * the saved App Secret exactly as signature_matches() in
     * DD_WhatsApp_Webhook does, and POST it to this site's own webhook
     * URL (never calling the handler class directly, so the real route and
     * the real signature check are both exercised). Reports the HTTP
     * status plus the stored conversation/message created_at values and
     * their difference, the same comparison previously done by hand in
     * phpMyAdmin. Never logs or echoes the App Secret itself.
     */
    public function ajax_test_webhook(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dd_whatsapp_test_webhook' );

        if ( ! current_user_can( 'dd_manage_settings' ) ) {
            $this->json_error( __( 'Unauthorized.', 'dish-dash' ), 403 );
            return;
        }

        $app_secret = get_option( 'dd_whatsapp_app_secret', '' );
        if ( '' === $app_secret ) {
            $this->json_error( __( 'The App Secret is not saved — enter and save it above before testing the webhook.', 'dish-dash' ) );
            return;
        }

        // ddtest_ prefix makes the id recognizable as a test; a fresh
        // UUID per click means the webhook's own wa_message_id idempotency
        // check (DD_WhatsApp_Webhook::insert_message_log()) never treats a
        // repeat click as an already-stored duplicate.
        $message_id = 'ddtest_' . wp_generate_uuid4();
        $raw_body   = wp_json_encode( $this->build_test_webhook_payload( $message_id ) );
        $signature  = 'sha256=' . hash_hmac( 'sha256', $raw_body, $app_secret );

        $response = wp_remote_post( rest_url( 'dish-dash/v1/whatsapp-webhook' ), [
            'headers' => [
                'Content-Type'        => 'application/json',
                'X-Hub-Signature-256' => $signature,
            ],
            'body'    => $raw_body,
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->json_error( sprintf(
                /* translators: %s: the underlying WP_Error message */
                __( 'The loopback request failed — some hosts block a site from calling its own webhook URL. Error: %s', 'dish-dash' ),
                $response->get_error_message()
            ) );
            return;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            $this->json_error( sprintf(
                /* translators: %d: HTTP status code returned by the webhook */
                __( 'The webhook rejected the request — HTTP %d.', 'dish-dash' ),
                $status
            ) );
            return;
        }

        global $wpdb;
        $conversation_created = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}dishdash_wa_conversations WHERE wa_number = %s",
            self::TEST_WA_NUMBER
        ) );
        $message_created = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}dishdash_message_log WHERE wa_message_id = %s",
            $message_id
        ) );

        if ( ! $conversation_created || ! $message_created ) {
            $this->json_error( __( 'The webhook accepted the request (HTTP 200) but no stored row was found afterward — check the server error log.', 'dish-dash' ) );
            return;
        }

        $diff_minutes = round( ( strtotime( $message_created ) - strtotime( $conversation_created ) ) / 60, 2 );

        $this->json_success( [
            'http_status'          => $status,
            'conversation_created' => $conversation_created,
            'message_created'      => $message_created,
            'diff_minutes'         => $diff_minutes,
        ], __( 'Test webhook delivered.', 'dish-dash' ) );
    }

    /**
     * Sample inbound-text payload, shaped exactly like Meta's real
     * messages webhook (the 'field' => 'messages' entry the v3.18.52
     * field allow-list in DD_WhatsApp_Webhook::process_payload() requires).
     */
    private function build_test_webhook_payload( string $message_id ): array {
        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [ [
                'id'      => get_option( 'dd_whatsapp_waba_id', '' ) ?: '0',
                'changes' => [ [
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata'          => [
                            'display_phone_number' => self::TEST_WA_NUMBER,
                            'phone_number_id'      => get_option( 'dd_whatsapp_phone_number_id', '' ) ?: '0',
                        ],
                        'contacts' => [ [
                            'profile' => [ 'name' => 'Dish Dash test' ],
                            'wa_id'   => self::TEST_WA_NUMBER,
                        ] ],
                        'messages' => [ [
                            'from'      => self::TEST_WA_NUMBER,
                            'id'        => $message_id,
                            'timestamp' => (string) time(),
                            'type'      => 'text',
                            'text'      => [ 'body' => 'Dish Dash webhook test message.' ],
                        ] ],
                    ],
                ] ],
            ] ],
        ];
    }

    /**
     * AJAX: delete only the rows the webhook test tool created — identified
     * solely by TEST_WA_NUMBER, a number no real customer can have — from
     * both WhatsApp tables. Never touches a real conversation.
     */
    public function ajax_delete_test_messages(): void {
        DD_Ajax::verify_nonce( 'nonce', 'dd_whatsapp_cleanup_test' );

        if ( ! current_user_can( 'dd_manage_settings' ) ) {
            $this->json_error( __( 'Unauthorized.', 'dish-dash' ), 403 );
            return;
        }

        global $wpdb;
        $conversation_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dishdash_wa_conversations WHERE wa_number = %s",
            self::TEST_WA_NUMBER
        ) );

        $deleted_messages = 0;
        if ( $conversation_id ) {
            $deleted_messages = (int) $wpdb->delete(
                $wpdb->prefix . 'dishdash_message_log',
                [ 'conversation_id' => $conversation_id ],
                [ '%d' ]
            );
        }

        $deleted_conversations = (int) $wpdb->delete(
            $wpdb->prefix . 'dishdash_wa_conversations',
            [ 'wa_number' => self::TEST_WA_NUMBER ],
            [ '%s' ]
        );

        $this->json_success( [
            'deleted_messages'      => $deleted_messages,
            'deleted_conversations' => $deleted_conversations,
        ], __( 'Test messages deleted.', 'dish-dash' ) );
    }
}
