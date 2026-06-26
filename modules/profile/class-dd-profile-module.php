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
        // Register the endpoint with WooCommerce's OWN query-vars list — without this,
        // WooCommerce doesn't recognize /my-account/my-profile/ and falls back to the dashboard.
        add_filter( 'woocommerce_get_query_vars', [ $this, 'add_wc_query_var' ] );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Replace WooCommerce's empty Orders tab with real Dish Dash order history.
        add_action( 'woocommerce_account_orders_endpoint', [ $this, 'render_order_history' ], 5 );

        add_action( 'wp_ajax_dd_profile_save_birthday', [ $this, 'ajax_save_birthday' ] );
        add_action( 'wp_ajax_dd_profile_link_phone',    [ $this, 'ajax_link_phone' ] );
        add_action( 'wp_ajax_dd_profile_reorder',       [ $this, 'ajax_reorder' ] );

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
     * Register the endpoint in WooCommerce's account query vars so WC renders
     * its content callback instead of falling back to the dashboard.
     */
    public function add_wc_query_var( $vars ) {
        $vars[ self::ENDPOINT ] = self::ENDPOINT;
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
        $this->print_reorder_script();
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
                "SELECT item_name, quantity, line_total, menu_item_id
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
                $reorder_items = [];
                foreach ( $items as $it ) {
                    echo '<li><span>' . (int) $it->quantity . '× ' . esc_html( $it->item_name ) . '</span>'
                       . '<span>' . number_format( (float) $it->line_total ) . ' RWF</span></li>';
                    $reorder_items[] = [
                        'id'   => (int) $it->menu_item_id,
                        'name' => $it->item_name,
                        'qty'  => (int) $it->quantity,
                    ];
                }
                echo '</ul>';
                if ( $o->status !== 'cancelled' && ! empty( $reorder_items ) ) {
                    echo '<button type="button" class="dd-btn dd-btn--brand dd-btn--sm dd-reorder-btn" '
                       . 'data-items="' . esc_attr( wp_json_encode( $reorder_items ) ) . '">Reorder this meal</button>';
                }
            }
            echo '</div>';
        }

        echo '</div>';
        $this->print_reorder_script();
    }

    /**
     * Print the reorder JS once per request (static guard prevents duplicates when
     * both the profile tab and the order-history tab would otherwise each emit it).
     */
    private function print_reorder_script(): void {
        static $printed = false;
        if ( $printed ) return;
        $printed  = true;
        $nonce    = wp_json_encode( wp_create_nonce( 'dish_dash_frontend' ) );
        $ajax_url = wp_json_encode( admin_url( 'admin-ajax.php' ) );
        echo '<script>
(function(){
    var cartNonce = ' . $nonce . ';
    var ajaxUrl   = ' . $ajax_url . ';
    document.querySelectorAll( ".dd-reorder-btn" ).forEach( function( btn ) {
        if ( btn.dataset.wired ) return;
        btn.dataset.wired = "1";
        btn.addEventListener( "click", function() {
            var items = btn.dataset.items;
            if ( ! items ) return;
            var original = btn.textContent;
            btn.disabled = true;
            btn.textContent = "Adding…";
            var fd = new FormData();
            fd.append( "action", "dd_profile_reorder" );
            fd.append( "nonce", cartNonce );
            fd.append( "items", items );
            fetch( ajaxUrl, { method: "POST", body: fd } )
                .then( function(r){ return r.json(); } )
                .then( function(res){
                    btn.disabled = false;
                    btn.textContent = original;
                    if ( res.success ) {
                        if ( window.DDCart ) {
                            if ( typeof window.DDCart.refresh === "function" ) window.DDCart.refresh();
                            if ( typeof window.DDCart.open === "function" ) window.DDCart.open();
                        }
                        if ( window.DDTrack && typeof window.DDTrack.event === "function" ) {
                            window.DDTrack.event( "add_to_cart", null, null, { qty: 1, source: "reorder" } );
                        }
                    } else {
                        alert( (res.data && res.data.message) ? res.data.message : "Could not reorder." );
                    }
                } )
                .catch( function(){
                    btn.disabled = false;
                    btn.textContent = original;
                    alert( "Something went wrong. Please try again." );
                } );
        } );
    } );
})();
</script>';
    }

    /**
     * Resolve a menu item (possibly stale ID) to a current purchasable WooCommerce product.
     * Tries the stored ID first, then falls back to an exact title match.
     *
     * @param int    $menu_item_id Stored product ID (may be stale).
     * @param string $item_name    Stored item name (used as fallback).
     * @return int Resolved purchasable product ID, or 0 if none found.
     */
    private function resolve_product( int $menu_item_id, string $item_name ): int {
        if ( $menu_item_id ) {
            $p = wc_get_product( $menu_item_id );
            if ( $p && $p->is_purchasable() ) {
                return $menu_item_id;
            }
        }
        if ( $item_name ) {
            $posts = get_posts( [
                'post_type'   => 'product',
                'title'       => $item_name,
                'post_status' => 'publish',
                'numberposts' => 1,
            ] );
            if ( $posts ) {
                $p = wc_get_product( $posts[0]->ID );
                if ( $p && $p->is_purchasable() ) {
                    return (int) $posts[0]->ID;
                }
            }
        }
        return 0;
    }

    /**
     * AJAX — reorder favorites or a past order.
     * Accepts a JSON list of items [{ id, name, qty }] and adds the resolved
     * current products to the cart. Returns counts of added vs unavailable.
     */
    public function ajax_reorder(): void {
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Please log in.' ] );
        check_ajax_referer( 'dish_dash_frontend', 'nonce' );

        if ( class_exists( 'DD_Hours' ) ) {
            $state = DD_Hours::get_state();
            if ( $state === 'closed' || $state === 'break' ) {
                wp_send_json_error( [ 'message' => 'Orders are currently unavailable. The restaurant is closed.' ] );
            }
        }

        $raw   = isset( $_POST['items'] ) ? stripslashes( $_POST['items'] ) : '[]';
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( [ 'message' => 'Nothing to reorder.' ] );
        }

        if ( ! class_exists( 'DD_Cart' ) ) {
            require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-cart.php';
        }
        $cart = new DD_Cart();

        $added       = 0;
        $unavailable = [];

        foreach ( $items as $it ) {
            $id         = (int) ( $it['id'] ?? 0 );
            $name       = sanitize_text_field( $it['name'] ?? '' );
            $qty        = max( 1, (int) ( $it['qty'] ?? 1 ) );
            $product_id = $this->resolve_product( $id, $name );

            if ( ! $product_id ) {
                $unavailable[] = $name ?: ( 'item #' . $id );
                continue;
            }

            $product = wc_get_product( $product_id );
            $image   = wp_get_attachment_url( $product->get_image_id() ) ?: wc_placeholder_img_src();
            $cart->add( [
                'id'        => $product_id,
                'name'      => $product->get_name(),
                'price'     => (float) $product->get_price(),
                'qty'       => $qty,
                'image'     => $image,
                'variation' => '',
                'addons'    => [],
                'note'      => '',
            ] );
            $added++;
        }

        if ( $added === 0 ) {
            wp_send_json_error( [ 'message' => 'Those items are no longer available.' ] );
        }

        $msg = $added . ' item' . ( $added !== 1 ? 's' : '' ) . ' added to your cart.';
        if ( ! empty( $unavailable ) ) {
            $msg .= ' Some items are no longer available: ' . implode( ', ', $unavailable ) . '.';
        }

        wp_send_json_success( [
            'message'     => $msg,
            'added'       => $added,
            'unavailable' => $unavailable,
        ] );
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
