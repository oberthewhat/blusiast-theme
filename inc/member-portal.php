<?php
/**
 * Blusiast Member Portal — inc/member-portal.php
 *
 * Handles all front-end member account functionality:
 *  - Login / register
 *  - Account profile editing
 *  - Event history
 *  - Photo submissions
 *  - Member directory (with privacy toggle)
 *  - Help / contact admin
 *
 * Load this file from functions.php:
 *   require_once BLUSIAST_DIR . '/inc/member-portal.php';
 *
 * Then create a WP page with slug "member-portal" — the template
 * page-member-portal.php will handle the rest.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ─────────────────────────────────────────
// 1. DB — extend bl_members with portal cols
// ─────────────────────────────────────────

add_action( 'after_switch_theme', 'blusiast_portal_install_db' );
add_action( 'init',               'blusiast_portal_install_db' );  // runs once, gated by version check

function blusiast_portal_install_db() {
    if ( get_option( 'blusiast_portal_db_version' ) === '1.3' ) return;

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';

    // Add portal columns if missing
    $cols = $wpdb->get_col( "DESCRIBE $mtable", 0 );

    $add = [];
    if ( ! in_array( 'bio',            $cols ) ) $add[] = "ADD COLUMN bio TEXT";
    if ( ! in_array( 'home_park',      $cols ) ) $add[] = "ADD COLUMN home_park VARCHAR(200) NOT NULL DEFAULT ''";
    if ( ! in_array( 'fave_coaster',   $cols ) ) $add[] = "ADD COLUMN fave_coaster VARCHAR(200) NOT NULL DEFAULT ''";
    if ( ! in_array( 'avatar_url',     $cols ) ) $add[] = "ADD COLUMN avatar_url VARCHAR(500) NOT NULL DEFAULT ''";
    if ( ! in_array( 'hide_from_dir',  $cols ) ) $add[] = "ADD COLUMN hide_from_dir TINYINT(1) NOT NULL DEFAULT 0";
    if ( ! in_array( 'instagram',      $cols ) ) $add[] = "ADD COLUMN instagram VARCHAR(100) NOT NULL DEFAULT ''";
    if ( ! in_array( 'handle',         $cols ) ) $add[] = "ADD COLUMN handle VARCHAR(50) NOT NULL DEFAULT ''";
    if ( ! in_array( 'dir_name_pref',  $cols ) ) $add[] = "ADD COLUMN dir_name_pref VARCHAR(10) NOT NULL DEFAULT 'real'";
    if ( ! in_array( 'coaster_count',  $cols ) ) $add[] = "ADD COLUMN coaster_count INT(6) UNSIGNED NOT NULL DEFAULT 0";

    if ( $add ) {
        $wpdb->query( "ALTER TABLE $mtable " . implode( ', ', $add ) );
    }

    // Photo submissions table
    $ptable  = $wpdb->prefix . 'bl_photo_submissions';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $ptable (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        member_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        wp_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        caption     TEXT,
        status      VARCHAR(20) NOT NULL DEFAULT 'pending',
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY member_id (member_id),
        KEY status (status)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Migration: tie photo submissions to an event (past-event galleries)
    $pcols = $wpdb->get_col( "SHOW COLUMNS FROM $ptable" );
    if ( $pcols && ! in_array( 'event_id', $pcols ) ) {
        $wpdb->query( "ALTER TABLE $ptable ADD COLUMN event_id BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD KEY event_id (event_id)" );
    }

    // Help messages table
    $htable = $wpdb->prefix . 'bl_help_messages';
    $sql2 = "CREATE TABLE IF NOT EXISTS $htable (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        member_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        wp_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        subject     VARCHAR(200) NOT NULL DEFAULT '',
        message     TEXT,
        status      VARCHAR(20) NOT NULL DEFAULT 'open',
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status)
    ) $charset;";
    dbDelta( $sql2 );

    // Remove any duplicate email rows, keeping the one with the lowest id
    $mtable = $wpdb->prefix . 'bl_members';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$mtable'" ) === $mtable ) {
        $wpdb->query(
            "DELETE m1 FROM $mtable m1
             INNER JOIN $mtable m2
             WHERE m1.email = m2.email AND m1.id > m2.id"
        );
    }

    update_option( 'blusiast_portal_db_version', '1.3' );
}


// ─────────────────────────────────────────
// 2. HELPERS
// ─────────────────────────────────────────

/**
 * Get bl_members row for the current logged-in user.
 */
function blusiast_get_current_member() {
    if ( ! is_user_logged_in() ) return null;
    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $user   = wp_get_current_user();
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $mtable WHERE email = %s LIMIT 1", $user->user_email
    ) );
}

/**
 * Get or create a bl_members row for the current user.
 * Ensures the record always exists before profile operations.
 */
function blusiast_get_or_create_member() {
    if ( ! is_user_logged_in() ) return null;

    blusiast_portal_install_db();

    $member = blusiast_get_current_member();
    if ( $member ) return $member;

    global $wpdb;
    $user = wp_get_current_user();

    // Use INSERT IGNORE so a race condition or duplicate email can never create two rows
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}bl_members WHERE email = %s LIMIT 1",
        $user->user_email
    ) );
    if ( ! $existing ) {
        $wpdb->insert( $wpdb->prefix . 'bl_members', [
            'email'          => $user->user_email,
            'first_name'     => $user->first_name ?: $user->display_name,
            'last_name'      => $user->last_name ?: '',
            'phone'          => '',
            'zip'            => '',
            'wp_user_id'     => $user->ID,
            'account_status' => 'free',
            'member_number'  => blusiast_generate_member_number(),
            'joined_at'      => blusiast_eastern_now(),
        ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );
    }

    return blusiast_get_current_member();
}

/**
 * Portal page URL.
 */
function blusiast_portal_url( $tab = '' ) {
    $page = get_page_by_path( 'member-portal' );
    $url  = $page ? get_permalink( $page->ID ) : home_url( '/member-portal/' );
    return $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
}


// ─────────────────────────────────────────
// 3. ENQUEUE PORTAL ASSETS (front-end only)
// ─────────────────────────────────────────

// Hide WP admin bar for non-admins
add_action( 'after_setup_theme', function() {
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        show_admin_bar( false );
    }
} );

add_action( 'wp_enqueue_scripts', 'blusiast_portal_enqueue' );

function blusiast_portal_enqueue() {
    if ( ! is_page( 'member-portal' ) && ! is_singular( 'bl_event' ) ) return;
    wp_add_inline_script( 'blusiast-main', blusiast_portal_js() );
    wp_add_inline_style( 'blusiast-main', blusiast_portal_css() );
    wp_localize_script( 'blusiast-main', 'bluPortal', [
        'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'blusiast_portal_nonce' ),
        'restUrl'    => rest_url( 'blusiast/v1/parks' ),
        'restNonce'  => wp_create_nonce( 'wp_rest' ),
        'portalUrl'  => blusiast_portal_url(),
        'isLoggedIn' => is_user_logged_in() ? 1 : 0,
    ] );
}


// ─────────────────────────────────────────
// 4. AJAX — LOGIN
// ─────────────────────────────────────────

add_action( 'wp_ajax_nopriv_blusiast_portal_login', 'blusiast_portal_login' );

function blusiast_portal_login() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );

    $email    = sanitize_email( $_POST['email'] ?? '' );
    $password = $_POST['password'] ?? '';

    if ( ! $email || ! $password ) {
        wp_send_json_error( [ 'message' => 'Email and password are required.' ] );
    }

    $user = get_user_by( 'email', $email );
    if ( ! $user || ! wp_check_password( $password, $user->data->user_pass, $user->ID ) ) {
        wp_send_json_error( [ 'message' => 'Incorrect email or password.' ] );
    }

    wp_set_auth_cookie( $user->ID, true );
    wp_send_json_success( [ 'redirect' => blusiast_portal_url( 'dashboard' ) ] );
}


// ─────────────────────────────────────────
// 5. AJAX — REGISTER (direct signup)
// ─────────────────────────────────────────

add_action( 'wp_ajax_nopriv_blusiast_portal_register', 'blusiast_portal_register' );

