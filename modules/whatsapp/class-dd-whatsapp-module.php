<?php
/**
 * File:    modules/whatsapp/class-dd-whatsapp-module.php
 * Module:  DD_WhatsApp_Module (extends DD_Module)
 * Purpose: WhatsApp Cloud API foundation — credentials settings screen +
 *          test-send (Step 1), the two message-log tables (Step 2), the
 *          public webhook endpoint (Step 3), the customer order-confirmed
 *          message (v3.18.58, Step 4), and now (v3.18.60) a second,
 *          independent outbound message: the kitchen new-order alert. Both
 *          messages share one generic send/retry/alert pipeline
 *          (attempt_whatsapp_send()/finish_whatsapp_attempt()) — see that
 *          pipeline's own docblocks for the shared mechanics, and
 *          "v3.18.60" comments below for what's kitchen-specific.
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
 *   dd_whatsapp_last_alert_at (v3.18.58), dd_whatsapp_kitchen_alert_enabled,
 *   dd_whatsapp_kitchen_alert_template, dd_whatsapp_kitchen_alert_lang,
 *   dd_whatsapp_kitchen_last_alert_at (v3.18.60)
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
 * v3.18.60 — Phase 9A Step 5a: kitchen new-order WhatsApp alert, a second
 * and fully independent automatic send triggered by the same
 * dish_dash_order_status_changed('confirmed') event, added via its own
 * add_action() call — the v3.18.58 listener's registration and body are
 * untouched. The send/retry/alert mechanics (attempt_whatsapp_send(),
 * finish_whatsapp_attempt(), is_transient_failure(), the retry sweep) are
 * now shared with the customer message via a small refactor:
 * attempt_order_confirmed_send() is a thin wrapper (reads customer_phone,
 * builds the same 4 params as before, in the same order) that now calls
 * the shared attempt_whatsapp_send(); the old finish_order_confirmed_
 * attempt() is that same shared finish_whatsapp_attempt(), now carrying an
 * $is_staff flag (controls the conversation's display name + role) and a
 * $message_kind string ('customer'|'kitchen', only used to keep the two
 * alert emails' wording and throttle windows independent). Recipient is
 * the existing dd_whatsapp_kitchen option (already used by the pre-existing
 * manual wa.me kitchen link — read-only reuse of that option, nothing about
 * the wa.me link itself is touched), through the same normalize_phone(). An
 * empty or unnormalizable kitchen number is a silent skip — one DD_DIAG log
 * line, no message_log row, no alert, since there's nothing actionable to
 * alert about until a number is configured. The conversation this creates
 * is marked role='staff' (new nullable column, v3.18.60) so Phase 9E's
 * future inbox can eventually tell it apart from a real customer; every
 * existing conversation's role stays NULL (unaffected). The items-line
 * parameter is built from a local, independent decode of
 * dishdash_order_items.variation/addons JSON (no call into the orders
 * module's own class — a direct SELECT, same cross-module-read pattern
 * already used for dishdash_orders/dishdash_customers elsewhere in this
 * file) — see build_kitchen_items_line(). Kitchen alerts carry one
 * additional retry cutoff beyond the shared 3-attempt cap: a failed row
 * older than 10 minutes (DB server clock, via a SQL NOW() comparison —
 * never PHP's time()/current_time(), which run several hours apart from
 * the DB clock on this host) is force-exhausted and alerted on rather than
 * retried further; customer confirmations keep their original
 * count-only cap, unchanged.
 *
 * Last modified: v3.18.60
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

        // v3.18.60 — Phase 9A Step 5a: kitchen new-order alert. A second,
        // independent listener on the same hook — this add_action() call is
        // new; the one directly above it is untouched, still its own
        // separate registration.
        add_action( 'dish_dash_order_status_changed', [ $this, 'maybe_schedule_kitchen_alert' ], 10, 3 );
        add_action( 'dd_whatsapp_send_kitchen_alert', [ $this, 'send_kitchen_alert' ], 10, 1 );

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
    //  SHARED SEND PIPELINE (v3.18.58, extended v3.18.60 for the kitchen
    //  alert — see the class docblock's v3.18.60 paragraph)
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
     *
     * v3.18.60: the credentials half of this check is now the shared
     * whatsapp_credentials_configured() (also used by the kitchen-alert
     * counterpart below) — purely an internal extraction, every input and
     * every logged message stays byte-for-byte identical to v3.18.58.
     */
    private function order_confirmed_feature_ready(): bool {
        if ( '1' !== get_option( 'dd_whatsapp_order_confirmed_enabled', '0' ) ) {
            error_log( 'DD_DIAG: WhatsApp order-confirmed send skipped — reason=disabled' );
            return false;
        }
        if ( ! $this->whatsapp_credentials_configured() ) {
            error_log( 'DD_DIAG: WhatsApp order-confirmed send skipped — reason=not_configured' );
            return false;
        }
        return true;
    }

    /**
     * v3.18.60 — kitchen-alert counterpart to order_confirmed_feature_ready(),
     * same shape, its own independent enabled switch
     * (dd_whatsapp_kitchen_alert_enabled). Checked both before scheduling and
     * again inside the cron handler, same reason as the customer path.
     */
    private function kitchen_alert_feature_ready(): bool {
        if ( '1' !== get_option( 'dd_whatsapp_kitchen_alert_enabled', '0' ) ) {
            error_log( 'DD_DIAG: WhatsApp kitchen-alert send skipped — reason=disabled' );
            return false;
        }
        if ( ! $this->whatsapp_credentials_configured() ) {
            error_log( 'DD_DIAG: WhatsApp kitchen-alert send skipped — reason=not_configured' );
            return false;
        }
        return true;
    }

    /** Shared credentials-configured check — see order_confirmed_feature_ready()'s v3.18.60 note. */
    private function whatsapp_credentials_configured(): bool {
        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-client.php';
        return ( new DD_WhatsApp_Client() )->is_configured();
    }

    /**
     * v3.18.60 — hook listener for the kitchen alert, independent of
     * maybe_schedule_order_confirmed() above (own add_action() registration
     * in init(), that one untouched). $old_status unused, same reasoning:
     * any path into 'confirmed' qualifies.
     */
    public function maybe_schedule_kitchen_alert( int $order_id, string $old_status, string $new_status ): void {
        if ( 'confirmed' !== $new_status ) {
            return;
        }
        if ( ! $this->kitchen_alert_feature_ready() ) {
            return;
        }
        wp_schedule_single_event( time(), 'dd_whatsapp_send_kitchen_alert', [ $order_id ] );
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
        $existing      = $this->find_whatsapp_log_row( $order_id, $template_name );

        if ( $existing && ! $this->row_is_retryable( $existing ) ) {
            return; // already sent, already delivered/read, or permanently failed/exhausted
        }

        $this->attempt_order_confirmed_send( $order, $template_name, $existing );
    }

    /**
     * v3.18.60 — kitchen-alert counterpart to send_order_confirmed(), same
     * shape. A missing/unnormalizable dd_whatsapp_kitchen number is a
     * silent skip (one log line, no row, no alert) — there's nothing an
     * alert could usefully say until a number is configured.
     */
    public function send_kitchen_alert( int $order_id ): void {
        if ( ! $this->kitchen_alert_feature_ready() ) {
            return;
        }

        global $wpdb;
        $order = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, order_number, order_type, special_instructions
             FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
            $order_id
        ) );
        if ( ! $order ) {
            error_log( 'DD_DIAG: WhatsApp kitchen-alert send aborted — order_id=' . $order_id . ' not found' );
            return;
        }

        $kitchen_raw = (string) get_option( 'dd_whatsapp_kitchen', '' );
        if ( '' === $this->normalize_phone( $kitchen_raw ) ) {
            error_log( 'DD_DIAG: WhatsApp kitchen-alert send skipped — reason=empty_or_invalid_kitchen_number' );
            return;
        }

        $template_name = (string) get_option( 'dd_whatsapp_kitchen_alert_template', 'dd_kitchen_new_order_v1' );
        $existing      = $this->find_whatsapp_log_row( $order_id, $template_name );

        if ( $existing && ! $this->row_is_retryable( $existing ) ) {
            return;
        }

        $this->attempt_kitchen_send( $order, $kitchen_raw, $template_name, $existing );
    }

    /**
     * Idempotency lookup — "an order gets at most one message per template,
     * even if the hook fires twice" is enforced here, by order_id +
     * template_name, not by wa_message_id (unknown before the first attempt
     * even starts). Generic as of v3.18.60 — shared by the customer and
     * kitchen sends (was find_order_confirmed_log_row(), renamed, no logic
     * changed).
     */
    private function find_whatsapp_log_row( int $order_id, string $template_name ): ?object {
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
     * are never retried. (Kitchen alerts additionally stop at a 10-minute
     * age cutoff — enforced in run_retry_sweep()'s own SQL, not here, since
     * that comparison must run on the DB server's clock; see that method.)
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
     * Thin wrapper — unchanged from v3.18.58 except its last line, which now
     * calls the shared attempt_whatsapp_send() instead of doing the
     * normalize/send/classify work inline. Still reads customer_phone and
     * builds the same 4 params in the same order (customer_name, order_
     * number, restaurant, total); the invalid-phone and empty-required-
     * parameter checks that used to live here are now done generically
     * inside attempt_whatsapp_send() with the identical error_detail text,
     * so behavior is unchanged — see the class docblock's Condition F note.
     */
    private function attempt_order_confirmed_send( object $order, string $template_name, ?object $existing ): void {
        $customer_name = $this->sanitize_param( (string) $order->customer_name, __( 'Customer', 'dish-dash' ) );
        $order_number  = $this->sanitize_param( (string) $order->order_number, '' );
        $restaurant    = $this->sanitize_param( (string) get_option( 'dish_dash_restaurant_name', 'Restaurant' ), 'Restaurant' );
        $total         = $this->sanitize_param( $this->format_total( (float) $order->total ), '' );

        $params        = [ $customer_name, $order_number, $restaurant, $total ];
        $language_code = (string) get_option( 'dd_whatsapp_order_confirmed_lang', 'en' );

        $this->attempt_whatsapp_send(
            $order, $template_name, $language_code, (string) $order->customer_phone,
            $params, $existing, false, 'customer'
        );
    }

    /**
     * v3.18.60 — kitchen-alert counterpart to attempt_order_confirmed_send().
     * Builds the 3 template params (order number, order type, items line)
     * and hands off to the same shared attempt_whatsapp_send(), $is_staff
     * true and $message_kind 'kitchen'.
     */
    private function attempt_kitchen_send( object $order, string $kitchen_raw, string $template_name, ?object $existing ): void {
        $order_number = $this->sanitize_param( (string) $order->order_number, '' );
        $order_type   = $this->sanitize_param( $this->format_order_type( (string) $order->order_type ), '' );
        $items_line   = $this->sanitize_param(
            $this->build_kitchen_items_line( (int) $order->id, (string) $order->special_instructions ),
            ''
        );

        $params        = [ $order_number, $order_type, $items_line ];
        $language_code = (string) get_option( 'dd_whatsapp_kitchen_alert_lang', 'en' );

        $this->attempt_whatsapp_send(
            $order, $template_name, $language_code, $kitchen_raw,
            $params, $existing, true, 'kitchen'
        );
    }

    /**
     * v3.18.60 — the generic send call, shared by the customer and kitchen
     * paths (was the back half of attempt_order_confirmed_send() in
     * v3.18.58). $to_raw is normalized here (not by the caller) so both
     * callers get identical invalid-phone/empty-parameter handling; $params
     * is the already-built, ordered list of template body parameters.
     */
    private function attempt_whatsapp_send(
        object $order,
        string $template_name,
        string $language_code,
        string $to_raw,
        array $params,
        ?object $existing,
        bool $is_staff,
        string $message_kind
    ): void {
        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-webhook.php';
        $webhook = new DD_WhatsApp_Webhook();

        // Best-effort digits for the conversation record even when the
        // number turns out unusable for sending — mirrors how the webhook's
        // own inbound handler extracts wa_number from Meta's 'from' field
        // (plain digit-strip, no stricter validation applied there either).
        $wa_number_digits = preg_replace( '/\D/', '', $to_raw );
        $to               = $this->normalize_phone( $to_raw );

        if ( '' === $to ) {
            $this->finish_whatsapp_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
                'success'      => false,
                'permanent'    => true,
                'error_detail' => 'permanent: invalid_phone',
            ], $is_staff, $message_kind );
            return;
        }

        foreach ( $params as $p ) {
            if ( '' === trim( (string) $p ) ) {
                // Shouldn't happen for a real order row — defensive, not
                // expected. Same error_detail text v3.18.58 used for this.
                $this->finish_whatsapp_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
                    'success'      => false,
                    'permanent'    => true,
                    'error_detail' => 'permanent: empty required parameter',
                ], $is_staff, $message_kind );
                return;
            }
        }

        $components = [ [
            'type'       => 'body',
            'parameters' => array_map( fn( $v ) => [ 'type' => 'text', 'text' => $v ], $params ),
        ] ];

        require_once DD_PLUGIN_DIR . 'modules/whatsapp/class-dd-whatsapp-client.php';
        $result = ( new DD_WhatsApp_Client() )->send_template( $to, $template_name, $language_code, $components );

        if ( $result['success'] ) {
            $this->finish_whatsapp_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
                'success'    => true,
                'message_id' => $result['message_id'],
                'params'     => $params,
            ], $is_staff, $message_kind );
            return;
        }

        $transient = $this->is_transient_failure( $result );
        $this->finish_whatsapp_attempt( $order, $template_name, $existing, $webhook, $wa_number_digits, [
            'success'      => false,
            'permanent'    => ! $transient,
            'error_detail' => ( $transient ? 'transient: ' : 'permanent: ' ) . ( $result['error'] ?? 'Unknown error' ),
        ], $is_staff, $message_kind );
    }

    /**
     * Writes (inserts or updates) the outbound message_log row for one
     * attempt, applies any parked orphan status update on success, bumps
     * retry_count and queues an alert on failure. Every timestamp here is
     * current_time('mysql'). v3.18.60: was finish_order_confirmed_attempt();
     * now generic (gained $is_staff/$message_kind), same logic otherwise.
     * $is_staff picks the conversation's display name + role; $message_kind
     * only reaches queue_failure_alert(), to keep the two alert emails'
     * wording and throttle windows independent.
     */
    private function finish_whatsapp_attempt(
        object $order,
        string $template_name,
        ?object $existing,
        DD_WhatsApp_Webhook $webhook,
        string $wa_number_digits,
        array $outcome,
        bool $is_staff,
        string $message_kind
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_message_log';
        $now   = current_time( 'mysql' );

        $conversation_id = $existing
            ? (int) $existing->conversation_id
            : $webhook->get_or_create_conversation(
                  $wa_number_digits,
                  $is_staff ? 'Kitchen' : (string) $order->customer_name,
                  $is_staff ? 'staff' : ''
              );

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

        $this->queue_failure_alert( $template_name, $now, $message_kind );
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
     * never picked up here, by construction of the WHERE clauses below.
     *
     * v3.18.60: no longer filters on one hardcoded template. Scans rows
     * whose template_name matches either of the two configured template-
     * name options (customer confirmed, kitchen alert) — a row with a null
     * or unknown template_name is never touched, since neither IN(...)
     * value can match it. Before anything else, any kitchen-alert row that
     * has been failing-transiently for more than 10 minutes is pulled out
     * and force-exhausted (alerted, not retried) — that age comparison runs
     * entirely inside MySQL via NOW(), never PHP's time()/current_time(),
     * because this host's PHP clock and DB clock differ by several hours
     * (Condition A) and a PHP-side comparison would misjudge the age.
     * Customer-confirmed rows keep their original count-only cap, no age
     * cutoff.
     */
    public function run_retry_sweep(): void {
        $lock_key = 'dd_whatsapp_retry_lock';
        if ( false !== get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, time(), 4 * MINUTE_IN_SECONDS );

        try {
            global $wpdb;
            $customer_template = (string) get_option( 'dd_whatsapp_order_confirmed_template', 'dd_order_confirmed_v1' );
            $kitchen_template  = (string) get_option( 'dd_whatsapp_kitchen_alert_template', 'dd_kitchen_new_order_v1' );

            // Kitchen rows older than 10 minutes (DB clock) — stop retrying,
            // alert instead. Runs regardless of the kitchen-alert enabled
            // switch: this is bookkeeping on already-queued failures, not a
            // new send attempt.
            $expired = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, error_detail
                 FROM {$wpdb->prefix}dishdash_message_log
                 WHERE direction = 'outbound' AND template_name = %s
                   AND status = 'failed' AND retry_count < 3
                   AND error_detail LIKE 'transient:%%'
                   AND created_at < NOW() - INTERVAL 10 MINUTE",
                $kitchen_template
            ) );
            foreach ( $expired as $row ) {
                $this->expire_retry_row( (int) $row->id, (string) $row->error_detail, $kitchen_template );
            }

            if ( ! $this->whatsapp_credentials_configured() ) {
                return; // credentials pulled mid-flight — nothing can send regardless of template
            }

            $customer_ready = $this->order_confirmed_feature_ready();
            $kitchen_ready  = $this->kitchen_alert_feature_ready();
            if ( ! $customer_ready && ! $kitchen_ready ) {
                return; // both switched off
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_id, conversation_id, template_name, status, retry_count, error_detail
                 FROM {$wpdb->prefix}dishdash_message_log
                 WHERE direction = 'outbound'
                   AND template_name IN (%s, %s)
                   AND status = 'failed' AND retry_count < 3
                   AND error_detail LIKE 'transient:%%'
                   AND ( template_name != %s OR created_at >= NOW() - INTERVAL 10 MINUTE )
                 ORDER BY status_at ASC
                 LIMIT 20",
                $customer_template, $kitchen_template, $kitchen_template
            ) );

            foreach ( $rows as $row ) {
                $is_kitchen_row = ( $row->template_name === $kitchen_template );
                if ( $is_kitchen_row && ! $kitchen_ready ) {
                    continue;
                }
                if ( ! $is_kitchen_row && ! $customer_ready ) {
                    continue;
                }

                if ( $is_kitchen_row ) {
                    $order = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, order_number, order_type, special_instructions
                         FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
                        (int) $row->order_id
                    ) );
                    if ( ! $order ) {
                        continue; // order no longer exists — nothing sane to retry
                    }
                    $kitchen_raw = (string) get_option( 'dd_whatsapp_kitchen', '' );
                    if ( '' === $this->normalize_phone( $kitchen_raw ) ) {
                        continue; // number was cleared since the first attempt
                    }
                    $this->attempt_kitchen_send( $order, $kitchen_raw, $row->template_name, $row );
                } else {
                    $order = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, order_number, customer_name, customer_phone, total
                         FROM {$wpdb->prefix}dishdash_orders WHERE id = %d",
                        (int) $row->order_id
                    ) );
                    if ( ! $order ) {
                        continue;
                    }
                    $this->attempt_order_confirmed_send( $order, $row->template_name, $row );
                }
            }
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * v3.18.60 — forces a stale kitchen-alert row (transient-failed for
     * more than 10 minutes, per run_retry_sweep()'s own DB-clock check) into
     * the same 'permanent: exhausted...' shape the 3-attempt cap already
     * uses elsewhere, then queues the failure alert exactly as a normal
     * failed attempt would — this path just never calls Meta again first.
     */
    private function expire_retry_row( int $row_id, string $error_detail, string $template_name ): void {
        global $wpdb;
        $now    = current_time( 'mysql' );
        $reason = 'permanent: exhausted retries after 10 minutes — ' . substr( $error_detail, strlen( 'transient: ' ) );

        $wpdb->update(
            $wpdb->prefix . 'dishdash_message_log',
            [ 'status' => 'failed', 'error_detail' => $reason, 'status_at' => $now ],
            [ 'id' => $row_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        $this->queue_failure_alert( $template_name, $now, 'kitchen' );
    }

    /**
     * One email per hour, listing every send of this message kind that
     * failed since that kind's own previous alert — order number, phone on
     * the order, reason, time. Never includes a token or secret: error_
     * detail only ever contains this module's own 'transient:'/'permanent:'
     * label plus whatever DD_WhatsApp_Client::send_template() returned,
     * which never echoes credentials into any error it returns (confirmed
     * by reading that method — it only ever surfaces Meta's error.message
     * or a WP_Error message, never $this->token/$this->phone_number_id).
     *
     * v3.18.60: gained $message_kind ('customer'|'kitchen'). Wording is now
     * generic, built from a label per kind — for 'customer' the label
     * ('order-confirmed') reproduces v3.18.58's exact subject/body text, so
     * the customer alert email is unchanged. Customer and kitchen alerts
     * use separate throttle transients and separate "since" options, so one
     * kind's alert cadence never suppresses or advances the other's.
     */
    private const MESSAGE_KIND_LABELS = [
        'customer' => 'order-confirmed',
        'kitchen'  => 'kitchen-alert',
    ];

    private function queue_failure_alert( string $template_name, string $now, string $message_kind ): void {
        $label        = self::MESSAGE_KIND_LABELS[ $message_kind ] ?? $message_kind;
        $throttle_key = 'kitchen' === $message_kind ? 'dd_whatsapp_kitchen_alert_throttle' : 'dd_whatsapp_alert_throttle';
        $option_key   = 'kitchen' === $message_kind ? 'dd_whatsapp_kitchen_last_alert_at' : 'dd_whatsapp_last_alert_at';

        if ( false !== get_transient( $throttle_key ) ) {
            return; // an alert of this kind already went out within the last hour
        }

        global $wpdb;
        $since = (string) get_option( $option_key, '1970-01-01 00:00:00' );

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
                /* translators: 1: number of failed sends in this alert, 2: message kind label */
                __( '[Dish Dash] %1$d WhatsApp %2$s message(s) failed', 'dish-dash' ),
                count( $failures ), $label
            ),
            sprintf(
                /* translators: %s: message kind label */
                __( 'The following %s WhatsApp messages failed to send:', 'dish-dash' ), $label
            ) . "\n\n" . implode( "\n", $lines )
        );

        set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
        update_option( $option_key, $now, false );
    }

    /**
     * v3.18.60 — "dine_in" → "Dine in". Literal per the brief: underscores
     * to spaces, then ucfirst (not ucwords) — flagged in this release's
     * report that the live order_type ENUM actually uses hyphens
     * ('dine-in'), not underscores, so that specific value passes through
     * with only its first letter capitalized ("Dine-in") rather than being
     * reworded; worth confirming against the brief's intent before Meta
     * review if that reads oddly in the approved template.
     */
    private function format_order_type( string $raw ): string {
        return ucfirst( str_replace( '_', ' ', $raw ) );
    }

    /**
     * v3.18.60 — builds the kitchen template's 3rd parameter: "{qty}x
     * {item_name} (variation/addon values)" per item, comma-joined, with
     * every non-empty note (each item's own special_note, plus the order's
     * own special_instructions) collected into one trailing "| Note: ..."
     * segment. Decodes dishdash_order_items.variation/addons JSON locally
     * (own small decode below) rather than calling into the orders module's
     * class or DD_Notifications — same cross-module-read-only pattern this
     * file already uses for dishdash_orders/dishdash_customers, just against
     * dishdash_order_items this time.
     */
    private function build_kitchen_items_line( int $order_id, string $order_instructions ): string {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_name, quantity, variation, addons, special_note
             FROM {$wpdb->prefix}dishdash_order_items WHERE order_id = %d ORDER BY id ASC",
            $order_id
        ) );

        $item_parts = [];
        $notes      = [];

        foreach ( $rows as $row ) {
            $name = $this->collapse_whitespace( (string) $row->item_name );
            $qty  = (int) $row->quantity;
            $tags = array_merge(
                $this->decode_variation_values( (string) $row->variation ),
                $this->decode_addon_values( (string) $row->addons )
            );

            $part = $qty . 'x ' . $name;
            if ( $tags ) {
                $part .= ' (' . implode( ', ', $tags ) . ')';
            }
            $item_parts[] = $part;

            $note = $this->collapse_whitespace( stripslashes( (string) $row->special_note ) );
            if ( '' !== $note ) {
                $notes[] = $note;
            }
        }

        $order_note = $this->collapse_whitespace( stripslashes( $order_instructions ) );
        if ( '' !== $order_note ) {
            $notes[] = $order_note;
        }

        $line = implode( ', ', $item_parts );
        if ( $notes ) {
            $line .= ' | Note: ' . implode( '; ', $notes );
        }

        return $this->truncate_param( $line, 300 );
    }

    /**
     * Variation JSON is an attribute-name => value map (e.g.
     * {"Size":"Large"}) — same shape DD_Notifications::variation_lines()
     * reads, but that helper returns "key: value" lines; the kitchen
     * message wants values only (e.g. "Large"), per the brief, so this is
     * its own small decode rather than reusing/wrapping that other module's
     * helper.
     */
    private function decode_variation_values( string $raw ): array {
        if ( '' === trim( $raw ) || '{}' === trim( $raw ) ) {
            return [];
        }
        $decoded = json_decode( stripslashes( $raw ), true );
        if ( ! is_array( $decoded ) || empty( $decoded ) ) {
            return [];
        }
        return array_values( array_map(
            fn( $v ) => $this->collapse_whitespace( (string) $v ),
            $decoded
        ) );
    }

    /**
     * Addons JSON shape varies by entry (an array of {name, price} objects
     * is what insert_order_items() actually writes; some existing readers
     * in this codebase instead assume a name => value map) — this decode
     * accepts either: an object/array entry contributes its 'name' (falling
     * back to its first value if 'name' is absent), a plain scalar entry
     * keyed by a string contributes that key. Empty/placeholder JSON
     * ('{}', '[]', blank) returns no tags.
     */
    private function decode_addon_values( string $raw ): array {
        if ( '' === trim( $raw ) || '{}' === trim( $raw ) || '[]' === trim( $raw ) ) {
            return [];
        }
        $decoded = json_decode( stripslashes( $raw ), true );
        if ( ! is_array( $decoded ) || empty( $decoded ) ) {
            return [];
        }

        $values = [];
        foreach ( $decoded as $key => $entry ) {
            if ( is_array( $entry ) ) {
                $values[] = $entry['name'] ?? ( reset( $entry ) ?: '' );
            } elseif ( is_string( $key ) ) {
                $values[] = $key;
            } else {
                $values[] = $entry;
            }
        }

        $values = array_filter( $values, fn( $v ) => '' !== trim( (string) $v ) );
        return array_values( array_map( fn( $v ) => $this->collapse_whitespace( (string) $v ), $values ) );
    }

    /**
     * Condition C: Meta rejects a template parameter containing a line
     * break, a tab, or a run of more than four consecutive spaces — collapse
     * all three to single spaces before any truncation, on every piece
     * (item name, variation value, addon value, note) individually, so a
     * collapsed boundary between two pieces can never reintroduce a run
     * long enough to violate that rule once joined.
     */
    private function collapse_whitespace( string $s ): string {
        return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $s ) );
    }

    /**
     * Truncates to at most $max characters total (ellipsis included), and
     * never returns an empty string — Meta rejects an empty parameter, so a
     * line with zero items (shouldn't happen for a real order) falls back
     * to a visible placeholder instead of sending blank.
     */
    private function truncate_param( string $s, int $max ): string {
        $s = trim( $s );
        if ( '' === $s ) {
            return __( '(no items)', 'dish-dash' );
        }
        if ( mb_strlen( $s ) <= $max ) {
            return $s;
        }
        return mb_substr( $s, 0, $max - 1 ) . '…';
    }
}
