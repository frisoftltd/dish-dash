<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DD_WhatsApp_Client — WhatsApp Cloud API client.
 * IMPORTANT: This class makes NO HTTP calls on instantiation.
 * All API calls happen only when send_template() is called.
 * Shaped after DD_PesaPal (modules/payments/class-dd-pesapal.php) — same
 * "credentials read in the constructor, plain array returns, no exceptions"
 * contract.
 */
class DD_WhatsApp_Client {

    // Single Graph API version constant — per Phase 9A brief, bump only here
    // when Meta retires the current version.
    const GRAPH_API_VERSION = 'v21.0';

    private string $phone_number_id;
    private string $token;

    public function __construct() {
        $this->phone_number_id = get_option( 'dd_whatsapp_phone_number_id', '' );
        $this->token            = get_option( 'dd_whatsapp_token', '' );
    }

    public function is_configured(): bool {
        return ! empty( $this->phone_number_id ) && ! empty( $this->token );
    }

    /**
     * Send an approved template message.
     *
     * @param  string $to            Recipient, international digits-only (no leading +).
     * @param  string $template_name Exact Meta-approved template name.
     * @param  string $language_code Template's approved language code, e.g. 'en_US'.
     * @param  array  $components    Optional Graph API "components" array for template parameters.
     * @return array  { success: bool, message_id?: string, error?: string }
     */
    public function send_template(
        string $to,
        string $template_name,
        string $language_code = 'en_US',
        array  $components = []
    ): array {
        if ( ! $this->is_configured() ) {
            return [ 'success' => false, 'error' => 'WhatsApp is not configured.' ];
        }

        $template = [
            'name'     => $template_name,
            'language' => [ 'code' => $language_code ],
        ];
        if ( ! empty( $components ) ) {
            $template['components'] = $components;
        }

        $response = wp_remote_post(
            $this->graph_url( $this->phone_number_id . '/messages' ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to,
                    'type'              => 'template',
                    'template'          => $template,
                ] ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( ! empty( $body->error ) ) {
            return [
                'success' => false,
                'error'   => $body->error->message ?? 'WhatsApp API error.',
            ];
        }

        $message_id = $body->messages[0]->id ?? '';
        if ( ! $message_id ) {
            return [ 'success' => false, 'error' => 'WhatsApp API returned no message id.' ];
        }

        return [ 'success' => true, 'message_id' => $message_id ];
    }

    private function graph_url( string $path ): string {
        return 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/' . $path;
    }
}
