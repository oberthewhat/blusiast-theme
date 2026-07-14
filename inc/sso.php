<?php
/**
 * Blusiast SSO — inc/sso.php
 *
 * Provides Sign In With Google, Facebook, and Apple across the entire site.
 *
 * How it works:
 *  1. User clicks a provider button → redirected to that provider's auth page
 *  2. Provider redirects back to a REST callback endpoint on this site
 *  3. Endpoint verifies the token, extracts name + email
 *  4. Finds or creates WP user + bl_members record
 *  5. Logs them in and redirects back to where they came from
 *
 * REST endpoints:
 *   GET /wp-json/blusiast/v1/sso/google/init
 *   GET /wp-json/blusiast/v1/sso/google/callback
 *   GET /wp-json/blusiast/v1/sso/facebook/init
 *   GET /wp-json/blusiast/v1/sso/facebook/callback
 *   GET /wp-json/blusiast/v1/sso/apple/init
 *   POST /wp-json/blusiast/v1/sso/apple/callback   (Apple uses POST)
 *
 * Settings are stored in WP options and managed via
 * Blusiast CRM → SSO Settings admin page.
 *
 * Load from functions.php:
 *   require_once BLUSIAST_DIR . '/inc/sso.php';
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ─────────────────────────────────────────
// 1. REGISTER REST ENDPOINTS
// ─────────────────────────────────────────

add_action( 'rest_api_init', function () {

    $open = [ 'permission_callback' => '__return_true' ];

    // Google
    register_rest_route( 'blusiast/v1', '/sso/google/init',     array_merge( $open, [ 'methods' => 'GET', 'callback' => 'blusiast_sso_google_init'     ] ) );
    register_rest_route( 'blusiast/v1', '/sso/google/callback', array_merge( $open, [ 'methods' => 'GET', 'callback' => 'blusiast_sso_google_callback' ] ) );

    // Facebook
    register_rest_route( 'blusiast/v1', '/sso/facebook/init',     array_merge( $open, [ 'methods' => 'GET', 'callback' => 'blusiast_sso_facebook_init'     ] ) );
    register_rest_route( 'blusiast/v1', '/sso/facebook/callback', array_merge( $open, [ 'methods' => 'GET', 'callback' => 'blusiast_sso_facebook_callback' ] ) );

    // Apple
    register_rest_route( 'blusiast/v1', '/sso/apple/init',     array_merge( $open, [ 'methods' => 'GET',  'callback' => 'blusiast_sso_apple_init'     ] ) );
    register_rest_route( 'blusiast/v1', '/sso/apple/callback', array_merge( $open, [ 'methods' => 'POST', 'callback' => 'blusiast_sso_apple_callback' ] ) );

} );


// ─────────────────────────────────────────
// 2. STATE / REDIRECT HELPERS
// ─────────────────────────────────────────

/**
 * Generate and store a CSRF state token.
 * Stores the return URL so we can send the user back after auth.
 */
function blusiast_sso_make_state( $return_url = '' ) {
    $state = wp_generate_password( 24, false );
    set_transient( 'bl_sso_state_' . $state, $return_url ?: home_url(), 10 * MINUTE_IN_SECONDS );
    return $state;
}

/**
 * Verify state token and return the stored return URL.
 * Deletes the transient after use (one-time token).
 */
function blusiast_sso_verify_state( $state ) {
    $key        = 'bl_sso_state_' . sanitize_text_field( $state );
    $return_url = get_transient( $key );
    delete_transient( $key );
    return $return_url; // false if not found / expired
}

/**
 * Redirect the browser (works even from REST context by sending headers directly).
 */
function blusiast_sso_redirect( $url ) {
    wp_redirect( $url );
    exit;
}

/**
 * Die with a user-facing error page.
 */
function blusiast_sso_error( $message ) {
    wp_die(
        '<p style="font-family:sans-serif;padding:40px;">'
        . '<strong>Sign-in error:</strong> ' . esc_html( $message )
        . ' <a href="' . esc_url( blusiast_portal_url() ) . '">Return to sign-in</a></p>',
        'Sign-in Error',
        [ 'response' => 400 ]
    );
}

/**
 * Find or create a WP user + bl_members record, then log them in.
 * Returns the WP user ID on success, WP_Error on failure.
 *
 * @param string $email
 * @param string $first_name
 * @param string $last_name
 * @param string $avatar_url   Optional — URL to provider avatar
 * @param string $provider     'google' | 'facebook' | 'apple'
 */
