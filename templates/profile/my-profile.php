<?php
/**
 * My Profile tab content. Expects $profile (from DD_Customer_Profile::get()).
 * White-label: uses var(--brand)/var(--dd-*) tokens only.
 *
 * @package DishDash
 * @since   3.10.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$nonce  = wp_create_nonce( 'dd_profile' );
$months = [ 1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
            7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December' ];
?>
<div class="dd-profile" data-nonce="<?php echo esc_attr( $nonce ); ?>">

    <?php if ( ! $profile['is_linked'] ) : ?>
        <div class="dd-profile__link-card">
            <h3 class="dd-profile__link-title">See your order history</h3>
            <p class="dd-profile__link-text">Add your phone number to connect your past orders, favorites, and rewards to your account.</p>
            <div class="dd-profile__link-row">
                <input type="tel" id="ddProfilePhone" class="dd-profile__input" placeholder="07XX XXX XXX" inputmode="numeric">
                <button type="button" id="ddProfileLinkBtn" class="dd-btn dd-btn--brand">Connect</button>
            </div>
            <p class="dd-profile__link-msg" id="ddProfileLinkMsg"></p>
        </div>
    <?php else : ?>

        <div class="dd-profile__header">
            <div>
                <h2 class="dd-profile__name"><?php echo esc_html( $profile['name'] ); ?></h2>
                <?php if ( $profile['member_since'] ) : ?>
                    <p class="dd-profile__since">Member since <?php echo esc_html( $profile['member_since'] ); ?></p>
                <?php endif; ?>
            </div>
            <div class="dd-profile__tier dd-tier--<?php echo esc_attr( $profile['tier']['slug'] ); ?>">
                <span class="dd-profile__tier-icon"><?php echo $profile['tier']['icon']; // emoji, no user-controlled input ?></span>
                <span class="dd-profile__tier-label"><?php echo esc_html( $profile['tier']['label'] ); ?></span>
            </div>
        </div>

        <div class="dd-profile__stats">
            <div class="dd-profile__stat">
                <span class="dd-profile__stat-num"><?php echo (int) $profile['total_orders']; ?></span>
                <span class="dd-profile__stat-label">Orders</span>
            </div>
            <div class="dd-profile__stat">
                <span class="dd-profile__stat-num"><?php echo number_format( $profile['total_spent'] ); ?> <small>RWF</small></span>
                <span class="dd-profile__stat-label">Total spent</span>
            </div>
        </div>

        <?php if ( ! empty( $profile['favorites'] ) ) : ?>
        <div class="dd-profile__section">
            <h3 class="dd-profile__section-title">Your favorites</h3>
            <ul class="dd-profile__favs">
                <?php foreach ( $profile['favorites'] as $f ) : ?>
                    <li class="dd-profile__fav">
                        <span class="dd-profile__fav-name"><?php echo esc_html( $f['item_name'] ); ?></span>
                        <span class="dd-profile__fav-count">ordered <?php echo (int) $f['times_ordered']; ?>×</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="dd-profile__section">
            <h3 class="dd-profile__section-title">Birthday</h3>
            <?php if ( $profile['birthday_display'] ) : ?>
                <p class="dd-profile__birthday-set">🎂 <?php echo esc_html( $profile['birthday_display'] ); ?></p>
            <?php else : ?>
                <p class="dd-profile__birthday-hint">Add your birthday and enjoy a treat from us on your special day.</p>
                <div class="dd-profile__birthday-row">
                    <select id="ddBdayMonth" class="dd-profile__select">
                        <option value="">Month</option>
                        <?php foreach ( $months as $n => $label ) : ?>
                            <option value="<?php echo $n; ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="ddBdayDay" class="dd-profile__select">
                        <option value="">Day</option>
                        <?php for ( $d = 1; $d <= 31; $d++ ) : ?>
                            <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="button" id="ddBdaySave" class="dd-btn dd-btn--brand">Save</button>
                </div>
                <p class="dd-profile__birthday-msg" id="ddBdayMsg"></p>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $profile['whatsapp_contact'] ) ) :
            $wa_number = preg_replace( '/[^0-9]/', '', $profile['whatsapp_contact'] );
            $wa_url    = 'https://wa.me/' . $wa_number;
        ?>
        <div class="dd-profile__section">
            <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener" class="dd-btn dd-btn--whatsapp dd-profile__whatsapp">
                💬 Contact the restaurant
            </a>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
( function() {
    var wrap = document.querySelector( '.dd-profile' );
    if ( ! wrap ) return;
    var nonce   = wrap.dataset.nonce;
    var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

    // Link phone
    var linkBtn = document.getElementById( 'ddProfileLinkBtn' );
    if ( linkBtn ) {
        linkBtn.addEventListener( 'click', function() {
            var phone = ( document.getElementById( 'ddProfilePhone' ).value || '' ).trim();
            var msg   = document.getElementById( 'ddProfileLinkMsg' );
            if ( ! phone ) { msg.textContent = 'Please enter your phone number.'; return; }
            linkBtn.disabled = true;
            msg.textContent  = 'Connecting…';
            var fd = new FormData();
            fd.append( 'action', 'dd_profile_link_phone' );
            fd.append( 'nonce',  nonce );
            fd.append( 'phone',  phone );
            fetch( ajaxUrl, { method: 'POST', body: fd } )
                .then( function( r ) { return r.json(); } )
                .then( function( res ) {
                    if ( res.success ) { location.reload(); }
                    else { msg.textContent = res.data || 'Could not connect that number.'; linkBtn.disabled = false; }
                } )
                .catch( function() { msg.textContent = 'Something went wrong. Try again.'; linkBtn.disabled = false; } );
        } );
    }

    // Save birthday
    var bdayBtn = document.getElementById( 'ddBdaySave' );
    if ( bdayBtn ) {
        bdayBtn.addEventListener( 'click', function() {
            var m   = document.getElementById( 'ddBdayMonth' ).value;
            var d   = document.getElementById( 'ddBdayDay' ).value;
            var msg = document.getElementById( 'ddBdayMsg' );
            if ( ! m || ! d ) { msg.textContent = 'Pick a month and day.'; return; }
            bdayBtn.disabled = true;
            msg.textContent  = 'Saving…';
            var fd = new FormData();
            fd.append( 'action', 'dd_profile_save_birthday' );
            fd.append( 'nonce',  nonce );
            fd.append( 'month',  m );
            fd.append( 'day',    d );
            fetch( ajaxUrl, { method: 'POST', body: fd } )
                .then( function( r ) { return r.json(); } )
                .then( function( res ) {
                    if ( res.success ) { msg.textContent = '🎂 Saved: ' + res.data.display; }
                    else { msg.textContent = res.data || 'Could not save.'; bdayBtn.disabled = false; }
                } )
                .catch( function() { msg.textContent = 'Something went wrong.'; bdayBtn.disabled = false; } );
        } );
    }
} )();
</script>
