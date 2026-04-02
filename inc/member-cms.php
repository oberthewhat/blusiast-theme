<?php
/**
 * Blusiast Member CMS — inc/member-cms.php
 *
 * Features:
 *  - DB table for event registrations
 *  - AJAX sign-up handler (creates WP user as subscriber)
 *  - Admin dashboard: events as clickable cards → drill-down to registrations
 *  - Per-event registration list with status, notes, delete
 *  - Member Spotlights management page
 *  - CSV export
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ─────────────────────────────────────────
// 1. DB INSTALL / MIGRATE
// ─────────────────────────────────────────

register_activation_hook( get_template_directory() . '/functions.php', 'blusiast_install_db' );
add_action( 'after_switch_theme', 'blusiast_install_db' );

function blusiast_install_db() {
    global $wpdb;
    $table   = $wpdb->prefix . 'bl_event_registrations';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        event_id    BIGINT UNSIGNED  NOT NULL,
        first_name  VARCHAR(100)     NOT NULL DEFAULT '',
        last_name   VARCHAR(100)     NOT NULL DEFAULT '',
        email       VARCHAR(200)     NOT NULL DEFAULT '',
        phone       VARCHAR(50)      NOT NULL DEFAULT '',
        guest_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
        zip         VARCHAR(10)      NOT NULL DEFAULT '',
        status      VARCHAR(20)      NOT NULL DEFAULT 'pending',
        notes       TEXT,
        wp_user_id  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
        created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_id (event_id),
        KEY email (email),
        KEY status (status)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Column migrations for existing installs
    $columns = $wpdb->get_col( "DESCRIBE $table", 0 );
    if ( ! in_array( 'zip', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE $table ADD COLUMN zip VARCHAR(10) NOT NULL DEFAULT '' AFTER guest_count" );
    }
    if ( ! in_array( 'wp_user_id', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE $table ADD COLUMN wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER notes" );
    }

    // Member meta table for billing/status tracking
    $mtable  = $wpdb->prefix . 'bl_members';
    $msql = "CREATE TABLE IF NOT EXISTS $mtable (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email          VARCHAR(200)    NOT NULL DEFAULT '',
        first_name     VARCHAR(100)    NOT NULL DEFAULT '',
        last_name      VARCHAR(100)    NOT NULL DEFAULT '',
        phone          VARCHAR(50)     NOT NULL DEFAULT '',
        zip            VARCHAR(10)     NOT NULL DEFAULT '',
        wp_user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        account_status VARCHAR(20)     NOT NULL DEFAULT 'free',
        billing_notes  TEXT,
        joined_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email (email)
    ) $charset;";
    dbDelta( $msql );

    update_option( 'blusiast_db_version', '1.3' );
}


// ─────────────────────────────────────────
// 2. AJAX — FRONT-END SIGN-UP
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_event_signup',        'blusiast_handle_event_signup' );
add_action( 'wp_ajax_nopriv_blusiast_event_signup', 'blusiast_handle_event_signup' );

function blusiast_handle_event_signup() {
    if ( ! check_ajax_referer( 'blusiast_event_signup', 'bl_nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Security check failed. Please refresh and try again.' ], 403 );
    }

    $event_id    = absint( $_POST['event_id']    ?? 0 );
    $first_name  = sanitize_text_field( $_POST['first_name']  ?? '' );
    $last_name   = sanitize_text_field( $_POST['last_name']   ?? '' );
    $email       = sanitize_email(      $_POST['email']       ?? '' );
    $phone       = sanitize_text_field( $_POST['phone']       ?? '' );
    $zip         = sanitize_text_field( $_POST['zip']         ?? '' );
    $guest_count = max( 1, min( 8, absint( $_POST['guest_count'] ?? 1 ) ) );

    $errors = [];
    if ( ! $event_id )          $errors[] = 'Invalid event.';
    if ( ! $first_name )        $errors[] = 'First name is required.';
    if ( ! $last_name )         $errors[] = 'Last name is required.';
    if ( ! is_email( $email ) ) $errors[] = 'A valid email is required.';
    if ( empty( $_POST['consent'] ) ) $errors[] = 'Please accept the communications checkbox.';
    if ( $errors ) wp_send_json_error( [ 'message' => implode( ' ', $errors ) ] );

    $sold_out = function_exists( 'get_field' ) ? get_field( 'event_sold_out', $event_id ) : false;
    if ( $sold_out ) wp_send_json_error( [ 'message' => 'Sorry — this event is sold out.' ] );

    // Ensure table exists
    blusiast_install_db();

    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';

    // Duplicate check
    $duplicate = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE event_id = %d AND email = %s LIMIT 1",
        $event_id, $email
    ) );
    if ( $duplicate ) wp_send_json_error( [ 'message' => 'This email is already registered for this event.' ] );

    // ── Create or find WP user ──
    $wp_user_id = 0;
    $existing   = get_user_by( 'email', $email );
    if ( $existing ) {
        $wp_user_id = $existing->ID;
    } else {
        $username = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
        // Ensure unique username
        $base = $username;
        $n    = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $n++;
        }
        $password   = wp_generate_password( 12, false );
        $wp_user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
            'role'         => 'subscriber',
            'user_pass'    => $password,
        ] );
        if ( is_wp_error( $wp_user_id ) ) $wp_user_id = 0;
    }

    // Insert registration
    $inserted = $wpdb->insert( $table, [
        'event_id'    => $event_id,
        'first_name'  => $first_name,
        'last_name'   => $last_name,
        'email'       => $email,
        'phone'       => $phone,
        'guest_count' => $guest_count,
        'zip'         => $zip,
        'status'      => 'pending',
        'wp_user_id'  => (int) $wp_user_id,
        'created_at'  => current_time( 'mysql' ),
    ], [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ] );

    if ( ! $inserted ) {
        wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error ] );
    }

    // ── Emails ──
    $event_title = get_the_title( $event_id );
    $event_date  = function_exists( 'get_field' ) ? get_field( 'event_date',     $event_id ) : '';
    $event_loc   = function_exists( 'get_field' ) ? get_field( 'event_location', $event_id ) : '';
    $fmt_date    = $event_date ? date( 'F j, Y', strtotime( $event_date ) ) : '';
    $event_url   = get_permalink( $event_id );

    // Confirmation to registrant
    $subject = "You're registered: {$event_title} — Blusiast";
    $body    = "Hey {$first_name},\n\n"
             . "You're locked in for {$event_title}!\n\n"
             . "📅 {$fmt_date}\n"
             . ( $event_loc ? "📍 {$event_loc}\n" : '' )
             . "👥 Guests: {$guest_count}\n\n"
             . "We'll be in touch with more details as the event gets closer.\n\n"
             . "Ride on,\nThe Blusiast Crew\n\n"
             . $event_url;

    wp_mail( $email, $subject, $body, [
        'From: Blusiast <' . get_option('admin_email') . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ] );

    // Notification to admin
    wp_mail(
        get_option( 'admin_email' ),
        "New Registration: {$event_title} — {$first_name} {$last_name}",
        "Name:   {$first_name} {$last_name}\nEmail:  {$email}\nPhone:  {$phone}\nZip:    {$zip}\nGuests: {$guest_count}\nEvent:  {$event_title}\nDate:   {$fmt_date}\n\nView registrations: " . admin_url('admin.php?page=blusiast-registrations&event_id=' . $event_id)
    );

    // Upsert into master members table
    $mtable = $wpdb->prefix . 'bl_members';
    $existing_member = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $mtable WHERE email = %s LIMIT 1", $email ) );
    if ( ! $existing_member ) {
        $wpdb->insert( $mtable, [
            'email'          => $email,
            'first_name'     => $first_name,
            'last_name'      => $last_name,
            'phone'          => $phone,
            'zip'            => $zip,
            'wp_user_id'     => (int) $wp_user_id,
            'account_status' => 'free',
            'joined_at'      => current_time( 'mysql' ),
        ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );
    } else {
        // Update contact info in case it changed
        $wpdb->update( $mtable,
            [ 'first_name' => $first_name, 'last_name' => $last_name, 'phone' => $phone, 'zip' => $zip ],
            [ 'email' => $email ],
            [ '%s', '%s', '%s', '%s' ], [ '%s' ]
        );
    }

    wp_send_json_success( [ 'message' => "You're registered! Check your email for confirmation." ] );
}


// ─────────────────────────────────────────
// 3. AJAX — ADMIN ACTIONS
// ─────────────────────────────────────────

// Update status
function blusiast_update_reg_status() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );
    global $wpdb;
    $table   = $wpdb->prefix . 'bl_event_registrations';
    $id      = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
    $status  = sanitize_text_field( isset( $_POST['status'] ) ? $_POST['status'] : 'pending' );
    $allowed = array( 'pending', 'confirmed', 'cancelled', 'waitlist' );
    if ( ! in_array( $status, $allowed, true ) ) wp_send_json_error();
    $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_blusiast_update_reg_status', 'blusiast_update_reg_status' );

// Save note
function blusiast_save_reg_note() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';
    $id    = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
    $note  = sanitize_textarea_field( isset( $_POST['note'] ) ? $_POST['note'] : '' );
    $wpdb->update( $table, array( 'notes' => $note ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_blusiast_save_reg_note', 'blusiast_save_reg_note' );

// Delete registration
function blusiast_delete_reg() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';
    $id    = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
    $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_blusiast_delete_reg', 'blusiast_delete_reg' );


// ─────────────────────────────────────────
// 3a. AJAX — MEMBER ACCOUNT STATUS & NOTES
// ─────────────────────────────────────────

function blusiast_update_member_status() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );
    global $wpdb;
    $mtable  = $wpdb->prefix . 'bl_members';
    $id      = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
    $status  = sanitize_text_field( isset( $_POST['status'] ) ? $_POST['status'] : 'free' );
    $allowed = array( 'free', 'active', 'lapsed', 'banned' );
    if ( ! in_array( $status, $allowed, true ) ) wp_send_json_error();
    $wpdb->update( $mtable, array( 'account_status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_blusiast_update_member_status', 'blusiast_update_member_status' );

function blusiast_save_member_note() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );
    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $id     = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
    $note   = sanitize_textarea_field( isset( $_POST['note'] ) ? $_POST['note'] : '' );
    $wpdb->update( $mtable, array( 'billing_notes' => $note ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_blusiast_save_member_note', 'blusiast_save_member_note' );


// ─────────────────────────────────────────
// 3a-ii. AJAX — DELETE MEMBER
// ─────────────────────────────────────────

function blusiast_delete_member() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    global $wpdb;
    $id        = absint( $_POST['id'] ?? 0 );
    $mtable    = $wpdb->prefix . 'bl_members';

    // Get the member so we can also remove their WP user if desired
    $member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $mtable WHERE id = %d", $id ) );
    if ( ! $member ) wp_send_json_error( [ 'message' => 'Member not found.' ] );

    // Safety: never allow deleting an administrator account
    if ( $member->wp_user_id ) {
        $wp_user = get_userdata( (int) $member->wp_user_id );
        if ( $wp_user && in_array( 'administrator', (array) $wp_user->roles, true ) ) {
            wp_send_json_error( [ 'message' => 'Administrator accounts cannot be removed here. Use the WordPress Users screen.' ] );
        }
    }

    // Delete from bl_members
    $wpdb->delete( $mtable, [ 'id' => $id ], [ '%d' ] );

    // Optionally delete the WP user account too
    // NOTE: we reassign their content to the current admin rather than deleting it
    if ( ! empty( $_POST['delete_wp_user'] ) && $member->wp_user_id ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        // Reassign any posts/media to the current admin so nothing gets deleted
        $reassign_to = get_current_user_id();
        wp_delete_user( (int) $member->wp_user_id, $reassign_to );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_blusiast_delete_member', 'blusiast_delete_member' );


// ─────────────────────────────────────────
// 3b. AJAX — SEND BLAST EMAIL TO ALL MEMBERS
// ─────────────────────────────────────────

function blusiast_send_member_blast() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    $subject  = sanitize_text_field( isset( $_POST['subject'] ) ? $_POST['subject'] : '' );
    $body     = sanitize_textarea_field( isset( $_POST['body'] ) ? $_POST['body'] : '' );
    if ( ! $subject || ! $body ) {
        wp_send_json_error( array( 'message' => 'Subject and message are both required.' ) );
    }

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $rows   = $wpdb->get_results(
        "SELECT first_name, email FROM $mtable WHERE account_status != 'banned'"
    );

    if ( empty( $rows ) ) {
        wp_send_json_error( array( 'message' => 'No members matched the selected groups.' ) );
    }

    $from    = 'Blusiast <' . get_option( 'admin_email' ) . '>';
    $headers = array( 'Content-Type: text/plain; charset=UTF-8', 'From: ' . $from );
    $sent    = 0;
    $failed  = 0;

    foreach ( $rows as $row ) {
        $personalised = str_replace( '{name}', $row->first_name, $body );
        $personalised .= "

— The Blusiast Crew
blusiast.com";
        if ( wp_mail( $row->email, $subject, $personalised, $headers ) ) {
            $sent++;
        } else {
            $failed++;
        }
    }

    wp_send_json_success( array( 'sent' => $sent, 'failed' => $failed, 'total' => count( $rows ) ) );
}
add_action( 'wp_ajax_blusiast_send_member_blast', 'blusiast_send_member_blast' );


// ─────────────────────────────────────────
// 3c. AJAX — SEND BLAST EMAIL TO EVENT REGISTRANTS
// ─────────────────────────────────────────

function blusiast_send_event_blast() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    $event_id = absint( isset( $_POST['event_id'] ) ? $_POST['event_id'] : 0 );
    $subject  = sanitize_text_field( isset( $_POST['subject'] ) ? $_POST['subject'] : '' );
    $body     = sanitize_textarea_field( isset( $_POST['body'] ) ? $_POST['body'] : '' );
    $statuses = isset( $_POST['statuses'] ) ? (array) $_POST['statuses'] : array( 'confirmed', 'pending', 'waitlist' );
    $statuses = array_map( 'sanitize_text_field', $statuses );

    if ( ! $event_id || ! $subject || ! $body ) {
        wp_send_json_error( array( 'message' => 'Event, subject and message are all required.' ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';

    $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
    $query_args   = array_merge( array( $event_id ), $statuses );
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT first_name, email FROM $table WHERE event_id = %d AND status IN ($placeholders)",
            $query_args
        )
    );

    if ( empty( $rows ) ) {
        wp_send_json_error( array( 'message' => 'No registrants matched the selected statuses.' ) );
    }

    $event_title = get_the_title( $event_id );
    $from        = 'Blusiast <' . get_option( 'admin_email' ) . '>';
    $headers     = array( 'Content-Type: text/plain; charset=UTF-8', 'From: ' . $from );

    $sent   = 0;
    $failed = 0;

    foreach ( $rows as $row ) {
        // Personalise: replace {name} with first name
        $personalised_body = str_replace( '{name}', $row->first_name, $body );
        $personalised_body .= "

— The Blusiast Crew
blusiast.com";

        if ( wp_mail( $row->email, $subject, $personalised_body, $headers ) ) {
            $sent++;
        } else {
            $failed++;
        }
    }

    wp_send_json_success( array(
        'sent'   => $sent,
        'failed' => $failed,
        'total'  => count( $rows ),
    ) );
}
add_action( 'wp_ajax_blusiast_send_event_blast', 'blusiast_send_event_blast' );


// ─────────────────────────────────────────
// 4. ADMIN MENU
// ─────────────────────────────────────────

function blusiast_admin_menu() {
    add_menu_page( 'Blusiast CMS', 'Blusiast CMS', 'manage_options', 'blusiast-cms',
        'blusiast_cms_dashboard', 'dashicons-groups', 3 );
    add_submenu_page( 'blusiast-cms', 'Event Registrations', 'Registrations', 'manage_options',
        'blusiast-registrations', 'blusiast_registrations_page' );
    add_submenu_page( 'blusiast-cms', 'All Members', 'All Members', 'manage_options',
        'blusiast-all-members', 'blusiast_all_members_page' );
    add_submenu_page( 'blusiast-cms', 'Member Spotlights', 'Spotlights', 'manage_options',
        'blusiast-members', 'blusiast_members_page' );
    add_submenu_page( 'blusiast-cms', 'Email Settings', 'Email Settings', 'manage_options',
        'blusiast-email-settings', 'blusiast_email_settings_page' );
}
add_action( 'admin_menu', 'blusiast_admin_menu' );


// ─────────────────────────────────────────
// 5. ADMIN ASSETS
// ─────────────────────────────────────────

function blusiast_admin_enqueue( $hook ) {
    $our = [ 'toplevel_page_blusiast-cms', 'blusiast-cms_page_blusiast-registrations', 'blusiast-cms_page_blusiast-all-members', 'blusiast-cms_page_blusiast-members', 'blusiast-cms_page_blusiast-email-settings', 'blusiast-cms_page_blusiast-reviews' ];
    if ( ! in_array( $hook, $our, true ) ) return;

    wp_enqueue_style( 'blusiast-admin-fonts',
        'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600&display=swap',
        [], null );

    wp_add_inline_style( 'blusiast-admin-fonts', blusiast_admin_css() );

    wp_enqueue_script( 'jquery' );
    wp_add_inline_script( 'jquery', blusiast_admin_js() );

    wp_localize_script( 'jquery', 'bluAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'blusiast_admin_nonce' ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'blusiast_admin_enqueue' );

function blusiast_admin_css() { return '
:root{--bl-red:#CC0000;--bl-red-d:#8C0000;--bl-black:#0a0a0a;--bl-s1:#111;--bl-s2:#1a1a1a;--bl-s3:#242424;--bl-s4:#2e2e2e;--bl-g1:#888;--bl-g2:#aaa;--bl-white:#fff;--bl-fd:"Barlow Condensed",sans-serif;--bl-fb:"Barlow",sans-serif;--bl-r:8px;}
#wpcontent,#wpbody-content{background:var(--bl-black);}
.bl-cms-wrap{font-family:var(--bl-fb);color:var(--bl-white);padding:24px;max-width:1280px;}
.bl-cms-header{display:flex;align-items:center;gap:16px;margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--bl-s3);}
.bl-cms-logo{font-family:var(--bl-fd);font-size:32px;font-weight:900;text-transform:uppercase;color:var(--bl-white);letter-spacing:-.01em;}
.bl-cms-logo span{color:var(--bl-red);}
.bl-cms-subtitle{font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--bl-g1);}
/* tabs */
.bl-cms-tabs{display:flex;gap:4px;margin-bottom:24px;}
.bl-cms-tab{display:inline-block;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;color:var(--bl-g1);background:transparent;transition:color .15s,background .15s;}
.bl-cms-tab:hover{color:var(--bl-white);background:var(--bl-s2);}
.bl-cms-tab--active{background:var(--bl-red);color:var(--bl-white)!important;}
/* stat row */
.bl-stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px;}
.bl-stat-card{background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:20px;}
.bl-stat-label{font-size:11px;color:var(--bl-g1);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px;}
.bl-stat-num{font-family:var(--bl-fd);font-size:36px;font-weight:800;color:var(--bl-white);line-height:1;}
.bl-stat-num--red{color:var(--bl-red);}
/* event cards grid */
.bl-event-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:32px;}
.bl-event-card{background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:20px 24px;text-decoration:none;display:block;transition:border-color .15s,transform .15s;}
.bl-event-card:hover{border-color:var(--bl-red);transform:translateY(-2px);}
.bl-event-card__date{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-red);margin-bottom:6px;font-weight:600;}
.bl-event-card__title{font-family:var(--bl-fd);font-size:20px;font-weight:800;text-transform:uppercase;color:var(--bl-white);margin-bottom:12px;line-height:1.1;}
.bl-event-card__stats{display:flex;gap:16px;}
.bl-event-card__stat{text-align:center;}
.bl-event-card__stat-num{font-family:var(--bl-fd);font-size:28px;font-weight:800;color:var(--bl-white);line-height:1;}
.bl-event-card__stat-num--red{color:var(--bl-red);}
.bl-event-card__stat-label{font-size:10px;color:var(--bl-g1);text-transform:uppercase;letter-spacing:.08em;}
/* table */
.bl-table-wrap{background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);overflow:hidden;margin-bottom:24px;}
.bl-table-toolbar{display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid var(--bl-s3);flex-wrap:wrap;}
.bl-table-toolbar h2{font-family:var(--bl-fd);font-size:18px;font-weight:700;text-transform:uppercase;color:var(--bl-white);margin:0;flex:1;}
.bl-search-input,.bl-filter-select{background:var(--bl-s2);border:1px solid var(--bl-s3);border-radius:6px;color:var(--bl-white);padding:6px 10px;font-size:13px;}
.bl-search-input:focus,.bl-filter-select:focus{outline:none;border-color:var(--bl-red);}
.bl-btn-sm{background:var(--bl-s3);border:1px solid var(--bl-s4);color:var(--bl-g2);border-radius:6px;padding:6px 14px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:background .15s,color .15s;}
.bl-btn-sm:hover{background:var(--bl-s4);color:var(--bl-white);}
.bl-btn-danger{background:rgba(204,0,0,.15);border:1px solid rgba(204,0,0,.3);color:#ff6666;}
.bl-btn-danger:hover{background:rgba(204,0,0,.3);color:#fff;}
table.bl-table{width:100%;border-collapse:collapse;}
table.bl-table th{text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1);padding:10px 16px;background:var(--bl-s2);border-bottom:1px solid var(--bl-s3);}
table.bl-table td{padding:11px 16px;border-bottom:1px solid var(--bl-s3);font-size:13px;color:var(--bl-g2);vertical-align:middle;}
table.bl-table tr:last-child td{border-bottom:none;}
table.bl-table tr:hover td{background:var(--bl-s2);}
.bl-td-name{font-weight:600;color:var(--bl-white);font-size:14px;}
.bl-td-event{color:var(--bl-white);font-size:12px;max-width:180px;}
/* status */
.bl-status{display:inline-block;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
.bl-status--pending{background:#2a1f00;color:#f5a623;}
.bl-status--confirmed{background:#0a2a0a;color:#5cb85c;}
.bl-status--cancelled{background:#2a0000;color:#ff5555;}
.bl-status--waitlist{background:#001a2a;color:#5bc0de;}
.bl-status-select{background:transparent;border:none;color:inherit;font:inherit;cursor:pointer;width:100%;}
/* note */
.bl-note-input{background:var(--bl-s3);border:1px solid var(--bl-s4);border-radius:4px;color:var(--bl-g2);font-size:12px;padding:4px 8px;width:120px;font-family:var(--bl-fb);resize:none;}
.bl-note-input:focus{outline:none;border-color:var(--bl-red);color:var(--bl-white);}
/* members */
.bl-member-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-top:24px;}
.bl-member-card{background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:20px;display:flex;flex-direction:column;gap:8px;}
.bl-member-card--active{border-color:var(--bl-red);}
.bl-member-avatar{width:44px;height:44px;border-radius:50%;background:var(--bl-red);color:var(--bl-white);font-family:var(--bl-fd);font-size:18px;font-weight:800;display:flex;align-items:center;justify-content:center;text-transform:uppercase;}
.bl-member-name{font-weight:600;color:var(--bl-white);font-size:15px;}
.bl-member-meta{font-size:12px;color:var(--bl-g1);}
.bl-member-spotlight-badge{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-red);background:rgba(204,0,0,.1);border:1px solid rgba(204,0,0,.2);border-radius:100px;padding:2px 8px;display:inline-block;width:fit-content;}
/* breadcrumb */
.bl-breadcrumb{font-size:13px;color:var(--bl-g1);margin-bottom:20px;}
.bl-breadcrumb a{color:var(--bl-red);text-decoration:none;}
.bl-breadcrumb a:hover{text-decoration:underline;}
/* empty */
.bl-empty{text-align:center;padding:48px 24px;color:var(--bl-g1);font-size:14px;}
.bl-empty strong{display:block;font-family:var(--bl-fd);font-size:24px;color:var(--bl-g2);text-transform:uppercase;margin-bottom:8px;}
/* back link */
.bl-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--bl-g2);text-decoration:none;margin-bottom:20px;padding:6px 12px;background:var(--bl-s2);border-radius:6px;border:1px solid var(--bl-s3);}
.bl-back:hover{color:var(--bl-white);border-color:var(--bl-s4);}
/* email info box */
.bl-info-box{background:var(--bl-s2);border:1px solid var(--bl-s3);border-left:3px solid var(--bl-red);border-radius:var(--bl-r);padding:16px 20px;margin-bottom:24px;font-size:13px;color:var(--bl-g2);line-height:1.7;}
.bl-info-box strong{color:var(--bl-white);}
.bl-info-box code{background:var(--bl-s3);padding:2px 6px;border-radius:4px;font-size:12px;color:#f5a623;}
/* member account status */
.bl-acct--free{background:#1a1a1a;color:#888;}
.bl-acct--active{background:#0a2a0a;color:#5cb85c;}
.bl-acct--lapsed{background:#2a1f00;color:#f5a623;}
.bl-acct--banned{background:#2a0000;color:#ff5555;}
.bl-member-stat{text-align:center;padding:0 20px;border-right:1px solid var(--bl-s3);}
.bl-member-stat:last-child{border-right:none;}
.bl-member-stat-num{font-family:var(--bl-fd);font-size:32px;font-weight:800;line-height:1;}
.bl-member-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1);margin-top:4px;}
.bl-member-stats-strip{display:flex;background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:20px 0;margin-bottom:24px;}
/* email blast button */
.bl-btn-email-blast{background:rgba(204,0,0,.15);border:1px solid rgba(204,0,0,.4);color:#ff8888;}
.bl-btn-email-blast:hover{background:rgba(204,0,0,.3);color:#fff;border-color:var(--bl-red);}
/* blast panel */
.bl-blast-panel{background:var(--bl-s1);border:1px solid var(--bl-s3);border-top:3px solid var(--bl-red);border-radius:0 0 var(--bl-r) var(--bl-r);margin-bottom:24px;}
.bl-blast-panel__header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--bl-s3);}
.bl-blast-panel__title{font-family:var(--bl-fd);font-size:18px;font-weight:800;text-transform:uppercase;color:var(--bl-white);}
.bl-blast-panel__sub{font-size:12px;color:var(--bl-g1);margin-top:2px;}
.bl-blast-panel__sub strong{color:var(--bl-g2);}
.bl-blast-close{background:var(--bl-s3);border:1px solid var(--bl-s4);color:var(--bl-g1);border-radius:50%;width:28px;height:28px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.bl-blast-close:hover{color:var(--bl-white);}
.bl-blast-panel__body{padding:20px;}
.bl-blast-row{display:flex;flex-direction:column;gap:6px;margin-bottom:16px;}
.bl-blast-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g2);}
.bl-blast-hint{font-size:11px;color:var(--bl-g1);text-transform:none;letter-spacing:0;font-weight:400;margin-left:8px;}
.bl-blast-hint code{background:var(--bl-s3);padding:1px 5px;border-radius:3px;color:#f5a623;}
.bl-blast-checkboxes{display:flex;gap:12px;flex-wrap:wrap;}
.bl-blast-check{display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--bl-g2);}
.bl-blast-check input{accent-color:var(--bl-red);cursor:pointer;}
.bl-blast-input{background:var(--bl-s2);border:1px solid var(--bl-s3);border-radius:6px;color:var(--bl-white);padding:8px 12px;font-size:13px;width:100%;font-family:var(--bl-fb);}
.bl-blast-input:focus{outline:none;border-color:var(--bl-red);}
.bl-blast-textarea{resize:vertical;min-height:140px;line-height:1.6;}
.bl-blast-actions{display:flex;align-items:center;gap:16px;margin-top:8px;}
.bl-blast-send-btn{background:var(--bl-red);border:none;color:#fff;font-family:var(--bl-fd);font-size:15px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;padding:10px 24px;border-radius:6px;cursor:pointer;transition:background .15s;}
.bl-blast-send-btn:hover{background:#ff1a1a;}
.bl-blast-send-btn:disabled{opacity:.5;cursor:not-allowed;}
.bl-blast-fine{font-size:11px;color:var(--bl-g1);}
.bl-blast-result{padding:12px 14px;border-radius:6px;font-size:13px;font-weight:600;margin-top:4px;}
.bl-blast-result--success{background:#0a2a0a;color:#5cb85c;border:1px solid #1a4a1a;}
.bl-blast-result--error{background:#2a0000;color:#ff5555;border:1px solid #4a0000;}
'; }

function blusiast_admin_js() { return <<<'JSCODE'
jQuery(function($){
  $(document).on('change','.bl-status-select',function(){
    var id=$(this).data('id'), status=$(this).val();
    $(this).closest('.bl-status').removeClass('bl-status--pending bl-status--confirmed bl-status--cancelled bl-status--waitlist').addClass('bl-status--'+status);
    $.post(bluAdmin.ajaxUrl,{action:'blusiast_update_reg_status',nonce:bluAdmin.nonce,id:id,status:status});
  });
  $(document).on('blur','.bl-note-input',function(){
    $.post(bluAdmin.ajaxUrl,{action:'blusiast_save_reg_note',nonce:bluAdmin.nonce,id:$(this).data('id'),note:$(this).val()});
  });
  $(document).on('input','#bl-reg-search',function(){
    var q=$(this).val().toLowerCase();
    $('table.bl-table tbody tr').each(function(){$(this).toggle($(this).text().toLowerCase().indexOf(q)>-1);});
  });
  $(document).on('click','.bl-delete-reg',function(){
    var id=$(this).data('id'), row=$(this).closest('tr');
    if(!confirm('Remove this registration? This cannot be undone.')) return;
    $.post(bluAdmin.ajaxUrl,{action:'blusiast_delete_reg',nonce:bluAdmin.nonce,id:id},function(res){
      if(res.success) row.fadeOut(300,function(){row.remove();});
    });
  });
  // Member blast toggle
  $(document).on('click','#bl-member-blast-toggle',function(){
    var panel=$('#bl-member-blast-panel');
    if(panel.is(':visible')){panel.slideUp(200);}
    else{panel.slideDown(200);$('html,body').animate({scrollTop:panel.offset().top-60},300);}
  });
  $(document).on('click','#bl-member-blast-close',function(){
    $('#bl-member-blast-panel').slideUp(200);
  });
  $(document).on('click','#bl-member-blast-send',function(){
    var btn=$(this);
    var subject=$('#bl-member-blast-subject').val().trim();
    var body=$('#bl-member-blast-body').val().trim();
    var result=$('#bl-member-blast-result');
    if(!subject||!body){result.removeClass('bl-blast-result--success').addClass('bl-blast-result--error bl-blast-result').text('Please fill in both subject and message.').show();return;}
    btn.prop('disabled',true);
    btn.find('.bl-blast-send-label').hide();
    btn.find('.bl-blast-send-spinner').show();
    result.hide();
    $.post(bluAdmin.ajaxUrl,{
      action:'blusiast_send_member_blast',
      nonce:bluAdmin.nonce,
      subject:subject,
      body:body
    },function(res){
      btn.prop('disabled',false);
      btn.find('.bl-blast-send-label').show();
      btn.find('.bl-blast-send-spinner').hide();
      if(res.success){
        var d=res.data;
        var msg='✓ Sent '+d.sent+' of '+d.total+' emails.';
        if(d.failed>0) msg+=' ('+d.failed+' failed — check your SMTP settings)';
        result.removeClass('bl-blast-result--error').addClass('bl-blast-result--success bl-blast-result').text(msg).show();
        $('#bl-member-blast-subject').val('');
        $('#bl-member-blast-body').val('');
      } else {
        result.removeClass('bl-blast-result--success').addClass('bl-blast-result--error bl-blast-result').text(res.data&&res.data.message?res.data.message:'Something went wrong.').show();
      }
    });
  });
  // Member account status change
  $(document).on('change','.bl-member-status-select',function(){
    var id=$(this).data('id'), status=$(this).val();
    $(this).closest('.bl-status').removeClass('bl-acct--free bl-acct--active bl-acct--lapsed bl-acct--banned').addClass('bl-acct--'+status);
    $.post(bluAdmin.ajaxUrl,{action:'blusiast_update_member_status',nonce:bluAdmin.nonce,id:id,status:status});
  });
  // Member billing note save
  $(document).on('blur','.bl-member-note-input',function(){
    $.post(bluAdmin.ajaxUrl,{action:'blusiast_save_member_note',nonce:bluAdmin.nonce,id:$(this).data('id'),note:$(this).val()});
  });
  // Email blast toggle
  $(document).on('click','#bl-email-blast-toggle',function(){
    var panel=$('#bl-email-blast-panel');
    if(panel.is(':visible')){panel.slideUp(200);}
    else{panel.slideDown(200);$('html,body').animate({scrollTop:panel.offset().top-60},300);}
  });
  $(document).on('click','#bl-email-blast-close',function(){
    $('#bl-email-blast-panel').slideUp(200);
  });
  // Send blast
  $(document).on('click','#bl-blast-send',function(){
    var btn=$(this);
    var event_id=btn.data('event-id');
    var subject=$('#bl-blast-subject').val().trim();
    var body=$('#bl-blast-body').val().trim();
    var statuses=[];
    $('input[name="bl_blast_status"]:checked').each(function(){statuses.push($(this).val());});
    var result=$('#bl-blast-result');

    if(!subject||!body){result.removeClass('bl-blast-result--success bl-blast-result--error').addClass('bl-blast-result--error').text('Please fill in both subject and message.').show();return;}
    if(!statuses.length){result.removeClass('bl-blast-result--success bl-blast-result--error').addClass('bl-blast-result--error').text('Select at least one recipient group.').show();return;}

    btn.prop('disabled',true);
    btn.find('.bl-blast-send-label').hide();
    btn.find('.bl-blast-send-spinner').show();
    result.hide();

    $.post(bluAdmin.ajaxUrl,{
      action:'blusiast_send_event_blast',
      nonce:bluAdmin.nonce,
      event_id:event_id,
      subject:subject,
      body:body,
      statuses:statuses
    },function(res){
      btn.prop('disabled',false);
      btn.find('.bl-blast-send-label').show();
      btn.find('.bl-blast-send-spinner').hide();
      if(res.success){
        var d=res.data;
        var msg='✓ Sent '+d.sent+' of '+d.total+' emails.';
        if(d.failed>0) msg+=' ('+d.failed+' failed — check your SMTP settings)';
        result.removeClass('bl-blast-result--error').addClass('bl-blast-result--success bl-blast-result').text(msg).show();
        $('#bl-blast-subject').val('');
        $('#bl-blast-body').val('');
      } else {
        result.removeClass('bl-blast-result--success').addClass('bl-blast-result--error bl-blast-result').text(res.data.message||'Something went wrong.').show();
      }
    });
  });
});
JSCODE; }


// ─────────────────────────────────────────
// 6. HELPER — shared tabs markup
// ─────────────────────────────────────────

function blusiast_admin_tabs( $active ) {
    $tabs = [
        'blusiast-cms'           => 'Dashboard',
        'blusiast-registrations' => 'Registrations',
        'blusiast-all-members'   => 'All Members',
        'blusiast-members'       => 'Spotlights',
        'blusiast-help'          => 'Help',
    ];
    echo '<div class="bl-cms-tabs">';
    foreach ( $tabs as $page => $label ) {
        $cls = ( $page === $active ) ? ' bl-cms-tab--active' : '';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '" class="bl-cms-tab' . $cls . '">' . esc_html( $label ) . '</a>';
    }
    echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=bl_event' ) ) . '" class="bl-cms-tab">Events</a>';
    echo '</div>';
}

function blusiast_admin_header( $subtitle ) {
    echo '<div class="bl-cms-header"><div>';
    echo '<div class="bl-cms-logo">Blus<span>iast</span> CMS</div>';
    echo '<div class="bl-cms-subtitle">' . esc_html( $subtitle ) . '</div>';
    echo '</div></div>';
}


// ─────────────────────────────────────────
// 7. DASHBOARD — events as clickable cards
// ─────────────────────────────────────────

function blusiast_cms_dashboard() {
    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';

    // Summary stats
    $total_regs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    $confirmed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='confirmed'" );
    $pending    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='pending'" );

    // Per-event counts
    $event_counts = $wpdb->get_results(
        "SELECT event_id, COUNT(*) as total,
                SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) as pending
         FROM $table GROUP BY event_id", OBJECT_K
    );

    // All published events ordered by date
    $events = get_posts( [
        'post_type'      => 'bl_event',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_key'       => 'event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
    ] );
    ?>
    <div class="bl-cms-wrap">
        <?php blusiast_admin_header( 'Dashboard' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-cms' ); ?>

        <div class="bl-stat-row">
            <div class="bl-stat-card"><div class="bl-stat-label">Total Registrations</div><div class="bl-stat-num bl-stat-num--red"><?php echo $total_regs; ?></div></div>
            <div class="bl-stat-card"><div class="bl-stat-label">Confirmed</div><div class="bl-stat-num"><?php echo $confirmed; ?></div></div>
            <div class="bl-stat-card"><div class="bl-stat-label">Pending</div><div class="bl-stat-num"><?php echo $pending; ?></div></div>
            <div class="bl-stat-card"><div class="bl-stat-label">Active Events</div><div class="bl-stat-num"><?php echo count( $events ); ?></div></div>
        </div>

        <p class="bl-cms-subtitle" style="margin-bottom:16px;">Click an event to view its registrations</p>

        <?php if ( $events ) : ?>
        <div class="bl-event-grid">
            <?php foreach ( $events as $ev ) :
                $ev_date  = function_exists( 'get_field' ) ? get_field( 'event_date', $ev->ID ) : '';
                $fmt_date = $ev_date ? date( 'M j, Y', strtotime( $ev_date ) ) : 'Date TBD';
                $counts   = $event_counts[ $ev->ID ] ?? null;
                $total    = $counts ? (int) $counts->total     : 0;
                $conf     = $counts ? (int) $counts->confirmed : 0;
                $pend     = $counts ? (int) $counts->pending   : 0;
                $url      = admin_url( 'admin.php?page=blusiast-registrations&event_id=' . $ev->ID );
            ?>
                <a href="<?php echo esc_url( $url ); ?>" class="bl-event-card">
                    <div class="bl-event-card__date"><?php echo esc_html( $fmt_date ); ?></div>
                    <div class="bl-event-card__title"><?php echo esc_html( $ev->post_title ); ?></div>
                    <div class="bl-event-card__stats">
                        <div class="bl-event-card__stat">
                            <div class="bl-event-card__stat-num bl-event-card__stat-num--red"><?php echo $total; ?></div>
                            <div class="bl-event-card__stat-label">Registered</div>
                        </div>
                        <div class="bl-event-card__stat">
                            <div class="bl-event-card__stat-num"><?php echo $conf; ?></div>
                            <div class="bl-event-card__stat-label">Confirmed</div>
                        </div>
                        <div class="bl-event-card__stat">
                            <div class="bl-event-card__stat-num"><?php echo $pend; ?></div>
                            <div class="bl-event-card__stat-label">Pending</div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
            <div class="bl-empty"><strong>No Events Yet</strong><a href="<?php echo esc_url( admin_url('post-new.php?post_type=bl_event') ); ?>" style="color:var(--bl-red);">Create your first event →</a></div>
        <?php endif; ?>

    </div>
    <?php
}


// ─────────────────────────────────────────
// 8. REGISTRATIONS PAGE (all or per-event)
// ─────────────────────────────────────────

function blusiast_registrations_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';

    // CSV export
    if ( isset( $_GET['bl_export'] ) && current_user_can( 'manage_options' ) ) {
        blusiast_export_registrations_csv( absint( $_GET['event_id'] ?? 0 ) );
        return;
    }

    $filter_event_id = absint( $_GET['event_id'] ?? 0 );

    if ( $filter_event_id ) {
        $registrations = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, p.post_title as event_name
             FROM $table r LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             WHERE r.event_id = %d ORDER BY r.created_at DESC",
            $filter_event_id
        ) );
        $event_title = get_the_title( $filter_event_id );
    } else {
        $registrations = $wpdb->get_results(
            "SELECT r.*, p.post_title as event_name
             FROM $table r LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             ORDER BY r.created_at DESC"
        );
        $event_title = 'All Events';
    }

    $events = get_posts( [ 'post_type' => 'bl_event', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
    $export_url = admin_url( 'admin.php?page=blusiast-registrations&bl_export=1' . ( $filter_event_id ? '&event_id=' . $filter_event_id : '' ) );
    ?>
    <div class="bl-cms-wrap">
        <?php blusiast_admin_header( 'Registrations' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-registrations' ); ?>

        <?php if ( $filter_event_id ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=blusiast-cms' ) ); ?>" class="bl-back">← Back to Dashboard</a>
        <?php endif; ?>

        <div class="bl-table-wrap">
            <div class="bl-table-toolbar">
                <h2><?php echo esc_html( $event_title ); ?> (<?php echo count( $registrations ); ?>)</h2>
                <input type="search" id="bl-reg-search" class="bl-search-input" placeholder="Search name, email…">
                <?php if ( ! $filter_event_id ) : ?>
                <select id="bl-event-filter" class="bl-filter-select" onchange="location='<?php echo esc_js( admin_url('admin.php?page=blusiast-registrations') ); ?>&event_id='+this.value">
                    <option value="">All Events</option>
                    <?php foreach ( $events as $ev ) : ?>
                        <option value="<?php echo $ev->ID; ?>"><?php echo esc_html( $ev->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <a href="<?php echo esc_url( $export_url ); ?>" class="bl-btn-sm">↓ Export CSV</a>
                <?php if ( $filter_event_id && $registrations ) : ?>
                <button type="button" class="bl-btn-sm bl-btn-email-blast" id="bl-email-blast-toggle"
                        data-event-id="<?php echo esc_attr( $filter_event_id ); ?>"
                        data-event-name="<?php echo esc_attr( $event_title ); ?>">
                    ✉ Email Registrants
                </button>
                <?php endif; ?>
            </div>

            <?php if ( $filter_event_id && $registrations ) : ?>
            <!-- Email blast compose panel -->
            <div id="bl-email-blast-panel" style="display:none;">
                <div class="bl-blast-panel">
                    <div class="bl-blast-panel__header">
                        <div>
                            <div class="bl-blast-panel__title">✉ Email Registrants</div>
                            <div class="bl-blast-panel__sub">Sending to: <strong><?php echo esc_html( $event_title ); ?></strong></div>
                        </div>
                        <button type="button" class="bl-blast-close" id="bl-email-blast-close">✕</button>
                    </div>

                    <div class="bl-blast-panel__body">
                        <div class="bl-blast-row">
                            <label class="bl-blast-label">Send to</label>
                            <div class="bl-blast-checkboxes">
                                <label class="bl-blast-check"><input type="checkbox" name="bl_blast_status" value="confirmed" checked> <span class="bl-status bl-status--confirmed">Confirmed</span></label>
                                <label class="bl-blast-check"><input type="checkbox" name="bl_blast_status" value="pending" checked> <span class="bl-status bl-status--pending">Pending</span></label>
                                <label class="bl-blast-check"><input type="checkbox" name="bl_blast_status" value="waitlist" checked> <span class="bl-status bl-status--waitlist">Waitlist</span></label>
                                <label class="bl-blast-check"><input type="checkbox" name="bl_blast_status" value="cancelled"> <span class="bl-status bl-status--cancelled">Cancelled</span></label>
                            </div>
                        </div>

                        <div class="bl-blast-row">
                            <label class="bl-blast-label" for="bl-blast-subject">Subject</label>
                            <input type="text" id="bl-blast-subject" class="bl-blast-input"
                                   placeholder="e.g. Important update about <?php echo esc_attr( $event_title ); ?>">
                        </div>

                        <div class="bl-blast-row">
                            <label class="bl-blast-label" for="bl-blast-body">
                                Message
                                <span class="bl-blast-hint">Use <code>{name}</code> to personalise with the recipient's first name.</span>
                            </label>
                            <textarea id="bl-blast-body" class="bl-blast-input bl-blast-textarea"
                                      rows="8" placeholder="Hey {name}, just wanted to let you know..."></textarea>
                        </div>

                        <div id="bl-blast-result" class="bl-blast-result" style="display:none;"></div>

                        <div class="bl-blast-actions">
                            <button type="button" id="bl-blast-send" class="bl-blast-send-btn"
                                    data-event-id="<?php echo esc_attr( $filter_event_id ); ?>">
                                <span class="bl-blast-send-label">Send Emails</span>
                                <span class="bl-blast-send-spinner" style="display:none;">Sending…</span>
                            </button>
                            <span class="bl-blast-fine">Emails send individually — each recipient only sees their own.</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $registrations ) : ?>
            <table class="bl-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Zip</th>
                        <?php if ( ! $filter_event_id ) : ?><th>Event</th><?php endif; ?>
                        <th>Guests</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $registrations as $reg ) : ?>
                    <tr data-status="<?php echo esc_attr( $reg->status ); ?>">
                        <td style="color:var(--bl-g1);font-size:11px;"><?php echo (int) $reg->id; ?></td>
                        <td class="bl-td-name"><?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?></td>
                        <td><a href="mailto:<?php echo esc_attr( $reg->email ); ?>" style="color:var(--bl-g2);"><?php echo esc_html( $reg->email ); ?></a></td>
                        <td><?php echo esc_html( $reg->phone ?: '—' ); ?></td>
                        <td><?php echo esc_html( $reg->zip ?: '—' ); ?></td>
                        <?php if ( ! $filter_event_id ) : ?><td class="bl-td-event"><?php echo esc_html( $reg->event_name ); ?></td><?php endif; ?>
                        <td style="text-align:center;"><?php echo (int) $reg->guest_count; ?></td>
                        <td>
                            <span class="bl-status bl-status--<?php echo esc_attr( $reg->status ); ?>">
                                <select class="bl-status-select" data-id="<?php echo (int) $reg->id; ?>">
                                    <option value="pending"   <?php selected( $reg->status, 'pending'   ); ?>>Pending</option>
                                    <option value="confirmed" <?php selected( $reg->status, 'confirmed' ); ?>>Confirmed</option>
                                    <option value="waitlist"  <?php selected( $reg->status, 'waitlist'  ); ?>>Waitlist</option>
                                    <option value="cancelled" <?php selected( $reg->status, 'cancelled' ); ?>>Cancelled</option>
                                </select>
                            </span>
                        </td>
                        <td>
                            <textarea class="bl-note-input" data-id="<?php echo (int) $reg->id; ?>" rows="2" placeholder="Note…"><?php echo esc_textarea( $reg->notes ); ?></textarea>
                        </td>
                        <td style="font-size:11px;white-space:nowrap;"><?php echo esc_html( date( 'M j, Y', strtotime( $reg->created_at ) ) ); ?></td>
                        <td>
                            <button class="bl-btn-sm bl-btn-danger bl-delete-reg" data-id="<?php echo (int) $reg->id; ?>">Remove</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <div class="bl-empty"><strong>No Registrations Yet</strong>Sign-ups will appear here once visitors register.</div>
            <?php endif; ?>
        </div>

        <!-- Email setup info -->
        <div class="bl-info-box">
            <strong>📧 Getting emails to work:</strong> WordPress's built-in <code>wp_mail()</code> requires your hosting to have a configured mail server. For reliable delivery install the free
            <strong>WP Mail SMTP</strong> plugin and connect it to any transactional mail service. Recommended free options:
            <strong>Brevo (Sendinblue)</strong> — 300 emails/day free, or <strong>Mailgun</strong> — 100/day free.
            Once set up, every registration fires a confirmation to the attendee and a notification to <code><?php echo esc_html( get_option('admin_email') ); ?></code>.
            Your "From" address is set to that same admin email automatically.
        </div>

    </div>
    <?php
}

function blusiast_export_registrations_csv( $event_id = 0 ) {
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';
    $sql   = "SELECT r.*, p.post_title as event_name FROM $table r LEFT JOIN {$wpdb->posts} p ON p.ID=r.event_id";
    if ( $event_id ) $sql .= $wpdb->prepare( " WHERE r.event_id=%d", $event_id );
    $sql  .= " ORDER BY r.event_id, r.created_at";
    $rows  = $wpdb->get_results( $sql, ARRAY_A );

    $filename = $event_id ? 'blusiast-' . sanitize_title( get_the_title( $event_id ) ) . '-' . date('Y-m-d') : 'blusiast-registrations-' . date('Y-m-d');
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, [ 'ID', 'Event', 'First Name', 'Last Name', 'Email', 'Phone', 'Zip', 'Guests', 'Status', 'Notes', 'Registered' ] );
    foreach ( $rows as $row ) {
        fputcsv( $out, [ $row['id'], $row['event_name'], $row['first_name'], $row['last_name'],
            $row['email'], $row['phone'], $row['zip'], $row['guest_count'], $row['status'], $row['notes'], $row['created_at'] ] );
    }
    fclose( $out );
    exit;
}


// ─────────────────────────────────────────
// 9. ALL MEMBERS PAGE
// ─────────────────────────────────────────

function blusiast_all_members_page() {
    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $rtable = $wpdb->prefix . 'bl_event_registrations';

    // Ensure table exists
    blusiast_install_db();

    // CSV export
    if ( isset( $_GET['bl_export_members'] ) && current_user_can( 'manage_options' ) ) {
        $rows = $wpdb->get_results(
            "SELECT m.*,
                COUNT(DISTINCT r.id) as event_count,
                MAX(r.created_at) as last_event
             FROM $mtable m
             LEFT JOIN $rtable r ON r.email = m.email
             GROUP BY m.id
             ORDER BY m.joined_at DESC",
            ARRAY_A
        );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="blusiast-members-' . date('Y-m-d') . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'First Name', 'Last Name', 'Handle', 'Email', 'Phone', 'Zip', 'Account Status', 'Events Attended', 'Last Event', 'Joined', 'Billing Notes' ] );
        foreach ( $rows as $row ) {
            fputcsv( $out, [ $row['id'], $row['first_name'], $row['last_name'], $row['email'],
                $row['phone'], $row['zip'], $row['account_status'], $row['event_count'],
                $row['last_event'], $row['joined_at'], $row['billing_notes'] ] );
        }
        fclose( $out );
        exit;
    }

    // Summary stats
    $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mtable" );
    $active   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mtable WHERE account_status='active'" );
    $free     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mtable WHERE account_status='free'" );
    $lapsed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $mtable WHERE account_status='lapsed'" );

    // All members with aggregated event data
    $members = $wpdb->get_results(
        "SELECT m.*,
            COUNT(DISTINCT r.event_id) as event_count,
            SUM(r.guest_count) as total_guests,
            MAX(r.created_at) as last_event_date,
            GROUP_CONCAT(DISTINCT p.post_title ORDER BY r.created_at DESC SEPARATOR ', ') as event_names
         FROM $mtable m
         LEFT JOIN $rtable r ON r.email = m.email
         LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
         GROUP BY m.id
         ORDER BY m.joined_at DESC"
    );

    $export_url = admin_url( 'admin.php?page=blusiast-all-members&bl_export_members=1' );
    ?>
    <div class="bl-cms-wrap">
        <?php blusiast_admin_header( 'All Members' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-all-members' ); ?>

        <!-- Stats strip -->
        <div class="bl-member-stats-strip">
            <div class="bl-member-stat">
                <div class="bl-member-stat-num" style="color:var(--bl-red);"><?php echo $total; ?></div>
                <div class="bl-member-stat-label">Total Members</div>
            </div>
            <div class="bl-member-stat">
                <div class="bl-member-stat-num" style="color:#5cb85c;"><?php echo $active; ?></div>
                <div class="bl-member-stat-label">Paid Active</div>
            </div>
            <div class="bl-member-stat">
                <div class="bl-member-stat-num"><?php echo $free; ?></div>
                <div class="bl-member-stat-label">Free / Unsigned</div>
            </div>
            <div class="bl-member-stat">
                <div class="bl-member-stat-num" style="color:#f5a623;"><?php echo $lapsed; ?></div>
                <div class="bl-member-stat-label">Lapsed</div>
            </div>
        </div>

        <div class="bl-info-box" style="margin-bottom:20px;">
            <strong>Account Statuses:</strong>
            <span class="bl-status bl-acct--free" style="margin-left:8px;">Free</span> — signed up for events, no paid membership yet &nbsp;|&nbsp;
            <span class="bl-status bl-acct--active">Active</span> — paying member &nbsp;|&nbsp;
            <span class="bl-status bl-acct--lapsed">Lapsed</span> — previously paid, now inactive &nbsp;|&nbsp;
            <span class="bl-status bl-acct--banned">Banned</span> — removed from community.
            When you launch paid memberships, update statuses here.
        </div>

        <div class="bl-table-wrap">
            <div class="bl-table-toolbar">
                <h2>Members (<?php echo count( $members ); ?>)</h2>
                <input type="search" id="bl-reg-search" class="bl-search-input" placeholder="Search name, email, zip…">
                <select class="bl-filter-select" id="bl-member-status-filter">
                    <option value="">All Statuses</option>
                    <option value="free">Free</option>
                    <option value="active">Active</option>
                    <option value="lapsed">Lapsed</option>
                    <option value="banned">Banned</option>
                </select>
                <a href="<?php echo esc_url( $export_url ); ?>" class="bl-btn-sm">↓ Export CSV</a>
                <?php if ( $members ) : ?>
                <button type="button" class="bl-btn-sm bl-btn-email-blast" id="bl-member-blast-toggle">
                    ✉ Email Members
                </button>
                <?php endif; ?>
            </div>

            <?php if ( $members ) : ?>
            <!-- Member email blast panel -->
            <div id="bl-member-blast-panel" style="display:none;">
                <div class="bl-blast-panel">
                    <div class="bl-blast-panel__header">
                        <div>
                            <div class="bl-blast-panel__title">✉ Email Members</div>
                            <div class="bl-blast-panel__sub">Send to your full member list or filter by account status</div>
                        </div>
                        <button type="button" class="bl-blast-close" id="bl-member-blast-close">✕</button>
                    </div>
                    <div class="bl-blast-panel__body">
                        <div class="bl-blast-row">
                            <label class="bl-blast-label" for="bl-member-blast-subject">Subject</label>
                            <input type="text" id="bl-member-blast-subject" class="bl-blast-input" placeholder="e.g. Exciting news from the Blusiast crew!">
                        </div>
                        <div class="bl-blast-row">
                            <label class="bl-blast-label" for="bl-member-blast-body">
                                Message
                                <span class="bl-blast-hint">Use <code>{name}</code> to personalise with each member's first name.</span>
                            </label>
                            <textarea id="bl-member-blast-body" class="bl-blast-input bl-blast-textarea" rows="8" placeholder="Hey {name}, we've got something exciting to share…"></textarea>
                        </div>
                        <div id="bl-member-blast-result" class="bl-blast-result" style="display:none;"></div>
                        <div class="bl-blast-actions">
                            <button type="button" id="bl-member-blast-send" class="bl-blast-send-btn">
                                <span class="bl-blast-send-label">Send Emails</span>
                                <span class="bl-blast-send-spinner" style="display:none;">Sending…</span>
                            </button>
                            <span class="bl-blast-fine">Each member receives their own individual email.</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $members ) : ?>
            <table class="bl-table" id="bl-members-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Handle</th>
                        <th>Phone</th>
                        <th>Zip</th>
                        <th>Events</th>
                        <th>Last Event</th>
                        <th>Joined</th>
                        <th>Account</th>
                        <th>Billing Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $members as $m ) :
                    $initials = strtoupper( substr( $m->first_name, 0, 1 ) . substr( $m->last_name, 0, 1 ) );
                    $last_evt = $m->last_event_date ? date( 'M j, Y', strtotime( $m->last_event_date ) ) : '—';
                    $joined   = date( 'M j, Y', strtotime( $m->joined_at ) );
                ?>
                    <tr data-status="<?php echo esc_attr( $m->account_status ); ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div class="bl-member-avatar" style="width:34px;height:34px;font-size:13px;flex-shrink:0;"><?php echo esc_html( $initials ); ?></div>
                                <div>
                                    <div class="bl-td-name"><?php echo esc_html( $m->first_name . ' ' . $m->last_name ); ?></div>
                                    <?php if ( $m->zip ) : ?>
                                    <div style="font-size:11px;color:var(--bl-g1);" class="bl-zip-location" data-zip="<?php echo esc_attr( $m->zip ); ?>">
                                        📍 <?php echo esc_html( $m->zip ); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><a href="mailto:<?php echo esc_attr( $m->email ); ?>" style="color:var(--bl-g2);"><?php echo esc_html( $m->email ); ?></a></td>
                        <td style="font-size:12px;color:var(--bl-red);"><?php echo $m->handle ? esc_html( '@' . $m->handle ) : '<span style="color:var(--bl-g1);">—</span>'; ?></td>
                        <td><?php echo esc_html( $m->phone ?: '—' ); ?></td>
                        <td><?php echo esc_html( $m->zip ?: '—' ); ?></td>
                        <td style="text-align:center;">
                            <strong style="color:var(--bl-white);"><?php echo (int) $m->event_count; ?></strong>
                            <?php if ( $m->total_guests > $m->event_count ) : ?>
                                <div style="font-size:10px;color:var(--bl-g1);"><?php echo (int) $m->total_guests; ?> total guests</div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;white-space:nowrap;"><?php echo esc_html( $last_evt ); ?></td>
                        <td style="font-size:12px;white-space:nowrap;"><?php echo esc_html( $joined ); ?></td>
                        <td>
                            <span class="bl-status bl-acct--<?php echo esc_attr( $m->account_status ); ?>">
                                <select class="bl-member-status-select" data-id="<?php echo (int) $m->id; ?>">
                                    <option value="free"   <?php selected( $m->account_status, 'free'   ); ?>>Free</option>
                                    <option value="active" <?php selected( $m->account_status, 'active' ); ?>>Active</option>
                                    <option value="lapsed" <?php selected( $m->account_status, 'lapsed' ); ?>>Lapsed</option>
                                    <option value="banned" <?php selected( $m->account_status, 'banned' ); ?>>Banned</option>
                                </select>
                            </span>
                        </td>
                        <td>
                            <textarea class="bl-note-input bl-member-note-input" data-id="<?php echo (int) $m->id; ?>" rows="2" placeholder="Billing notes…"><?php echo esc_textarea( $m->billing_notes ); ?></textarea>
                        </td>
                        <td>
                            <button class="bl-btn-sm bl-btn-danger bl-delete-member"
                                    data-id="<?php echo (int) $m->id; ?>"
                                    data-name="<?php echo esc_attr( $m->first_name . ' ' . $m->last_name ); ?>"
                                    data-has-user="<?php echo $m->wp_user_id ? '1' : '0'; ?>">
                                Remove
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <div class="bl-empty">
                    <strong>No Members Yet</strong>
                    Members are added automatically when someone registers for an event.
                </div>
            <?php endif; ?>
        </div>

    </div>
    <!-- Delete confirmation modal -->
    <div id="bl-delete-member-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.7);align-items:center;justify-content:center;">
        <div style="background:#111;border:1px solid #333;border-top:3px solid #CC0000;border-radius:8px;padding:28px;max-width:420px;width:90%;font-family:'Barlow',sans-serif;">
            <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:800;text-transform:uppercase;color:#fff;margin-bottom:8px;">Remove Member?</div>
            <p style="font-size:13px;color:#aaa;margin-bottom:16px;">You are about to remove <strong id="bl-delete-member-name" style="color:#fff;"></strong> from the member database. This cannot be undone.</p>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#aaa;margin-bottom:20px;cursor:pointer;">
                <input type="checkbox" id="bl-delete-wp-user" style="accent-color:#CC0000;">
                Also delete their WordPress login account
            </label>
            <div style="display:flex;gap:10px;">
                <button id="bl-delete-member-confirm" style="background:#CC0000;border:none;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:9px 20px;border-radius:6px;cursor:pointer;">Yes, Remove</button>
                <button id="bl-delete-member-cancel" style="background:#1a1a1a;border:1px solid #333;color:#aaa;font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:9px 20px;border-radius:6px;cursor:pointer;">Cancel</button>
            </div>
        </div>
    </div>

    <script>
    jQuery(function($){
        $('#bl-member-status-filter').on('change', function(){
            var val = $(this).val();
            $('#bl-members-table tbody tr').each(function(){
                if (!val || $(this).data('status') === val) $(this).show();
                else $(this).hide();
            });
        });

        // Zip → City lookup via zippopotam.us (free, no key needed)
        var zipCache = {};
        function lookupZip(zip, el) {
            var clean = zip.replace(/\D/g, '').substring(0, 5);
            if (clean.length < 5) return;
            if (zipCache[clean]) { $(el).html('📍 ' + zipCache[clean]); return; }
            $.getJSON('https://api.zippopotam.us/us/' + clean, function(data) {
                if (data && data.places && data.places[0]) {
                    var city  = data.places[0]['place name'];
                    var state = data.places[0]['state abbreviation'];
                    var label = city + ', ' + state;
                    zipCache[clean] = label;
                    $(el).html('📍 ' + label);
                }
            }).fail(function(){ /* keep showing zip if lookup fails */ });
        }

        // Stagger requests to avoid hammering the API
        var delay = 0;
        $('.bl-zip-location').each(function() {
            var el  = this;
            var zip = $(this).data('zip');
            setTimeout(function(){ lookupZip(zip, el); }, delay);
            delay += 120;
        });

        // Delete member flow
        var $modal    = $('#bl-delete-member-modal');
        var pendingId = null;
        var pendingRow = null;

        $(document).on('click', '.bl-delete-member', function(){
            pendingId  = $(this).data('id');
            pendingRow = $(this).closest('tr');
            var name    = $(this).data('name');
            var hasUser = $(this).data('has-user');
            $('#bl-delete-member-name').text(name);
            $('#bl-delete-wp-user').prop('checked', false);
            $('#bl-delete-wp-user').closest('label').toggle(hasUser == '1');
            $modal.css('display','flex');
        });

        $('#bl-delete-member-cancel').on('click', function(){
            $modal.hide();
            pendingId = null;
            pendingRow = null;
        });

        $modal.on('click', function(e){
            if (e.target === this) { $modal.hide(); pendingId = null; pendingRow = null; }
        });

        $('#bl-delete-member-confirm').on('click', function(){
            if (!pendingId) return;
            var btn = $(this);
            btn.text('Removing…').prop('disabled', true);
            $.post('<?php echo esc_js( admin_url("admin-ajax.php") ); ?>', {
                action:          'blusiast_delete_member',
                nonce:           '<?php echo wp_create_nonce("blusiast_admin_nonce"); ?>',
                id:              pendingId,
                delete_wp_user:  $('#bl-delete-wp-user').is(':checked') ? 1 : 0
            }, function(res){
                $modal.hide();
                btn.text('Yes, Remove').prop('disabled', false);
                if (res.success && pendingRow) {
                    pendingRow.fadeOut(300, function(){ $(this).remove(); });
                }
                pendingId = null;
                pendingRow = null;
            });
        });
    });
    </script>
    <?php
}


// ─────────────────────────────────────────
// 10. MEMBERS / SPOTLIGHTS PAGE
// ─────────────────────────────────────────

function blusiast_email_settings_page() {
    // Save settings
    if ( isset( $_POST['bl_email_save'] ) && check_admin_referer( 'bl_email_settings' ) ) {
        update_option( 'bl_email_from_name',       sanitize_text_field( $_POST['from_name']      ?? 'Blusiast' ) );
        update_option( 'bl_email_from_address',    sanitize_email( $_POST['from_address']         ?? get_option('admin_email') ) );
        update_option( 'bl_email_reg_subject',     sanitize_text_field( $_POST['reg_subject']    ?? '' ) );
        update_option( 'bl_email_reg_body',        sanitize_textarea_field( $_POST['reg_body']   ?? '' ) );
        update_option( 'bl_email_signup_subject',  sanitize_text_field( $_POST['signup_subject'] ?? '' ) );
        update_option( 'bl_email_signup_body',     sanitize_textarea_field( $_POST['signup_body']?? '' ) );
        update_option( 'bl_smtp_host',             sanitize_text_field( $_POST['smtp_host']      ?? '' ) );
        update_option( 'bl_smtp_port',             absint( $_POST['smtp_port']                   ?? 587 ) );
        update_option( 'bl_smtp_user',             sanitize_text_field( $_POST['smtp_user']      ?? '' ) );
        if ( ! empty( $_POST['smtp_pass'] ) ) {
            update_option( 'bl_smtp_pass', sanitize_text_field( $_POST['smtp_pass'] ) );
        }
        update_option( 'bl_smtp_encryption',       sanitize_text_field( $_POST['smtp_encryption'] ?? 'tls' ) );
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
    ?>
    <div class="bl-cms-wrap">
        <?php blusiast_admin_header( 'Email Settings' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-email-settings' ); ?>

        <div class="bl-info-box" style="margin-bottom:24px;">
            <strong>📧 SMTP Setup:</strong> WordPress uses PHP mail by default which often lands in spam.
            Enter your SMTP credentials below (from Brevo, Mailgun, Gmail, etc.) and install
            <strong>WP Mail SMTP</strong> plugin to apply them — or if you prefer, configure SMTP directly in
            that plugin and ignore the fields here. The email templates below control what gets sent automatically.
            Use <code>{name}</code>, <code>{event}</code>, <code>{date}</code>, <code>{location}</code> as placeholders.
        </div>

        <form method="post">
            <?php wp_nonce_field( 'bl_email_settings' ); ?>

            <!-- From Address -->
            <div class="bl-table-wrap" style="margin-bottom:20px;">
                <div class="bl-table-toolbar"><h2>Sender Identity</h2></div>
                <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">From Name</label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="text" name="from_name" value="<?php echo esc_attr(get_option('bl_email_from_name','Blusiast')); ?>">
                    </div>
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">From Email Address</label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="email" name="from_address" value="<?php echo esc_attr(get_option('bl_email_from_address', get_option('admin_email'))); ?>">
                    </div>
                </div>
            </div>

            <!-- SMTP -->
            <div class="bl-table-wrap" style="margin-bottom:20px;">
                <div class="bl-table-toolbar"><h2>SMTP Configuration</h2></div>
                <div style="padding:20px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">SMTP Host</label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="text" name="smtp_host" placeholder="smtp.brevo.com" value="<?php echo esc_attr(get_option('bl_smtp_host','')); ?>">
                    </div>
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">Port</label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="number" name="smtp_port" value="<?php echo esc_attr(get_option('bl_smtp_port',587)); ?>">
                    </div>
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">Encryption</label>
                        <select class="bl-filter-select" style="width:100%;padding:8px 12px;" name="smtp_encryption">
                            <option value="tls" <?php selected(get_option('bl_smtp_encryption','tls'),'tls'); ?>>TLS (recommended)</option>
                            <option value="ssl" <?php selected(get_option('bl_smtp_encryption','tls'),'ssl'); ?>>SSL</option>
                            <option value="none" <?php selected(get_option('bl_smtp_encryption','tls'),'none'); ?>>None</option>
                        </select>
                    </div>
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">SMTP Username</label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="text" name="smtp_user" value="<?php echo esc_attr(get_option('bl_smtp_user','')); ?>">
                    </div>
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">SMTP Password <span style="font-weight:400;text-transform:none;">(leave blank to keep current)</span></label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="password" name="smtp_pass" placeholder="••••••••">
                    </div>
                </div>
                <div style="padding:0 20px 16px;">
                    <div class="bl-info-box" style="font-size:12px;">
                        <strong>Brevo (free 300/day):</strong> smtp-relay.brevo.com · port 587 · TLS &nbsp;|&nbsp;
                        <strong>Mailgun (free 100/day):</strong> smtp.mailgun.org · port 587 · TLS &nbsp;|&nbsp;
                        <strong>Gmail:</strong> smtp.gmail.com · port 587 · TLS (use App Password)
                    </div>
                </div>
            </div>

            <!-- Event Registration Email -->
            <div class="bl-table-wrap" style="margin-bottom:20px;">
                <div class="bl-table-toolbar"><h2>Event Registration Confirmation Email</h2></div>
                <div style="padding:20px;display:flex;flex-direction:column;gap:16px;">
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">Subject</label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="text" name="reg_subject"
                               value="<?php echo esc_attr(get_option('bl_email_reg_subject', "You're registered: {event} — Blusiast")); ?>">
                    </div>
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">Body <span style="font-weight:400;text-transform:none;">— use {name} {event} {date} {location}</span></label>
                        <textarea class="bl-search-input" style="width:100%;padding:8px 12px;min-height:160px;resize:vertical;" name="reg_body"><?php echo esc_textarea(get_option('bl_email_reg_body', "Hey {name},

You're locked in for {event}!

📅 {date}
📍 {location}

We'll be in touch with more details soon.

Ride on,
The Blusiast Crew")); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Membership Signup Email -->
            <div class="bl-table-wrap" style="margin-bottom:24px;">
                <div class="bl-table-toolbar"><h2>New Member Welcome Email</h2></div>
                <div style="padding:20px;display:flex;flex-direction:column;gap:16px;">
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">Subject</label>
                        <input class="bl-search-input" style="width:100%;padding:8px 12px;" type="text" name="signup_subject"
                               value="<?php echo esc_attr(get_option('bl_email_signup_subject', 'Welcome to Blusiast, {name}!')); ?>">
                    </div>
                    <div>
                        <label class="bl-stat-label" style="display:block;margin-bottom:6px;">Body <span style="font-weight:400;text-transform:none;">— use {name} {portal_url}</span></label>
                        <textarea class="bl-search-input" style="width:100%;padding:8px 12px;min-height:160px;resize:vertical;" name="signup_body"><?php echo esc_textarea(get_option('bl_email_signup_body', "Hey {name},

You're officially part of the crew!

Your account is ready. Head to your portal:
{portal_url}

Ride on,
The Blusiast Crew")); ?></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" name="bl_email_save" class="bl-btn-sm" style="background:var(--bl-red);color:#fff;border-color:var(--bl-red);padding:10px 24px;font-size:14px;">
                Save All Settings
            </button>
        </form>
    </div>
    <?php
}


function blusiast_members_page() {
    $spotlights = get_posts( [ 'post_type' => 'bl_spotlight', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ] );
    ?>
    <div class="bl-cms-wrap">
        <?php blusiast_admin_header( 'Member Spotlights' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-members' ); ?>

        <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
            <a href="<?php echo esc_url( admin_url('post-new.php?post_type=bl_spotlight') ); ?>" class="bl-btn-sm">+ Add New Spotlight</a>
        </div>

        <p style="color:var(--bl-g2);font-size:13px;margin-bottom:16px;">
            Toggle <strong style="color:var(--bl-white);">Current Month Feature</strong> in a spotlight's ACF fields to make it appear on the homepage.
        </p>

        <?php if ( $spotlights ) : ?>
        <div class="bl-member-grid">
            <?php foreach ( $spotlights as $sp ) :
                $is_active = function_exists( 'get_field' ) ? get_field( 'spotlight_is_active',    $sp->ID ) : false;
                $home_park = function_exists( 'get_field' ) ? get_field( 'spotlight_home_park',     $sp->ID ) : '';
                $fave      = function_exists( 'get_field' ) ? get_field( 'spotlight_fave_coaster',  $sp->ID ) : '';
                $years     = function_exists( 'get_field' ) ? get_field( 'spotlight_years_member',  $sp->ID ) : '';
                $parts     = explode( ' ', $sp->post_title );
                $initials  = strtoupper( substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );
            ?>
                <div class="bl-member-card <?php echo $is_active ? 'bl-member-card--active' : ''; ?>">
                    <?php if ( has_post_thumbnail( $sp->ID ) ) : ?>
                        <img src="<?php echo esc_url( get_the_post_thumbnail_url( $sp->ID, 'thumbnail' ) ); ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;" alt="">
                    <?php else : ?>
                        <div class="bl-member-avatar"><?php echo esc_html( $initials ); ?></div>
                    <?php endif; ?>
                    <div class="bl-member-name"><?php echo esc_html( $sp->post_title ); ?></div>
                    <?php if ( $is_active ) : ?><div class="bl-member-spotlight-badge">★ Current Feature</div><?php endif; ?>
                    <?php if ( $home_park ) : ?><div class="bl-member-meta">🏠 <?php echo esc_html( $home_park ); ?></div><?php endif; ?>
                    <?php if ( $fave )      : ?><div class="bl-member-meta">🎢 <?php echo esc_html( $fave ); ?></div><?php endif; ?>
                    <?php if ( $years )     : ?><div class="bl-member-meta"><?php echo (int) $years; ?> yr<?php echo $years != 1 ? 's' : ''; ?> as member</div><?php endif; ?>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <a href="<?php echo esc_url( get_edit_post_link( $sp->ID ) ); ?>" class="bl-btn-sm">Edit</a>
                        <a href="<?php echo esc_url( get_permalink( $sp->ID ) ); ?>" class="bl-btn-sm" target="_blank">View</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="bl-table-wrap" style="margin-top:32px;">
            <div class="bl-table-toolbar">
                <h2>All Spotlights (<?php echo count( $spotlights ); ?>)</h2>
                <a href="<?php echo esc_url( admin_url('edit.php?post_type=bl_spotlight') ); ?>" class="bl-btn-sm">Manage in WP →</a>
            </div>
            <table class="bl-table">
                <thead><tr><th>Name</th><th>Home Park</th><th>Fav Coaster</th><th>Parks Visited</th><th>Years</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $spotlights as $sp ) :
                    $is_active = function_exists( 'get_field' ) ? get_field( 'spotlight_is_active',    $sp->ID ) : false;
                    $home_park = function_exists( 'get_field' ) ? get_field( 'spotlight_home_park',     $sp->ID ) : '—';
                    $fave      = function_exists( 'get_field' ) ? get_field( 'spotlight_fave_coaster',  $sp->ID ) : '—';
                    $visited   = function_exists( 'get_field' ) ? get_field( 'spotlight_parks_visited', $sp->ID ) : '—';
                    $years     = function_exists( 'get_field' ) ? get_field( 'spotlight_years_member',  $sp->ID ) : '—';
                ?>
                    <tr>
                        <td class="bl-td-name"><?php echo esc_html( $sp->post_title ); ?></td>
                        <td><?php echo esc_html( $home_park ?: '—' ); ?></td>
                        <td><?php echo esc_html( $fave ?: '—' ); ?></td>
                        <td><?php echo esc_html( $visited ?: '—' ); ?></td>
                        <td><?php echo esc_html( $years ?: '—' ); ?></td>
                        <td><?php if ( $is_active ) : ?><span class="bl-status bl-status--confirmed">Active Feature</span><?php else : ?><span class="bl-status bl-status--pending">Inactive</span><?php endif; ?></td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $sp->ID ) ); ?>" class="bl-btn-sm">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else : ?>
            <div class="bl-empty"><strong>No Spotlights Yet</strong><a href="<?php echo esc_url( admin_url('post-new.php?post_type=bl_spotlight') ); ?>" style="color:var(--bl-red);">Add your first spotlight →</a></div>
        <?php endif; ?>
    </div>
    <?php
}