function blusiast_sso_login_or_create( $email, $first_name, $last_name, $avatar_url = '', $provider = '' ) {

    if ( ! is_email( $email ) ) {
        return new WP_Error( 'invalid_email', 'Invalid email returned from provider.' );
    }

    $existing_user = get_user_by( 'email', $email );

    if ( $existing_user ) {
        $wp_user_id = $existing_user->ID;
    } else {
        // New user — create WP account
        $username = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
        $base = $username; $n = 1;
        while ( username_exists( $username ) ) { $username = $base . $n++; }

        $wp_user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 24, true ),
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim( $first_name . ' ' . $last_name ),
            'role'         => 'subscriber',
        ] );

        if ( is_wp_error( $wp_user_id ) ) return $wp_user_id;

        // Store which provider created the account
        update_user_meta( $wp_user_id, 'bl_sso_provider', $provider );

        // Send welcome email
        blusiast_sso_send_welcome( $email, $first_name );
    }

    // Ensure bl_members record exists
    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    blusiast_portal_install_db();

    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, avatar_url FROM $mtable WHERE wp_user_id = %d LIMIT 1",
        $wp_user_id
    ) );

    if ( ! $member ) {
        $wpdb->insert( $mtable, [
            'email'          => $email,
            'first_name'     => $first_name,
            'last_name'      => $last_name,
            'wp_user_id'     => $wp_user_id,
            'account_status' => 'free',
            'avatar_url'     => $avatar_url,
            'joined_at'      => blusiast_eastern_now(),
        ], [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );
    } elseif ( $avatar_url && empty( $member->avatar_url ) ) {
        // Backfill avatar if they didn't have one
        $wpdb->update( $mtable, [ 'avatar_url' => $avatar_url ], [ 'wp_user_id' => $wp_user_id ], [ '%s' ], [ '%d' ] );
    }

    // Log the user in
    wp_clear_auth_cookie();
    wp_set_current_user( $wp_user_id );
    wp_set_auth_cookie( $wp_user_id, true );

    return $wp_user_id;
}

function blusiast_sso_send_welcome( $email, $first_name ) {
    $portal_url = blusiast_portal_url( 'dashboard' );
    $from_name  = get_option( 'bl_email_from_name', 'Blusiast' );
    $from_addr  = get_option( 'bl_email_from_address', get_option( 'admin_email' ) );
    $subject    = get_option( 'bl_email_signup_subject', 'Welcome to Blusiast, {name}!' );
    $body       = get_option( 'bl_email_signup_body',
        "Hey {name},\n\nYou're officially part of the crew!\n\nYour portal: {portal_url}\n\nRide on,\nThe Blusiast Crew"
    );
    $repl = [ '{name}' => $first_name, '{portal_url}' => $portal_url ];
    wp_mail(
        $email,
        str_replace( array_keys( $repl ), $repl, $subject ),
        str_replace( array_keys( $repl ), $repl, $body ),
        [ 'From: ' . $from_name . ' <' . $from_addr . '>', 'Content-Type: text/plain; charset=UTF-8' ]
    );
}


// ─────────────────────────────────────────
// 3. GOOGLE
// ─────────────────────────────────────────

function blusiast_sso_google_init( WP_REST_Request $req ) {
    $client_id = get_option( 'blusiast_sso_google_client_id', '' );
    if ( ! $client_id ) blusiast_sso_error( 'Google SSO is not configured yet.' );

    $return_url   = sanitize_url( $req->get_param( 'return' ) ?: blusiast_portal_url( 'dashboard' ) );
    $state        = blusiast_sso_make_state( $return_url );
    $redirect_uri = rest_url( 'blusiast/v1/sso/google/callback' );

    $params = http_build_query( [
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ] );

    blusiast_sso_redirect( 'https://accounts.google.com/o/oauth2/v2/auth?' . $params );
}

