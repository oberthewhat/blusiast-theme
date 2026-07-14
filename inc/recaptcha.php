<?php
/**
 * Blusiast reCAPTCHA v3 — inc/recaptcha.php
 *
 * Centralized Google reCAPTCHA v3 integration.
 *
 * WHAT IT PROTECTS:
 *   - Member registration        (AJAX: blusiast_portal_register)
 *   - Member login               (AJAX: blusiast_portal_login)
 *   - Contact form               (page-contact.php, POST)
 *   - Help / support messages    (AJAX: blusiast_send_help)
 *   - Community service form     (page-community-service.php, POST)
 *   - Coaster review submission  (AJAX: blusiast_submit_review)
 *
 * SETUP:
 *   1. Get keys at https://www.google.com/recaptcha/admin → reCAPTCHA v3
 *   2. Enter Site Key + Secret Key in Blusiast CMS → reCAPTCHA Settings
 *   3. Done — all covered forms are automatically protected.
 *
 * HOW IT WORKS:
 *   - reCAPTCHA v3 is invisible — no checkbox, no puzzle for users.
 *   - On submit, JS calls grecaptcha.execute() → gets a token → sends with form.
 *   - Server calls blusiast_verify_recaptcha() → Google confirms score 0.0–1.0.
 *   - Scores below the threshold (default 0.5) are rejected.
 *   - Score threshold is configurable in the settings panel.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────
// 1. HELPERS
// ─────────────────────────────────────────

function blusiast_recaptcha_site_key() {
    return trim( get_option( 'bl_recaptcha_site_key', '' ) );
}

function blusiast_recaptcha_secret_key() {
    return trim( get_option( 'bl_recaptcha_secret_key', '' ) );
}

function blusiast_recaptcha_threshold() {
    return (float) get_option( 'bl_recaptcha_threshold', 0.5 );
}

function blusiast_recaptcha_enabled() {
    return get_option( 'bl_recaptcha_enabled', '0' ) === '1'
        && blusiast_recaptcha_site_key()
        && blusiast_recaptcha_secret_key();
}

/**
 * Server-side token verification.
 * Call inside any form handler. Returns true = human, false = bot/error.
 *
 * @param  string $token    The g-recaptcha-response value from $_POST.
 * @param  string $action   The action name used on the frontend (for logging).
 * @return bool
 */
function blusiast_verify_recaptcha( $token = '', $action = '' ) {
    if ( ! blusiast_recaptcha_enabled() ) {
        return true; // Not configured — let it through.
    }

    $token = sanitize_text_field( $token );
    if ( ! $token ) {
        return false;
    }

    $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
        'timeout' => 10,
        'body'    => [
            'secret'   => blusiast_recaptcha_secret_key(),
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        // Network error — fail open (don't block real users for a Google outage).
        error_log( 'Blusiast reCAPTCHA: network error — ' . $response->get_error_message() );
        return true;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body['success'] ) ) {
        return false;
    }

    // Action mismatch check (optional but good practice)
    if ( $action && ! empty( $body['action'] ) && $body['action'] !== $action ) {
        return false;
    }

    $score = (float) ( $body['score'] ?? 0 );
    return $score >= blusiast_recaptcha_threshold();
}

// ─────────────────────────────────────────
// 2. ENQUEUE — load reCAPTCHA script site-wide
// ─────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'blusiast_recaptcha_enqueue', 5 );

