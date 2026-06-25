<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DD_PesaPal — PesaPal v3 API client.
 * IMPORTANT: This class makes NO HTTP calls on instantiation.
 * All API calls happen only when submit_order() or get_transaction_status() are called.
 */
class DD_PesaPal {

    private string $consumer_key;
    private string $secret_key;
    private string $base_url;

    public function __construct() {
        $settings           = get_option( 'woocommerce_pesapal_settings', [] );
        $test_mode          = ( $settings['testmode'] ?? 'no' ) === 'yes';
        $this->consumer_key = $test_mode
            ? ( $settings['testconsumerkey'] ?? '' )
            : ( $settings['consumerkey']     ?? '' );
        $this->secret_key   = $test_mode
            ? ( $settings['testsecretkey'] ?? '' )
            : ( $settings['secretkey']     ?? '' );
        $this->base_url     = $test_mode
            ? 'https://cybqa.pesapal.com/pesapalv3'
            : 'https://pay.pesapal.com/v3';
    }

    public function is_configured(): bool {
        return ! empty( $this->consumer_key ) && ! empty( $this->secret_key );
    }

    private function get_access_token(): string|false {
        $response = wp_remote_post( $this->base_url . '/api/Auth/RequestToken', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'consumer_key'    => $this->consumer_key,
                'consumer_secret' => $this->secret_key,
            ] ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ) );
        return $body->token ?? false;
    }

    private function get_or_register_ipn( string $token ): string|false {
        $callback_url = home_url( '/wc-api/wc_pesapal_gateway/' );

        // Check existing IPN list first
        $response = wp_remote_get( $this->base_url . '/api/URLSetup/GetIpnList', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 15,
        ] );
        if ( ! is_wp_error( $response ) ) {
            $list = json_decode( wp_remote_retrieve_body( $response ) );
            if ( is_array( $list ) ) {
                foreach ( $list as $ipn ) {
                    if ( isset( $ipn->url ) && $ipn->url === $callback_url ) {
                        return $ipn->ipn_id;
                    }
                }
            }
        }

        // Register new IPN
        $reg = wp_remote_post( $this->base_url . '/api/URLSetup/RegisterIPN', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'url'                   => $callback_url,
                'ipn_notification_type' => 'GET',
            ] ),
            'timeout' => 15,
        ] );
        if ( is_wp_error( $reg ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $reg ) );
        return $body->ipn_id ?? false;
    }

    public function submit_order(
        float  $amount,
        string $currency,
        string $ref,
        string $description,
        string $phone,
        string $first_name
    ): array {
        $token = $this->get_access_token();
        if ( ! $token ) return [ 'success' => false, 'error' => 'PesaPal authentication failed.' ];

        $ipn_id = $this->get_or_register_ipn( $token );
        if ( ! $ipn_id ) return [ 'success' => false, 'error' => 'PesaPal IPN registration failed.' ];

        $response = wp_remote_post( $this->base_url . '/api/Transactions/SubmitOrderRequest', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode( [
                'id'              => $ref,
                'currency'        => $currency,
                'amount'          => $amount,
                'description'     => $description,
                'callback_url'    => home_url( '/wc-api/wc_pesapal_gateway/' ),
                'notification_id' => $ipn_id,
                'billing_address' => [
                    'phone_number' => $phone,
                    'first_name'   => $first_name,
                    'last_name'    => '',
                    'country_code' => 'RW',
                ],
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $body->redirect_url ) ) {
            return [
                'success' => false,
                'error'   => $body->error->message ?? 'PesaPal order submission failed.',
            ];
        }

        return [
            'success'           => true,
            'redirect_url'      => $body->redirect_url,
            'order_tracking_id' => $body->order_tracking_id,
        ];
    }

    public function get_transaction_status( string $order_tracking_id ): string {
        $token = $this->get_access_token();
        if ( ! $token ) return 'UNKNOWN';

        $response = wp_remote_get(
            $this->base_url . '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode( $order_tracking_id ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) return 'UNKNOWN';
        $body = json_decode( wp_remote_retrieve_body( $response ) );
        return strtoupper( $body->payment_status_description ?? 'PENDING' );
    }
}
