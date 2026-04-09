<?php
/**
 * Product card partial — shared between homepage Featured Dishes
 * and menu grid. Expects $product (WC_Product) in scope.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! isset( $product ) || ! ( $product instanceof WC_Product ) ) return;

$_tag = isset( $tag ) ? $tag : '';

$img_id  = $product->get_image_id();
$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium_large' ) : '';
if ( ! $img_url ) {
    $img_url = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'medium_large' ) : '';
}

if ( ! $_tag ) $_tag = $product->is_featured() ? 'Best Seller' : 'Popular';

$raw_price = (float) $product->get_price();
$price     = $raw_price ? 'RWF ' . number_format( $raw_price, 0, '.', ',' ) : '';

$short = $product->get_short_description();
$long  = $product->get_description();
$desc  = wp_trim_words( strip_tags( $short ? $short : $long ), 14, '...' );

$id    = $product->get_id();
$nonce = wp_create_nonce( 'dd_add_to_cart' );

$filter_parts = array( strtolower( $_tag ) );
if ( $product->is_featured() ) $filter_parts[] = 'featured';
$wc_tags = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'all' ) );
if ( ! is_wp_error( $wc_tags ) && ! empty( $wc_tags ) ) {
    foreach ( $wc_tags as $wt ) {
        $filter_parts[] = $wt->slug;
        $filter_parts[] = strtolower( $wt->name );
    }
}
$filter_str = implode( ',', array_unique( $filter_parts ) );
?>
<article class="dd-dish-card"
         data-id="<?php echo esc_attr( $id ); ?>"
         data-filter="<?php echo esc_attr( $filter_str ); ?>"
         data-img="<?php echo esc_attr( $img_url ); ?>"
         data-price="<?php echo esc_attr( $price ); ?>"
         data-desc="<?php echo esc_attr( $desc ); ?>">
    <div class="dd-dish-card__media">
        <img src="<?php echo esc_url( $img_url ); ?>"
             alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
    </div>
    <div class="dd-dish-card__body">
        <h3 class="dd-dish-card__title dd-serif"><?php echo esc_html( $product->get_name() ); ?></h3>
        <p class="dd-dish-card__desc"><?php echo esc_html( $desc ); ?></p>
        <div class="dd-dish-card__footer">
            <span class="dd-price"><?php echo esc_html( $price ); ?></span>
            <button class="dd-btn dd-btn--brand dd-btn--sm dd-add-btn"
                    data-id="<?php echo esc_attr( $id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                Add to cart
            </button>
        </div>
    </div>
</article>