function blusiast_portal_register() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );

    $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
    $last_name  = sanitize_text_field( $_POST['last_name']  ?? '' );
    $email      = sanitize_email(      $_POST['email']      ?? '' );
    $phone      = sanitize_text_field( $_POST['phone']      ?? '' );
    $zip        = sanitize_text_field( $_POST['zip']        ?? '' );
    $password   = $_POST['password']   ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    // Validate
    $errors = [];
    if ( ! $first_name )        $errors[] = 'First name is required.';
    if ( ! $last_name )         $errors[] = 'Last name is required.';
    if ( ! is_email( $email ) ) $errors[] = 'A valid email address is required.';
    if ( email_exists( $email ) ) $errors[] = 'An account with that email already exists. Please sign in.';
    if ( strlen( $password ) < 8 ) $errors[] = 'Password must be at least 8 characters.';
    if ( $password !== $confirm )  $errors[] = 'Passwords do not match.';
    if ( empty( $_POST['consent'] ) ) $errors[] = 'Please accept the terms to continue.';

    if ( $errors ) {
        wp_send_json_error( [ 'message' => implode( ' ', $errors ) ] );
    }

    // Create WP user
    $username = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
    $base = $username; $n = 1;
    while ( username_exists( $username ) ) { $username = $base . $n++; }

    $wp_user_id = wp_insert_user( [
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $password,
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'display_name' => $first_name . ' ' . $last_name,
        'role'         => 'subscriber',
    ] );

    if ( is_wp_error( $wp_user_id ) ) {
        wp_send_json_error( [ 'message' => $wp_user_id->get_error_message() ] );
    }

    // Create bl_members record
    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';

    // Ensure table exists
    blusiast_portal_install_db();

    $wpdb->insert( $mtable, [
        'email'          => $email,
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'phone'          => $phone,
        'zip'            => $zip,
        'wp_user_id'     => $wp_user_id,
        'account_status' => 'free',
        'member_number'  => blusiast_generate_member_number(),
        'joined_at'      => blusiast_eastern_now(),
    ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );

    // Sync new member to Brevo
    if ( function_exists( 'blusiast_brevo_sync_contact' ) ) {
        blusiast_brevo_sync_contact( $email, $first_name, $last_name, 'free' );
    }

    // Log them in immediately
    wp_set_auth_cookie( $wp_user_id, true );

    // Welcome email — uses template from Email Settings
    $portal_url  = blusiast_portal_url( 'dashboard' );
    $from_name   = get_option( 'bl_email_from_name', 'Blusiast' );
    $from_addr   = get_option( 'bl_email_from_address', get_option('admin_email') );
    $tpl_subj    = get_option( 'bl_email_signup_subject', 'Welcome to Blusiast, {name}!' );
    $tpl_body    = get_option( 'bl_email_signup_body', "Hey {name},\n\nYou're officially part of the crew!\n\nYour portal: {portal_url}\n\nRide on,\nThe Blusiast Crew" );
    $repl        = [ '{name}' => $first_name, '{portal_url}' => $portal_url ];
    wp_mail( $email,
        str_replace( array_keys($repl), array_values($repl), $tpl_subj ),
        str_replace( array_keys($repl), array_values($repl), $tpl_body ),
        [ 'From: ' . $from_name . ' <' . $from_addr . '>', 'Content-Type: text/plain; charset=UTF-8' ]
    );

    // Notify admin
    wp_mail( get_option('admin_email'),
        'New Member Signup: ' . $first_name . ' ' . $last_name,
        "Name:  {$first_name} {$last_name}
Email: {$email}
Phone: {$phone}
Zip:   {$zip}

View in CRM: " . admin_url('admin.php?page=blusiast-all-members')
    );

    wp_send_json_success( [ 'redirect' => $portal_url ] );
}


// ─────────────────────────────────────────
// 6. AJAX — UPDATE PROFILE
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_update_profile', 'blusiast_update_profile' );

function blusiast_update_profile() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';

    $member = blusiast_get_or_create_member();
    if ( ! $member ) {
        wp_send_json_error( [ 'message' => 'Could not create member record. Please contact support.' ] );
    }

    $first_name    = sanitize_text_field( $_POST['first_name']   ?? '' );
    $last_name     = sanitize_text_field( $_POST['last_name']    ?? '' );
    $phone         = sanitize_text_field( $_POST['phone']        ?? '' );
    $zip           = sanitize_text_field( $_POST['zip']          ?? '' );
    $bio           = sanitize_textarea_field( $_POST['bio']      ?? '' );
    $home_park     = sanitize_text_field( $_POST['home_park']    ?? '' );
    $fave_coaster  = sanitize_text_field( $_POST['fave_coaster'] ?? '' );
    $instagram     = sanitize_text_field( $_POST['instagram']    ?? '' );
    $handle        = sanitize_text_field( $_POST['handle']        ?? '' );
    $coaster_count = max( 0, (int) ( $_POST['coaster_count'] ?? 0 ) );
    $dir_name_pref = in_array( $_POST['dir_name_pref'] ?? 'real', [ 'real', 'handle' ] ) ? $_POST['dir_name_pref'] : 'real';
    $hide_from_dir = ! empty( $_POST['hide_from_dir'] ) ? 1 : 0;

    // If they chose handle display but haven't set one, fall back to real
    if ( $dir_name_pref === 'handle' && empty( $handle ) ) {
        $dir_name_pref = 'real';
    }

    // Update WP user display name
    $wp_display = ( $dir_name_pref === 'handle' && $handle )
        ? $handle
        : $first_name . ' ' . $last_name;
    wp_update_user( [
        'ID'           => get_current_user_id(),
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'display_name' => $wp_display,
    ] );

    $wpdb->update( $mtable, [
        'first_name'    => $first_name,
        'last_name'     => $last_name,
        'phone'         => $phone,
        'zip'           => $zip,
        'bio'           => $bio,
        'home_park'     => $home_park,
        'fave_coaster'  => $fave_coaster,
        'instagram'     => sanitize_text_field( ltrim( $instagram, '@' ) ),
        'coaster_count' => $coaster_count,
        'handle'        => ltrim( $handle, '@' ),
        'dir_name_pref' => $dir_name_pref,
        'hide_from_dir' => $hide_from_dir,
    ], [ 'id' => $member->id ],
    [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ],
    [ '%d' ] );

    wp_send_json_success( [ 'message' => 'Profile updated!' ] );
}


// ─────────────────────────────────────────
// 6b. AJAX — AVATAR UPLOAD
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_upload_avatar', 'blusiast_upload_avatar' );

function blusiast_upload_avatar() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in.' ] );
    }

    if ( empty( $_FILES['avatar'] ) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK ) {
        $err = $_FILES['avatar']['error'] ?? -1;
        wp_send_json_error( [ 'message' => 'No file received (error code: ' . $err . ').' ] );
    }

    // Subscribers don't have upload_files cap — grant it temporarily for this request
    $user = wp_get_current_user();
    $user->add_cap( 'upload_files' );

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment_id = media_handle_upload( 'avatar', 0 );

    // Remove the temporary capability
    $user->remove_cap( 'upload_files' );

    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( [ 'message' => 'Upload failed: ' . $attachment_id->get_error_message() ] );
    }

    // Get a square thumbnail URL for display
    $url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
    if ( ! $url ) {
        $url = wp_get_attachment_url( $attachment_id );
    }

    // Save to bl_members
    $member = blusiast_get_or_create_member();
    if ( $member ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bl_members',
            [ 'avatar_url' => $url ],
            [ 'id' => $member->id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    wp_send_json_success( [ 'url' => $url, 'message' => 'Avatar updated!' ] );
}


// ─────────────────────────────────────────
// 6. AJAX — CHANGE PASSWORD
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_change_password', 'blusiast_change_password' );

function blusiast_change_password() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

    $current  = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password']     ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $user = wp_get_current_user();

    if ( ! wp_check_password( $current, $user->data->user_pass, $user->ID ) ) {
        wp_send_json_error( [ 'message' => 'Current password is incorrect.' ] );
    }
    if ( strlen( $new_pass ) < 8 ) {
        wp_send_json_error( [ 'message' => 'New password must be at least 8 characters.' ] );
    }
    if ( $new_pass !== $confirm ) {
        wp_send_json_error( [ 'message' => 'Passwords do not match.' ] );
    }

    wp_set_password( $new_pass, $user->ID );
    wp_set_auth_cookie( $user->ID, true ); // keep them logged in after change
    wp_send_json_success( [ 'message' => 'Password changed successfully.' ] );
}


// ─────────────────────────────────────────
// 7. AJAX — SUBMIT PHOTO
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_submit_photo', 'blusiast_submit_photo' );

function blusiast_submit_photo() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Not logged in.' ] );

    $member = blusiast_get_or_create_member();
    if ( ! $member ) wp_send_json_error( [ 'message' => 'Member account not found.' ] );

    if ( empty( $_FILES['photo'] ) ) wp_send_json_error( [ 'message' => 'No file uploaded.' ] );

    // Grant upload capability temporarily for subscribers
    $current_user = wp_get_current_user();
    $current_user->add_cap( 'upload_files' );

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment_id = media_handle_upload( 'photo', 0 );

    $current_user->remove_cap( 'upload_files' );

    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( [ 'message' => 'Upload failed: ' . $attachment_id->get_error_message() ] );
    }

    $caption = sanitize_textarea_field( $_POST['caption'] ?? '' );

    $event_id = absint( $_POST['event_id'] ?? 0 );
    if ( $event_id && get_post_type( $event_id ) !== 'bl_event' ) $event_id = 0;

    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'bl_photo_submissions', [
        'member_id'     => $member->id,
        'wp_user_id'    => get_current_user_id(),
        'attachment_id' => $attachment_id,
        'event_id'      => $event_id,
        'caption'       => $caption,
        'status'        => 'pending',
        'submitted_at'  => blusiast_eastern_now(),
    ], [ '%d', '%d', '%d', '%d', '%s', '%s', '%s' ] );

    // Notify admin
    wp_mail(
        get_option( 'admin_email' ),
        'New Photo Submission — ' . $member->first_name . ' ' . $member->last_name,
        "A member submitted a photo for review.\n\nMember: {$member->first_name} {$member->last_name}\nEmail: {$member->email}\nCaption: {$caption}\n\nReview in the media library: " . admin_url( 'upload.php' )
    );

    wp_send_json_success( [ 'message' => 'Photo submitted! Our team will review it shortly.' ] );
}