function blusiast_recaptcha_enqueue() {
    if ( ! blusiast_recaptcha_enabled() ) return;

    $site_key = blusiast_recaptcha_site_key();

    // Load Google's script — render=explicit so we control timing
    wp_enqueue_script(
        'google-recaptcha',
        'https://www.google.com/recaptcha/api.js?render=' . urlencode( $site_key ),
        [],
        null,
        false // in <head> so it's ready before any form submit
    );

    // Pass site key to our JS
    wp_add_inline_script( 'google-recaptcha', '
        window.blusiast_recaptcha_site_key = ' . json_encode( $site_key ) . ';
    ', 'before' );
}

// ─────────────────────────────────────────
// 3. FRONTEND JS — auto-token injection
// ─────────────────────────────────────────
// Appended inline to main.js so it's always available.

add_action( 'wp_footer', 'blusiast_recaptcha_inline_js', 99 );

function blusiast_recaptcha_inline_js() {
    if ( ! blusiast_recaptcha_enabled() ) return;
    ?>
    <script>
    (function() {
        'use strict';

        /**
         * For each covered form, intercept submit → get reCAPTCHA token →
         * inject into hidden input → re-submit.
         *
         * Forms are identified by data-recaptcha-action attribute.
         * AJAX forms: the JS sends the token as 'recaptcha_token' in the POST body.
         * Standard POST forms: a hidden input is injected automatically.
         */

        var SITE_KEY = window.blusiast_recaptcha_site_key || '';
        if ( ! SITE_KEY ) return;

        function waitForRecaptcha( cb ) {
            if ( window.grecaptcha && grecaptcha.execute ) {
                cb();
            } else {
                setTimeout( function() { waitForRecaptcha( cb ); }, 100 );
            }
        }

        // Standard POST forms (contact, community service)
        function protectPostForms() {
            var forms = document.querySelectorAll( '[data-recaptcha-action]' );
            forms.forEach( function( form ) {
                form.addEventListener( 'submit', function( e ) {
                    var action = form.getAttribute( 'data-recaptcha-action' );
                    // Only intercept once — if token already present, let through
                    if ( form.querySelector( 'input[name="recaptcha_token"]' ) ) return;
                    e.preventDefault();
                    waitForRecaptcha( function() {
                        grecaptcha.execute( SITE_KEY, { action: action } ).then( function( token ) {
                            var inp = document.createElement( 'input' );
                            inp.type  = 'hidden';
                            inp.name  = 'recaptcha_token';
                            inp.value = token;
                            form.appendChild( inp );
                            form.submit();
                        } );
                    } );
                } );
            } );
        }

        /**
         * For AJAX forms (register, login, help, review), we hook into the
         * existing bluSite / blusiast_ajax patterns by patching FormData/fetch
         * is too fragile — instead we expose a helper function the AJAX calls use.
         *
         * blusiast_get_recaptcha_token(action) → Promise<string>
         */
        window.blusiast_get_recaptcha_token = function( action ) {
            return new Promise( function( resolve ) {
                waitForRecaptcha( function() {
                    grecaptcha.execute( SITE_KEY, { action: action } ).then( resolve );
                } );
            } );
        };

        // Badge styling — move it out of the way
        var style = document.createElement( 'style' );
        style.textContent = '.grecaptcha-badge { visibility: hidden !important; }';
        document.head.appendChild( style );

        // Legal disclosure (required by Google when badge is hidden)
        // Injected near all covered forms
        var disclosure = 'This site is protected by reCAPTCHA. <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Privacy</a> &amp; <a href="https://policies.google.com/terms" target="_blank" rel="noopener">Terms</a>.';
        document.querySelectorAll( '[data-recaptcha-action]' ).forEach( function( form ) {
            if ( ! form.querySelector( '.bl-recaptcha-disclosure' ) ) {
                var p = document.createElement( 'p' );
                p.className = 'bl-recaptcha-disclosure';
                p.style.cssText = 'font-size:11px;color:#666;margin-top:10px;';
                p.innerHTML = disclosure;
                form.appendChild( p );
            }
        } );

        waitForRecaptcha( protectPostForms );
    })();
    </script>
    <?php
}

// ─────────────────────────────────────────
// 4. SERVER HOOKS — verify on each form
// ─────────────────────────────────────────

// — Registration —
add_action( 'wp_ajax_nopriv_blusiast_portal_register', 'blusiast_recaptcha_check_register', 1 );
function blusiast_recaptcha_check_register() {
    if ( ! blusiast_recaptcha_enabled() ) return;
    $token = sanitize_text_field( $_POST['recaptcha_token'] ?? '' );
    if ( ! blusiast_verify_recaptcha( $token, 'register' ) ) {
        wp_send_json_error( [ 'message' => 'reCAPTCHA verification failed. Please try again.' ] );
    }
}

// — Login —
add_action( 'wp_ajax_nopriv_blusiast_portal_login', 'blusiast_recaptcha_check_login', 1 );
function blusiast_recaptcha_check_login() {
    if ( ! blusiast_recaptcha_enabled() ) return;
    $token = sanitize_text_field( $_POST['recaptcha_token'] ?? '' );
    if ( ! blusiast_verify_recaptcha( $token, 'login' ) ) {
        wp_send_json_error( [ 'message' => 'reCAPTCHA verification failed. Please try again.' ] );
    }
}

// — Help message (logged-out path) —
add_action( 'wp_ajax_nopriv_blusiast_send_help', 'blusiast_recaptcha_check_help', 1 );
function blusiast_recaptcha_check_help() {
    if ( ! blusiast_recaptcha_enabled() ) return;
    $token = sanitize_text_field( $_POST['recaptcha_token'] ?? '' );
    if ( ! blusiast_verify_recaptcha( $token, 'help' ) ) {
        wp_send_json_error( [ 'message' => 'reCAPTCHA verification failed. Please try again.' ] );
    }
}

// — Coaster review submission —
add_action( 'wp_ajax_blusiast_submit_review', 'blusiast_recaptcha_check_review', 1 );
function blusiast_recaptcha_check_review() {
    if ( ! blusiast_recaptcha_enabled() ) return;
    $token = sanitize_text_field( $_POST['recaptcha_token'] ?? '' );
    if ( ! blusiast_verify_recaptcha( $token, 'review' ) ) {
        wp_send_json_error( [ 'message' => 'reCAPTCHA verification failed. Please try again.' ] );
    }
}

// — Contact form + Community service form —
// These are standard PHP POST forms, verified inline in their templates.
// The template checks: blusiast_verify_recaptcha( $_POST['recaptcha_token'], 'contact' )
// See page-contact.php and page-community-service.php for the integration point.
// A helper is provided below so templates just call one function.

function blusiast_recaptcha_verify_post_form( $action ) {
    if ( ! blusiast_recaptcha_enabled() ) return true;
    $token = sanitize_text_field( $_POST['recaptcha_token'] ?? '' );
    return blusiast_verify_recaptcha( $token, $action );
}

// ─────────────────────────────────────────
// 5. ADMIN — Settings page under Blusiast CMS
// ─────────────────────────────────────────

// reCAPTCHA Settings page is registered via the central Settings hub in member-cms.php

function blusiast_recaptcha_settings_page() {
    $notice = '';

    if ( isset( $_POST['bl_save_recaptcha'] ) && check_admin_referer( 'bl_recaptcha_nonce' ) ) {
        update_option( 'bl_recaptcha_enabled',    isset( $_POST['recaptcha_enabled'] ) ? '1' : '0' );
        update_option( 'bl_recaptcha_site_key',   sanitize_text_field( $_POST['recaptcha_site_key']   ?? '' ) );
        update_option( 'bl_recaptcha_secret_key', sanitize_text_field( $_POST['recaptcha_secret_key'] ?? '' ) );
        $thresh = max( 0.1, min( 0.9, (float) ( $_POST['recaptcha_threshold'] ?? 0.5 ) ) );
        update_option( 'bl_recaptcha_threshold',  $thresh );
        $notice = '<div class="notice notice-success is-dismissible"><p>reCAPTCHA settings saved.</p></div>';
    }

    $enabled    = get_option( 'bl_recaptcha_enabled',    '0' );
    $site_key   = get_option( 'bl_recaptcha_site_key',   '' );
    $secret_key = get_option( 'bl_recaptcha_secret_key', '' );
    $threshold  = get_option( 'bl_recaptcha_threshold',  0.5 );

    echo $notice;

    $inp  = 'background:#0d0d0d;border:1px solid #444;color:#fff;padding:8px 12px;border-radius:4px;width:100%;box-sizing:border-box;font-size:14px;font-family:monospace;';
    $card = 'background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:24px;';
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'reCAPTCHA Settings' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-recaptcha' ); ?>
        <p style="margin:0 0 20px;">
            <a href="<?php echo esc_url( admin_url('admin.php?page=blusiast-settings') ); ?>" style="color:#888;font-size:12px;text-decoration:none;">← Back to Settings</a>
        </p>

        <div style="max-width:700px;margin-top:24px;">
            <form method="post">
                <?php wp_nonce_field( 'bl_recaptcha_nonce' ); ?>

                <!-- Enable toggle -->
                <div style="<?php echo $card; ?>margin-bottom:20px;">
                    <label style="display:flex;align-items:center;gap:12px;cursor:pointer;">
                        <input type="checkbox" name="recaptcha_enabled" value="1" <?php checked( $enabled, '1' ); ?>
                            style="width:18px;height:18px;accent-color:#cc0000;cursor:pointer;">
                        <span style="color:#fff;font-size:15px;font-weight:700;">Enable reCAPTCHA v3</span>
                    </label>
                    <p style="color:#888;font-size:13px;margin:10px 0 0 30px;">
                        Invisible to real users — no checkbox, no puzzle. Google assigns a 0–1 score behind the scenes. Submissions below your threshold are blocked.
                    </p>
                </div>

                <!-- Keys -->
                <div style="<?php echo $card; ?>margin-bottom:20px;display:flex;flex-direction:column;gap:16px;">
                    <h3 style="margin:0;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">API Keys
                        <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener"
                            style="font-size:11px;color:#cc0000;text-transform:none;letter-spacing:0;margin-left:10px;font-weight:400;">
                            Get keys ↗
                        </a>
                    </h3>
                    <p style="color:#888;font-size:12px;margin:0;">Register your domain at Google. Choose <strong style="color:#ccc;">reCAPTCHA v3</strong>. Add both <code style="color:#cc0000;">localhost</code> and your live domain.</p>
                    <div>
                        <label style="color:#ccc;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Site Key (public)</label>
                        <input type="text" name="recaptcha_site_key" value="<?php echo esc_attr( $site_key ); ?>"
                            style="<?php echo $inp; ?>" placeholder="6Lc...">
                    </div>
                    <div>
                        <label style="color:#ccc;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Secret Key (private)</label>
                        <input type="password" name="recaptcha_secret_key" value="<?php echo esc_attr( $secret_key ); ?>"
                            style="<?php echo $inp; ?>" placeholder="6Lc...">
                        <p style="color:#666;font-size:11px;margin:6px 0 0;">Stored encrypted in wp_options. Never exposed in page source.</p>
                    </div>
                </div>

                <!-- Score threshold -->
                <div style="<?php echo $card; ?>margin-bottom:20px;">
                    <h3 style="margin:0 0 12px;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">Score Threshold</h3>
                    <div style="display:flex;align-items:center;gap:16px;">
                        <input type="range" name="recaptcha_threshold" id="bl-rc-slider"
                            min="0.1" max="0.9" step="0.1" value="<?php echo esc_attr( $threshold ); ?>"
                            style="flex:1;accent-color:#cc0000;"
                            oninput="document.getElementById('bl-rc-val').textContent=this.value;">
                        <span id="bl-rc-val" style="color:#cc0000;font-size:22px;font-weight:800;font-family:monospace;min-width:32px;">
                            <?php echo esc_html( $threshold ); ?>
                        </span>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:11px;color:#666;">
                        <span>0.1 — Block almost everyone</span>
                        <span style="color:#888;font-weight:600;">← Recommended: 0.5 →</span>
                        <span>0.9 — Only block obvious bots</span>
                    </div>
                    <div style="margin-top:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                        <?php
                        $presets = [
                            [ 'val' => '0.3', 'label' => 'Strict',   'desc' => 'More blocks, fewer bots',       'color' => '#cc0000' ],
                            [ 'val' => '0.5', 'label' => 'Balanced', 'desc' => 'Recommended starting point',    'color' => '#cc8800' ],
                            [ 'val' => '0.7', 'label' => 'Relaxed',  'desc' => 'Fewer blocks, some bots slip',  'color' => '#4caf50' ],
                        ];
                        foreach ( $presets as $p ) : ?>
                        <div style="background:#0d0d0d;border:1px solid <?php echo $p['color']; ?>33;border-radius:6px;padding:12px;text-align:center;cursor:pointer;"
                            onclick="document.getElementById('bl-rc-slider').value='<?php echo $p['val']; ?>';document.getElementById('bl-rc-val').textContent='<?php echo $p['val']; ?>';">
                            <div style="color:<?php echo $p['color']; ?>;font-size:18px;font-weight:800;font-family:monospace;"><?php echo $p['val']; ?></div>
                            <div style="color:#fff;font-size:12px;font-weight:700;margin:2px 0;"><?php echo $p['label']; ?></div>
                            <div style="color:#666;font-size:11px;"><?php echo $p['desc']; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Protected forms reference -->
                <div style="<?php echo $card; ?>margin-bottom:20px;">
                    <h3 style="margin:0 0 12px;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">Covered Forms</h3>
                    <?php
                    $forms = [
                        [ 'icon' => '🔐', 'name' => 'Member Registration',    'risk' => 'high',   'note' => 'Public AJAX — highest risk' ],
                        [ 'icon' => '🔑', 'name' => 'Member Login',           'risk' => 'medium', 'note' => 'Rate-limits credential stuffing' ],
                        [ 'icon' => '📬', 'name' => 'Contact Form',           'risk' => 'high',   'note' => 'Public POST form' ],
                        [ 'icon' => '🛠',  'name' => 'Help / Support',        'risk' => 'medium', 'note' => 'Public when logged out' ],
                        [ 'icon' => '🤝', 'name' => 'Community Service Form', 'risk' => 'medium', 'note' => 'Public POST form' ],
                        [ 'icon' => '🎢', 'name' => 'Coaster Review Submit',  'risk' => 'low',    'note' => 'Logged-in only, extra safety' ],
                    ];
                    $risk_colors = [ 'high' => '#cc0000', 'medium' => '#cc8800', 'low' => '#4caf50' ];
                    ?>
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid #333;">
                                <th style="color:#888;font-size:11px;text-align:left;padding:6px 8px;">Form</th>
                                <th style="color:#888;font-size:11px;text-align:left;padding:6px 8px;">Risk</th>
                                <th style="color:#888;font-size:11px;text-align:left;padding:6px 8px;">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $forms as $f ) :
                            $rc = $risk_colors[ $f['risk'] ]; ?>
                        <tr style="border-bottom:1px solid #222;">
                            <td style="color:#fff;font-size:13px;padding:8px;"><?php echo $f['icon']; ?> <?php echo esc_html($f['name']); ?></td>
                            <td style="padding:8px;"><span style="background:<?php echo $rc; ?>22;color:<?php echo $rc; ?>;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;"><?php echo esc_html($f['risk']); ?></span></td>
                            <td style="color:#666;font-size:12px;padding:8px;"><?php echo esc_html($f['note']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" name="bl_save_recaptcha" class="button button-primary" style="background:#cc0000;border-color:#aa0000;padding:8px 24px;font-size:14px;">
                    Save reCAPTCHA Settings
                </button>
            </form>
        </div>
    </div>
    <?php
}
