<?php
/**
 * File:    modules/whatsapp/class-dd-whatsapp-webhook.php
 * Class:   DD_WhatsApp_Webhook
 * Purpose: Phase 9A Step 3 — the public Meta webhook endpoint. Answers
 *          Meta's GET verification handshake, verifies the
 *          X-Hub-Signature-256 header on POST events against this install's
 *          own app secret, and stores inbound messages / status updates
 *          idempotently into dishdash_wa_conversations / dishdash_message_log.
 *
 * Registered as a REST route (dish-dash/v1/whatsapp-webhook) rather than the
 * wc-api pattern PesaPal's IPN uses — this is the first genuinely public,
 * unauthenticated route in the codebase (permission_callback => '__return_true'):
 * Meta's own GET/POST calls carry no WordPress auth, only the verify token /
 * signature header this class checks itself. Endpoint URL for Meta's
 * dashboard: https://{site}/wp-json/dish-dash/v1/whatsapp-webhook
 *
 * Fires do_action('dd_whatsapp_message_received', $conversation_id, $log_id)
 * on every stored inbound message, so Phase 9E's inbox module (not built
 * yet) can listen without this module depending on it — zero cross-module
 * dependencies, per the architecture rule. The one cross-table read here
 * (matching dishdash_customers.whatsapp) is a SELECT, never a write to
 * another module's table.
 *
 * Instantiated by DD_WhatsApp_Module::init() — not a DD_Module itself, since
 * it owns no admin page or settings of its own.
 *
 * Last modified: v3.18.51
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_WhatsApp_Webhook {

    public function register_routes(): void {
        register_rest_route( 'dish-dash/v1', '/whatsapp-webhook', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'handle_request' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        return 'GET' === $request->get_method()
            ? $this->handle_verify( $request )
            : $this->handle_event( $request );
    }

    // ── GET: Meta's webhook verification handshake ──────────────────────────
    private function handle_verify( WP_REST_Request $request ): WP_REST_Response {
        // PHP converts dots in query-string keys to underscores when
        // populating superglobals, so hub.mode/hub.verify_token/hub.challenge
        // arrive as hub_mode/hub_verify_token/hub_challenge — standard PHP
        // behavior, not specific to this code.
        $mode         = (string) $request->get_param( 'hub_mode' );
        $verify_token = trim( (string) $request->get_param( 'hub_verify_token' ) );
        $challenge    = (string) $request->get_param( 'hub_challenge' );

        // Trim the stored side too — sanitize_text_field() on the settings
        // save already trims, but trimming again here keeps both sides of
        // the comparison symmetric regardless of how the option got set.
        $stored_token = trim( (string) get_option( 'dd_whatsapp_verify_token', '' ) );

        if ( '' === $stored_token ) {
            error_log( 'DD_DIAG: WhatsApp webhook verify failed — reason=no_stored_token' );
            return new WP_REST_Response( [ 'error' => 'Verification failed.' ], 403 );
        }

        if ( 'subscribe' !== $mode ) {
            error_log( 'DD_DIAG: WhatsApp webhook verify failed — reason=bad_mode' );
            return new WP_REST_Response( [ 'error' => 'Verification failed.' ], 403 );
        }

        if ( ! hash_equals( $stored_token, $verify_token ) ) {
            // Never log the token values — only their lengths, which is
            // enough to spot a whitespace or truncation mismatch.
            error_log( sprintf(
                'DD_DIAG: WhatsApp webhook verify failed — reason=token_mismatch stored_len=%d received_len=%d',
                strlen( $stored_token ),
                strlen( $verify_token )
            ) );
            return new WP_REST_Response( [ 'error' => 'Verification failed.' ], 403 );
        }

        // Meta expects the raw challenge value as the body. Returning an
        // int here JSON-encodes unquoted (wp_json_encode(12345) ===
        // "12345"), byte-identical to the raw text Meta compares against.
        return new WP_REST_Response( (int) $challenge, 200 );
    }

    // ── POST: inbound messages + status updates ─────────────────────────────
    private function handle_event( WP_REST_Request $request ): WP_REST_Response {
        $app_secret = get_option( 'dd_whatsapp_app_secret', '' );
        $signature  = (string) $request->get_header( 'x-hub-signature-256' );
        $raw_body   = $request->get_body();

        if ( '' === $app_secret || '' === $signature || ! $this->signature_matches( $raw_body, $signature, $app_secret ) ) {
            error_log( 'DD_DIAG: WhatsApp webhook rejected — signature check failed' );
            return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 403 );
        }

        $payload = json_decode( $raw_body, true );
        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload.' ], 400 );
        }

        try {
            $this->process_payload( $payload );
        } catch ( Throwable $e ) {
            // Signature already verified — this is a genuine Meta event, so
            // acknowledge it (200) rather than let Meta retry-storm us for an
            // application bug; the error is still visible in the log.
            error_log( 'DD_DIAG: WhatsApp webhook processing error — ' . $e->getMessage() );
        }

        return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    private function signature_matches( string $raw_body, string $signature_header, string $app_secret ): bool {
        $expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $app_secret );
        return hash_equals( $expected, $signature_header );
    }

    private function process_payload( array $payload ): void {
        foreach ( (array) ( $payload['entry'] ?? [] ) as $entry ) {
            foreach ( (array) ( $entry['changes'] ?? [] ) as $change ) {
                $value    = (array) ( $change['value'] ?? [] );
                $contacts = (array) ( $value['contacts'] ?? [] );

                foreach ( (array) ( $value['messages'] ?? [] ) as $message ) {
                    $this->handle_inbound_message( (array) $message, $contacts );
                }
                foreach ( (array) ( $value['statuses'] ?? [] ) as $status ) {
                    $this->apply_status_update( (array) $status );
                }
            }
        }
    }

    // ── Inbound messages ─────────────────────────────────────────────────────
    private function handle_inbound_message( array $message, array $contacts ): void {
        $from      = (string) ( $message['from'] ?? '' );
        $wa_number = preg_replace( '/\D/', '', $from );
        if ( ! $wa_number ) {
            return;
        }

        $profile_name = '';
        foreach ( $contacts as $contact ) {
            if ( ( $contact['wa_id'] ?? '' ) === $from ) {
                $profile_name = sanitize_text_field( (string) ( $contact['profile']['name'] ?? '' ) );
                break;
            }
        }

        $conversation_id = $this->get_or_create_conversation( $wa_number, $profile_name );
        if ( ! $conversation_id ) {
            return;
        }

        $type = sanitize_text_field( (string) ( $message['type'] ?? 'text' ) );
        $body = 'text' === $type
            ? sanitize_textarea_field( (string) ( $message['text']['body'] ?? '' ) )
            : '[' . $type . ']'; // media placeholder, per the data model

        $log_id = $this->insert_message_log( [
            'conversation_id' => $conversation_id,
            'direction'       => 'inbound',
            'wa_message_id'   => sanitize_text_field( (string) ( $message['id'] ?? '' ) ),
            'message_type'    => $type,
            'body'            => $body,
            'status'          => 'received',
            'created_at'      => current_time( 'mysql' ),
        ] );

        if ( ! $log_id ) {
            return;
        }

        $this->touch_conversation_on_inbound( $conversation_id, $body, $profile_name );

        do_action( 'dd_whatsapp_message_received', $conversation_id, $log_id );
    }

    private function touch_conversation_on_inbound( int $conversation_id, string $preview, string $profile_name ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_wa_conversations';
        $now   = current_time( 'mysql' );

        if ( '' !== $profile_name ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET last_message_at = %s, last_inbound_at = %s, last_message_preview = %s,
                     unread_count = unread_count + 1, customer_name = %s
                 WHERE id = %d",
                $now, $now, $preview, $profile_name, $conversation_id
            ) );
        } else {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET last_message_at = %s, last_inbound_at = %s, last_message_preview = %s,
                     unread_count = unread_count + 1
                 WHERE id = %d",
                $now, $now, $preview, $conversation_id
            ) );
        }
    }

    // ── Conversation lookup / create ─────────────────────────────────────────
    private function get_or_create_conversation( string $wa_number, string $profile_name ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_wa_conversations';

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE wa_number = %s", $wa_number
        ) );
        if ( $id ) {
            return $id;
        }

        $customer_id = $this->resolve_customer_id( $wa_number );

        $inserted = $wpdb->insert( $table, [
            'wa_number'       => $wa_number,
            'customer_id'     => $customer_id ?: null,
            'customer_name'   => $profile_name,
            'last_message_at' => current_time( 'mysql' ),
        ], [ '%s', '%d', '%s', '%s' ] );

        if ( false === $inserted ) {
            // Lost a race against a concurrent webhook delivery for the same
            // number (e.g. a near-simultaneous redelivery) — the UNIQUE KEY
            // on wa_number means another call already created the row.
            if ( strpos( (string) $wpdb->last_error, 'Duplicate entry' ) !== false ) {
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE wa_number = %s", $wa_number
                ) );
            }
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Match against dishdash_customers.whatsapp — stripping '+' so a stored
     * "+250788123456" matches Meta's own digits-only "250788123456" (the
     * stored format isn't consistent across this codebase's various
     * WhatsApp fields — see investigation-whatsapp-cloud-api.md §3).
     */
    private function resolve_customer_id( string $wa_number ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dishdash_customers
             WHERE REPLACE(whatsapp, '+', '') = %s LIMIT 1",
            $wa_number
        ) );
    }

    // ── Idempotent message-log insert ────────────────────────────────────────
    // Explicit per-column formats rather than relying on $wpdb->insert()'s
    // type inference — conversation_id must always bind as %d regardless of
    // which columns a given caller includes (this class only inserts the
    // inbound shape today; Step 4's outbound sends will reuse this with a
    // different column subset).
    private const MESSAGE_LOG_FORMATS = [
        'conversation_id' => '%d',
        'direction'       => '%s',
        'wa_message_id'   => '%s',
        'message_type'    => '%s',
        'body'            => '%s',
        'template_name'   => '%s',
        'template_params' => '%s',
        'status'          => '%s',
        'error_detail'    => '%s',
        'created_at'      => '%s',
        'status_at'       => '%s',
    ];

    private function insert_message_log( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_message_log';

        if ( ! empty( $data['wa_message_id'] ) ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE wa_message_id = %s", $data['wa_message_id']
            ) );
            if ( $existing ) {
                return $existing; // already stored — a redelivered webhook is a no-op
            }
        }

        $format   = array_map( fn( $key ) => self::MESSAGE_LOG_FORMATS[ $key ] ?? '%s', array_keys( $data ) );
        $inserted = $wpdb->insert( $table, $data, $format );

        if ( false === $inserted ) {
            if ( ! empty( $data['wa_message_id'] ) && strpos( (string) $wpdb->last_error, 'Duplicate entry' ) !== false ) {
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE wa_message_id = %s", $data['wa_message_id']
                ) );
            }
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    // ── Status updates (sent/delivered/read/failed) ──────────────────────────
    private function apply_status_update( array $status ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dishdash_message_log';

        $wa_message_id = sanitize_text_field( (string) ( $status['id'] ?? '' ) );
        $new_status    = sanitize_text_field( (string) ( $status['status'] ?? '' ) );

        $rank     = [ 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4 ];
        $new_rank = $rank[ $new_status ] ?? 0;

        if ( '' === $wa_message_id || ! $new_rank ) {
            return;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE wa_message_id = %s", $wa_message_id
        ) );

        if ( ! $row ) {
            // The status webhook beat our own send call's HTTP response back
            // to us — park it rather than drop it. Step 4's send path must
            // check get_transient('dd_wa_orphan_status_' . $wa_message_id)
            // immediately after inserting a new outbound row with this id.
            set_transient( 'dd_wa_orphan_status_' . $wa_message_id, $status, DAY_IN_SECONDS );
            return;
        }

        $current_rank = $rank[ $row->status ] ?? 0;
        if ( $new_rank <= $current_rank ) {
            return; // never move backward; a replayed identical status is a no-op too
        }

        $update = [ 'status' => $new_status, 'status_at' => current_time( 'mysql' ) ];
        $format = [ '%s', '%s' ];

        if ( 'failed' === $new_status && ! empty( $status['errors'][0]['title'] ) ) {
            $update['error_detail'] = sanitize_text_field( (string) $status['errors'][0]['title'] );
            $format[]               = '%s';
        }

        $wpdb->update( $table, $update, [ 'id' => (int) $row->id ], $format, [ '%d' ] );
    }
}