// ─────────────────────────────────────────
// 8. AJAX — SEND HELP MESSAGE
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_send_help',        'blusiast_send_help' );
add_action( 'wp_ajax_nopriv_blusiast_send_help', 'blusiast_send_help' );

function blusiast_send_help() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );

    $subject = sanitize_text_field( $_POST['subject']  ?? '' );
    $message = sanitize_textarea_field( $_POST['message'] ?? '' );
    $name    = sanitize_text_field( $_POST['name']    ?? '' );
    $email   = sanitize_email( $_POST['email']        ?? '' );

    if ( ! $subject || ! $message ) {
        wp_send_json_error( [ 'message' => 'Subject and message are required.' ] );
    }

    // If logged in, pull from member record
    $member_id  = 0;
    $wp_user_id = 0;
    if ( is_user_logged_in() ) {
        $wp_user_id = get_current_user_id();
        $member     = blusiast_get_current_member();
        if ( $member ) {
            $member_id = $member->id;
            $name      = $member->first_name . ' ' . $member->last_name;
            $email     = $member->email;
        }
    }

    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'bl_help_messages', [
        'member_id'    => $member_id,
        'wp_user_id'   => $wp_user_id,
        'subject'      => $subject,
        'message'      => $message,
        'status'       => 'open',
        'submitted_at' => blusiast_eastern_now(),
    ], [ '%d', '%d', '%s', '%s', '%s', '%s' ] );

    // Email admin
    wp_mail(
        get_option( 'admin_email' ),
        '🆘 Help Request: ' . $subject . ' — ' . $name,
        "From: {$name} <{$email}>\n\nSubject: {$subject}\n\n{$message}\n\nRespond at: " . admin_url( 'admin.php?page=blusiast-help' )
    );

    // Confirm to sender
    if ( $email ) {
        wp_mail( $email, 'We got your message — Blusiast', "Hey {$name},\n\nWe received your message and will get back to you shortly.\n\nYour message:\n{$message}\n\n— The Blusiast Crew" );
    }

    wp_send_json_success( [ 'message' => "Message sent! We'll get back to you soon." ] );
}


// ─────────────────────────────────────────
// 9. HEADER — smart Sign In / portal link
// ─────────────────────────────────────────

function blusiast_header_account_buttons() {
    $portal_url = blusiast_portal_url();
    if ( is_user_logged_in() ) {
        $user   = wp_get_current_user();
        $logout = wp_logout_url( home_url() );
        echo '<a href="' . esc_url( $portal_url ) . '" class="bl-btn bl-btn--ghost bl-btn--sm">Member Portal</a>';
        echo '<a href="' . esc_url( $logout ) . '" class="bl-btn bl-btn--primary bl-btn--sm">Log Out</a>';
    } else {
        echo '<a href="' . esc_url( $portal_url ) . '" class="bl-btn bl-btn--ghost bl-btn--sm">Sign In</a>';
        echo '<a href="' . esc_url( add_query_arg( 'tab', 'register', $portal_url ) ) . '" class="bl-btn bl-btn--primary bl-btn--sm">Join Now</a>';
    }
}


// ─────────────────────────────────────────
// 9b. AJAX — APPROVE / REJECT PHOTO
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_review_photo', 'blusiast_review_photo' );

function blusiast_review_photo() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    global $wpdb;
    $ptable      = $wpdb->prefix . 'bl_photo_submissions';
    $id          = absint( $_POST['id'] ?? 0 );
    $photo_action = sanitize_text_field( $_POST['photo_action'] ?? '' );

    if ( ! in_array( $photo_action, [ 'approved', 'rejected' ], true ) ) {
        wp_send_json_error( [ 'message' => 'Invalid action.' ] );
    }

    $wpdb->update(
        $ptable,
        [ 'status' => $photo_action ],
        [ 'id'     => $id ],
        [ '%s' ],
        [ '%d' ]
    );

    // Notify member on approval
    if ( $photo_action === 'approved' ) {
        $photo = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ptable WHERE id = %d", $id ) );
        if ( $photo && $photo->wp_user_id ) {
            $user = get_userdata( $photo->wp_user_id );
            if ( $user ) {
                wp_mail(
                    $user->user_email,
                    'Your photo was approved — Blusiast',
                    "Hey {$user->first_name},

Great shot! Your photo has been approved and added to your profile gallery.

— The Blusiast Crew",
                    [ 'From: Blusiast <' . get_option( 'admin_email' ) . '>' ]
                );
            }
        }
    }

    wp_send_json_success();
}


// ─────────────────────────────────────────
// 10. ADMIN — Help Messages CRM page
// ─────────────────────────────────────────

// Photos and Help pages are handled by the main blusiast_admin_enqueue() in member-cms.php
// which matches any hook containing 'blusiast' — no separate hook needed here.

add_action( 'admin_menu', 'blusiast_photo_menu', 19 );

function blusiast_photo_menu() {
    global $wpdb;
    $ptable = $wpdb->prefix . 'bl_photo_submissions';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$ptable'" ) !== $ptable ) return;
    $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ptable WHERE status='pending'" );
    $badge   = $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';
    add_submenu_page( 'blusiast-cms', 'Photo Submissions', 'Photos ' . $badge, 'manage_options',
        'blusiast-photos', 'blusiast_photo_submissions_page' );
}

function blusiast_photo_submissions_page() {
    global $wpdb;
    $ptable = $wpdb->prefix . 'bl_photo_submissions';
    $mtable = $wpdb->prefix . 'bl_members';
    $photos = $wpdb->get_results(
        "SELECT p.*, m.first_name, m.last_name, m.handle, m.dir_name_pref, m.email
         FROM $ptable p LEFT JOIN $mtable m ON m.id = p.member_id
         ORDER BY p.submitted_at DESC"
    );
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Photo Submissions' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-photos' ); ?>
        <div class="bl-table-wrap">
            <div class="bl-table-toolbar"><h2>Submissions (<?php echo count($photos); ?>)</h2></div>
            <?php if ( $photos ) : ?>
            <table class="bl-table">
                <thead><tr><th>Photo</th><th>Member</th><th>Event</th><th>Caption</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $photos as $p ) :
                    $img_url = $p->attachment_id ? wp_get_attachment_image_url( $p->attachment_id, 'thumbnail' ) : '';
                    $use_h   = ! empty( $p->handle ) && ( $p->dir_name_pref ?? 'real' ) === 'handle';
                    $name    = $use_h ? $p->handle : $p->first_name . ' ' . $p->last_name;
                    $evt_id    = isset( $p->event_id ) ? (int) $p->event_id : 0;
                    $evt_title = $evt_id ? get_the_title( $evt_id ) : '';
                ?>
                <tr>
                    <td style="width:72px;"><div style="width:64px;height:64px;border-radius:6px;overflow:hidden;background:var(--bl-s3);border:1px solid var(--bl-s4);"><?php if ($img_url) : ?><img src="<?php echo esc_url($img_url); ?>" style="width:100%;height:100%;object-fit:cover;display:block;" alt=""><?php else : ?><div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--bl-g1);font-size:20px;">📷</div><?php endif; ?></div></td>
                    <td class="bl-td-name"><?php echo esc_html( $name ); ?></td>
                    <td style="font-size:12px;max-width:170px;">
                        <?php if ( $evt_title ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $evt_id ) ); ?>"><?php echo esc_html( $evt_title ); ?></a>
                        <?php else : ?>
                            <span style="color:var(--bl-g1);">General</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;max-width:200px;"><?php echo esc_html( $p->caption ?: '—' ); ?></td>
                    <td><span class="bl-status bl-status--<?php echo $p->status === 'pending' ? 'pending' : ($p->status === 'approved' ? 'confirmed' : 'cancelled'); ?>"><?php echo esc_html(ucfirst($p->status)); ?></span></td>
                    <td style="font-size:11px;white-space:nowrap;"><?php echo esc_html(date('M j, Y', strtotime($p->submitted_at))); ?></td>
                    <td><div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if ($p->status === 'pending') : ?>
                        <button class="bl-btn-sm bl-photo-action" data-id="<?php echo (int)$p->id; ?>" data-action="approved" style="background:rgba(92,184,92,.15);border-color:rgba(92,184,92,.3);color:#5cb85c;">✓ Approve</button>
                        <button class="bl-btn-sm bl-photo-action" data-id="<?php echo (int)$p->id; ?>" data-action="rejected" style="background:rgba(204,0,0,.15);border-color:rgba(204,0,0,.3);color:#ff6666;">✕ Reject</button>
                        <?php endif; ?>
                        <?php if ($img_url) : ?><a href="<?php echo esc_url(wp_get_attachment_url($p->attachment_id)); ?>" class="bl-btn-sm" target="_blank">View Full</a><?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <div class="bl-empty"><strong>No Submissions Yet</strong>Member photo submissions will appear here for review.</div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    jQuery(function($){
        $(document).on('click','.bl-photo-action',function(){
            var btn=$(this), id=btn.data('id'), action=btn.data('action');
            $.post(ajaxurl,{action:'blusiast_review_photo',nonce:'<?php echo wp_create_nonce("blusiast_admin_nonce"); ?>',id:id,photo_action:action},function(r){
                if(r.success) btn.closest('tr').find('.bl-status').text(action.charAt(0).toUpperCase()+action.slice(1)).attr('class','bl-status '+(action==='approved'?'bl-status--confirmed':'bl-status--cancelled')), btn.closest('td').find('.bl-photo-action').remove();
            });
        });
    });
    </script>
    <?php
}

