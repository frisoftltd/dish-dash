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
            $resolved_id = (int) $existing->id;
        } else {
            $wpdb->insert(
                $table,
                [ 'whatsapp' => $whatsapp, 'name' => $name, 'created_at' => current_time( 'mysql' ) ],
                [ '%s', '%s', '%s' ]
            );
            $resolved_id = (int) $wpdb->insert_id;
        }

        // Back-fill user_id link when a logged-in customer places an order.
        $uid = get_current_user_id();
        if ( $uid && $resolved_id ) {
            $current = $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE id = %d", $resolved_id
            ) );
            if ( empty( $current ) ) {
                $already = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $uid
                ) );
                if ( ! $already ) {
                    $wpdb->update( $table, [ 'user_id' => $uid ], [ 'id' => $resolved_id ], [ '%d' ], [ '%d' ] );
                }
            }
        }

        return $resolved_id;
    }

    /**
     * Link a logged-in WordPress user to their phone-based commercial record.
     *
     * Identity model:
     *  - If a customers row exists for this phone and is unlinked → link it to this user.
     *  - If it's already linked to THIS user → no-op.
     *  - If it's linked to a DIFFERENT user → conflict; refuse.
     *  - If no row exists → create one linked to this user.
     *
     * Also stores the phone on the WP user (dd_phone + billing_phone) for future lookups.
     *
     * @param int    $user_id WordPress user ID.
     * @param string $phone   Raw phone input.
     * @param string $name    Display name (for new rows).
     * @return array { success: bool, customer_id: int, message: string }
     */
    public static function link_user_to_phone( int $user_id, string $phone, string $name = '' ): array {
        global $wpdb;

        if ( ! $user_id ) {
            return [ 'success' => false, 'customer_id' => 0, 'message' => 'Not logged in.' ];
        }

        $whatsapp = self::normalize_phone( $phone );
        if ( ! $whatsapp ) {
            return [ 'success' => false, 'customer_id' => 0, 'message' => 'Please enter a valid phone number.' ];
        }

        $table = $wpdb->prefix . 'dishdash_customers';

        $existing_for_user = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, whatsapp FROM {$table} WHERE user_id = %d LIMIT 1", $user_id
        ) );

        $row_for_phone = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, user_id FROM {$table} WHERE whatsapp = %s LIMIT 1", $whatsapp
        ) );

        // Phone belongs to a different user → conflict, do not steal.
        if ( $row_for_phone && (int) $row_for_phone->user_id && (int) $row_for_phone->user_id !== $user_id ) {
            return [
                'success'     => false,
                'customer_id' => 0,
                'message'     => 'This phone number is already linked to another account. Please contact the restaurant if this is your number.',
            ];
        }

        if ( $existing_for_user && $existing_for_user->whatsapp !== $whatsapp ) {
            // User is linked to a different phone row — move the link to the new phone.
            if ( $row_for_phone ) {
                // New phone row exists and is unlinked → link it, unlink old.
                $wpdb->update( $table, [ 'user_id' => null ], [ 'id' => $existing_for_user->id ], [ '%d' ], [ '%d' ] );
                $wpdb->update( $table, [ 'user_id' => $user_id ], [ 'id' => $row_for_phone->id ], [ '%d' ], [ '%d' ] );
                $customer_id = (int) $row_for_phone->id;
            } else {
                // Update the linked row's phone to the new number.
                $wpdb->update( $table, [ 'whatsapp' => $whatsapp ], [ 'id' => $existing_for_user->id ], [ '%s' ], [ '%d' ] );
                $customer_id = (int) $existing_for_user->id;
            }
        } elseif ( $row_for_phone ) {
            // Phone row exists and is unlinked (or already this user) → link.
            $wpdb->update( $table, [ 'user_id' => $user_id ], [ 'id' => $row_for_phone->id ], [ '%d' ], [ '%d' ] );
            $customer_id = (int) $row_for_phone->id;
        } else {
            // No row at all → create one linked to this user.
            $wpdb->insert(
                $table,
                [
                    'whatsapp'   => $whatsapp,
                    'name'       => $name ?: 'Customer',
                    'user_id'    => $user_id,
                    'created_at' => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%d', '%s' ]
            );
            $customer_id = (int) $wpdb->insert_id;
        }

        update_user_meta( $user_id, 'dd_phone', $whatsapp );
        update_user_meta( $user_id, 'billing_phone', $whatsapp );

        return [ 'success' => true, 'customer_id' => $customer_id, 'message' => 'Phone linked successfully.' ];
    }

    /**
     * Get the customers-table row for a logged-in user, if linked.
     * Returns null if the user hasn't linked a phone yet.
     *
     * @param int $user_id
     * @return object|null
     */
    public static function get_customer_for_user( int $user_id ) {
        global $wpdb;
        if ( ! $user_id ) return null;
        $table = $wpdb->prefix . 'dishdash_customers';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id
        ) );
    }

    /**
     * Normalize phone to the canonical bare identity key: 250XXXXXXXXX (no +).
     *
     * R2.5 (v3.10.39): parses via libphonenumber as the primary path, then emits the
     * SAME bare 250… form this function produced before — byte-identical, so stored
     * customer keys never change. The historical digit-based logic is retained as the
     * fallback AND the validity gate:
     *   - The "< 9 → ''" rejection below runs FIRST, so junk the old logic would have
     *     dropped (e.g. an 8-digit string) is still dropped — the library never gets
     *     to coerce it into a spurious key.
     *   - For inputs that pass that gate, libphonenumber recomputes the canonical bare
     *     form. On a parse throw, a missing library, or non-digit output, we fall back
     *     to $digits (today's exact result). Malformed input therefore degrades
     *     gracefully instead of fataling the order flow.
     * No format flip here (still bare, no +); the E.164 migration is R3.
     */
    public static function normalize_phone( string $phone ): string {
        // ── Format flag (R3, v3.10.40) ──
        // 'bare' → 250788123456 (legacy);  'e164' → +250788123456.
        // Defaults to 'bare', so deploying R3 is behavior-neutral — the flip stays
        // dormant until the R3 --commit migration sets dd_phone_format = 'e164' as its
        // FINAL step, AFTER every stored key has been backfilled. Gating the single
        // normalizer covers all 7 store/match sites (they all route through here).
        $e164_mode = ( 'e164' === get_option( 'dd_phone_format', 'bare' ) );

        // ── Historical digit-based logic: canonical fallback + validity gate ──
        $digits = preg_replace( '/[^0-9]/', '', $phone );
        // National trunk prefix: 0788123456 (10) → 788123456 (9), then the +250 branch applies.
        if ( strlen( $digits ) === 10 && $digits[0] === '0' ) {
            $digits = substr( $digits, 1 );
        }
        if ( strlen( $digits ) === 9 ) $digits = '250' . $digits;
        if ( strlen( $digits ) < 9 )  return '';   // parity gate: junk stays rejected (format-agnostic)

        // ── Primary path: libphonenumber ──
        // Guarded on class_exists so a missing/unloaded vendor tree never fatals; only
        // reached for inputs the gate above already accepted.
        //
        // Region rule (R3-fix, v3.10.41): a value that ALREADY carries a leading '+' is
        // authoritative about its country — parse it as INTERNATIONAL (region null) so a
        // foreign number stays foreign (+674069873633 → +674…, never coerced to +250…).
        // A BARE value is assumed Rwandan, but accepted ONLY when it is a VALID RW number;
        // bare non-RW digits (a foreign number that lost its '+') are treated as junk
        // (return '') rather than coerced to +250…, per locked decision #3.
        if ( class_exists( '\libphonenumber\PhoneNumberUtil' ) ) {
            try {
                $util     = \libphonenumber\PhoneNumberUtil::getInstance();
                $has_plus = ( 1 === preg_match( '/^\s*\+/', $phone ) );
                $parsed   = $util->parse( $phone, $has_plus ? null : 'RW' );

                // '+' input trusted as-declared; bare input must be a valid RW number.
                if ( $has_plus || $util->isValidNumber( $parsed ) ) {
                    $e164 = $util->format( $parsed, \libphonenumber\PhoneNumberFormat::E164 ); // "+250788123456"
                    if ( $e164_mode ) {
                        // E.164 WITH the leading +. Guard: + then 9–15 digits.
                        if ( preg_match( '/^\+\d{9,15}$/', $e164 ) ) {
                            return $e164;
                        }
                    } else {
                        // Legacy bare (today's behavior): strip the +.
                        $bare = ltrim( $e164, '+' );
                        if ( '' !== $bare && ctype_digit( $bare ) ) {
                            return $bare;
                        }
                    }
                }

                // Parsed but not accepted (bare + invalid RW) → junk. Do NOT coerce.
                return '';
            } catch ( \libphonenumber\NumberParseException $e ) {
                // Unparseable → junk.
                return '';
            }
        }

        // ── Fallback: only when the library is UNAVAILABLE (class_exists false) ──
        // Historical digit result, format-matched to the flag. Under e164 it must carry
        // the '+', or a library-less environment would re-fragment the column.
        return $e164_mode ? ( '+' . $digits ) : $digits;
    }
}
