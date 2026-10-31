<?php
/**
 * File:    modules/whatsapp/class-dd-whatsapp-module.php
 * Module:  DD_WhatsApp_Module (extends DD_Module)
 * Purpose: WhatsApp Cloud API foundation — credentials settings screen +
 *          test-send (Step 1), the two message-log tables (Step 2), the
 *          public webhook endpoint (Step 3), and now (v3.18.58, Step 4) the
 *          first real outbound send: the customer order-confirmed message.
 *
 * Dependencies (this file needs):
 *   - DD_Module base class
 *   - DD_Ajax (dishdash-core/class-dd-ajax.php)
 *   - modules/whatsapp/class-dd-whatsapp-client.php (DD_WhatsApp_Client)
 *   - modules/whatsapp/class-dd-whatsapp-webhook.php (DD_WhatsApp_Webhook —
 *     get_or_create_conversation()/insert_message_log()/apply_status_update()
 *     are public specifically so this class can reuse them, v3.18.58)
 *
 * Dependents (files that need this):
 *   - dishdash-core/class-dd-loader.php (instantiates DD_WhatsApp_Module)
 *
 * Cross-module reads (never writes, never a method call into another
 * module's class — same SELECT-only pattern DD_WhatsApp_Webhook::
 * resolve_customer_id() already uses against dishdash_customers):
 *   - dishdash_orders (order_number/customer_name/customer_phone/total) —
 *     this module knows about orders; the orders module has zero reference
 *     to this one anywhere in modules/orders/, per the brief.
 *
 * Admin page owned: dish-dash-whatsapp (admin/pages/whatsapp-settings.php)
 * REST route owned: dish-dash/v1/whatsapp-webhook (GET verify, POST events —
 *   public, permission_callback => '__return_true'; see
 *   class-dd-whatsapp-webhook.php for the signature/idempotency handling)
 * WP options written: dd_whatsapp_phone_number_id, dd_whatsapp_waba_id,
 *   dd_whatsapp_token, dd_whatsapp_verify_token, dd_whatsapp_app_secret,
 *   dd_whatsapp_order_confirmed_enabled, dd_whatsapp_order_confirmed_template,
 *   dd_whatsapp_order_confirmed_lang, dd_whatsapp_default_country_code,
 *   dd_whatsapp_last_alert_at (v3.18.58)
 * Nonce actions: dd_whatsapp_settings_save (page save),
 *   dd_whatsapp_test_send (template test-send AJAX),
 *   dd_whatsapp_test_webhook (webhook test AJAX, v3.18.53),
 *   dd_whatsapp_cleanup_test (delete-test-messages AJAX, v3.18.53)
 *
 * v3.18.58 — Phase 9A Step 4: customer order-confirmed WhatsApp message.
 * Listens to the orders module's existing dish_dash_order_status_changed
 * hook (new_status === 'confirmed') and schedules a single WP-Cron event
 * (maybe_schedule_order_confirmed() → send_order_confirmed()) — the Meta API
 * is never called inline on the Confirm click. Idempotent by
 * (order_id, template_name): at most one confirmation message per order,
 * ever, regardless of how many times the hook fires or the order cycles
 * through statuses again. Default OFF (dd_whatsapp_order_confirmed_enabled),
 * and skips quietly (one DD_DIAG log line, no alert) when off or
 * credentials aren't configured. Phone numbers are normalized by an
 * independent normalizer (normalize_phone()) — deliberately not
 * DD_Customer_Manager::normalize_phone() (orders module), since that
 * method's output format depends on the site-wide dd_phone_format option
 * and this module needs one fixed shape regardless of it. Failures are
 * classified transient (network/server/rate-limit — retried) vs permanent
 * (invalid phone, template/parameter issues, or anything unrecognized —
 * never retried) via is_transient_failure(), using Meta's own current
 * error-code documentation (fetched live, not from memory). A capped
 * retry sweep (dd_whatsapp_retry_sweep, every 5 minutes, shaped after
 * DD_Orders_Module::run_pesapal_reconcile_sweep()'s transient-lock pattern)
 * retries only rows this module itself labeled 'transient:', up to 3 total
 * attempts, then relabels permanent. One throttled (1/hour) admin email
 * lists every failed send since the previous alert — order number,
 * customer phone, reason, time — never a token or secret.
 *
 * Last modified: v3.18.58
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

        // v3.18.58 — Phase 9A Step 4: customer order-confirmed message.
        // Listens to the orders module's existing dish_dash_order_status_changed
        // hook only — this module knows about orders, orders never knows about
        // this module (no reverse reference anywhere in modules/orders/).
        add_action( 'dish_dash_order_status_changed', [ $this, 'maybe_schedule_order_confirmed' ], 10, 3 );

        // The actual send always runs on WP-Cron, never inline on the Confirm
        // click — Meta's latency must never slow reception down. A single
        // event per order (idempotency is enforced inside the handler, not by
        // preventing duplicate scheduling — a second schedule_single_event()
        // for the same already-sent order is a harmless no-op once it fires).
        add_action( 'dd_whatsapp_send_order_confirmed', [ $this, 'send_order_confirmed' ], 10, 1 );

        // Capped retry sweep, shaped after dd_pesapal_reconcile_sweep — a
        // recurring interval, a transient overlap lock, a bounded batch.
        add_filter( 'cron_schedules', [ $this, 'register_whatsapp_retry_interval' ] );
        add_action( 'dd_whatsapp_retry_sweep', [ $this, 'run_retry_sweep' ] );
        $scheduled = wp_get_scheduled_event( 'dd_whatsapp_retry_sweep' );
        if ( ! $scheduled || 'dd_five_minutes' !== $scheduled->schedule ) {
            wp_clear_scheduled_hook( 'dd_whatsapp_retry_sweep' );
            wp_schedule_event( time(), 'dd_five_minutes', 'dd_whatsapp_retry_sweep' );
        }
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

    // ─────────────────────────────────────────
    //  ORDER-CONFIRMED MESSAGE (v3.18.58, Phase 9A Step 4)
    // ─────────────────────────────────────────

    public function register_whatsapp_retry_interval( array $schedules ): array {
        if ( ! isset( $schedules['dd_five_minutes'] ) ) {
            $schedules['dd_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes (Dish Dash)', 'dish-dash' ),
            ];
        }
        return $schedules;
    }

    /**
     * Hook listener for the orders module's existing
     * dish_dash_order_status_changed. Only ever schedules a WP-Cron event —
     * never calls the Meta API inline, so Meta's latency can never slow the
     * Confirm click down. $old_status is intentionally unused: any path
     * into 'confirmed' qualifies, regardless of where it came from.
     */
    public function maybe_schedule_order_confirmed( int $order_id, string $old_status, string $new_status ): void {
        if ( 'confirmed' !== $new_status ) {
            return;
        }
        if ( ! $this->order_confirmed_feature_ready() ) {
            return; // already logged its one quiet line inside the check
        }
        wp_schedule_single_event( time(), 'dd_whatsapp_send_order_confirmed', [ $order_id ] );
    }

    /**
     * True only when the switch is on AND credentials are configured.
     * Logs exactly one quiet DD_DIAG line per reason when not — no alert,
     * per the brief ("skip quietly"). Checked both before scheduling and
     * again inside the cron handler itself, in case the setting changes
     * in the few seconds between the two.
     */
    private function order_confirmed_feature_ready(): bool {
        if ( '1' !== get_option( 'dd_whatsapp_order_confirmed_enabled', '0' ) ) {
            error_log( 'DD_DIAG: WhatsApp order-confirmed send skipped — reason=disabled' );
            return false;
        }
        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-client.php';
        if ( ! ( new DD_WhatsApp_Client() )->is_configured() ) {
            error_log( 'DD_DIAG: WhatsApp order-confirmed send skipped — reason=not_configured' );
            return false;
        }
        return true;
    }

    /**
     * WP-Cron handler — the actual send. Reads the order directly via SQL
     * (never DD_Orders_Module::get_order() — this module does not call into
     * the orders module's class, only reads its table, same pattern
     * DD_WhatsApp_Webhook::resolve_customer_id() already uses against
     * dishdash_customers: a SELECT, never a write, to another module's table).
     */
    public function send_order_confirmed( int $order_id ): void {
        if ( ! $this->order_confirmed_feature_ready() ) {
            return;
        }

        global $wpdb;
        $order = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, order_number, customer_name, customer_phone, total
             FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
            $order_id
        ) );
        if ( ! $order ) {
            error_log( 'DD_DIAG: WhatsApp order-confirmed send aborted — order_id=' . $order_id . ' not found' );
            return;
        }

        $template_name = (string) get_option( 'dd_whatsapp_order_confirmed_template', 'dd_order_confirmed_v1' );
        $existing      = $this->find_order_confirmed_log_row( $order_id, $template_name );

        if ( $existing && ! $this->row_is_retryable( $existing ) ) {
            return; // already sent, already delivered/read, or permanently failed/exhausted
        }

        $this->attempt_order_confirmed_send( $order, $template_name, $existing );
    }

    /**
     * Idempotency lookup — "an order gets at most one confirmation message,
     * even if the hook fires twice" is enforced here, by order_id +
     * template_name, not by wa_message_id (unknown before the first attempt
     * even starts).
     */
    private function find_order_confirmed_log_row( int $order_id, string $template_name ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, conversation_id, status, retry_count, error_detail
             FROM {$wpdb->prefix}dishdash_message_log
             WHERE order_id = %d AND template_name = %s AND direction = 'outbound'
             LIMIT 1",
            $order_id, $template_name
        ) );
    }

    /**
     * A row is eligible for another attempt only when it previously failed
     * transiently (error_detail prefixed 'transient:' — see
     * is_transient_failure()) and hasn't yet used all 3 attempts. Already
     * sent/delivered/read, or a permanent/invalid_phone/exhausted failure,
     * are never retried.
     */
    private function row_is_retryable( object $row ): bool {
        if ( in_array( $row->status, [ 'sent', 'delivered', 'read' ], true ) ) {
            return false;
        }
        if ( (int) $row->retry_count >= 3 ) {
            return false;
        }
        return 0 === strpos( (string) $row->error_detail, 'transient:' );
    }

    /**
     * One send attempt — shared by the hook-triggered first attempt
     * (send_order_confirmed(), $existing null) and run_retry_sweep()'s
     * later attempts against the same existing row ($existing set), so the
     * actual call + classification + logging logic lives in exactly one
     * place.
     */
    private function attempt_order_confirmed_send( object $order, string $template_name, ?object $existing ): void {
        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-webhook.php';
        $webhook = new DD_WhatsApp_Webhook();

        // Best-effort digits for the conversation record even when the
        // number turns out unusable for sending — mirrors how the webhook's
        // own inbound handler extracts wa_number from Meta's 'from' field
        // (plain digit-strip, no stricter validation applied there either).
        $wa_number_digits = preg_replace( '/\D/', '', (string) $order->customer_phone );
        $to               = $this->normalize_phone( (string) $order->customer_phone );

        if ( '' === $to ) {
            $this->finish_order_confirmed_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
                'success'      => false,
                'permanent'    => true,
                'error_detail' => 'permanent: invalid_phone',
            ] );
            return;
        }

        $customer_name = $this->sanitize_param( (string) $order->customer_name, __( 'Customer', 'dish-dash' ) );
        $order_number  = $this->sanitize_param( (string) $order->order_number, '' );
        $restaurant    = $this->sanitize_param( (string) get_option( 'dish_dash_restaurant_name', 'Restaurant' ), 'Restaurant' );
        $total         = $this->sanitize_param( $this->format_total( (float) $order->total ), '' );

        if ( '' === $order_number || '' === $total ) {
            // Shouldn't happen for a real order row — defensive, not expected.
            $this->finish_order_confirmed_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
                'success'      => false,
                'permanent'    => true,
                'error_detail' => 'permanent: empty required parameter',
            ] );
            return;
        }

        $params        = [ $customer_name, $order_number, $restaurant, $total ];
        $language_code = (string) get_option( 'dd_whatsapp_order_confirmed_lang', 'en' );
        $components    = [ [
            'type'       => 'body',
            'parameters' => array_map( fn( $v ) => [ 'type' => 'text', 'text' => $v ], $params ),
        ] ];

        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-client.php';
        $result = ( new DD_WhatsApp_Client() )->send_template( $to, $template_name, $language_code, $components );

        if ( $result['success'] ) {
            $this->finish_order_confirmed_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
                'success'    => true,
                'message_id' => $result['message_id'],
                'params'     => $params,
            ] );
            return;
        }

        $transient = $this->is_transient_failure( $result );
        $this->finish_order_confirmed_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
            'success'      => false,
            'permanent'    => ! $transient,
            'error_detail' => ( $transient ? 'transient: ' : 'permanent: ' ) . ( $result['error'] ?? 'Unknown error' ),
        ] );
    }

    /**
     * Writes (inserts or updates) the outbound message_log row for one
     * attempt, applies any parked orphan status update on success, bumps
     * retry_count and queues an alert on failure. Every timestamp here is
     * current_time('mysql').
     */
    private function finish_order_confirmed_attempt(
        object $order,
        string $template_name,
        ?object $existing,
        DD_WhatsApp_Webhook $webhook,
        string $wa_number_digits,
        array $outcome
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_message_log';
        $now   = current_time( 'mysql' );

        $conversation_id = $existing
            ? (int) $existing->conversation_id
            : $webhook->get_or_create_conversation( $wa_number_digits, (string) $order->customer_name );

        if ( $outcome['success'] ) {
            if ( $existing ) {
                $wpdb->update( $table, [
                    'wa_message_id'   => $outcome['message_id'],
                    'template_params' => wp_json_encode( $outcome['params'] ),
                    'status'          => 'sent',
                    'error_detail'    => null,
                    'status_at'       => $now,
                ], [ 'id' => $existing->id ], [ '%s', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            } else {
                $webhook->insert_message_log( [
                    'conversation_id' => $conversation_id,
                    'order_id'        => (int) $order->id,
                    'direction'       => 'outbound',
                    'wa_message_id'   => $outcome['message_id'],
                    'message_type'    => 'template',
                    'template_name'   => $template_name,
                    'template_params' => wp_json_encode( $outcome['params'] ),
                    'status'          => 'sent',
                    'retry_count'     => 0,
                    'created_at'      => $now,
                ] );
            }

            // Meta's status webhook may have beaten our own HTTP response
            // back — apply any parked update immediately, per
            // DD_WhatsApp_Webhook::apply_status_update()'s own documented
            // requirement for this send path.
            $parked = get_transient( 'dd_wa_orphan_status_' . $outcome['message_id'] );
            if ( $parked ) {
                delete_transient( 'dd_wa_orphan_status_' . $outcome['message_id'] );
                $webhook->apply_status_update( (array) $parked );
            }
            return;
        }

        $retry_count  = $existing ? (int) $existing->retry_count : 0;
        $error_detail = $outcome['error_detail'];
        if ( ! $outcome['permanent'] ) {
            $retry_count++;
            if ( $retry_count >= 3 ) {
                // Exhausted the capped budget — relabel so neither this
                // idempotency check nor the retry sweep ever touch it again.
                $error_detail = 'permanent: exhausted retries after 3 attempts — ' . substr( $error_detail, strlen( 'transient: ' ) );
            }
        }

        if ( $existing ) {
            $wpdb->update( $table, [
                'status'       => 'failed',
                'error_detail' => $error_detail,
                'retry_count'  => $retry_count,
                'status_at'    => $now,
            ], [ 'id' => $existing->id ], [ '%s', '%s', '%d', '%s' ], [ '%d' ] );
        } else {
            $webhook->insert_message_log( [
                'conversation_id' => $conversation_id,
                'order_id'        => (int) $order->id,
                'direction'       => 'outbound',
                'message_type'    => 'template',
                'template_name'   => $template_name,
                'status'          => 'failed',
                'error_detail'    => $error_detail,
                'retry_count'     => $retry_count,
                'created_at'      => $now,
                'status_at'       => $now,
            ] );
        }

        $this->queue_failure_alert( $template_name, $now );
    }

    /**
     * Independent phone normalizer — deliberately does NOT call
     * DD_Customer_Manager::normalize_phone() (orders module): that method's
     * output format depends on the site-wide dd_phone_format option, and
     * this module needs one fixed, predictable shape regardless of that
     * setting — digits only, with the country code, no leading '+', per
     * DD_WhatsApp_Client::send_template()'s own documented $to contract.
     *
     * Accepts: 0788123456, 788123456, +250 788 123 456, 250788123456, a
     * leading 00 international prefix, and any other number that arrived
     * with an explicit '+' (kept foreign, 8-15 digits — Meta itself is the
     * real validity check for those). Returns '' for anything else.
     */
    private function normalize_phone( string $raw ): string {
        $had_plus = (bool) preg_match( '/^\s*\+/', $raw );
        $digits   = preg_replace( '/\D/', '', $raw );

        if ( '' === $digits ) {
            return '';
        }

        // Leading 00 international prefix — treat the rest exactly like an
        // explicit '+' number from here on.
        if ( ! $had_plus && strlen( $digits ) > 2 && '00' === substr( $digits, 0, 2 ) ) {
            $digits   = substr( $digits, 2 );
            $had_plus = true;
        }

        if ( $had_plus ) {
            return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? $digits : '';
        }

        $default_cc = preg_replace( '/\D/', '', (string) get_option( 'dd_whatsapp_default_country_code', '250' ) );
        $default_cc = '' !== $default_cc ? $default_cc : '250';

        // National trunk prefix: 0788123456 (10 digits) → 788123456 (9).
        if ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
            $digits = substr( $digits, 1 );
        }

        // Bare local subscriber number — prepend the default country code.
        if ( 9 === strlen( $digits ) ) {
            return $default_cc . $digits;
        }

        // Already carries the default country code without a leading '+'.
        if ( strlen( $digits ) === strlen( $default_cc ) + 9 && 0 === strpos( $digits, $default_cc ) ) {
            return $digits;
        }

        return ''; // everything else is unusable
    }

    /**
     * Transient-vs-permanent classification. "Transient" is exactly the
     * brief's own allow-list — network errors, server errors, rate limits —
     * nothing else; an unrecognized error code defaults to permanent, since
     * retrying something retrying cannot fix only wastes the capped attempt
     * budget before a human ever sees it.
     *
     * Meta error codes below are sourced from Meta's own current WhatsApp
     * Cloud API error-code reference (developers.facebook.com/documentation/
     * business-messaging/whatsapp/support/error-codes), checked 2026-10-31
     * via a live fetch — not from memory, since misclassifying here
     * silently misroutes retries. Meta's own docs describe generic code 1
     * as ambiguous ("invalid request or possible server error") — left out
     * of the retryable list deliberately, so an ambiguous code defaults to
     * the safer (don't-retry-forever) side.
     */
    private const RETRYABLE_ERROR_CODES = [
        // Rate-limiting
        4, 80007, 130429, 131048, 131056, 133016, 131064,
        // Temporary / server
        2, 131000, 131016, 131057, 133004, 2494100,
    ];

    private function is_transient_failure( array $result ): bool {
        $http_status = (int) ( $result['http_status'] ?? 0 );

        if ( 0 === $http_status ) {
            return true; // no HTTP response at all — a network-level failure
        }
        if ( 429 === $http_status || $http_status >= 500 ) {
            return true;
        }
        if ( isset( $result['error_code'] ) && in_array( (int) $result['error_code'], self::RETRYABLE_ERROR_CODES, true ) ) {
            return true;
        }
        return false;
    }

    /**
     * "RWF 12,500" — not dd_price() (that helper defaults to '$' and always
     * shows 2 decimals; this matches the existing invoice-email convention
     * instead: get_option(currency_symbol) + a no-decimal, comma-grouped
     * amount), still read dynamically per-restaurant, never hardcoded.
     */
    private function format_total( float $total ): string {
        $symbol = (string) get_option( 'dish_dash_currency_symbol', 'RWF' );
        return $symbol . ' ' . number_format( $total, 0 );
    }

    /**
     * A WhatsApp template parameter can't be empty or contain line breaks
     * (Meta rejects both) — strips newlines/tabs, trims, falls back when
     * the result is empty.
     */
    private function sanitize_param( string $value, string $fallback ): string {
        $clean = trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
        return '' !== $clean ? $clean : $fallback;
    }

    /**
     * Capped retry sweep — shaped after DD_Orders_Module::
     * run_pesapal_reconcile_sweep(): a transient overlap lock, a bounded
     * batch (LIMIT 20), re-using the exact same attempt/classify/log path
     * as the first attempt. Only ever touches rows this module itself
     * marked 'transient:' — a permanent/invalid_phone/exhausted row is
     * never picked up here, by construction of that WHERE clause.
     */
    public function run_retry_sweep(): void {
        $lock_key = 'dd_whatsapp_retry_lock';
        if ( false !== get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, time(), 4 * MINUTE_IN_SECONDS );

        try {
            if ( ! $this->order_confirmed_feature_ready() ) {
                return; // switched off or credentials pulled mid-flight
            }

            global $wpdb;
            $template_name = (string) get_option( 'dd_whatsapp_order_confirmed_template', 'dd_order_confirmed_v1' );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_id, conversation_id, status, retry_count, error_detail
                 FROM {$wpdb->prefix}dishdash_message_log
                 WHERE direction = 'outbound' AND template_name = %s
                   AND status = 'failed' AND retry_count < 3
                   AND error_detail LIKE 'transient:%%'
                 ORDER BY status_at ASC
                 LIMIT 20",
                $template_name
            ) );

            foreach ( $rows as $row ) {
                $order = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, order_number, customer_name, customer_phone, total
                     FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
                    (int) $row->order_id
                ) );
                if ( ! $order ) {
                    continue; // order no longer exists — nothing sane to retry
                }
                $this->attempt_order_confirmed_send( $order, $template_name, $row );
            }
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * One email per hour, listing every order-confirmed send that failed
     * since the previous alert — order number, customer phone, reason,
     * time. Never includes a token or secret: error_detail only ever
     * contains this module's own 'transient:'/'permanent:' label plus
     * whatever DD_WhatsApp_Client::send_template() returned, which never
     * echoes credentials into any error it returns (confirmed by reading
     * that method — it only ever surfaces Meta's error.message or a
     * WP_Error message, never $this->token/$this->phone_number_id).
     */
    private function queue_failure_alert( string $template_name, string $now ): void {
        $throttle_key = 'dd_whatsapp_alert_throttle';
        if ( false !== get_transient( $throttle_key ) ) {
            return; // an alert already went out within the last hour
        }

        global $wpdb;
        $since = (string) get_option( 'dd_whatsapp_last_alert_at', '1970-01-01 00:00:00' );

        $failures = $wpdb->get_results( $wpdb->prepare(
            "SELECT ml.error_detail, ml.status_at, o.order_number, o.customer_phone
             FROM {$wpdb->prefix}dishdash_message_log ml
             LEFT JOIN {$wpdb->prefix}dishdash_orders o ON o.id = ml.order_id
             WHERE ml.direction = 'outbound' AND ml.template_name = %s
               AND ml.status = 'failed' AND ml.status_at > %s
             ORDER BY ml.status_at ASC",
            $template_name, $since
        ) );

        if ( ! $failures ) {
            return;
        }

        $lines = array_map( function ( $f ) {
            return sprintf(
                '%s — %s — %s — %s',
                $f->order_number ?: '(order not found)',
                $f->customer_phone ?: '(no phone on file)',
                $f->error_detail,
                $f->status_at
            );
        }, $failures );

        $to = get_option( 'dd_admin_email', get_option( 'admin_email' ) );
        wp_mail(
            $to,
            sprintf(
                /* translators: %d: number of failed sends in this alert */
                __( '[Dish Dash] %d WhatsApp order-confirmed message(s) failed', 'dish-dash' ),
                count( $failures )
            ),
            __( 'The following order-confirmed WhatsApp messages failed to send:', 'dish-dash' ) . "\n\n" . implode( "\n", $lines )
        );

        set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
        update_option( 'dd_whatsapp_last_alert_at', $now, false );
    }
}
