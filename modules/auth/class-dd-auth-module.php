
<?php
/**
 * Dish Dash – Auth Module
 *
 * Custom login + registration with Google OAuth.
 * Injects modal HTML on all pages via wp_footer.
 * Handles AJAX login/register + Google OAuth flow.
 *
 * @package DishDash
 * @since   2.5.69
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Auth_Module extends DD_Module {

    protected string $id = 'auth';

    /** Google OAuth endpoints */
    const GOOGLE_AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function init(): void {
        // Admin settings
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_init', [ $this, 'save_settings' ] );

        // Inject auth modal on all frontend pages
        add_action( 'wp_footer', [ $this, 'inject_auth_modal' ] );

        // AJAX handlers
        DD_Ajax::register( 'dd_login',    [ $this, 'ajax_login' ],    false );
        DD_Ajax::register( 'dd_register', [ $this, 'ajax_register' ], false );
        DD_Ajax::register( 'dd_logout',   [ $this, 'ajax_logout' ],   true  );

        // Google OAuth — runs early to intercept the callback
        add_action( 'init', [ $this, 'handle_google_oauth' ] );
    }

    // ─────────────────────────────────────────
    //  ADMIN PAGE
    // ─────────────────────────────────────────
    public function register_admin_page(): void {
        add_submenu_page(
            'dish-dash',
            __( 'Auth Settings', 'dish-dash' ),
            __( '🔐 Auth & Login', 'dish-dash' ),
            'manage_options',
            'dish-dash-auth',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page(): void {
        $saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
        $callback_url = home_url( '/?dd_google_callback=1' );
        ?>
        <div class="wrap dd-admin-wrap">
            <div class="dd-admin-header">
                <div class="dd-admin-header__logo">
                    <span class="dd-logo-icon">🔐</span>
                    <div>
                        <h1><?php esc_html_e( 'Auth & Login Settings', 'dish-dash' ); ?></h1>
                        <span class="dd-version">Custom login modal + Google OAuth</span>
                    </div>
                </div>
            </div>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:1rem">
                <p>✅ <strong>Settings saved!</strong></p>
            </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'dd_auth_settings', 'dd_auth_nonce' ); ?>

                <div class="dd-settings-card" style="margin-top:1.5rem;">
                    <h2>🔑 Google OAuth Setup</h2>

                    <div style="background:#f8f5ff;border:1px solid #e0d6ff;border-radius:10px;padding:16px;margin-bottom:20px;font-size:13px;line-height:1.8;">
                        <strong>Setup steps:</strong><br>
                        1. Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console → Credentials</a><br>
                        2. Click <strong>Create Credentials → OAuth 2.0 Client ID</strong><br>
                        3. Application type: <strong>Web application</strong><br>
                        4. Add Authorized redirect URI: <code style="background:#fff;padding:2px 6px;border-radius:4px;font-family:monospace;"><?php echo esc_html( $callback_url ); ?></code><br>
                        5. Copy the Client ID and Client Secret below
                    </div>

                    <div class="dd-form-group">
                        <label>Google Client ID</label>
                        <input type="text" name="dd_google_client_id"
                            value="<?php echo esc_attr( get_option( 'dd_google_client_id', '' ) ); ?>"
                            placeholder="xxxx.apps.googleusercontent.com" style="width:100%;font-family:monospace;font-size:13px;" />
                    </div>
                    <div class="dd-form-group">
                        <label>Google Client Secret</label>
                        <input type="password" name="dd_google_client_secret"
                            value="<?php echo esc_attr( get_option( 'dd_google_client_secret', '' ) ); ?>"
                            placeholder="GOCSPX-..." style="width:100%;font-family:monospace;font-size:13px;" />
                    </div>

                    <div class="dd-form-group">
                        <label>Callback URL (copy this to Google Console)</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" value="<?php echo esc_attr( $callback_url ); ?>"
                                readonly style="flex:1;background:#f5f5f5;font-family:monospace;font-size:13px;" />
                            <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $callback_url ); ?>');this.textContent='Copied!';" class="button">
                                Copy
                            </button>
                        </div>
                    </div>
                </div>

                <div class="dd-settings-card" style="margin-top:1rem;">
                    <h2>⚙️ General</h2>
                    <div class="dd-form-group">
                        <label>
                            <input type="checkbox" name="dd_auth_allow_registration" value="1"
                                <?php checked( get_option( 'dd_auth_allow_registration', '1' ), '1' ); ?> />
                            Allow new user registration
                        </label>
                    </div>
                    <div class="dd-form-group">
                        <label>After login, redirect to</label>
                        <select name="dd_auth_redirect_after_login" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;">
                            <option value="home" <?php selected( get_option('dd_auth_redirect_after_login','home'), 'home' ); ?>>Homepage</option>
                            <option value="same" <?php selected( get_option('dd_auth_redirect_after_login','home'), 'same' ); ?>>Same page (stay)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top:1.5rem;">
                    <?php submit_button( '💾 Save Settings', 'primary large', 'dd_auth_save', false ); ?>
                </div>
            </form>
        </div>

        <style>
        .dd-settings-card{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.04);}
        .dd-settings-card h2{font-size:1rem;font-weight:700;margin:0 0 1.25rem;padding-bottom:.75rem;border-bottom:2px solid #f0f0f0;}
        .dd-form-group{margin-bottom:1rem;}
        .dd-form-group label{display:block;font-size:.82rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#888;margin-bottom:.35rem;}
        .dd-form-group input[type="text"],.dd-form-group input[type="password"]{width:100%;padding:.6rem .85rem;border:1.5px solid #e0e0e0;border-radius:8px;font-size:.9rem;}
        </style>
        <?php
    }

    public function save_settings(): void {
        if (
            ! isset( $_POST['dd_auth_save'] ) ||
            ! check_admin_referer( 'dd_auth_settings', 'dd_auth_nonce' ) ||
            ! current_user_can( 'manage_options' )
        ) return;

        $fields = [
            'dd_google_client_id'          => 'sanitize_text_field',
            'dd_google_client_secret'      => 'sanitize_text_field',
            'dd_auth_redirect_after_login' => 'sanitize_text_field',
        ];

        foreach ( $fields as $key => $fn ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, $fn( $_POST[ $key ] ) );
            }
        }

        update_option( 'dd_auth_allow_registration',
            isset( $_POST['dd_auth_allow_registration'] ) ? '1' : '0'
        );

        wp_redirect( add_query_arg( [ 'page' => 'dish-dash-auth', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ─────────────────────────────────────────
    //  INJECT AUTH MODAL HTML
    // ─────────────────────────────────────────
    public function inject_auth_modal(): void {
        if ( is_admin() ) return;
        $allow_reg = get_option( 'dd_auth_allow_registration', '1' ) === '1';
        $has_google = get_option( 'dd_google_client_id', '' ) !== '';
        $google_url = $has_google ? add_query_arg( 'dd_google_auth', '1', home_url( '/' ) ) : '';
        ?>
        <!-- ══ AUTH MODAL ═════════════════════════════════════════════ -->
        <div class="dd-auth-modal" id="ddAuthModal" role="dialog" aria-modal="true">
            <div class="dd-auth-modal__overlay" id="ddAuthOverlay"></div>
            <div class="dd-auth-modal__wrap">

                <button class="dd-auth-modal__close" id="ddAuthClose" aria-label="Close">&#10005;</button>

                <!-- ── LOGIN PANEL ──────────────────────────── -->
                <div class="dd-auth-panel" id="ddLoginPanel">
                    <div class="dd-auth-modal__logo">
                        <?php
                        $logo = get_option( 'dish_dash_logo_url', '' );
                        $name = get_option( 'dish_dash_restaurant_name', 'Khana Khazana' );
                        if ( $logo ) : ?>
                            <img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>">
                        <?php else : ?>
                            <div class="dd-auth-modal__badge"><?php echo esc_html( strtoupper( substr( $name, 0, 2 ) ) ); ?></div>
                        <?php endif; ?>
                    </div>
                    <h2 class="dd-auth-modal__title">Welcome back</h2>
                    <p class="dd-auth-modal__sub">Sign in to track orders & get recommendations</p>

                    <?php if ( $has_google ) : ?>
                    <a href="<?php echo esc_url( $google_url ); ?>" class="dd-auth-google-btn">
                        <svg width="20" height="20" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                        Continue with Google
                    </a>
                    <div class="dd-auth-divider"><span>or</span></div>
                    <?php endif; ?>

                    <div class="dd-auth-msg" id="ddLoginMsg"></div>

                    <div class="dd-auth-field">
                        <label>Email address</label>
                        <input type="email" id="ddLoginEmail" placeholder="you@example.com" autocomplete="email">
                    </div>
                    <div class="dd-auth-field">
                        <label>Password</label>
                        <input type="password" id="ddLoginPassword" placeholder="••••••••" autocomplete="current-password">
                    </div>
                    <div class="dd-auth-row">
                        <label class="dd-auth-check">
                            <input type="checkbox" id="ddLoginRemember"> Remember me
                        </label>
                        <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="dd-auth-link" target="_blank">Forgot password?</a>
                    </div>

                    <button class="dd-btn dd-btn--brand dd-btn--block dd-auth-submit" id="ddLoginSubmit">
                        Sign in
                    </button>

                    <?php if ( $allow_reg ) : ?>
                    <p class="dd-auth-switch">
                        Don't have an account?
                        <button class="dd-auth-link dd-auth-link--btn" id="ddGoRegister">Create one</button>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- ── REGISTER PANEL ────────────────────────── -->
                <?php if ( $allow_reg ) : ?>
                <div class="dd-auth-panel" id="ddRegisterPanel" style="display:none;">
                    <div class="dd-auth-modal__logo">
                        <?php if ( $logo ) : ?>
                            <img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>">
                        <?php else : ?>
                            <div class="dd-auth-modal__badge"><?php echo esc_html( strtoupper( substr( $name, 0, 2 ) ) ); ?></div>
                        <?php endif; ?>
                    </div>
                    <h2 class="dd-auth-modal__title">Create account</h2>
                    <p class="dd-auth-modal__sub">Join to enjoy personalized recommendations</p>

                    <?php if ( $has_google ) : ?>
                    <a href="<?php echo esc_url( $google_url ); ?>" class="dd-auth-google-btn">
                        <svg width="20" height="20" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                        Continue with Google
                    </a>
                    <div class="dd-auth-divider"><span>or</span></div>
                    <?php endif; ?>

                    <div class="dd-auth-msg" id="ddRegisterMsg"></div>

                    <div class="dd-auth-field">
                        <label>Full name</label>
                        <input type="text" id="ddRegName" placeholder="Your name" autocomplete="name">
                    </div>
                    <div class="dd-auth-field">
                        <label>Email address</label>
                        <input type="email" id="ddRegEmail" placeholder="you@example.com" autocomplete="email">
                    </div>
                    <div class="dd-auth-field">
                        <label>Password</label>
                        <input type="password" id="ddRegPassword" placeholder="Min 8 characters" autocomplete="new-password">
                    </div>

                    <button class="dd-btn dd-btn--brand dd-btn--block dd-auth-submit" id="ddRegSubmit">
                        Create account
                    </button>

                    <p class="dd-auth-switch">
                        Already have an account?
                        <button class="dd-auth-link dd-auth-link--btn" id="ddGoLogin">Sign in</button>
                    </p>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <script>
        (function() {
            var modal      = document.getElementById('ddAuthModal');
            var overlay    = document.getElementById('ddAuthOverlay');
            var closeBtn   = document.getElementById('ddAuthClose');
            var loginPanel = document.getElementById('ddLoginPanel');
            var regPanel   = document.getElementById('ddRegisterPanel');

            if (!modal) return;

            /* Open / close */
            window.ddOpenLogin = function() {
                if (loginPanel) loginPanel.style.display = '';
                if (regPanel)   regPanel.style.display   = 'none';
                modal.classList.add('open');
                document.body.style.overflow = 'hidden';
            };
            window.ddOpenRegister = function() {
                if (loginPanel) loginPanel.style.display = 'none';
                if (regPanel)   regPanel.style.display   = '';
                modal.classList.add('open');
                document.body.style.overflow = 'hidden';
            };
            window.ddCloseAuth = function() {
                modal.classList.remove('open');
                document.body.style.overflow = '';
            };

            if (overlay)  overlay.addEventListener('click', window.ddCloseAuth);
            if (closeBtn) closeBtn.addEventListener('click', window.ddCloseAuth);
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') window.ddCloseAuth();
            });

            /* Panel switching */
            var goReg   = document.getElementById('ddGoRegister');
            var goLogin = document.getElementById('ddGoLogin');
            if (goReg)   goReg.addEventListener('click',   window.ddOpenRegister);
            if (goLogin) goLogin.addEventListener('click', window.ddOpenLogin);

            /* ── LOGIN ── */
            var loginBtn = document.getElementById('ddLoginSubmit');
            var loginMsg = document.getElementById('ddLoginMsg');
            if (loginBtn) {
                loginBtn.addEventListener('click', function() {
                    var email    = (document.getElementById('ddLoginEmail')    || {}).value || '';
                    var password = (document.getElementById('ddLoginPassword') || {}).value || '';
                    var remember = (document.getElementById('ddLoginRemember') || {}).checked ? 1 : 0;

                    if (!email || !password) {
                        ddAuthMsg(loginMsg, 'Please enter your email and password.', 'error');
                        return;
                    }

                    loginBtn.textContent = 'Signing in…';
                    loginBtn.disabled = true;

                    ddAuthAjax('dd_login', { email: email, password: password, remember: remember }, function(res) {
                        loginBtn.textContent = 'Sign in';
                        loginBtn.disabled = false;
                        if (res.success) {
                            ddAuthMsg(loginMsg, '✓ Welcome back! Refreshing…', 'success');
                            setTimeout(function() { window.location.reload(); }, 800);
                        } else {
                            ddAuthMsg(loginMsg, res.data || 'Login failed. Please try again.', 'error');
                        }
                    });
                });
            }

            /* ── REGISTER ── */
            var regBtn = document.getElementById('ddRegSubmit');
            var regMsg = document.getElementById('ddRegisterMsg');
            if (regBtn) {
                regBtn.addEventListener('click', function() {
                    var name     = (document.getElementById('ddRegName')     || {}).value || '';
                    var email    = (document.getElementById('ddRegEmail')    || {}).value || '';
                    var password = (document.getElementById('ddRegPassword') || {}).value || '';

                    if (!name || !email || !password) {
                        ddAuthMsg(regMsg, 'Please fill in all fields.', 'error');
                        return;
                    }
                    if (password.length < 8) {
                        ddAuthMsg(regMsg, 'Password must be at least 8 characters.', 'error');
                        return;
                    }

                    regBtn.textContent = 'Creating account…';
                    regBtn.disabled = true;

                    ddAuthAjax('dd_register', { name: name, email: email, password: password }, function(res) {
                        regBtn.textContent = 'Create account';
                        regBtn.disabled = false;
                        if (res.success) {
                            ddAuthMsg(regMsg, '✓ Account created! Signing you in…', 'success');
                            setTimeout(function() { window.location.reload(); }, 900);
                        } else {
                            ddAuthMsg(regMsg, res.data || 'Registration failed. Please try again.', 'error');
                        }
                    });
                });
            }

            /* ── Helpers ── */
            function ddAuthAjax(action, data, callback) {
                var body = new URLSearchParams({ action: action, nonce: '<?php echo esc_js( wp_create_nonce("dd_auth") ); ?>' });
                Object.keys(data).forEach(function(k) { body.append(k, data[k]); });
                fetch('<?php echo esc_url( admin_url("admin-ajax.php") ); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function(r) { return r.json(); })
                .then(callback)
                .catch(function() { callback({ success: false, data: 'Network error. Please try again.' }); });
            }

            function ddAuthMsg(el, msg, type) {
                if (!el) return;
                el.textContent = msg;
                el.className = 'dd-auth-msg dd-auth-msg--' + type;
                el.style.display = '';
            }

            /* ── Wire up header buttons ── */
            document.addEventListener('click', function(e) {
                if (e.target.closest('#ddOpenLogin'))    { e.preventDefault(); window.ddOpenLogin(); }
                if (e.target.closest('#ddOpenRegister')) { e.preventDefault(); window.ddOpenRegister(); }
                if (e.target.closest('#ddLogoutBtn'))    { e.preventDefault(); ddAuthAjax('dd_logout', {}, function() { window.location.reload(); }); }
            });
        })();
        </script>
        <?php
    }

    // ─────────────────────────────────────────
    //  AJAX — LOGIN
    // ─────────────────────────────────────────
    public function ajax_login(): void {
        check_ajax_referer( 'dd_auth', 'nonce' );

        $email    = sanitize_email( $_POST['email']    ?? '' );
        $password = $_POST['password'] ?? '';
        $remember = ! empty( $_POST['remember'] );

        if ( ! $email || ! $password ) {
            wp_send_json_error( 'Email and password are required.' );
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            wp_send_json_error( 'No account found with that email.' );
        }

        if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
            wp_send_json_error( 'Incorrect password. Please try again.' );
        }

        wp_set_auth_cookie( $user->ID, $remember );
        wp_set_current_user( $user->ID );
        do_action( 'wp_login', $user->user_login, $user );

        wp_send_json_success( [ 'user_id' => $user->ID, 'name' => $user->display_name ] );
    }

    // ─────────────────────────────────────────
    //  AJAX — REGISTER
    // ─────────────────────────────────────────
    public function ajax_register(): void {
        check_ajax_referer( 'dd_auth', 'nonce' );

        if ( get_option( 'dd_auth_allow_registration', '1' ) !== '1' ) {
            wp_send_json_error( 'Registration is currently disabled.' );
        }

        $name     = sanitize_text_field( $_POST['name']     ?? '' );
        $email    = sanitize_email(      $_POST['email']    ?? '' );
        $password = $_POST['password'] ?? '';

        if ( ! $name || ! $email || ! $password ) {
            wp_send_json_error( 'All fields are required.' );
        }
        if ( strlen( $password ) < 8 ) {
            wp_send_json_error( 'Password must be at least 8 characters.' );
        }
        if ( email_exists( $email ) ) {
            wp_send_json_error( 'An account with this email already exists.' );
        }

        // Split name into first/last
        $parts      = explode( ' ', $name, 2 );
        $first_name = $parts[0];
        $last_name  = $parts[1] ?? '';

        $username = sanitize_user( strtolower( $first_name . ( $last_name ? '.' . $last_name : '' ) ) );
        if ( username_exists( $username ) ) {
            $username = $username . rand( 100, 999 );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( $user_id->get_error_message() );
        }

        wp_update_user( [
            'ID'           => $user_id,
            'display_name' => $name,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
        ] );

        wp_set_auth_cookie( $user_id, true );
        wp_set_current_user( $user_id );

        wp_send_json_success( [ 'user_id' => $user_id, 'name' => $name ] );
    }

    // ─────────────────────────────────────────
    //  AJAX — LOGOUT
    // ─────────────────────────────────────────
    public function ajax_logout(): void {
        check_ajax_referer( 'dd_auth', 'nonce' );
        wp_logout();
        wp_send_json_success();
    }

    // ─────────────────────────────────────────
    //  GOOGLE OAUTH
    // ─────────────────────────────────────────
    public function handle_google_oauth(): void {
        // Step 1 — initiate: ?dd_google_auth=1
        if ( isset( $_GET['dd_google_auth'] ) ) {
            $client_id    = get_option( 'dd_google_client_id', '' );
            $redirect_uri = home_url( '/?dd_google_callback=1' );

            if ( ! $client_id ) {
                wp_redirect( home_url( '/' ) );
                exit;
            }

            $state = wp_create_nonce( 'dd_google_state' );
            set_transient( 'dd_google_state_' . $state, 1, 300 );

            $params = http_build_query( [
                'client_id'     => $client_id,
                'redirect_uri'  => $redirect_uri,
                'response_type' => 'code',
                'scope'         => 'openid email profile',
                'state'         => $state,
                'access_type'   => 'online',
                'prompt'        => 'select_account',
            ] );

            wp_redirect( self::GOOGLE_AUTH_URL . '?' . $params );
            exit;
        }

        // Step 2 — callback: ?dd_google_callback=1&code=xxx&state=xxx
        if ( isset( $_GET['dd_google_callback'], $_GET['code'], $_GET['state'] ) ) {
            $state = sanitize_text_field( $_GET['state'] );

            // Verify state
            if ( ! get_transient( 'dd_google_state_' . $state ) ) {
                wp_redirect( home_url( '/?dd_auth_error=invalid_state' ) );
                exit;
            }
            delete_transient( 'dd_google_state_' . $state );

            $client_id     = get_option( 'dd_google_client_id', '' );
            $client_secret = get_option( 'dd_google_client_secret', '' );
            $redirect_uri  = home_url( '/?dd_google_callback=1' );
            $code          = sanitize_text_field( $_GET['code'] );

            // Exchange code for token
            $token_response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
                'body' => [
                    'code'          => $code,
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'redirect_uri'  => $redirect_uri,
                    'grant_type'    => 'authorization_code',
                ],
            ] );

            if ( is_wp_error( $token_response ) ) {
                wp_redirect( home_url( '/?dd_auth_error=token_error' ) );
                exit;
            }

            $token_data   = json_decode( wp_remote_retrieve_body( $token_response ), true );
            $access_token = $token_data['access_token'] ?? '';

            if ( ! $access_token ) {
                wp_redirect( home_url( '/?dd_auth_error=no_token' ) );
                exit;
            }

            // Get user info
            $user_response = wp_remote_get( self::GOOGLE_USERINFO_URL, [
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
            ] );

            if ( is_wp_error( $user_response ) ) {
                wp_redirect( home_url( '/?dd_auth_error=userinfo_error' ) );
                exit;
            }

            $google_user = json_decode( wp_remote_retrieve_body( $user_response ), true );
            $email       = sanitize_email( $google_user['email'] ?? '' );
            $name        = sanitize_text_field( $google_user['name']  ?? '' );
            $google_id   = sanitize_text_field( $google_user['sub']   ?? '' );

            if ( ! $email ) {
                wp_redirect( home_url( '/?dd_auth_error=no_email' ) );
                exit;
            }

            // Find or create WP user
            $user = get_user_by( 'email', $email );

            if ( ! $user ) {
                // Create new user
                $parts     = explode( ' ', $name, 2 );
                $username  = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ) );
                if ( username_exists( $username ) ) $username .= rand( 100, 999 );

                $user_id = wp_create_user( $username, wp_generate_password(), $email );
                if ( is_wp_error( $user_id ) ) {
                    wp_redirect( home_url( '/?dd_auth_error=create_failed' ) );
                    exit;
                }

                wp_update_user( [
                    'ID'           => $user_id,
                    'display_name' => $name,
                    'first_name'   => $parts[0],
                    'last_name'    => $parts[1] ?? '',
                ] );

                update_user_meta( $user_id, 'dd_google_id', $google_id );
                $user = get_user_by( 'ID', $user_id );
            }

            // Log in
            wp_set_auth_cookie( $user->ID, true );
            wp_set_current_user( $user->ID );
            do_action( 'wp_login', $user->user_login, $user );

            // Redirect to homepage or previous page
            $redirect = get_option( 'dd_auth_redirect_after_login', 'home' ) === 'home'
                ? home_url( '/' )
                : home_url( '/' );

            wp_redirect( $redirect );
            exit;
        }
    }
}
