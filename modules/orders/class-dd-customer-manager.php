<?php
/**
 * DD_Customer_Manager
 *
 * Handles WhatsApp-based customer identity:
 * - Upsert on every order (create or update stats)
 * - First-order detection for birthday flow
 * - Token generation, validation, and birthday saving
 *
 * WhatsApp number is the primary customer identity in DishDash.
 *
 * @package DishDash
 * @since   3.2.54
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Customer_Manager {

    /**
     * Upsert customer on every order.
     *
     * @return array { customer_id: int, is_first_order: bool }
     */
    public static function upsert(
        string $whatsapp,
        string $name,
        string $delivery_address,
        float  $order_total
    ): array {
        global $wpdb;

        $whatsapp = self::normalize_phone( $whatsapp );
        if ( ! $whatsapp ) {
            return [ 'customer_id' => 0, 'is_first_order' => false ];
        }

        $table    = $wpdb->prefix . 'dishdash_customers';
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, total_orders, total_spent FROM {$table} WHERE whatsapp = %s LIMIT 1",
            $whatsapp
        ) );

        $now = current_time( 'mysql' );

        if ( $existing ) {
            $wpdb->update(
                $table,
                [
                    'name'             => $name,
                    'delivery_address' => $delivery_address,
                    'total_orders'     => (int) $existing->total_orders + 1,
                    'total_spent'      => (float) $existing->total_spent + $order_total,
                    'last_order_at'    => $now,
                ],
                [ 'id' => (int) $existing->id ],
                [ '%s', '%s', '%d', '%f', '%s' ],
                [ '%d' ]
            );
            return [
                'customer_id'    => (int) $existing->id,
                'is_first_order' => false,
            ];
        }

        $wpdb->insert(
            $table,
            [
                'whatsapp'         => $whatsapp,
                'name'             => $name,
                'delivery_address' => $delivery_address,
                'total_orders'     => 1,
                'total_spent'      => $order_total,
                'first_order_at'   => $now,
                'last_order_at'    => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%f', '%s', '%s' ]
        );

        return [
            'customer_id'    => (int) $wpdb->insert_id,
            'is_first_order' => true,
        ];
    }

    /**
     * Generate a unique single-use birthday token (expires 30 days).
     */
    public static function generate_birthday_token( int $customer_id ): string {
        global $wpdb;

        $token   = bin2hex( random_bytes( 32 ) );
        $expires = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

        $wpdb->insert(
            $wpdb->prefix . 'dishdash_birthday_tokens',
            [
                'token'       => $token,
                'customer_id' => $customer_id,
                'expires_at'  => $expires,
            ],
            [ '%s', '%d', '%s' ]
        );

        return $token;
    }

    /**
     * Validate token. Returns customer_id if valid, 0 if not.
     */
    public static function validate_token( string $token ): int {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT customer_id, used, expires_at
             FROM {$wpdb->prefix}dishdash_birthday_tokens
             WHERE token = %s LIMIT 1",
            $token
        ) );

        if ( ! $row )                                   return 0;
        if ( (int) $row->used )                         return 0;
        if ( strtotime( $row->expires_at ) < time() )  return 0;

        return (int) $row->customer_id;
    }

    /**
     * Save birthday and mark token as used.
     */
    public static function save_birthday( string $token, int $month, int $day ): bool {
        global $wpdb;

        $customer_id = self::validate_token( $token );
        if ( ! $customer_id ) return false;

        // Store as 2000-MM-DD — year is a placeholder, only month+day matter
        $birthday = sprintf( '2000-%02d-%02d', $month, $day );

        $wpdb->update(
            $wpdb->prefix . 'dishdash_customers',
            [ 'birthday' => $birthday ],
            [ 'id' => $customer_id ],
            [ '%s' ], [ '%d' ]
        );

        $wpdb->update(
            $wpdb->prefix . 'dishdash_birthday_tokens',
            [ 'used' => 1 ],
            [ 'token' => $token ],
            [ '%d' ], [ '%s' ]
        );

        return true;
    }

    /**
     * Mark dd_birthday_asked = 1 — message never sends twice.
     */
    public static function mark_birthday_asked( int $customer_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'dishdash_customers',
            [ 'dd_birthday_asked' => 1 ],
            [ 'id' => $customer_id ],
            [ '%d' ], [ '%d' ]
        );
    }

    /**
     * Register action/filter hooks — called from DD_Orders_Module::init().
     */
    public static function register_hooks(): void {
        add_filter( 'dd_resolve_customer_id', [ self::class, 'on_resolve_customer_id' ], 10, 3 );
    }

    /**
     * Filter handler: get-or-create customer by WhatsApp, return customer ID.
     * Used by modules that need a customer ID but should not write to this table directly.
     */
    public static function on_resolve_customer_id( int $customer_id, string $whatsapp, string $name ): int {
        global $wpdb;
        $whatsapp = self::normalize_phone( $whatsapp );
        if ( ! $whatsapp ) return 0;

        $table    = $wpdb->prefix . 'dishdash_customers';
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE whatsapp = %s LIMIT 1", $whatsapp
        ) );

        if ( $existing ) {
            return (int) $existing->id;
        }

        $wpdb->insert(
            $table,
            [ 'whatsapp' => $whatsapp, 'name' => $name, 'created_at' => current_time( 'mysql' ) ],
            [ '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Normalize phone to digits only with Rwanda prefix.
     */
    public static function normalize_phone( string $phone ): string {
        $digits = preg_replace( '/[^0-9]/', '', $phone );
        if ( strlen( $digits ) === 9 ) $digits = '250' . $digits;
        if ( strlen( $digits ) < 9 )  return '';
        return $digits;
    }
}