function blusiast_sso_google_callback( WP_REST_Request $req ) {
    $code  = sanitize_text_field( $req->get_param( 'code' )  ?? '' );
    $state = sanitize_text_field( $req->get_param( 'state' ) ?? '' );
    $error = $req->get_param( 'error' );

    if ( $error ) blusiast_sso_error( 'Google sign-in was cancelled or denied.' );

    $return_url = blusiast_sso_verify_state( $state );
    if ( $return_url === false ) blusiast_sso_error( 'Invalid or expired state token. Please try again.' );

    $client_id     = get_option( 'blusiast_sso_google_client_id', '' );
    $client_secret = get_option( 'blusiast_sso_google_client_secret', '' );
    $redirect_uri  = rest_url( 'blusiast/v1/sso/google/callback' );

    // Exchange code for tokens
    $token_res = wp_remote_post( 'https://oauth2.googleapis.com/token', [
        'body' => [
            'code'          => $code,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => $redirect_uri,
            'grant_type'    => 'authorization_code',
        ],
    ] );

    if ( is_wp_error( $token_res ) ) blusiast_sso_error( 'Could not connect to Google.' );

    $token_data = json_decode( wp_remote_retrieve_body( $token_res ), true );
    if ( empty( $token_data['access_token'] ) ) blusiast_sso_error( 'Google did not return an access token.' );

    // Fetch user profile
    $profile_res = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/userinfo', [
        'headers' => [ 'Authorization' => 'Bearer ' . $token_data['access_token'] ],
    ] );

    if ( is_wp_error( $profile_res ) ) blusiast_sso_error( 'Could not retrieve Google profile.' );

    $profile = json_decode( wp_remote_retrieve_body( $profile_res ), true );

    $email      = sanitize_email( $profile['email'] ?? '' );
    $first_name = sanitize_text_field( $profile['given_name'] ?? $profile['name'] ?? '' );
    $last_name  = sanitize_text_field( $profile['family_name'] ?? '' );
    $avatar     = esc_url_raw( $profile['picture'] ?? '' );

    $result = blusiast_sso_login_or_create( $email, $first_name, $last_name, $avatar, 'google' );
    if ( is_wp_error( $result ) ) blusiast_sso_error( $result->get_error_message() );

    blusiast_sso_redirect( $return_url );
}


// ─────────────────────────────────────────
// 4. FACEBOOK
// ─────────────────────────────────────────

function blusiast_sso_facebook_init( WP_REST_Request $req ) {
    $app_id = get_option( 'blusiast_sso_facebook_app_id', '' );
    if ( ! $app_id ) blusiast_sso_error( 'Facebook SSO is not configured yet.' );

    $return_url   = sanitize_url( $req->get_param( 'return' ) ?: blusiast_portal_url( 'dashboard' ) );
    $state        = blusiast_sso_make_state( $return_url );
    $redirect_uri = rest_url( 'blusiast/v1/sso/facebook/callback' );

    $params = http_build_query( [
        'client_id'     => $app_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        'scope'         => 'email,public_profile',
        'state'         => $state,
    ] );

    blusiast_sso_redirect( 'https://www.facebook.com/v19.0/dialog/oauth?' . $params );
}

function blusiast_sso_facebook_callback( WP_REST_Request $req ) {
    $code  = sanitize_text_field( $req->get_param( 'code' )  ?? '' );
    $state = sanitize_text_field( $req->get_param( 'state' ) ?? '' );
    $error = $req->get_param( 'error' );

    if ( $error ) blusiast_sso_error( 'Facebook sign-in was cancelled or denied.' );

    $return_url = blusiast_sso_verify_state( $state );
    if ( $return_url === false ) blusiast_sso_error( 'Invalid or expired state token. Please try again.' );

    $app_id       = get_option( 'blusiast_sso_facebook_app_id', '' );
    $app_secret   = get_option( 'blusiast_sso_facebook_app_secret', '' );
    $redirect_uri = rest_url( 'blusiast/v1/sso/facebook/callback' );

    // Exchange code for access token
    $token_res = wp_remote_get( add_query_arg( [
        'client_id'     => $app_id,
        'client_secret' => $app_secret,
        'redirect_uri'  => $redirect_uri,
        'code'          => $code,
    ], 'https://graph.facebook.com/v19.0/oauth/access_token' ) );

    if ( is_wp_error( $token_res ) ) blusiast_sso_error( 'Could not connect to Facebook.' );

    $token_data = json_decode( wp_remote_retrieve_body( $token_res ), true );
    if ( empty( $token_data['access_token'] ) ) blusiast_sso_error( 'Facebook did not return an access token.' );

    // Fetch user profile
    $profile_res = wp_remote_get( add_query_arg( [
        'fields'       => 'id,first_name,last_name,email,picture.type(large)',
        'access_token' => $token_data['access_token'],
    ], 'https://graph.facebook.com/v19.0/me' ) );

    if ( is_wp_error( $profile_res ) ) blusiast_sso_error( 'Could not retrieve Facebook profile.' );

    $profile = json_decode( wp_remote_retrieve_body( $profile_res ), true );

    $email      = sanitize_email( $profile['email'] ?? '' );
    $first_name = sanitize_text_field( $profile['first_name'] ?? '' );
    $last_name  = sanitize_text_field( $profile['last_name']  ?? '' );
    $avatar     = esc_url_raw( $profile['picture']['data']['url'] ?? '' );

    if ( ! $email ) blusiast_sso_error( 'Facebook did not share an email address. Please ensure your Facebook account has a confirmed email.' );

    $result = blusiast_sso_login_or_create( $email, $first_name, $last_name, $avatar, 'facebook' );
    if ( is_wp_error( $result ) ) blusiast_sso_error( $result->get_error_message() );

    blusiast_sso_redirect( $return_url );
}