add_action( 'admin_menu', 'blusiast_help_menu', 20 );

function blusiast_help_menu() {
    global $wpdb;
    $htable = $wpdb->prefix . 'bl_help_messages';
    // Check table exists before querying
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$htable'" ) !== $htable ) return;
    $open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $htable WHERE status='open'" );
    $badge = $open ? ' <span class="awaiting-mod">' . $open . '</span>' : '';

    add_submenu_page( 'blusiast-cms', 'Help Messages', 'Help ' . $badge, 'manage_options',
        'blusiast-help', 'blusiast_help_page' );
}

add_action( 'wp_ajax_blusiast_close_help', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );
    global $wpdb;
    $id = absint( $_POST['id'] ?? 0 );
    $wpdb->update( $wpdb->prefix . 'bl_help_messages', [ 'status' => 'closed' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
    wp_send_json_success();
} );

add_action( 'wp_ajax_blusiast_reply_help', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    $id      = absint( $_POST['id'] ?? 0 );
    $reply   = sanitize_textarea_field( $_POST['reply'] ?? '' );
    $to      = sanitize_email( $_POST['email'] ?? '' );
    $subject = sanitize_text_field( $_POST['subject'] ?? '' );
    $name    = sanitize_text_field( $_POST['name'] ?? '' );

    if ( ! $id || ! $reply || ! is_email( $to ) ) {
        wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
    }

    $sent = wp_mail(
        $to,
        'Re: ' . $subject . ' — Blusiast',
        "Hey {$name},\n\n{$reply}\n\n— The Blusiast Team",
        [
            'From: Blusiast <' . get_option( 'admin_email' ) . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ]
    );

    if ( ! $sent ) {
        wp_send_json_error( [ 'message' => 'Email could not be sent. Check your mail settings.' ] );
    }

    // Auto-close the ticket after replying
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'bl_help_messages', [ 'status' => 'closed' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );

    wp_send_json_success( [ 'message' => 'Reply sent and ticket closed.' ] );
} );

