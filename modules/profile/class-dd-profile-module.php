<?php
/**
 * DD_Profile_Module — customer-facing "My Profile" tab on WooCommerce my-account.
 *
 * Adds a profile endpoint that surfaces DD_Customer_Profile::get():
 * tier, stats, favorites, birthday, WhatsApp contact, phone-link.
 *
 * White-label: all styling via var(--brand)/var(--dd-*) tokens injected
 * dynamically from each restaurant's settings. No hardcoded colors.
 *
 * @package DishDash
 * @since   3.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-customer-profile.php';
require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-customer-manager.php';

class DD_Profile_Module extends DD_Module {

    protected string $id = 'profile';

    const ENDPOINT = 'my-profile';

    public function init(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_endpoint' ] );
        add_filter( 'query_vars', [ $this, 'add_query_var' ], 0 );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Replace WooCommerce's empty Orders tab with real Dish Dash order history.
        add_action( 'woocommerce_account_orders_endpoint', [ $this, 'render_order_history' ], 5 );

        add_action( 'wp_ajax_dd_profile_save_birthday', [ $this, 'ajax_save_birthday' ] );
        add_action( 'wp_ajax_dd_profile_link_phone',    [ $this, 'ajax_link_phone' ] );

        add_action( 'init', [ $this, 'maybe_flush_rewrite' ], 99 );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public function add_query_var( $vars ) {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    /**
     * Flush rewrite rules once after this version deploys (endpoint 404s otherwise).
     */
    public function maybe_flush_rewrite(): void {
        if ( get_option( 'dd_profile_endpoint_flushed' ) !== DD_VERSION ) {
            $this->add_endpoint();
            flush_rewrite_rules( false );
            update_option( 'dd_profile_endpoint_flushed', DD_VERSION );
        }
    }

    /**
     * Build a clean account menu for a restaurant:
     * My Profile (first) · Order History · Addresses · Account details · Log out.
     * Removes Dashboard and Downloads (not useful for a restaurant).
     */
    public function add_menu_item( $items ) {
        $clean = [];
        $clean[ self::ENDPOINT ] = __( 'My Profile', 'dish-dash' );

        if ( isset( $items['orders'] ) ) {
            $clean['orders'] = __( 'Order History', 'dish-dash' );
        }
        if ( isset( $items['edit-address'] ) ) {
            $clean['edit-address'] = __( 'Addresses', 'dish-dash' );
        }
        if ( isset( $items['edit-account'] ) ) {
            $clean['edit-account'] = __( 'Account details', 'dish-dash' );
        }
        if ( isset( $items['customer-logout'] ) ) {
            $clean['customer-logout'] = $items['customer-logout'];
        }
        return $clean;
    }

    public function enqueue_assets(): void {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        wp_enqueue_style( 'dish-dash-profile', DD_ASSETS_URL . 'css/profile.css', [ 'dish-dash-theme' ], DD_VERSION );
    }

    /**
     * Render the My Profile tab content.
     */
    public function render_endpoint(): void {
        $user_id = get_current_user_id();
        $profile = DD_Customer_Profile::get( $user_id );
        $tpl     = DD_PLUGIN_DIR . 'templates/profile/my-profile.php';
        if ( file_exists( $tpl ) ) {
            include $tpl;
        }
    }

    /**
     * Render real order history from dishdash_orders for the logged-in customer,
     * replacing WooCommerce's empty Orders tab.
     */
    public function render_order_history(): void {
        // Stop WooCommerce's default "no orders" output for this endpoint.
        remove_all_actions( 'woocommerce_account_orders_endpoint' );

        global $wpdb;
        $user_id  = get_current_user_id();
        $customer = DD_Customer_Manager::get_customer_for_user( $user_id );

        echo '<div class="dd-order-history">';
        echo '<h2 class="dd-order-history__title">Order history</h2>';

        if ( ! $customer ) {
            echo '<p class="dd-order-history__empty">Add your phone number on the <a href="' . esc_url( home_url( '/my-account/my-profile/' ) ) . '">My Profile</a> page to see your order history.</p>';
            echo '</div>';
            return;
        }

        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_number, total, status, order_type, payment_method, created_at
             FROM {$wpdb->prefix}dishdash_orders
             WHERE customer_id = %d AND is_test = 0
             ORDER BY id DESC
             LIMIT 50",
            (int) $customer->id
        ) );

        if ( empty( $orders ) ) {
            echo '<p class="dd-order-history__empty">You haven\'t placed any orders yet.</p>';
            echo '</div>';
            return;
        }

        foreach ( $orders as $o ) {
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT item_name, quantity, line_total
                 FROM {$wpdb->prefix}dishdash_order_items
                 WHERE order_id = %d",
                (int) $o->id
            ) );

            $status_label = function_exists( 'dd_order_status_label' )
                ? dd_order_status_label( $o->status )
                : ucfirst( $o->status );
            $date = $o->created_at ? date_i18n( 'M j, Y', strtotime( $o->created_at ) ) : '';

            echo '<div class="dd-order-card dd-order--' . esc_attr( $o->status ) . '">';
            echo   '<div class="dd-order-card__head">';
            echo     '<div>';
            echo       '<span class="dd-order-card__num">' . esc_html( $o->order_number ) . '</span>';
            echo       '<span class="dd-order-card__date">' . esc_html( $date ) . '</span>';
            echo     '</div>';
            echo     '<div class="dd-order-card__right">';
            echo       '<span class="dd-order-card__status dd-status--' . esc_attr( $o->status ) . '">' . esc_html( $status_label ) . '</span>';
            echo       '<span class="dd-order-card__total">' . number_format( (float) $o->total ) . ' RWF</span>';
            echo     '</div>';
            echo   '</div>';

            if ( ! empty( $items ) ) {
                echo '<ul class="dd-order-card__items">';
                foreach ( $items as $it ) {
                    echo '<li><span>' . (int) $it->quantity . '× ' . esc_html( $it->item_name ) . '</span>'
                       . '<span>' . number_format( (float) $it->line_total ) . ' RWF</span></li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * AJAX — customer saves their birthday (month + day).
     */
    public function ajax_save_birthday(): void {
        if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
        check_ajax_referer( 'dd_profile', 'nonce' );

        $user_id = get_current_user_id();
        $month   = (int) ( $_POST['month'] ?? 0 );
        $day     = (int) ( $_POST['day'] ?? 0 );

        if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
            wp_send_json_error( 'Please choose a valid month and day.' );
        }

        global $wpdb;
        $customer = DD_Customer_Manager::get_customer_for_user( $user_id );
        if ( ! $customer ) {
            wp_send_json_error( 'Add your phone number first to set up your profile.' );
        }

        $birthday = sprintf( '2000-%02d-%02d', $month, $day );
        $wpdb->update(
            $wpdb->prefix . 'dishdash_customers',
            [ 'birthday' => $birthday ],
            [ 'id' => (int) $customer->id ],
            [ '%s' ], [ '%d' ]
        );
        update_user_meta( $user_id, 'dd_birthday', $birthday );

        wp_send_json_success( [ 'display' => date_i18n( 'F j', strtotime( $birthday ) ) ] );
    }

    /**
     * AJAX — customer links their phone (surfaces the Brief 1 foundation).
     */
    public function ajax_link_phone(): void {
        if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in.' );
        check_ajax_referer( 'dd_profile', 'nonce' );

        $user_id = get_current_user_id();
        $phone   = sanitize_text_field( $_POST['phone'] ?? '' );
        $user    = wp_get_current_user();
        $name    = $user ? $user->display_name : '';

        $result = DD_Customer_Manager::link_user_to_phone( $user_id, $phone, $name );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result['message'] );
    }
}