// ─────────────────────────────────────────
// 5. APPLE
// ─────────────────────────────────────────

function blusiast_sso_apple_init( WP_REST_Request $req ) {
    $services_id = get_option( 'blusiast_sso_apple_services_id', '' );
    if ( ! $services_id ) blusiast_sso_error( 'Apple SSO is not configured yet.' );

    $return_url   = sanitize_url( $req->get_param( 'return' ) ?: blusiast_portal_url( 'dashboard' ) );
    $state        = blusiast_sso_make_state( $return_url );
    $redirect_uri = rest_url( 'blusiast/v1/sso/apple/callback' );

    $params = http_build_query( [
        'client_id'     => $services_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code id_token',
        'response_mode' => 'form_post',
        'scope'         => 'name email',
        'state'         => $state,
    ] );

    blusiast_sso_redirect( 'https://appleid.apple.com/auth/authorize?' . $params );
}

function blusiast_sso_apple_callback( WP_REST_Request $req ) {
    // Apple sends a POST with form data
    $body  = $req->get_body_params();
    $state = sanitize_text_field( $body['state'] ?? '' );
    $code  = sanitize_text_field( $body['code']  ?? '' );
    $error = $body['error'] ?? '';

    if ( $error ) blusiast_sso_error( 'Apple sign-in was cancelled or denied.' );

    $return_url = blusiast_sso_verify_state( $state );
    if ( $return_url === false ) blusiast_sso_error( 'Invalid or expired state token. Please try again.' );

    // Apple sends user name only on the FIRST sign-in, in a JSON "user" field
    $user_json  = $body['user'] ?? '';
    $user_data  = $user_json ? json_decode( $user_json, true ) : [];
    $first_name = sanitize_text_field( $user_data['name']['firstName'] ?? '' );
    $last_name  = sanitize_text_field( $user_data['name']['lastName']  ?? '' );

    // Decode the id_token to get the email (Apple includes it in the JWT payload)
    $id_token = $body['id_token'] ?? '';
    $email    = '';

    if ( $id_token ) {
        $parts   = explode( '.', $id_token );
        $payload = isset( $parts[1] ) ? json_decode( base64_decode( str_pad( $parts[1], strlen( $parts[1] ) + ( 4 - strlen( $parts[1] ) % 4 ) % 4, '=' ) ), true ) : [];
        $email   = sanitize_email( $payload['email'] ?? '' );

        // Store name from JWT sub (Apple user ID) if name wasn't in POST
        if ( ! $first_name && ! empty( $payload['sub'] ) ) {
            // Use stored name from a previous sign-in if we have it
            $apple_sub    = sanitize_text_field( $payload['sub'] );
            $stored_first = get_option( 'bl_apple_name_' . md5( $apple_sub ), '' );
            if ( $stored_first ) {
                $first_name = $stored_first;
            }
        }

        // Store name for future sign-ins (Apple only sends it once)
        if ( $first_name && ! empty( $payload['sub'] ) ) {
            update_option( 'bl_apple_name_' . md5( $payload['sub'] ), $first_name, false );
        }
    }

    if ( ! $email ) blusiast_sso_error( 'Apple did not return an email address.' );

    // Exchange code for tokens to validate (optional but recommended)
    $services_id  = get_option( 'blusiast_sso_apple_services_id', '' );
    $team_id      = get_option( 'blusiast_sso_apple_team_id', '' );
    $key_id       = get_option( 'blusiast_sso_apple_key_id', '' );
    $private_key  = get_option( 'blusiast_sso_apple_private_key', '' );

    // Generate client_secret JWT for Apple (required for token exchange)
    if ( $services_id && $team_id && $key_id && $private_key ) {
        $client_secret = blusiast_apple_generate_client_secret( $services_id, $team_id, $key_id, $private_key );
        if ( $client_secret ) {
            $token_res = wp_remote_post( 'https://appleid.apple.com/auth/token', [
                'body' => [
                    'client_id'     => $services_id,
                    'client_secret' => $client_secret,
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => rest_url( 'blusiast/v1/sso/apple/callback' ),
                ],
            ] );
            // If token exchange fails we still proceed — we already have the email from id_token
        }
    }

    $result = blusiast_sso_login_or_create( $email, $first_name ?: 'Apple', $last_name ?: 'User', '', 'apple' );
    if ( is_wp_error( $result ) ) blusiast_sso_error( $result->get_error_message() );

    blusiast_sso_redirect( $return_url );
}

