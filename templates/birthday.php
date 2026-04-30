<?php
/**
 * Birthday collection page — /birthday/?c=TOKEN
 * No login required. Token is single-use, expires in 30 days.
 *
 * @package DishDash
 * @since   3.2.54
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once DD_PLUGIN_DIR . 'modules/orders/class-dd-customer-manager.php';

$token       = sanitize_text_field( $_GET['c'] ?? '' );
$customer_id = $token ? DD_Customer_Manager::validate_token( $token ) : 0;
$saved       = false;
$error       = '';

if ( ! $token || ! $customer_id ) {
    $error = 'This link is invalid or has already been used.';
}

if ( ! $error && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $month = (int) ( $_POST['month'] ?? 0 );
    $day   = (int) ( $_POST['day']   ?? 0 );

    if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
        $error = 'Please select a valid month and day.';
    } else {
        $saved = DD_Customer_Manager::save_birthday( $token, $month, $day );
        if ( ! $saved ) $error = 'This link has already been used.';
    }
}

get_header();
?>
<div class="dd-birthday-page">
    <div class="dd-birthday-card">

        <?php if ( $saved ) : ?>
            <div class="dd-birthday-card__icon">🎂</div>
            <h1 class="dd-birthday-card__title">Thank you!</h1>
            <p class="dd-birthday-card__text">
                We'll make sure to celebrate your special day. 🎉
            </p>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>"
               class="dd-birthday-card__btn">Back to Menu</a>

        <?php elseif ( $error ) : ?>
            <div class="dd-birthday-card__icon">⚠️</div>
            <h1 class="dd-birthday-card__title">Link Expired</h1>
            <p class="dd-birthday-card__text"><?php echo esc_html( $error ); ?></p>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>"
               class="dd-birthday-card__btn">Back to Menu</a>

        <?php else : ?>
            <div class="dd-birthday-card__icon">🎁</div>
            <h1 class="dd-birthday-card__title">When's your birthday?</h1>
            <p class="dd-birthday-card__text">
                We'd love to surprise you on your special day.
            </p>

            <form method="post" class="dd-birthday-form">
                <?php wp_nonce_field( 'dd_birthday_submit', 'dd_birthday_nonce' ); ?>
                <div class="dd-birthday-form__row">
                    <div class="dd-birthday-form__group">
                        <label for="dd-month">Month</label>
                        <select id="dd-month" name="month" required>
                            <option value="">Month</option>
                            <?php
                            $months = [
                                1=>'January', 2=>'February',  3=>'March',
                                4=>'April',   5=>'May',        6=>'June',
                                7=>'July',    8=>'August',     9=>'September',
                                10=>'October',11=>'November', 12=>'December',
                            ];
                            foreach ( $months as $num => $label ) :
                            ?>
                            <option value="<?php echo $num; ?>">
                                <?php echo $label; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dd-birthday-form__group">
                        <label for="dd-day">Day</label>
                        <select id="dd-day" name="day" required>
                            <option value="">Day</option>
                            <?php for ( $d = 1; $d <= 31; $d++ ) : ?>
                            <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="dd-birthday-form__submit">
                    Save My Birthday 🎂
                </button>
            </form>
        <?php endif; ?>

    </div>
</div>
<?php get_footer(); ?>
