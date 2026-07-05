<?php
/**
 * DD_Customer_Profile — unified read interface for a customer's profile.
 *
 * Joins:
 *   - Commercial record (wp_dishdash_customers, keyed by user_id after linking)
 *   - Behavioral favorites (computed authoritatively from order history;
 *     wp_dishdash_user_profiles is a cache that may be empty for guest-era orders)
 *   - Tier (computed from total_spent)
 *   - Birthday (from the customers row)
 *   - Recent orders (for one-click reorder)
 *   - Restaurant contact (WhatsApp)
 *
 * READ-ONLY. Does not write to any table. Other modules retain ownership of their data.
 *
 * @package DishDash
 * @since   3.9.9
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-customer-manager.php';

class DD_Customer_Profile {

    /**
     * Tier thresholds (RWF), matching the customers module.
     */
    private static function compute_tier( float $spent, int $orders ): array {
        if ( $orders === 0 ) {
            return [ 'slug' => 'new',      'label' => 'New',      'icon' => '🌱' ];
        }
        if ( $spent >= 500000 ) {
            return [ 'slug' => 'diamond',  'label' => 'Diamond',  'icon' => '💎' ];
        }
        if ( $spent >= 250000 ) {
            return [ 'slug' => 'champion', 'label' => 'Champion', 'icon' => '🏆' ];
        }
        if ( $spent >= 100000 ) {
            return [ 'slug' => 'vip',      'label' => 'VIP',      'icon' => '👑' ];
        }
        return [ 'slug' => 'regular',      'label' => 'Regular',  'icon' => '🧡' ];
    }

    /**
     * Get the full profile for a logged-in user.
     *
     * @param int $user_id
     * @return array
     */
    public static function get( int $user_id ): array {
        global $wpdb;

        $profile = [
            'is_linked'        => false,
            'user_id'          => $user_id,
            'name'             => '',
            'phone'            => '',
            'total_orders'     => 0,
            'total_spent'      => 0.0,
            'member_since'     => '',
            'tier'             => self::compute_tier( 0, 0 ),
            'birthday'         => null,      // 'MM-DD' or null
            'birthday_display' => '',        // e.g. "March 14" or ''
            'favorites'        => [],        // [ { menu_item_id, item_name, times_ordered } ]
            'recent_orders'    => [],        // [ { order_id, order_number, date, total, status, items:[...] } ]
            'whatsapp_contact' => '',        // restaurant public WhatsApp (digits)
        ];

        if ( ! $user_id ) return $profile;

        // ── Commercial record (linked customers row) ──────────────────────────
        $customer = DD_Customer_Manager::get_customer_for_user( $user_id );

        if ( $customer ) {
            $profile['is_linked']    = true;
            $profile['name']         = $customer->name;
            $profile['phone']        = $customer->whatsapp;
            $profile['total_orders'] = (int) $customer->total_orders;
            $profile['total_spent']  = (float) $customer->total_spent;
            $profile['member_since'] = $customer->created_at
                ? date_i18n( 'F Y', strtotime( $customer->created_at ) )
                : '';
            $profile['tier']         = self::compute_tier(
                (float) $customer->total_spent,
                (int) $customer->total_orders
            );

            // Birthday (stored as 2000-MM-DD in the customers table).
            if ( ! empty( $customer->birthday ) && $customer->birthday !== '0000-00-00' ) {
                $ts = strtotime( $customer->birthday );
                if ( $ts ) {
                    $profile['birthday']         = date( 'm-d', $ts );
                    $profile['birthday_display'] = date_i18n( 'F j', $ts );
                }
            }

            // ── Favorites — authoritative from order history (non-cancelled) ──
            // Orders store the WP user ID in customer_id (not the customers-table PK).
            $customer_id = (int) $user_id;
            $fav_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT oi.menu_item_id, oi.item_name,
                        SUM(oi.quantity) AS times_ordered
                 FROM {$wpdb->prefix}dishdash_order_items oi
                 JOIN {$wpdb->prefix}dishdash_orders o ON o.id = oi.order_id
                 WHERE o.customer_id = %d
                   AND o.is_test = 0
                   AND o.status NOT IN ('cancelled')
                 GROUP BY oi.menu_item_id, oi.item_name
                 ORDER BY times_ordered DESC
                 LIMIT 6",
                $customer_id
            ) );
            foreach ( $fav_rows as $f ) {
                $profile['favorites'][] = [
                    'menu_item_id'  => (int) $f->menu_item_id,
                    'item_name'     => $f->item_name,
                    'times_ordered' => (int) $f->times_ordered,
                ];
            }

            // ── Recent orders — for reorder (non-cancelled, newest first) ──────
            $order_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_number, total, status, created_at
                 FROM {$wpdb->prefix}dishdash_orders
                 WHERE customer_id = %d
                   AND is_test = 0
                   AND status NOT IN ('cancelled')
                 ORDER BY id DESC
                 LIMIT 5",
                $customer_id
            ) );
            foreach ( $order_rows as $o ) {
                $items = $wpdb->get_results( $wpdb->prepare(
                    "SELECT menu_item_id, item_name, quantity
                     FROM {$wpdb->prefix}dishdash_order_items
                     WHERE order_id = %d",
                    (int) $o->id
                ) );
                $item_list = [];
                foreach ( $items as $it ) {
                    $item_list[] = [
                        'menu_item_id' => (int) $it->menu_item_id,
                        'item_name'    => $it->item_name,
                        'quantity'     => (int) $it->quantity,
                    ];
                }
                $order_num = ! empty( $o->order_number )
                    ? $o->order_number
                    : 'DD-' . str_pad( $o->id, 5, '0', STR_PAD_LEFT );
                $profile['recent_orders'][] = [
                    'order_id'     => (int) $o->id,
                    'order_number' => $order_num,
                    'date'         => $o->created_at ? date_i18n( 'M j, Y', strtotime( $o->created_at ) ) : '',
                    'total'        => (float) $o->total,
                    'status'       => $o->status,
                    'items'        => $item_list,
                ];
            }
        }

        // ── Restaurant contact WhatsApp (public-facing) ───────────────────────
        $wa = get_option( 'dish_dash_whatsapp', '' );
        $profile['whatsapp_contact'] = preg_replace( '/[^0-9]/', '', $wa );

        return $profile;
    }
}