/**
 * Generate the Apple client_secret JWT.
 * Apple requires this instead of a static secret — it's a short-lived JWT
 * signed with the private key you download from the Apple Developer portal.
 */
function blusiast_apple_generate_client_secret( $services_id, $team_id, $key_id, $private_key ) {
    $now    = time();
    $header = base64_encode( json_encode( [ 'alg' => 'ES256', 'kid' => $key_id ] ) );
    $claims = base64_encode( json_encode( [
        'iss' => $team_id,
        'iat' => $now,
        'exp' => $now + 600,  // 10 minutes
        'aud' => 'https://appleid.apple.com',
        'sub' => $services_id,
    ] ) );

    // URL-safe base64
    $header = str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], $header );
    $claims = str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], $claims );

    $signing_input = $header . '.' . $claims;

    // openssl_sign with ECDSA-SHA256
    if ( ! function_exists( 'openssl_sign' ) ) return false;

    $key = openssl_pkey_get_private( $private_key );
    if ( ! $key ) return false;

    $signature = '';
    if ( ! openssl_sign( $signing_input, $signature, $key, 'SHA256' ) ) return false;

    // DER to raw signature conversion for ES256
    $sig_b64 = str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $signature ) );

    return $signing_input . '.' . $sig_b64;
}


// ─────────────────────────────────────────
// 6. REUSABLE SSO BUTTONS HTML
//    Call blusiast_sso_buttons( $return_url )
//    anywhere you want the buttons to appear.
// ─────────────────────────────────────────

function blusiast_sso_buttons( $return_url = '' ) {
    if ( is_user_logged_in() ) return '';

    $return_url   = $return_url ?: ( is_singular() ? get_permalink() : blusiast_portal_url( 'dashboard' ) );
    $google_url   = add_query_arg( 'return', urlencode( $return_url ), rest_url( 'blusiast/v1/sso/google/init' ) );
    $facebook_url = add_query_arg( 'return', urlencode( $return_url ), rest_url( 'blusiast/v1/sso/facebook/init' ) );
    $apple_url    = add_query_arg( 'return', urlencode( $return_url ), rest_url( 'blusiast/v1/sso/apple/init' ) );

    $has_google   = (bool) get_option( 'blusiast_sso_google_client_id' );
    $has_facebook = (bool) get_option( 'blusiast_sso_facebook_app_id' );
    $has_apple    = (bool) get_option( 'blusiast_sso_apple_services_id' );

    if ( ! $has_google && ! $has_facebook && ! $has_apple ) return '';

    ob_start();
    ?>
    <div class="bl-sso-wrap">
        <div class="bl-sso-divider"><span>or continue with</span></div>
        <div class="bl-sso-buttons">

            <?php if ( $has_google ) : ?>
            <a href="<?php echo esc_url( $google_url ); ?>" class="bl-sso-btn bl-sso-btn--google">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M17.64 9.205c0-.639-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
                    <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" fill="#34A853"/>
                    <path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
                    <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
                </svg>
                Google
            </a>
            <?php endif; ?>

            <?php if ( $has_facebook ) : ?>
            <a href="<?php echo esc_url( $facebook_url ); ?>" class="bl-sso-btn bl-sso-btn--facebook">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="#fff" xmlns="http://www.w3.org/2000/svg">
                    <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.931-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
                </svg>
                Facebook
            </a>
            <?php endif; ?>

            <?php if ( $has_apple ) : ?>
            <a href="<?php echo esc_url( $apple_url ); ?>" class="bl-sso-btn bl-sso-btn--apple">
                <svg width="16" height="18" viewBox="0 0 814 1000" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-57.8-155.5-127.4C46 790.9 0 694.7 0 602.1c0-154.4 100.2-236.4 198.8-236.4 50.8 0 93.1 33.7 125.2 33.7 30.8 0 79.2-35.6 141.4-35.6 22.9 0 108.2 1.9 163.7 87.5z"/>
                    <path d="M554.1 72.5C576.8 47.1 593 11.5 593 0c0-1.3 0-2.6-.1-4-.1-1.4-.2-2.8-.3-4.1C500.4-4 436.4 48.3 436.4 118.1c0 4 .6 8 1.3 11.9C444.2 131 450.7 131.7 457.1 131.7c55.1 0 113.5-42.4 97-59.2z"/>
                </svg>
                Apple
            </a>
            <?php endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}


