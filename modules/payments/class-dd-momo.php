<?php
/**
 * File:    modules/payments/class-dd-momo.php
 * Module:  DD_MoMo (utility class — does NOT extend DD_Module)
 * Purpose: Thin wrapper around the MTN MoMo Collections API v1.
 *          Reads credentials from the mtn-momo-woocommerce plugin settings.
 *          Used by DD_Orders_Module to initiate and poll in-drawer payments.
 *
 * Public methods:
 *   is_configured() — true when all three credentials are set
 *   request_to_pay( phone, amount, order_id ) — initiates a USSD payment request
 *   get_status( reference_id ) — polls for SUCCESSFUL / FAILED / PENDING
 *   generate_uuid() — RFC-4122 v4 UUID for X-Reference-Id header
 *
 * Credentials source: woocommerce_mtn_momo_settings wp_option
 *
 * Last modified: v3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DD_MoMo {

    private string $subscription_key;
    private string $api_user;
    private string $api_key;
    private string $environment;
    private string $currency;

    public function __construct() {
        $settings               = get_option( 'woocommerce_mtn_momo_settings', [] );
        $this->subscription_key = $settings['subscription_key'] ?? '';
        $this->api_user         = $settings['api_user']         ?? '';
        $this->api_key          = $settings['api_key']          ?? '';
        $this->environment      = $settings['environment']      ?? 'sandbox';
        $this->currency         = $settings['currency']         ?? 'RWF';
    }

    public function is_configured(): bool {
        return ! empty( $this->subscription_key )
            && ! empty( $this->api_user )
            && ! empty( $this->api_key );
    }

    private function base_url(): string {
        return $this->environment === 'production'
            ? 'https://proxy.momoapi.mtn.com'
            : 'https://sandbox.momodeveloper.mtn.com';
    }

    private function get_access_token(): string|false {
        $credentials = base64_encode( $this->api_user . ':' . $this->api_key );
        $response    = wp_remote_post(
            $this->base_url() . '/collection/token/',
            [
                'headers' => [
                    'Authorization'             => 'Basic ' . $credentials,
                    'Ocp-Apim-Subscription-Key' => $this->subscription_key,
                ],
                'body'    => '',
                'timeout' => 15,
            ]
        );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['access_token'] ?? false;
    }

    public function generate_uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }

    /**
     * Initiate a request-to-pay.
     *
     * @param string $phone    Raw phone number (normalised internally to 250XXXXXXXXX)
     * @param float  $amount   Order total in RWF
     * @param string $order_id DD order ID used as externalId
     * @return array{ success: bool, reference_id?: string, error?: string }
     */
    public function request_to_pay( string $phone, float $amount, string $order_id ): array {
        $token = $this->get_access_token();
        if ( ! $token ) {
            return [ 'success' => false, 'error' => 'Could not get MoMo access token.' ];
        }

        $reference_id = $this->generate_uuid();

        // Normalise phone: strip formatting, ensure 250XXXXXXXXX
        $phone = preg_replace( '/[\s\-\(\)\+]/', '', $phone );
        if ( strlen( $phone ) === 10 && $phone[0] === '0' ) {
            $phone = '250' . substr( $phone, 1 );
        } elseif ( strlen( $phone ) === 9 ) {
            $phone = '250' . $phone;
        }

        $response = wp_remote_post(
            $this->base_url() . '/collection/v1_0/requesttopay',
            [
                'headers' => [
                    'Authorization'             => 'Bearer ' . $token,
                    'X-Reference-Id'            => $reference_id,
                    'X-Target-Environment'      => $this->environment,
                    'Ocp-Apim-Subscription-Key' => $this->subscription_key,
                    'Content-Type'              => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'amount'       => (string) intval( $amount ),
                    'currency'     => $this->currency,
                    'externalId'   => $order_id,
                    'payer'        => [ 'partyIdType' => 'MSISDN', 'partyId' => $phone ],
                    'payerMessage' => 'Order #' . $order_id,
                    'payeeNote'    => 'Dish Dash order #' . $order_id,
                ] ),
                'timeout' => 20,
            ]
        );

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 202 || $code === 200 ) {
            return [ 'success' => true, 'reference_id' => $reference_id ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return [
            'success' => false,
            'error'   => $body['message'] ?? 'MoMo request failed (HTTP ' . $code . ')',
        ];
    }

    /**
     * Check payment status by reference ID.
     *
     * @return string One of: SUCCESSFUL, FAILED, REJECTED, TIMEOUT, PENDING
     */
    public function get_status( string $reference_id ): string {
        $token = $this->get_access_token();
        if ( ! $token ) {
            return 'PENDING';
        }

        $response = wp_remote_get(
            $this->base_url() . '/collection/v1_0/requesttopay/' . $reference_id,
            [
                'headers' => [
                    'Authorization'             => 'Bearer ' . $token,
                    'X-Target-Environment'      => $this->environment,
                    'Ocp-Apim-Subscription-Key' => $this->subscription_key,
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return 'PENDING';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['status'] ?? 'PENDING';
    }
}