function blusiast_help_page() {
    global $wpdb;
    $htable   = $wpdb->prefix . 'bl_help_messages';
    $mtable   = $wpdb->prefix . 'bl_members';
    $messages = $wpdb->get_results(
        "SELECT h.*, m.first_name, m.last_name, m.email AS member_email
         FROM $htable h
         LEFT JOIN $mtable m ON m.id = h.member_id
         ORDER BY h.submitted_at DESC"
    );
    $nonce = wp_create_nonce( 'blusiast_admin_nonce' );
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Help Messages' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-help' ); ?>
        <div class="bl-table-wrap">
            <div class="bl-table-toolbar"><h2>Help Messages (<?php echo count( $messages ); ?>)</h2></div>
            <?php if ( $messages ) : ?>

            <style>
                /* ── Help message list ── */
                .bl-help-list { display:flex;flex-direction:column;gap:0; }
                .bl-help-row { display:grid;grid-template-columns:36px 1fr 1fr minmax(80px,120px) 130px 200px;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid var(--bl-s3,#222);cursor:pointer;transition:background .15s; }
                .bl-help-row:hover { background:rgba(255,255,255,.04); }
                .bl-help-row.is-open-ticket { border-left:3px solid var(--bl-red,#e63946); }
                .bl-help-num { font-size:11px;color:var(--bl-g1,#888); }
                .bl-help-from { font-size:13px;font-weight:600;color:var(--bl-white,#fff); }
                .bl-help-email-small { font-size:11px;color:var(--bl-g1,#888);margin-top:2px; }
                .bl-help-subject { font-size:13px;color:var(--bl-g2,#aaa); }
                .bl-help-preview { font-size:12px;color:var(--bl-g1,#888);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
                .bl-help-date { font-size:11px;color:var(--bl-g1,#888);white-space:nowrap; }
                .bl-help-actions { display:flex;gap:6px;justify-content:flex-end; }

                /* ── Modal ── */
                #bl-help-modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:100000;align-items:center;justify-content:center; }
                #bl-help-modal-overlay.open { display:flex; }
                #bl-help-modal { background:var(--bl-s1,#141414);border:1px solid var(--bl-s3,#2a2a2a);border-radius:12px;width:660px;max-width:96vw;max-height:90vh;overflow-y:auto;padding:32px;position:relative; }
                .bl-modal-close { position:absolute;top:16px;right:16px;background:none;border:none;color:var(--bl-g1,#888);font-size:20px;cursor:pointer;line-height:1;padding:4px 8px; }
                .bl-modal-close:hover { color:var(--bl-white,#fff); }
                .bl-modal-label { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1,#888);margin-bottom:4px; }
                .bl-modal-value { font-size:14px;color:var(--bl-white,#fff);margin-bottom:16px;line-height:1.6; }
                .bl-modal-message-box { background:var(--bl-s2,#1a1a1a);border:1px solid var(--bl-s3,#2a2a2a);border-radius:8px;padding:16px;font-size:14px;color:var(--bl-g2,#bbb);line-height:1.75;white-space:pre-wrap;margin-bottom:24px; }
                .bl-modal-divider { border:none;border-top:1px solid var(--bl-s3,#2a2a2a);margin:20px 0; }
                .bl-modal-reply-label { font-family:var(--bl-fd,sans-serif);font-size:14px;font-weight:700;text-transform:uppercase;color:var(--bl-white,#fff);margin-bottom:12px; }
                .bl-modal-textarea { width:100%;background:var(--bl-s2,#1a1a1a);border:1px solid var(--bl-s3,#2a2a2a);border-radius:8px;color:var(--bl-white,#fff);font-size:14px;font-family:inherit;padding:12px;resize:vertical;min-height:120px;box-sizing:border-box; }
                .bl-modal-textarea:focus { outline:none;border-color:var(--bl-red,#e63946); }
                .bl-modal-footer { display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap; }
                .bl-modal-msg { font-size:13px;padding:8px 12px;border-radius:6px;display:none; }
                .bl-modal-msg.success { background:rgba(92,184,92,.1);border:1px solid rgba(92,184,92,.3);color:#5cb85c; }
                .bl-modal-msg.error   { background:rgba(204,0,0,.1);border:1px solid rgba(204,0,0,.3);color:#ff6666; }
            </style>

            <div class="bl-help-list">
                <div class="bl-help-row" style="cursor:default;background:rgba(255,255,255,.02);border-bottom:2px solid var(--bl-s3,#222);padding:10px 20px;">
                    <span style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--bl-g1,#888);">#</span>
                    <span style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--bl-g1,#888);">From</span>
                    <span style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--bl-g1,#888);">Subject</span>
                    <span style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--bl-g1,#888);">Status</span>
                    <span style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--bl-g1,#888);">Date</span>
                    <span></span>
                </div>

                <?php foreach ( $messages as $msg ) :
                    $u         = $msg->wp_user_id ? get_userdata( $msg->wp_user_id ) : null;
                    $name      = $msg->first_name ? trim( $msg->first_name . ' ' . $msg->last_name ) : ( $u ? $u->display_name : 'Unknown' );
                    $email     = $msg->member_email ?: ( $u ? $u->user_email : '' );
                    $is_open   = ( $msg->status === 'open' );
                    $msg_json  = wp_json_encode( [
                        'id'      => (int) $msg->id,
                        'name'    => $name,
                        'email'   => $email,
                        'subject' => $msg->subject,
                        'message' => $msg->message,
                        'date'    => date( 'F j, Y \a\t g:ia', strtotime( $msg->submitted_at ) ),
                        'status'  => $msg->status,
                    ] );
                ?>
                <div class="bl-help-row <?php echo $is_open ? 'is-open-ticket' : ''; ?>"
                     data-msg="<?php echo esc_attr( $msg_json ); ?>"
                     id="bl-help-row-<?php echo (int) $msg->id; ?>">
                    <span class="bl-help-num"><?php echo (int) $msg->id; ?></span>
                    <div>
                        <div class="bl-help-from"><?php echo esc_html( $name ); ?></div>
                        <?php if ( $email ) : ?><div class="bl-help-email-small"><?php echo esc_html( $email ); ?></div><?php endif; ?>
                    </div>
                    <div>
                        <div class="bl-help-subject"><?php echo esc_html( $msg->subject ); ?></div>
                        <div class="bl-help-preview"><?php echo esc_html( wp_trim_words( $msg->message, 12 ) ); ?></div>
                    </div>
                    <span class="bl-status <?php echo $is_open ? 'bl-status--pending' : 'bl-status--confirmed'; ?>">
                        <?php echo $is_open ? 'Open' : 'Closed'; ?>
                    </span>
                    <span class="bl-help-date"><?php echo esc_html( date( 'M j, Y g:ia', strtotime( $msg->submitted_at ) ) ); ?></span>
                    <div class="bl-help-actions">
                        <button class="bl-btn-sm bl-read-reply"
                                style="background:var(--bl-red,#e63946);border-color:var(--bl-red,#e63946);color:#fff;">
                            Read &amp; Reply ↗
                        </button>
                        <?php if ( $is_open ) : ?>
                        <button class="bl-btn-sm bl-close-help" data-id="<?php echo (int) $msg->id; ?>">Mark Closed</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else : ?>
                <div class="bl-empty"><strong>No Messages Yet</strong>Help requests from members will appear here.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── FULL MESSAGE + REPLY MODAL ── -->
    <div id="bl-help-modal-overlay">
        <div id="bl-help-modal" role="dialog" aria-modal="true">
            <button class="bl-modal-close" onclick="blHelpClose()" title="Close">✕</button>

            <div style="font-family:var(--bl-fd,sans-serif);font-size:22px;font-weight:800;text-transform:uppercase;color:var(--bl-white,#fff);margin-bottom:20px;" id="bl-modal-subject"></div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                <div>
                    <div class="bl-modal-label">From</div>
                    <div class="bl-modal-value" id="bl-modal-from"></div>
                </div>
                <div>
                    <div class="bl-modal-label">Date</div>
                    <div class="bl-modal-value" id="bl-modal-date"></div>
                </div>
            </div>

            <div class="bl-modal-label">Message</div>
            <div class="bl-modal-message-box" id="bl-modal-message"></div>

            <hr class="bl-modal-divider">

            <div class="bl-modal-reply-label">Reply to Member</div>
            <textarea class="bl-modal-textarea" id="bl-modal-reply" placeholder="Type your reply here…" rows="5"></textarea>
            <div class="bl-modal-footer">
                <button class="bl-btn-sm" id="bl-modal-send-btn"
                        style="background:var(--bl-red,#e63946);border-color:var(--bl-red,#e63946);color:#fff;padding:8px 18px;font-size:13px;">
                    Send Reply &amp; Close Ticket
                </button>
                <div class="bl-modal-msg" id="bl-modal-status"></div>
            </div>
        </div>
    </div>

    <script>
    var blHelpNonce = '<?php echo $nonce; ?>';
    var blHelpAjax  = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
    var blHelpCurrent = null;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.bl-read-reply');
        if (!btn) return;
        e.stopPropagation();
        var row = btn.closest('.bl-help-row');
        var data = JSON.parse(row.dataset.msg);
        blHelpOpen(data);
    });

    function blHelpOpen(data) {
        blHelpCurrent = data;
        document.getElementById('bl-modal-subject').textContent = data.subject;
        document.getElementById('bl-modal-from').textContent    = data.name + (data.email ? ' <' + data.email + '>' : '');
        document.getElementById('bl-modal-date').textContent    = data.date;
        document.getElementById('bl-modal-message').textContent = data.message;
        document.getElementById('bl-modal-reply').value         = '';
        var statusEl = document.getElementById('bl-modal-status');
        statusEl.style.display = 'none';
        statusEl.className = 'bl-modal-msg';
        var sendBtn = document.getElementById('bl-modal-send-btn');
        sendBtn.disabled = (data.status === 'closed');
        sendBtn.textContent = data.status === 'closed' ? 'Ticket Closed' : 'Send Reply & Close Ticket';
        document.getElementById('bl-help-modal-overlay').classList.add('open');
    }

    function blHelpClose() {
        document.getElementById('bl-help-modal-overlay').classList.remove('open');
        blHelpCurrent = null;
    }

    document.getElementById('bl-help-modal-overlay').addEventListener('click', function(e) {
        if (e.target === this) blHelpClose();
    });

    document.getElementById('bl-modal-send-btn').addEventListener('click', function() {
        var reply = document.getElementById('bl-modal-reply').value.trim();
        var statusEl = document.getElementById('bl-modal-status');
        if (!reply) {
            statusEl.textContent = 'Please write a reply before sending.';
            statusEl.className = 'bl-modal-msg error';
            statusEl.style.display = 'inline-block';
            return;
        }
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Sending…';
        statusEl.style.display = 'none';

        var data = new URLSearchParams({
            action:  'blusiast_reply_help',
            nonce:   blHelpNonce,
            id:      blHelpCurrent.id,
            reply:   reply,
            email:   blHelpCurrent.email,
            subject: blHelpCurrent.subject,
            name:    blHelpCurrent.name,
        });

        fetch(blHelpAjax, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: data.toString() })
            .then(function(r){ return r.json(); })
            .then(function(r) {
                if (r.success) {
                    statusEl.textContent = '✓ Reply sent! Ticket closed.';
                    statusEl.className = 'bl-modal-msg success';
                    statusEl.style.display = 'inline-block';
                    btn.textContent = 'Ticket Closed';
                    // Update row in the list
                    var row = document.getElementById('bl-help-row-' + blHelpCurrent.id);
                    if (row) {
                        row.classList.remove('is-open-ticket');
                        var badge = row.querySelector('.bl-status');
                        if (badge) { badge.className='bl-status bl-status--confirmed'; badge.textContent='Closed'; }
                        var closeBtn = row.querySelector('.bl-close-help');
                        if (closeBtn) closeBtn.remove();
                    }
                } else {
                    statusEl.textContent = (r.data && r.data.message) ? r.data.message : 'Error sending reply.';
                    statusEl.className = 'bl-modal-msg error';
                    statusEl.style.display = 'inline-block';
                    btn.disabled = false;
                    btn.textContent = 'Send Reply & Close Ticket';
                }
            })
            .catch(function() {
                statusEl.textContent = 'Network error. Please try again.';
                statusEl.className = 'bl-modal-msg error';
                statusEl.style.display = 'inline-block';
                btn.disabled = false;
                btn.textContent = 'Send Reply & Close Ticket';
            });
    });

    jQuery(function($){
        $(document).on('click', '.bl-close-help', function(e){
            e.stopPropagation();
            var btn=$(this), id=btn.data('id');
            $.post(blHelpAjax, {action:'blusiast_close_help', nonce:blHelpNonce, id:id}, function(r){
                if(r.success) {
                    var row = document.getElementById('bl-help-row-'+id);
                    if (row) {
                        row.classList.remove('is-open-ticket');
                        var badge = row.querySelector('.bl-status');
                        if (badge) { badge.className='bl-status bl-status--confirmed'; badge.textContent='Closed'; }
                    }
                    btn.remove();
                }
            });
        });
    });
    </script>
    <?php
}


// ─────────────────────────────────────────
// 11. PORTAL CSS
// ─────────────────────────────────────────

function blusiast_portal_css() { return '
/* ── PORTAL LAYOUT ── */
.portal-wrap { min-height: 80vh; padding: 120px 0 80px; }
.portal-gate { max-width: 440px; margin: 0 auto; }
.portal-gate__tabs { display: flex; gap: 0; margin-bottom: 32px; border-bottom: 1px solid var(--surface-3); }
.portal-gate__tab { flex: 1; text-align: center; padding: 12px; font-family: var(--font-display); font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--gray-1); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .15s, border-color .15s; }
.portal-gate__tab.active { color: var(--white); border-bottom-color: var(--red); }
.portal-body { display: grid; grid-template-columns: 240px 1fr; gap: 32px; align-items: start; }
@media(max-width:768px){ .portal-body { grid-template-columns: 1fr; } }

/* ── SIDEBAR ── */
.portal-sidebar { background: var(--surface-1); border: 1px solid var(--surface-3); border-radius: var(--radius-lg); overflow: hidden; position: sticky; top: 100px; }
@media (max-width: 768px) { .portal-sidebar { position: static; top: auto; } }
.portal-sidebar__member { padding: 24px 20px; border-bottom: 1px solid var(--surface-3); display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center; }
.portal-avatar { width: 72px; height: 72px; border-radius: 50%; background: var(--red); color: var(--white); font-family: var(--font-display); font-size: 28px; font-weight: 800; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 3px solid var(--surface-3); flex-shrink: 0; }
.portal-avatar img { width: 100%; height: 100%; object-fit: cover; }
.portal-sidebar__name { font-family: var(--font-display); font-size: 18px; font-weight: 800; text-transform: uppercase; color: var(--white); line-height: 1.1; }
.portal-sidebar__email { font-size: 12px; color: var(--gray-1); }
.portal-sidebar__status { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; padding: 3px 10px; border-radius: 100px; }
.portal-sidebar__status--free { background: var(--surface-3); color: var(--gray-2); }
.portal-sidebar__status--active { background: rgba(92,184,92,.15); color: #5cb85c; border: 1px solid rgba(92,184,92,.3); }
.portal-nav { padding: 8px 0; }
.portal-nav__item { display: flex; align-items: center; gap: 10px; padding: 10px 20px; font-size: 14px; font-weight: 500; color: var(--gray-2); cursor: pointer; transition: background .15s, color .15s; border-left: 3px solid transparent; text-decoration: none; }
.portal-nav__item:hover { background: var(--surface-2); color: var(--white); }
.portal-nav__item.active { background: var(--surface-2); color: var(--white); border-left-color: var(--red); }
.portal-nav__icon { width: 16px; flex-shrink: 0; opacity: .6; }
.portal-nav__item.active .portal-nav__icon { opacity: 1; }

/* ── MAIN CONTENT PANELS ── */
.portal-panel { display: none; }
.portal-panel.active { display: block; }
.portal-card { background: var(--surface-1); border: 1px solid var(--surface-3); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 20px; }
.portal-card__title { font-family: var(--font-display); font-size: 20px; font-weight: 800; text-transform: uppercase; color: var(--white); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.portal-card__title-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--red); flex-shrink: 0; }

/* ── FORM ELEMENTS (portal) ── */
.portal-form { display: flex; flex-direction: column; gap: 16px; }
.portal-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media(max-width:480px){ .portal-form-row { grid-template-columns: 1fr; } }
.portal-field { display: flex; flex-direction: column; gap: 6px; }
.portal-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .1em; color: var(--gray-2); }
.portal-input { background: var(--surface-2); border: 1px solid var(--surface-4); border-radius: var(--radius-md); color: var(--white); font-family: var(--font-body); font-size: 15px; padding: 10px 14px; width: 100%; transition: border-color .15s; }
.portal-input::placeholder { color: var(--gray-1); }
.portal-input:focus { outline: none; border-color: var(--red); }
.portal-textarea { min-height: 100px; resize: vertical; }
.portal-toggle { display: flex; align-items: flex-start; gap: 12px; background: var(--surface-2); border: 1px solid var(--surface-4); border-radius: var(--radius-md); padding: 14px; cursor: pointer; }
.portal-toggle input { accent-color: var(--red); margin-top: 2px; flex-shrink: 0; }
.portal-toggle-text { font-size: 13px; color: var(--gray-2); line-height: 1.5; }
.portal-toggle-text strong { display: block; color: var(--white); font-size: 14px; margin-bottom: 2px; }
.portal-msg { padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; font-weight: 500; display: none; }
.portal-msg--success { background: rgba(92,184,92,.1); border: 1px solid rgba(92,184,92,.3); color: #5cb85c; }
.portal-msg--error   { background: rgba(204,0,0,.1);  border: 1px solid var(--red-border); color: #ff6666; }

/* ── DASHBOARD STAT CARDS ── */
.portal-stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 20px; }
@media(max-width:480px){ .portal-stats { grid-template-columns: 1fr; } }
.portal-stat { background: var(--surface-2); border: 1px solid var(--surface-3); border-radius: var(--radius-md); padding: 16px; text-align: center; }
.portal-stat__num { font-family: var(--font-display); font-size: 36px; font-weight: 800; color: var(--red); line-height: 1; }
.portal-stat__label { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--gray-1); margin-top: 4px; }

/* ── EVENT HISTORY LIST ── */
.portal-event-list { display: flex; flex-direction: column; gap: 12px; }
.portal-event-item { background: var(--surface-2); border: 1px solid var(--surface-3); border-radius: var(--radius-md); padding: 14px 18px; display: flex; align-items: center; gap: 16px; }
.portal-event-item__date { background: var(--red); color: var(--white); font-family: var(--font-display); font-weight: 800; text-transform: uppercase; text-align: center; border-radius: var(--radius-sm); padding: 6px 10px; min-width: 52px; flex-shrink: 0; }
.portal-event-item__month { display: block; font-size: 10px; }
.portal-event-item__day { display: block; font-size: 22px; line-height: 1; }
.portal-event-item__info { flex: 1; }
.portal-event-item__name { font-family: var(--font-display); font-size: 16px; font-weight: 700; text-transform: uppercase; color: var(--white); }
.portal-event-item__meta { font-size: 12px; color: var(--gray-1); margin-top: 2px; }
.portal-event-badge { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; padding: 3px 8px; border-radius: 100px; white-space: nowrap; }
.portal-event-badge--confirmed { background: rgba(92,184,92,.15); color: #5cb85c; }
.portal-event-badge--pending   { background: rgba(245,166,35,.15); color: #f5a623; }
.portal-event-badge--waitlist  { background: rgba(91,192,222,.15); color: #5bc0de; }

/* ── MEMBER DIRECTORY ── */
.portal-directory { display: grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap: 16px; }
.portal-member-card { background: var(--surface-2); border: 1px solid var(--surface-3); border-radius: var(--radius-lg); padding: 20px; text-align: center; transition: border-color .2s, transform .2s; }
.portal-member-card:hover { border-color: var(--red-dim); transform: translateY(-2px); }
.portal-member-card__avatar { width: 60px; height: 60px; border-radius: 50%; background: var(--red); color: var(--white); font-family: var(--font-display); font-size: 22px; font-weight: 800; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; overflow: hidden; }
.portal-member-card__avatar img { width: 100%; height: 100%; object-fit: cover; }
.portal-member-card__name { font-family: var(--font-display); font-size: 14px; font-weight: 700; text-transform: uppercase; color: var(--white); margin-bottom: 4px; }
.portal-member-card__park { font-size: 11px; color: var(--gray-1); }
.portal-member-card__instagram { font-size: 11px; color: var(--red); margin-top: 4px; }

/* ── PHOTO UPLOAD ── */
.portal-upload-zone { border: 2px dashed var(--surface-4); border-radius: var(--radius-lg); padding: 40px 24px; text-align: center; cursor: pointer; transition: border-color .2s; }
.portal-upload-zone:hover { border-color: var(--red); }
.portal-upload-zone__icon { font-size: 36px; margin-bottom: 12px; }
.portal-upload-zone__label { font-family: var(--font-display); font-size: 18px; font-weight: 700; text-transform: uppercase; color: var(--white); margin-bottom: 6px; }
.portal-upload-zone__hint { font-size: 12px; color: var(--gray-1); }
.portal-upload-preview { max-width: 100%; border-radius: var(--radius-md); margin: 16px 0; display: none; }

/* ── REVIEW ROWS & TABS ── */
.review-row { padding: 14px; background: var(--surface-2); border: 1px solid var(--surface-3); border-radius: var(--radius-md); transition: border-color .15s; }
.review-row:hover { border-color: var(--red-dim); }
.review-tab { padding: 10px 16px; background: none; border: none; border-bottom: 2px solid transparent; color: var(--gray-1); font-family: var(--font-display); font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; cursor: pointer; margin-bottom: -1px; transition: color .15s, border-color .15s; }
.review-tab.active { color: var(--white); border-bottom-color: var(--red); }
.review-tab:hover { color: var(--white); }

/* ── PORTAL AVATAR ── */
.portal-avatar { width: 72px; height: 72px; border-radius: 50%; background: var(--red); color: var(--white); font-family: var(--font-display); font-size: 28px; font-weight: 800; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 3px solid var(--surface-3); flex-shrink: 0; }
.portal-avatar img { width: 100%; height: 100%; object-fit: cover; }

/* ── PORTAL CARD ── */
.portal-card { background: var(--surface-1); border: 1px solid var(--surface-3); border-radius: var(--radius-lg); padding: 28px; margin-bottom: 20px; }
.portal-card__title { font-family: var(--font-display); font-size: 20px; font-weight: 800; text-transform: uppercase; color: var(--white); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.portal-card__title-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--red); flex-shrink: 0; }

/* ── MEMBER GALLERY ── */
.member-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-top: 16px; }
.member-gallery__item { aspect-ratio: 1; overflow: hidden; border-radius: var(--radius-md); background: var(--surface-2); border: 1px solid var(--surface-3); }
.member-gallery__item img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.member-gallery__item:hover img { transform: scale(1.05); }
.member-gallery__caption { font-size: 11px; color: var(--gray-1); padding: 6px 0 0; }
';
}


// ─────────────────────────────────────────
// 12. PORTAL JS
// ─────────────────────────────────────────

function blusiast_portal_js() { return <<<'PORTALJS'
(function(){
'use strict';

var ajax = (window.bluPortal && bluPortal.ajaxUrl) ? bluPortal.ajaxUrl : '/wp-admin/admin-ajax.php';
var nonce = (window.bluPortal && bluPortal.nonce) ? bluPortal.nonce : '';

// ── Tab switching (login/register gate) ──
document.querySelectorAll('.portal-gate__tab').forEach(function(tab){
    tab.addEventListener('click', function(){
        var target = this.dataset.tab;
        document.querySelectorAll('.portal-gate__tab').forEach(function(t){ t.classList.remove('active'); });
        document.querySelectorAll('.portal-gate__pane').forEach(function(p){ p.style.display='none'; });
        this.classList.add('active');
        var pane = document.getElementById('gate-' + target);
        if(pane) pane.style.display = 'block';
    });
});

// ── Portal sidebar nav ──
document.querySelectorAll('.portal-nav__item[data-panel]').forEach(function(item){
    item.addEventListener('click', function(e){
        e.preventDefault();
        var target = this.dataset.panel;
        document.querySelectorAll('.portal-nav__item').forEach(function(i){ i.classList.remove('active'); });
        document.querySelectorAll('.portal-panel').forEach(function(p){ p.classList.remove('active'); });
        this.classList.add('active');
        var panel = document.getElementById('panel-' + target);
        if(panel) panel.classList.add('active');
        history.replaceState(null,'', '?tab=' + target);
    });
});

// ── Restore panel from URL ──
var urlTab = new URLSearchParams(window.location.search).get('tab');
if(urlTab){
    var navItem = document.querySelector('.portal-nav__item[data-panel="'+urlTab+'"]');
    if(navItem) navItem.click();
}

// ── AJAX form helper ──
function ajaxForm(formId, action, onSuccess){
    var form = document.getElementById(formId);
    if(!form) return;
    form.addEventListener('submit', function(e){
        e.preventDefault();
        var btn = form.querySelector('[type=submit]');
        var msgEl = form.querySelector('.portal-msg');
        var origText = btn ? btn.textContent : '';
        if(btn){ btn.disabled=true; btn.textContent='...'; }
        if(msgEl){ msgEl.style.display='none'; msgEl.className='portal-msg'; }

        var data = new FormData(form);
        data.append('action', action);
        data.append('nonce',  nonce);

        // reCAPTCHA v3 — get token if available, then send
        var rcaptchaActions = ['blusiast_portal_login','blusiast_portal_register','blusiast_send_help'];
        var rcAction = action.replace('blusiast_portal_','').replace('blusiast_','');
        if(rcaptchaActions.indexOf(action) !== -1 && window.blusiast_get_recaptcha_token){
            window.blusiast_get_recaptcha_token(rcAction).then(function(token){
                data.append('recaptcha_token', token);
                doFetch(data);
            });
            return; // doFetch will fire after token arrives
        }
        doFetch(data);

        function doFetch(postData){
        fetch(ajax, { method:'POST', body:postData })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if(btn){ btn.disabled=false; btn.textContent=origText; }
                if(json.success){
                    if(msgEl){ msgEl.className='portal-msg portal-msg--success'; msgEl.textContent=json.data.message||'Done!'; msgEl.style.display='block'; }
                    if(onSuccess) onSuccess(json.data);
                } else {
                    if(msgEl){ msgEl.className='portal-msg portal-msg--error'; msgEl.textContent=(json.data&&json.data.message)||'Something went wrong.'; msgEl.style.display='block'; }
                }
            })
            .catch(function(){
                if(btn){ btn.disabled=false; btn.textContent=origText; }
                if(msgEl){ msgEl.className='portal-msg portal-msg--error'; msgEl.textContent='Network error. Please try again.'; msgEl.style.display='block'; }
            });
        } // end doFetch
    });
}

// Login form
ajaxForm('portal-login-form', 'blusiast_portal_login', function(data){
    document.dispatchEvent(new CustomEvent('blusiast:logged_in'));
    var dest = window.blEventRedirect || (bluPortal && bluPortal.loginRedirect) || data.redirect;
    if(dest) window.location.href = dest;
});

// Register form
ajaxForm('portal-register-form', 'blusiast_portal_register', function(data){
    document.dispatchEvent(new CustomEvent('blusiast:registered'));
    var dest = window.blEventRedirect || (bluPortal && bluPortal.registerRedirect) || data.redirect;
    if(dest) window.location.href = dest;
});

// Profile form
ajaxForm('portal-profile-form', 'blusiast_update_profile');

// Password form
ajaxForm('portal-password-form', 'blusiast_change_password', function(){
    document.getElementById('portal-password-form').reset();
});

// Help form
ajaxForm('portal-help-form', 'blusiast_send_help', function(){
    document.getElementById('portal-help-form').reset();
});

// ── Photo upload preview ──
var photoInput = document.getElementById('portal-photo-input');
var preview    = document.getElementById('portal-photo-preview');
if(photoInput && preview){
    photoInput.addEventListener('change', function(){
        var file = this.files[0];
        if(file){
            var reader = new FileReader();
            reader.onload = function(e){ preview.src=e.target.result; preview.style.display='block'; };
            reader.readAsDataURL(file);
        }
    });
}

// Photo submit
var photoForm = document.getElementById('portal-photo-form');
if(photoForm){
    photoForm.addEventListener('submit', function(e){
        e.preventDefault();
        var btn = photoForm.querySelector('[type=submit]');
        var msgEl = photoForm.querySelector('.portal-msg');
        if(!photoInput || !photoInput.files[0]){
            if(msgEl){ msgEl.className='portal-msg portal-msg--error'; msgEl.textContent='Please select a photo first.'; msgEl.style.display='block'; }
            return;
        }
        if(btn){ btn.disabled=true; btn.textContent='Uploading…'; }
        if(msgEl){ msgEl.style.display='none'; }

        var data = new FormData(photoForm);
        data.append('action','blusiast_submit_photo');
        data.append('nonce', nonce);

        fetch(ajax, { method:'POST', body:data })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if(btn){ btn.disabled=false; btn.textContent='Submit Photo'; }
                if(json.success){
                    if(msgEl){ msgEl.className='portal-msg portal-msg--success'; msgEl.textContent=json.data.message; msgEl.style.display='block'; }
                    photoForm.reset();
                    if(preview){ preview.style.display='none'; }
                } else {
                    if(msgEl){ msgEl.className='portal-msg portal-msg--error'; msgEl.textContent=(json.data&&json.data.message)||'Upload failed.'; msgEl.style.display='block'; }
                }
            });
    });
}

// ── Drag & drop on upload zone ──
var zone = document.querySelector('.portal-upload-zone');
if(zone && photoInput){
    zone.addEventListener('click', function(){ photoInput.click(); });
    zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.style.borderColor='var(--red)'; });
    zone.addEventListener('dragleave', function(){ zone.style.borderColor=''; });
    zone.addEventListener('drop', function(e){
        e.preventDefault();
        zone.style.borderColor='';
        if(e.dataTransfer.files[0]){
            photoInput.files = e.dataTransfer.files;
            photoInput.dispatchEvent(new Event('change'));
        }
    });
}

// ── Avatar upload ──
var avatarInput = document.getElementById('avatar-file-input');
var avatarLabel = document.querySelector('label[for="avatar-file-input"]');

if(avatarInput){
    // Prevent any WP media library interference
    avatarInput.addEventListener('click', function(e){ e.stopPropagation(); });

    avatarInput.addEventListener('change', function(){
        var file = this.files[0];
        if(!file) return;

        // Validate file type
        if(!file.type.match(/^image\//)){
            alert('Please select an image file.');
            return;
        }

        // Show loading state on the avatar
        var av = document.getElementById('sidebar-avatar');
        if(av) av.style.opacity = '0.5';
        if(avatarLabel) avatarLabel.style.pointerEvents = 'none';

        // Preview immediately before upload
        var reader = new FileReader();
        reader.onload = function(e){
            var existing = document.getElementById('avatar-preview-img');
            if(existing){
                existing.src = e.target.result;
            } else if(av){
                var initials = document.getElementById('avatar-initials');
                if(initials) initials.style.display = 'none';
                av.innerHTML = '<img src="'+e.target.result+'" alt="" id="avatar-preview-img" style="width:100%;height:100%;object-fit:cover;">';
            }
        };
        reader.readAsDataURL(file);

        // Upload via AJAX
        var data = new FormData();
        data.append('action', 'blusiast_upload_avatar');
        data.append('nonce', nonce);
        data.append('avatar', file);

        fetch(ajax, {method:'POST', body:data})
            .then(function(r){ return r.json(); })
            .then(function(json){
                if(av) av.style.opacity = '1';
                if(avatarLabel) avatarLabel.style.pointerEvents = '';
                if(json.success && json.data.url){
                    // Update with the final stored URL
                    var img = document.getElementById('avatar-preview-img');
                    if(img) img.src = json.data.url;
                } else {
                    var msg = (json.data && json.data.message) ? json.data.message : 'Upload failed.';
                    alert(msg);
                }
            })
            .catch(function(){
                if(av) av.style.opacity = '1';
                if(avatarLabel) avatarLabel.style.pointerEvents = '';
                alert('Network error. Please try again.');
            });
    });
}

// ── Park typeahead ──
(function(){
    var ptSearch  = document.getElementById('park_search_input');
    if (!ptSearch) return;

    var ptHidden  = document.getElementById('park_name_value');
    var ptDrop    = document.getElementById('park_dropdown');
    var ptClear   = document.getElementById('park_search_clear');
    var ptPanel   = document.getElementById('park_add_panel');
    var ptAddName = document.getElementById('park_add_name');
    var ptAddLoc  = document.getElementById('park_add_location');
    var ptAddBtn  = document.getElementById('park_add_submit');
    var ptCancel  = document.getElementById('park_add_cancel');
    var ptMsg     = document.getElementById('park_add_msg');
    var ptBadge   = document.getElementById('park_selected_badge');
    var ptLabel   = document.getElementById('park_selected_label');
    var ptChange  = document.getElementById('park_selected_change');

    var ptTimer   = null;
    var ptRest    = (window.bluPortal && bluPortal.restUrl) ? bluPortal.restUrl : '/wp-json/blusiast/v1/parks';
    var ptNonce   = (window.bluPortal && bluPortal.restNonce) ? bluPortal.restNonce : '';

    function ptEsc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function ptShowBadge(name) {
        ptHidden.value   = name;
        ptLabel.textContent = name;
        ptBadge.style.display = 'flex';
        ptSearch.style.display  = 'none';
        ptClear.style.display   = 'none';
        ptDrop.style.display    = 'none';
        ptPanel.style.display   = 'none';
    }

    function ptReset() {
        ptHidden.value = '';
        ptSearch.value = '';
        ptBadge.style.display  = 'none';
        ptSearch.style.display = '';
        ptClear.style.display  = 'none';
        ptDrop.style.display   = 'none';
        ptPanel.style.display  = 'none';
        if (ptMsg) ptMsg.textContent = '';
        ptSearch.focus();
    }

    function ptRender(parks, q) {
        ptDrop.innerHTML = '';
        parks.forEach(function(p){
            var el = document.createElement('div');
            el.style.cssText = 'padding:10px 14px;cursor:pointer;color:var(--white);font-size:14px;border-bottom:1px solid var(--surface-3,#333);';
            el.innerHTML = '<strong>' + ptEsc(p.name) + '</strong>' + (p.location ? ' <span style="font-size:12px;color:var(--gray-1);">' + ptEsc(p.location) + '</span>' : '');
            el.addEventListener('mouseenter', function(){ this.style.background='var(--surface-3,#222)'; });
            el.addEventListener('mouseleave', function(){ this.style.background=''; });
            el.addEventListener('mousedown',  function(e){ e.preventDefault(); ptShowBadge(p.name); });
            ptDrop.appendChild(el);
        });
        if (q) {
            var add = document.createElement('div');
            add.style.cssText = 'padding:10px 14px;cursor:pointer;color:var(--red,#CC0000);font-size:13px;font-weight:700;';
            add.innerHTML = '+ Add <em>' + ptEsc(q) + '</em> as a new park';
            add.addEventListener('mouseenter', function(){ this.style.background='var(--surface-3,#222)'; });
            add.addEventListener('mouseleave', function(){ this.style.background=''; });
            add.addEventListener('mousedown',  function(e){
                e.preventDefault();
                ptDrop.style.display = 'none';
                ptAddName.value = q;
                ptPanel.style.display = 'block';
                ptAddName.focus();
            });
            ptDrop.appendChild(add);
        }
        ptDrop.style.display = (parks.length || q) ? 'block' : 'none';
    }

    function ptFetch(q) {
        fetch(ptRest + (q ? '?q=' + encodeURIComponent(q) : ''), { headers: ptNonce ? {'X-WP-Nonce': ptNonce} : {} })
            .then(function(r){ return r.json(); })
            .then(function(parks){ ptRender(parks, q); })
            .catch(function(){});
    }

    ptSearch.addEventListener('input', function(){
        var q = this.value.trim();
        ptClear.style.display  = q ? 'inline' : 'none';
        ptPanel.style.display  = 'none';
        clearTimeout(ptTimer);
        ptTimer = setTimeout(function(){ ptFetch(q); }, 220);
    });
    ptSearch.addEventListener('focus', function(){ if (!ptHidden.value) ptFetch(this.value.trim()); });
    ptSearch.addEventListener('blur',  function(){ setTimeout(function(){ ptDrop.style.display='none'; }, 180); });
    ptClear.addEventListener('click',  function(){ ptReset(); });
    if (ptChange) ptChange.addEventListener('click', function(){ ptReset(); });

    if (ptAddBtn) {
        ptAddBtn.addEventListener('click', function(){
            var name = ptAddName.value.trim();
            var loc  = ptAddLoc.value.trim();
            if (!name) { ptMsg.textContent = 'Park name is required.'; return; }
            ptAddBtn.disabled = true;
            ptMsg.textContent = 'Saving…';
            fetch(ptRest, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ptNonce },
                body: JSON.stringify({ name: name, location: loc })
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                ptAddBtn.disabled = false;
                if (data.error) { ptMsg.textContent = data.error; return; }
                ptShowBadge(data.name);
                ptMsg.textContent = '';
            })
            .catch(function(){ ptAddBtn.disabled = false; ptMsg.textContent = 'Network error — try again.'; });
        });
    }
    if (ptCancel) ptCancel.addEventListener('click', function(){ ptPanel.style.display='none'; ptSearch.focus(); });
})();

// ── Review form ──
ajaxForm('portal-review-form', 'blusiast_submit_review', function(){
    document.getElementById('portal-review-form').reset();
    // Reset park widget too
    var badge       = document.getElementById('park_selected_badge');
    var searchInput = document.getElementById('park_search_input');
    var hiddenInput = document.getElementById('park_name_value');
    if (badge)       { badge.style.display = 'none'; }
    if (searchInput) { searchInput.style.display = ''; searchInput.value = ''; }
    if (hiddenInput) { hiddenInput.value = ''; }
});

// ── Review tabs ──
document.querySelectorAll('.review-tab').forEach(function(tab){
    tab.addEventListener('click', function(){
        document.querySelectorAll('.review-tab').forEach(function(t){
            t.style.color='var(--gray-1)';
            t.style.borderBottomColor='transparent';
            t.classList.remove('active');
        });
        document.querySelectorAll('.rtab-pane').forEach(function(p){ p.style.display='none'; });
        this.style.color='var(--white)';
        this.style.borderBottomColor='var(--red)';
        this.classList.add('active');
        var pane = document.getElementById('rtab-'+this.dataset.rtab);
        if(pane) pane.style.display='block';
    });
});

// ── Zip → City lookup (directory + profile panels) ──
(function(){
    var cache = {};
    function lookup(zip, el) {
        var clean = zip.replace(/[^0-9]/g,'').substring(0,5);
        if(clean.length < 5){ return; }
        if(cache[clean]){ el.textContent = '📍 ' + cache[clean]; return; }
        fetch('https://api.zippopotam.us/us/' + clean)
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){
                if(d && d.places && d.places[0]){
                    var label = d.places[0]['place name'] + ', ' + d.places[0]['state abbreviation'];
                    cache[clean] = label;
                    el.textContent = '📍 ' + label;
                }
            })
            .catch(function(){});
    }
    var i = 0;
    document.querySelectorAll('[data-zip-lookup]').forEach(function(el){
        var zip = el.getAttribute('data-zip-lookup');
        setTimeout(function(){ lookup(zip, el); }, i * 120);
        i++;
    });
})();

// ── Review search ──
var reviewSearch = document.getElementById('review-search');
if(reviewSearch){
    reviewSearch.addEventListener('input', function(){
        var q = this.value.toLowerCase();
        document.querySelectorAll('.review-row').forEach(function(row){
            var match = row.dataset.coaster.indexOf(q) > -1 || row.dataset.park.indexOf(q) > -1;
            row.style.display = match ? '' : 'none';
        });
    });
}

})();
PORTALJS;
}