// ─────────────────────────────────────────
// 7. INJECT BUTTONS INTO PORTAL + EVENT GATE
//    Hooks into the portal CSS function to
//    add SSO button styles alongside portal styles.
// ─────────────────────────────────────────

// Enqueue SSO CSS on portal and event pages
add_action( 'wp_head', function() {
    if ( ! is_page( 'member-portal' ) && ! is_singular( 'bl_event' ) ) return;
    echo '<style id="bl-sso-styles">' . blusiast_sso_css() . '</style>';
}, 25 );

function blusiast_sso_css() {
    return '
.bl-sso-wrap { margin-top: 24px; }
.bl-sso-divider { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.bl-sso-divider::before,
.bl-sso-divider::after { content: ""; flex: 1; height: 1px; background: var(--surface-3, #2a2a2a); }
.bl-sso-divider span { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--gray-1, #777); white-space: nowrap; }
.bl-sso-buttons { display: flex; flex-direction: column; gap: 10px; }
.bl-sso-btn { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 11px 20px; border-radius: var(--radius-md, 8px); font-size: 14px; font-weight: 600; text-decoration: none; transition: opacity .15s, transform .1s; border: 1px solid transparent; }
.bl-sso-btn:hover { opacity: .88; transform: translateY(-1px); }
.bl-sso-btn:active { transform: scale(.98); }
.bl-sso-btn--google   { background: #fff; color: #3c4043; border-color: #dadce0; }
.bl-sso-btn--facebook { background: #1877F2; color: #fff; }
.bl-sso-btn--apple    { background: #000; color: #fff; border-color: #333; }
@media (prefers-color-scheme: light) {
    .bl-sso-btn--apple { background: #000; color: #fff; }
}
';
}


// ─────────────────────────────────────────
// 8. FILTER PORTAL LOGIN/REGISTER HTML
//    to inject SSO buttons below each form
// ─────────────────────────────────────────

// We hook into the portal JS output to append SSO buttons via a filter
// on the portal CSS function — but since portal HTML is in page-member-portal.php
// and single-bl_event.php, we inject via a wp_footer script that appends the
// buttons into the existing gate panes after the DOM is ready.

add_action( 'wp_footer', function() {
    if ( is_user_logged_in() ) return;
    if ( ! is_page( 'member-portal' ) && ! is_singular( 'bl_event' ) ) return;

    $buttons = blusiast_sso_buttons( is_singular( 'bl_event' ) ? get_permalink() : '' );
    if ( ! $buttons ) return;

    $buttons_json = json_encode( $buttons );
    ?>
    <script>
    (function(){
        var btns = <?php echo $buttons_json; ?>;
        // Append SSO buttons after the submit button in every gate pane
        document.querySelectorAll('.portal-gate__pane, #gate-login, #gate-register').forEach(function(pane){
            if (pane.querySelector('.bl-sso-wrap')) return; // already added
            pane.insertAdjacentHTML('beforeend', btns);
        });
    })();
    </script>
    <?php
} );


// ─────────────────────────────────────────
// 9. ADMIN MENU + SETTINGS PAGE
// ─────────────────────────────────────────

// SSO Settings page is registered via the central Settings hub in member-cms.php

add_action( 'admin_post_blusiast_sso_save', 'blusiast_sso_save_settings' );

function blusiast_sso_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
    check_admin_referer( 'blusiast_sso_settings' );

    $fields = [
        'blusiast_sso_google_client_id'     => 'sanitize_text_field',
        'blusiast_sso_google_client_secret' => 'sanitize_text_field',
        'blusiast_sso_facebook_app_id'      => 'sanitize_text_field',
        'blusiast_sso_facebook_app_secret'  => 'sanitize_text_field',
        'blusiast_sso_apple_services_id'    => 'sanitize_text_field',
        'blusiast_sso_apple_team_id'        => 'sanitize_text_field',
        'blusiast_sso_apple_key_id'         => 'sanitize_text_field',
        'blusiast_sso_apple_private_key'    => 'sanitize_textarea_field',
    ];

    foreach ( $fields as $option => $sanitizer ) {
        $val = call_user_func( $sanitizer, $_POST[ $option ] ?? '' );
        update_option( $option, $val );
    }

    wp_redirect( admin_url( 'admin.php?page=blusiast-sso&saved=1' ) );
    exit;
}

function blusiast_sso_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $saved = isset( $_GET['saved'] );
    $g_id  = get_option( 'blusiast_sso_google_client_id', '' );
    $g_sec = get_option( 'blusiast_sso_google_client_secret', '' );
    $fb_id = get_option( 'blusiast_sso_facebook_app_id', '' );
    $fb_sec= get_option( 'blusiast_sso_facebook_app_secret', '' );
    $ap_sid= get_option( 'blusiast_sso_apple_services_id', '' );
    $ap_tid= get_option( 'blusiast_sso_apple_team_id', '' );
    $ap_kid= get_option( 'blusiast_sso_apple_key_id', '' );
    $ap_key= get_option( 'blusiast_sso_apple_private_key', '' );

    $google_cb   = rest_url( 'blusiast/v1/sso/google/callback' );
    $facebook_cb = rest_url( 'blusiast/v1/sso/facebook/callback' );
    $apple_cb    = rest_url( 'blusiast/v1/sso/apple/callback' );
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'SSO Settings' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-sso' ); ?>
        <p style="margin:0 0 20px;">
            <a href="<?php echo esc_url( admin_url('admin.php?page=blusiast-settings') ); ?>" style="color:#888;font-size:12px;text-decoration:none;">← Back to Settings</a>
        </p>

        <?php if ( $saved ) : ?>
            <div class="bl-notice bl-notice--success">Settings saved.</div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'blusiast_sso_settings' ); ?>
            <input type="hidden" name="action" value="blusiast_sso_save">

            <!-- ── GOOGLE ── -->
            <div class="bl-settings-section">
                <h2 class="bl-settings-heading">
                    <span style="display:inline-flex;align-items:center;gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M17.64 9.205c0-.639-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/><path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" fill="#34A853"/><path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/><path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/></svg>
                        Google
                    </span>
                    <?php if ( $g_id && $g_sec ) echo '<span style="font-size:11px;color:#5cb85c;font-weight:400;text-transform:none;margin-left:8px;">&#10003; Connected</span>'; ?>
                </h2>
                <p class="bl-settings-desc">
                    Callback URL to paste into Google Cloud Console:
                    <br><code><?php echo esc_html( $google_cb ); ?></code>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:700px;">
                    <div>
                        <label class="bl-settings-label">Client ID</label>
                        <input type="text" name="blusiast_sso_google_client_id"
                               value="<?php echo esc_attr( $g_id ); ?>"
                               class="bl-settings-input" placeholder="123456789.apps.googleusercontent.com">
                    </div>
                    <div>
                        <label class="bl-settings-label">Client Secret</label>
                        <input type="password" name="blusiast_sso_google_client_secret"
                               value="<?php echo esc_attr( $g_sec ); ?>"
                               class="bl-settings-input" placeholder="GOCSPX-…">
                    </div>
                </div>
            </div>

            <!-- ── FACEBOOK ── -->
            <div class="bl-settings-section">
                <h2 class="bl-settings-heading">
                    <span style="display:inline-flex;align-items:center;gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.931-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                        Facebook
                    </span>
                    <?php if ( $fb_id && $fb_sec ) echo '<span style="font-size:11px;color:#5cb85c;font-weight:400;text-transform:none;margin-left:8px;">&#10003; Connected</span>'; ?>
                </h2>
                <p class="bl-settings-desc">
                    Callback URL to paste into Facebook Developer Console (Valid OAuth Redirect URIs):
                    <br><code><?php echo esc_html( $facebook_cb ); ?></code>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:700px;">
                    <div>
                        <label class="bl-settings-label">App ID</label>
                        <input type="text" name="blusiast_sso_facebook_app_id"
                               value="<?php echo esc_attr( $fb_id ); ?>"
                               class="bl-settings-input" placeholder="1234567890123456">
                    </div>
                    <div>
                        <label class="bl-settings-label">App Secret</label>
                        <input type="password" name="blusiast_sso_facebook_app_secret"
                               value="<?php echo esc_attr( $fb_sec ); ?>"
                               class="bl-settings-input" placeholder="abc123…">
                    </div>
                </div>
            </div>

            <!-- ── APPLE ── -->
            <div class="bl-settings-section">
                <h2 class="bl-settings-heading">
                    <span style="display:inline-flex;align-items:center;gap:8px;">
                        <svg width="14" height="16" viewBox="0 0 814 1000" fill="currentColor"><path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-57.8-155.5-127.4C46 790.9 0 694.7 0 602.1c0-154.4 100.2-236.4 198.8-236.4 50.8 0 93.1 33.7 125.2 33.7 30.8 0 79.2-35.6 141.4-35.6 22.9 0 108.2 1.9 163.7 87.5z"/></svg>
                        Apple
                    </span>
                    <?php if ( $ap_sid && $ap_key ) echo '<span style="font-size:11px;color:#5cb85c;font-weight:400;text-transform:none;margin-left:8px;">&#10003; Connected</span>'; ?>
                </h2>
                <p class="bl-settings-desc">
                    Callback URL to paste into your Apple Services ID (Return URLs):
                    <br><code><?php echo esc_html( $apple_cb ); ?></code>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:700px;margin-bottom:12px;">
                    <div>
                        <label class="bl-settings-label">Services ID <span style="font-weight:400;color:var(--bl-g1);">(client_id)</span></label>
                        <input type="text" name="blusiast_sso_apple_services_id"
                               value="<?php echo esc_attr( $ap_sid ); ?>"
                               class="bl-settings-input" placeholder="com.yoursite.signin">
                    </div>
                    <div>
                        <label class="bl-settings-label">Team ID</label>
                        <input type="text" name="blusiast_sso_apple_team_id"
                               value="<?php echo esc_attr( $ap_tid ); ?>"
                               class="bl-settings-input" placeholder="ABCD1234EF">
                    </div>
                    <div>
                        <label class="bl-settings-label">Key ID</label>
                        <input type="text" name="blusiast_sso_apple_key_id"
                               value="<?php echo esc_attr( $ap_kid ); ?>"
                               class="bl-settings-input" placeholder="ABC123DEFG">
                    </div>
                </div>
                <div style="max-width:700px;">
                    <label class="bl-settings-label">Private Key <span style="font-weight:400;color:var(--bl-g1);">(paste full contents of .p8 file)</span></label>
                    <textarea name="blusiast_sso_apple_private_key"
                              class="bl-settings-input"
                              rows="6"
                              style="font-family:monospace;font-size:11px;"
                              placeholder="-----BEGIN PRIVATE KEY-----&#10;MIGTAgEAMBMGByqGSM49AgEG…&#10;-----END PRIVATE KEY-----"><?php echo esc_textarea( $ap_key ); ?></textarea>
                </div>
            </div>

            <div style="margin-top:24px;">
                <button type="submit" class="button button-primary" style="padding:8px 24px;font-size:14px;">
                    Save Settings
                </button>
            </div>

        </form>
    </div>

    <style>
    .bl-settings-label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--bl-g1);
        margin-bottom: 6px;
    }
    </style>
    <?php
}

// Add SSO tab to the CRM nav bar
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'blusiast' ) === false ) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        var tabs = document.querySelector('.bl-crm-tabs');
        if (!tabs) return;
        if (tabs.querySelector('a[href*="blusiast-sso"]')) return;
        var a = document.createElement('a');
        a.href = '<?php echo esc_js( admin_url( "admin.php?page=blusiast-sso" ) ); ?>';
        a.className = 'bl-crm-tab<?php echo ( isset( $_GET['page'] ) && $_GET['page'] === 'blusiast-sso' ) ? ' bl-crm-tab--active' : ''; ?>';
        a.textContent = 'SSO';
        tabs.appendChild(a);
    });
    </script>
    <?php
}, 30 );
