<?php
/**
 * Blusiast Member CRM — inc/member-cms.php
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
// BREVO CONTACT SYNC
// ─────────────────────────────────────────
// Keeps Brevo contacts in sync with bl_members.
// Fires automatically on new member registration and profile updates.
// Brevo API key is stored in WP Admin → Blusiast CMS → Email Settings.
// ─────────────────────────────────────────

/**
 * Push a single member to Brevo as a contact.
 * Creates the contact if new, updates if already exists.
 * Silently no-ops if no API key is configured.
 *
 * @param string $email
 * @param string $first_name
 * @param string $last_name
 * @param string $account_status  e.g. 'free', 'active', 'lapsed'
 */
function blusiast_brevo_sync_contact( $email, $first_name, $last_name, $account_status = 'free' ) {
    $api_key = get_option( 'bl_brevo_api_key', '' );
    if ( ! $api_key || ! is_email( $email ) ) return;

    $list_id = (int) get_option( 'bl_brevo_list_id', 0 );

    $payload = [
        'email'            => $email,
        'updateEnabled'    => true, // create or update
        'attributes'       => [
            'FIRSTNAME' => $first_name,
            'LASTNAME'  => $last_name,
            'STATUS'    => $account_status,
        ],
    ];
    if ( $list_id > 0 ) {
        $payload['listIds'] = [ $list_id ];
    }

    wp_remote_post( 'https://api.brevo.com/v3/contacts', [
        'timeout' => 10,
        'headers' => [
            'api-key'      => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body' => wp_json_encode( $payload ),
    ] );
}

/**
 * AJAX: bulk-sync all existing bl_members to Brevo.
 * Triggered from the Email Settings page via "Sync Now" button.
 * Runs in batches to avoid timeouts — call repeatedly with ?offset=N.
 */
add_action( 'wp_ajax_blusiast_brevo_bulk_sync', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    $api_key = get_option( 'bl_brevo_api_key', '' );
    if ( ! $api_key ) {
        wp_send_json_error( [ 'message' => 'No Brevo API key configured. Save your API key first.' ] );
    }

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $offset = absint( $_POST['offset'] ?? 0 );
    $batch  = 50;

    $members = $wpdb->get_results( $wpdb->prepare(
        "SELECT email, first_name, last_name, account_status FROM $mtable
         WHERE account_status != 'banned' ORDER BY id ASC LIMIT %d OFFSET %d",
        $batch, $offset
    ) );

    if ( empty( $members ) ) {
        wp_send_json_success( [ 'done' => true, 'message' => 'All members synced to Brevo.' ] );
    }

    $synced = 0;
    foreach ( $members as $m ) {
        blusiast_brevo_sync_contact( $m->email, $m->first_name, $m->last_name, $m->account_status );
        $synced++;
    }

    wp_send_json_success( [
        'done'        => false,
        'synced'      => $synced,
        'next_offset' => $offset + $batch,
        'message'     => "Synced {$synced} members (offset {$offset})…",
    ] );
} );

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
    // Add state column to members table for region filtering
    $mem_cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}bl_members", 0 );
    if ( ! in_array( 'member_number', $mem_cols ) ) {
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}bl_members ADD COLUMN member_number VARCHAR(20) NOT NULL DEFAULT '' AFTER joined_at" );
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}bl_members ADD UNIQUE KEY member_number (member_number)" );
    }
    if ( $mem_cols && ! in_array( 'state', $mem_cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}bl_members ADD COLUMN state VARCHAR(2) NOT NULL DEFAULT '' AFTER zip" );
        $wpdb->query( "CREATE INDEX IF NOT EXISTS idx_bl_members_state ON {$wpdb->prefix}bl_members (state)" );
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
        member_number  VARCHAR(20)     NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY email (email),
        UNIQUE KEY member_number (member_number)
    ) $charset;";
    dbDelta( $msql );

    // Contact submissions table
    $ctable = $wpdb->prefix . 'bl_contact_submissions';
    $csql = "CREATE TABLE IF NOT EXISTS $ctable (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(200)    NOT NULL DEFAULT '',
        email      VARCHAR(200)    NOT NULL DEFAULT '',
        subject    VARCHAR(200)    NOT NULL DEFAULT '',
        message    TEXT,
        status     VARCHAR(20)     NOT NULL DEFAULT 'new',
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY email (email)
    ) $charset;";
    dbDelta( $csql );

    // Add status column for existing installs
    $ccols = $wpdb->get_col( "DESCRIBE $ctable", 0 );
    if ( $ccols && ! in_array( 'status', $ccols, true ) ) {
        $wpdb->query( "ALTER TABLE $ctable ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new' AFTER message" );
    }

    update_option( 'blusiast_db_version', '1.5' );
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
        'created_at'  => blusiast_eastern_now(),
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
            'member_number'  => blusiast_generate_member_number(),
            'joined_at'      => blusiast_eastern_now(),
        ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );
    } else {
        // Update contact info in case it changed
        $wpdb->update( $mtable,
            [ 'first_name' => $first_name, 'last_name' => $last_name, 'phone' => $phone, 'zip' => $zip ],
            [ 'email' => $email ],
            [ '%s', '%s', '%s', '%s' ], [ '%s' ]
        );
    }

    // Sync to Brevo
    blusiast_brevo_sync_contact( $email, $first_name, $last_name, 'free' );

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

    // Sync updated status to Brevo
    $member = $wpdb->get_row( $wpdb->prepare( "SELECT email, first_name, last_name FROM $mtable WHERE id = %d", $id ) );
    if ( $member ) {
        blusiast_brevo_sync_contact( $member->email, $member->first_name, $member->last_name, $status );
    }

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

    $debug = []; // collects debug info returned to the browser

    $subject = sanitize_text_field( $_POST['subject'] ?? '' );
    $body    = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

    $debug['subject_received']       = $subject;
    $debug['body_length_after_kses'] = strlen( $body );
    $debug['body_empty']             = empty( $body );

    if ( ! $subject || ! $body ) {
        wp_send_json_error( [ 'message' => 'Subject and message are both required.', 'debug' => $debug ] );
    }

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';

    // JS sends the exact visible (filtered) emails — use as definitive recipient list
    $filtered_emails = isset( $_POST['filtered_emails'] ) ? (array) $_POST['filtered_emails'] : [];
    $filtered_emails = array_filter( array_map( 'sanitize_email', $filtered_emails ) );

    $debug['filtered_emails_received'] = array_values( $filtered_emails );
    $debug['filtered_emails_count']    = count( $filtered_emails );

    if ( ! empty( $filtered_emails ) ) {
        $placeholders = implode( ',', array_fill( 0, count($filtered_emails), '%s' ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT first_name, email FROM $mtable WHERE email IN ($placeholders) AND account_status != 'banned'", ...$filtered_emails )
        );
        $debug['query_path']   = 'filtered_emails IN()';
        $debug['db_error']     = $wpdb->last_error ?: null;
        $debug['last_query']   = $wpdb->last_query;
    } else {
        // Fallback: status-only server-side filter
        $filter_status = sanitize_text_field( $_POST['filter_status'] ?? '' );
        $where  = [ "account_status != 'banned'" ];
        $params = [];
        if ( $filter_status && in_array( $filter_status, ['free','active','lapsed'], true ) ) {
            $where[]  = 'account_status = %s';
            $params[] = $filter_status;
        }
        $sql  = "SELECT first_name, email FROM $mtable WHERE " . implode( ' AND ', $where );
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
        $debug['query_path'] = 'fallback_status_filter';
        $debug['db_error']   = $wpdb->last_error ?: null;
        $debug['last_query'] = $wpdb->last_query;
    }

    $debug['rows_found'] = count( (array) $rows );

    if ( empty( $rows ) ) {
        wp_send_json_error( [ 'message' => 'No members match the current filters.', 'debug' => $debug ] );
    }

    $from    = 'Blusiast <' . get_option( 'bl_email_from_address', get_option('admin_email') ) . '>';
    $headers = [ 'Content-Type: text/html; charset=UTF-8', 'From: ' . $from ];
    $debug['from_address'] = $from;

    $sent = 0; $failed = 0;
    $mail_errors = [];

    foreach ( $rows as $row ) {
        $personalised_body = str_replace( '{name}', esc_html( $row->first_name ), $body );
        $msg = blusiast_build_email_template( $subject, $personalised_body );
        $result = wp_mail( $row->email, $subject, $msg, $headers );
        if ( $result ) {
            $sent++;
        } else {
            $failed++;
            global $phpmailer;
            $mail_errors[] = [
                'to'    => $row->email,
                'error' => isset( $phpmailer ) ? $phpmailer->ErrorInfo : 'unknown',
            ];
        }
    }

    $debug['sent']        = $sent;
    $debug['failed']      = $failed;
    $debug['mail_errors'] = $mail_errors;

    wp_send_json_success( [ 'sent' => $sent, 'failed' => $failed, 'total' => count($rows), 'debug' => $debug ] );
}
add_action( 'wp_ajax_blusiast_send_member_blast', 'blusiast_send_member_blast' );

/**
 * Wraps an HTML body in the Blusiast branded email shell.
 * Used by member blast so all emails share one consistent look.
 *
 * @param string $subject  Email subject (shown in title tag).
 * @param string $body     HTML body content (already personalised).
 * @return string          Full HTML email string.
 */
function blusiast_build_email_template( $subject, $body ) {
    $site_url  = home_url();
    $site_name = get_bloginfo( 'name' );
    $year      = date( 'Y' );

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?php echo esc_html( $subject ); ?></title>
<style>
  body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
  body{margin:0;padding:0;background-color:#111111;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;}
  table{border-collapse:collapse;}
  img{border:0;line-height:100%;outline:none;text-decoration:none;}
  a{color:#CC0000;}
  .wrapper{width:100%;background-color:#111111;}
  .container{max-width:620px;margin:0 auto;}
  .header{background-color:#0D0D0D;border-bottom:3px solid #CC0000;padding:28px 40px;text-align:center;}
  .header-wordmark{font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:28px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#FFFFFF;}
  .header-tagline{font-size:11px;color:#888888;letter-spacing:.2em;text-transform:uppercase;margin-top:4px;}
  .body-wrap{background-color:#1A1A1A;padding:36px 40px;}
  .body-wrap p{margin:0 0 16px;font-size:15px;line-height:1.7;color:#DDDDDD;}
  .body-wrap h1,.body-wrap h2,.body-wrap h3{color:#FFFFFF;margin:24px 0 12px;}
  .body-wrap h1{font-size:22px;}
  .body-wrap h2{font-size:18px;}
  .body-wrap h3{font-size:15px;}
  .body-wrap ul,.body-wrap ol{color:#DDDDDD;padding-left:20px;margin:0 0 16px;}
  .body-wrap li{font-size:15px;line-height:1.7;margin-bottom:6px;}
  .body-wrap a{color:#CC0000;text-decoration:underline;}
  .body-wrap strong{color:#FFFFFF;}
  .body-wrap blockquote{border-left:3px solid #CC0000;margin:20px 0;padding:12px 20px;background:#0D0D0D;color:#BBBBBB;font-style:italic;}
  .cta-wrap{text-align:center;padding:8px 40px 28px;}
  .cta-btn{display:inline-block;background-color:#CC0000;color:#FFFFFF !important;font-size:14px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;text-decoration:none !important;padding:13px 32px;border-radius:4px;}
  .footer{background-color:#0D0D0D;border-top:1px solid #2A2A2A;padding:24px 40px;text-align:center;}
  .footer p{font-size:11px;color:#555555;margin:0 0 6px;line-height:1.6;}
  .footer a{color:#CC0000;text-decoration:none;}
  @media only screen and (max-width:640px){
    .header,.body-wrap,.cta-wrap,.footer{padding-left:24px !important;padding-right:24px !important;}
  }
</style>
</head>
<body>
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td align="center">
<table class="container" width="620" cellpadding="0" cellspacing="0" role="presentation">
  <tr><td class="header">
    <div class="header-wordmark"><?php echo esc_html( $site_name ); ?></div>
    <div class="header-tagline">Black Enthusiasts</div>
  </td></tr>
  <tr><td class="body-wrap">
    <?php echo $body; ?>
  </td></tr>
  <tr><td class="cta-wrap">
    <a href="<?php echo esc_url( $site_url ); ?>" class="cta-btn">Visit Blusiast</a>
  </td></tr>
  <tr><td class="footer">
    <p><a href="<?php echo esc_url( $site_url ); ?>"><?php echo esc_html( $site_name ); ?></a> &mdash; Black Enthusiasts</p>
    <p>You received this email because you are a member of Blusiast.<br>
    Questions? <a href="mailto:<?php echo antispambot( get_option('admin_email') ); ?>">Contact us</a></p>
    <p>&copy; <?php echo $year; ?> Blusiast. All rights reserved.</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
    <?php
    return ob_get_clean();
}

// ── AJAX: resolve zip→state and save to bl_members ──
add_action( 'wp_ajax_blusiast_resolve_states', function() {
    if ( ! current_user_can('manage_options') ) wp_die(-1);
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $zip    = sanitize_text_field( $_POST['zip']   ?? '' );
    $state  = strtoupper( sanitize_text_field( $_POST['state'] ?? '' ) );
    $id     = absint( $_POST['member_id'] ?? 0 );

    if ( ! $id || ! $state || strlen($state) !== 2 ) {
        wp_send_json_error();
    }
    $wpdb->update( $mtable, [ 'state' => $state ], [ 'id' => $id ], ['%s'], ['%d'] );
    wp_send_json_success();
} );

// ── AJAX: get recipient count for current filters (live preview) ──
add_action( 'wp_ajax_blusiast_blast_count', function() {
    if ( ! current_user_can('manage_options') ) wp_die(-1);
    check_ajax_referer( 'blusiast_admin_nonce', 'nonce' );

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $rtable = $wpdb->prefix . 'bl_event_registrations';

    $filter_status = sanitize_text_field( $_POST['filter_status'] ?? '' );
    $filter_state  = strtoupper( sanitize_text_field( $_POST['filter_state'] ?? '' ) );
    $filter_region = sanitize_text_field( $_POST['filter_region'] ?? '' );
    $filter_events = sanitize_text_field( $_POST['filter_events'] ?? '' );
    $filter_joined = sanitize_text_field( $_POST['filter_joined'] ?? '' );

    $where = [ "account_status != 'banned'" ]; $params = [];

    if ( $filter_status && $filter_status !== 'all' ) { $where[] = 'account_status = %s'; $params[] = $filter_status; }

    if ( $filter_state && strlen($filter_state) === 2 ) { $where[] = 'state = %s'; $params[] = $filter_state; }

    $regions = [
        'south'     => ['TX','FL','GA','NC','SC','VA','AL','MS','TN','KY','AR','LA','OK','WV','MD','DE'],
        'northeast' => ['NY','PA','NJ','MA','CT','RI','VT','NH','ME'],
        'midwest'   => ['IL','OH','MI','IN','WI','MN','IA','MO','ND','SD','NE','KS'],
        'west'      => ['CA','WA','OR','NV','AZ','CO','UT','ID','MT','WY','NM','AK','HI'],
        'southeast' => ['FL','GA','NC','SC','VA','AL','MS','TN'],
    ];
    if ( $filter_region && isset($regions[$filter_region]) && ! $filter_state ) {
        $ph = implode(',', array_fill(0, count($regions[$filter_region]), '%s'));
        $where[] = "state IN ($ph)"; $params = array_merge($params, $regions[$filter_region]);
    }
    if ( $filter_events === '0' ) { $where[] = "email NOT IN (SELECT DISTINCT email FROM $rtable)"; }
    elseif ( $filter_events === '1plus' ) { $where[] = "email IN (SELECT email FROM $rtable GROUP BY email HAVING COUNT(DISTINCT event_id) >= 1)"; }
    elseif ( $filter_events === '5plus' ) { $where[] = "email IN (SELECT email FROM $rtable GROUP BY email HAVING COUNT(DISTINCT event_id) >= 5)"; }
    if ( $filter_joined && $filter_joined !== 'any' ) {
        $intervals = [ '30d'=>'30 DAY','90d'=>'90 DAY','6m'=>'6 MONTH','1y'=>'1 YEAR' ];
        if ( isset($intervals[$filter_joined]) ) $where[] = "joined_at >= DATE_SUB(NOW(), INTERVAL {$intervals[$filter_joined]})";
    }

    $sql   = "SELECT COUNT(*) FROM $mtable WHERE " . implode(' AND ', $where);
    $count = $params ? (int)$wpdb->get_var($wpdb->prepare($sql, $params)) : (int)$wpdb->get_var($sql);
    wp_send_json_success( [ 'count' => $count ] );
} );


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
blusiast.org
Need help? Contact us: blusiast.org/contact";

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
    add_menu_page( 'Blusiast CRM', 'Blusiast CRM', 'manage_options', 'blusiast-cms',
        'blusiast_cms_dashboard', 'dashicons-groups', 3 );
    add_submenu_page( 'blusiast-cms', 'Event Registrations', 'Registrations', 'manage_options',
        'blusiast-registrations', 'blusiast_registrations_page' );
    add_submenu_page( 'blusiast-cms', 'All Members', 'All Members', 'manage_options',
        'blusiast-all-members', 'blusiast_all_members_page' );
    add_submenu_page( 'blusiast-cms', 'Member Spotlights', 'Spotlights', 'manage_options',
        'blusiast-members', 'blusiast_members_page' );
    add_submenu_page( 'blusiast-cms', 'Email Settings', 'Email Settings', 'manage_options',
        'blusiast-email-settings', 'blusiast_email_settings_page' );
    add_submenu_page( 'blusiast-cms', 'Contact Submissions', 'Contact', 'manage_options',
        'blusiast-contact', 'blusiast_contact_submissions_page' );
    add_submenu_page( 'blusiast-cms', 'Shop Settings', 'Shop Settings', 'manage_options',
        'blusiast-shop-settings', 'blusiast_shop_settings_page' );
}
add_action( 'admin_menu', 'blusiast_admin_menu' );


// ─────────────────────────────────────────
// 5. ADMIN ASSETS
// ─────────────────────────────────────────

function blusiast_admin_enqueue( $hook ) {
    // Match any Blusiast CRM page — hook names vary by WP version so we use strpos
    $is_bl = ( strpos( $hook, 'blusiast' ) !== false || $hook === 'toplevel_page_blusiast-cms' );
    if ( ! $is_bl ) return;

    // Dark body class
    add_filter( 'admin_body_class', function( $classes ) { return $classes . ' blusiast-crm-page
/* ── MEMBER PROFILE PAGE ── */
.bl-profile-grid{display:grid;grid-template-columns:380px 1fr;gap:24px;align-items:flex-start;}
.bl-profile-left{display:flex;flex-direction:column;gap:16px;position:sticky;top:32px;}
.bl-profile-right{display:flex;flex-direction:column;gap:0;}
.bl-profile-card{background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:24px;}
.bl-profile-card--email{border-top:2px solid var(--bl-red);}
.bl-profile-card__title{font-family:var(--bl-fd);font-size:15px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--bl-white);margin-bottom:16px;}
.bl-profile-card__desc{font-size:12px;color:var(--bl-g1);margin-bottom:0;line-height:1.6;}
.bl-profile-card__avatar-row{display:flex;align-items:center;gap:16px;margin-bottom:20px;}
.bl-profile-avatar-img{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--bl-s3);flex-shrink:0;}
.bl-profile-name{font-family:var(--bl-fd);font-size:24px;font-weight:900;text-transform:uppercase;color:var(--bl-white);line-height:1;}
.bl-profile-handle{font-size:13px;color:var(--bl-red);margin-top:3px;font-weight:600;}
.bl-profile-bio{font-size:13px;color:var(--bl-g2);line-height:1.65;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--bl-s3);}
.bl-profile-meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.bl-profile-meta-item{display:flex;flex-direction:column;gap:3px;}
.bl-profile-meta-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1);}
.bl-profile-meta-value{font-size:14px;color:var(--bl-white);}
/* Contact info list */
.bl-contact-info-list{display:flex;flex-direction:column;gap:0;margin-bottom:18px;}
.bl-contact-info-row{display:flex;align-items:flex-start;gap:14px;padding:13px 0;border-bottom:1px solid var(--bl-s3);}
.bl-contact-info-row:last-child{border-bottom:none;}
.bl-contact-info-icon{font-size:18px;width:28px;text-align:center;flex-shrink:0;margin-top:1px;}
.bl-contact-info-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1);margin-bottom:3px;}
.bl-contact-info-value{font-size:14px;color:var(--bl-white);font-weight:500;text-decoration:none;display:block;transition:color .15s;}
a.bl-contact-info-value:hover{color:var(--bl-red);}
.bl-contact-actions{display:flex;gap:8px;flex-wrap:wrap;}
'; } );

    // Register + enqueue a real stylesheet handle so wp_add_inline_style works
    wp_register_style( 'blusiast-admin-fonts',
        'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600&display=swap',
        [], null );
    wp_enqueue_style( 'blusiast-admin-fonts' );

    // Attach all CRM CSS as inline on that handle
    wp_add_inline_style( 'blusiast-admin-fonts', blusiast_admin_css() );

    wp_enqueue_script( 'jquery' );
    wp_add_inline_script( 'jquery', blusiast_admin_js() );

    wp_localize_script( 'jquery', 'bluAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'blusiast_admin_nonce' ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'blusiast_admin_enqueue' );

// Fallback: output CSS directly in <head> via admin_head for maximum reliability
// This catches any pages where the enqueue hook fires but inline styles miss
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    $id = $screen->id . ' ' . $screen->base . ' ' . $screen->post_type;
    if ( strpos( $id, 'blusiast' ) === false ) return;
    echo '<style id="blusiast-crm-css">' . blusiast_admin_css() . '</style>';
} );

function blusiast_admin_css() { return '
:root{--bl-red:#CC0000;--bl-red-d:#8C0000;--bl-black:#0a0a0a;--bl-s1:#111111;--bl-s2:#1a1a1a;--bl-s3:#242424;--bl-s4:#2e2e2e;--bl-g1:#888;--bl-g2:#aaa;--bl-white:#fff;--bl-fd:"Barlow Condensed",sans-serif;--bl-fb:"Barlow",sans-serif;--bl-r:8px;}

/* ── FULL ADMIN PAGE DARK OVERRIDE ── */
#wpcontent,#wpbody-content,#wpbody,.wrap{background:var(--bl-black) !important;}
#wpcontent{padding-left:0 !important;}

/* Kill default WP white page chrome */
.wp-header-end{display:none !important;}
.notice{background:var(--bl-s2) !important;border-color:var(--bl-s4) !important;color:var(--bl-g2) !important;}
.notice-success{border-left-color:var(--bl-red) !important;}

/* WP native text */
.wrap h1,.wrap h2,.wp-heading-inline{color:var(--bl-white) !important;font-family:var(--bl-fd),sans-serif !important;text-shadow:none !important;}

/* ── MAIN WRAP ── */
.bl-crm-wrap{font-family:var(--bl-fb);color:var(--bl-white);padding:24px 28px;max-width:1400px;background:var(--bl-black);}
.bl-crm-header{display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:20px;border-bottom:2px solid var(--bl-s3);}
.bl-crm-logo{font-family:var(--bl-fd);font-size:34px;font-weight:900;text-transform:uppercase;color:var(--bl-white);letter-spacing:-.01em;line-height:1;}
.bl-crm-logo span{color:var(--bl-red);}
.bl-crm-subtitle{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--bl-g1);margin-top:3px;font-weight:600;}
/* tabs */
.bl-crm-tabs{display:flex;gap:4px;margin-bottom:24px;}
.bl-crm-tab{display:inline-block;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;color:var(--bl-g1);background:transparent;transition:color .15s,background .15s;}
.bl-crm-tab:hover{color:var(--bl-white);background:var(--bl-s2);}
.bl-crm-tab--active{background:var(--bl-red);color:var(--bl-white)!important;}
/* stat row */
.bl-stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px;}
.bl-stat-card{background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:20px 22px;border-top:2px solid var(--bl-s4);}
.bl-stat-label{font-size:10px;color:var(--bl-g1);text-transform:uppercase;letter-spacing:.12em;margin-bottom:10px;font-weight:600;}
.bl-stat-num{font-family:var(--bl-fd);font-size:40px;font-weight:900;color:var(--bl-white);line-height:1;letter-spacing:-.02em;}
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
.bl-search-input,.bl-filter-select{background:var(--bl-s2);border:1px solid var(--bl-s3);border-radius:6px;color:var(--bl-white);padding:7px 11px;font-size:13px;font-family:var(--bl-fb);transition:border-color .15s;}
.bl-search-input::placeholder{color:var(--bl-g1);}
.bl-search-input:focus,.bl-filter-select:focus{outline:none;border-color:var(--bl-red);background:var(--bl-s3);}
.bl-filter-select option{background:var(--bl-s2);color:var(--bl-white);}
.bl-btn-sm{background:var(--bl-s3);border:1px solid var(--bl-s4);color:var(--bl-g2);border-radius:6px;padding:7px 14px;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:background .15s,color .15s,border-color .15s;font-family:var(--bl-fb);}
.bl-btn-sm:hover{background:var(--bl-s4);color:var(--bl-white);border-color:var(--bl-g1);}
.bl-btn-secondary{background:transparent;border-color:var(--bl-s4);color:var(--bl-g2);}
.bl-btn-secondary:hover{background:var(--bl-s2);color:var(--bl-white);}
.bl-btn-danger{background:rgba(204,0,0,.15);border:1px solid rgba(204,0,0,.3);color:#ff6666;}
.bl-btn-danger:hover{background:rgba(204,0,0,.3);color:#fff;}
table.bl-table{width:100%;border-collapse:collapse;}
table.bl-table th{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--bl-g1);padding:11px 18px;background:var(--bl-s2);border-bottom:1px solid var(--bl-s3);}
table.bl-table td{padding:12px 18px;border-bottom:1px solid var(--bl-s3);font-size:13px;color:var(--bl-g2);vertical-align:middle;}
table.bl-table tr:last-child td{border-bottom:none;}
table.bl-table tr:hover td{background:rgba(255,255,255,.02);}
.bl-td-name{font-weight:600;color:var(--bl-white);font-size:14px;}
a:hover .bl-td-name{color:var(--bl-red);}
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
/* account status legend (shown above members table) */
.bl-acct-legend{background:var(--bl-s2);border:1px solid var(--bl-s3);border-left:3px solid var(--bl-s4);border-radius:var(--bl-r);padding:12px 16px;margin-bottom:20px;font-size:12px;color:var(--bl-g1);line-height:1.8;}
.bl-acct-legend strong{color:var(--bl-g2);}
/* Photos table */
.bl-photo-thumb{width:52px;height:52px;object-fit:cover;border-radius:6px;border:1px solid var(--bl-s3);}
/* Help empty */
.bl-help-empty{padding:48px 24px;text-align:center;color:var(--bl-g1);font-size:14px;}
.bl-help-empty strong{display:block;font-family:var(--bl-fd);font-size:22px;color:var(--bl-g2);text-transform:uppercase;margin-bottom:6px;}
/* Review rating badge */
.bl-review-rating{font-family:var(--bl-fd);font-size:22px;font-weight:900;color:var(--bl-red);line-height:1;}
.bl-review-rating small{font-size:12px;color:var(--bl-g1);font-weight:400;}
/* Sub-tabs (All Reviews / By Coaster) */
.bl-sub-tabs{display:flex;gap:8px;margin-bottom:20px;}
.bl-sub-tab{display:inline-block;padding:7px 16px;border-radius:6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;text-decoration:none;color:var(--bl-g1);background:var(--bl-s2);border:1px solid var(--bl-s3);transition:color .15s,background .15s,border-color .15s;}
.bl-sub-tab:hover{color:var(--bl-white);border-color:var(--bl-s4);}
.bl-sub-tab--active{background:var(--bl-red);color:var(--bl-white) !important;border-color:var(--bl-red);}
/* Member number badge */
.bl-member-number-badge{display:inline-block;background:var(--bl-red);color:var(--bl-white);font-family:var(--bl-fd);font-size:12px;font-weight:800;letter-spacing:.08em;padding:3px 8px;border-radius:4px;white-space:nowrap;}
/* Toolbar search area layout — defined in initial bl-table-toolbar block above */
/* Form label upgrade */
.bl-form-label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g2);margin-bottom:7px;}
/* Email settings section card */
.bl-settings-card{background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);margin-bottom:20px;overflow:hidden;}
.bl-settings-card__head{padding:14px 20px;border-bottom:1px solid var(--bl-s3);background:var(--bl-s2);}
.bl-settings-card__head h2{font-family:var(--bl-fd);font-size:18px;font-weight:800;text-transform:uppercase;color:var(--bl-white);margin:0;letter-spacing:.04em;}
.bl-settings-card__body{padding:20px;}
.bl-smtp-hint{font-size:11px;color:var(--bl-g1);margin-top:14px;padding-top:12px;border-top:1px solid var(--bl-s3);line-height:1.7;}
.bl-smtp-hint code{background:var(--bl-s3);padding:2px 5px;border-radius:3px;font-size:11px;color:#f5a623;}
/* member account status */
.bl-acct--free{background:#1a1a1a;color:#888;}
.bl-acct--active{background:#0a2a0a;color:#5cb85c;}
.bl-acct--lapsed{background:#2a1f00;color:#f5a623;}
.bl-acct--banned{background:#2a0000;color:#ff5555;}
.bl-member-stat{text-align:center;padding:0 28px;border-right:1px solid var(--bl-s3);}
.bl-member-stat:last-child{border-right:none;}
.bl-member-stat-num{font-family:var(--bl-fd);font-size:36px;font-weight:900;line-height:1;letter-spacing:-.02em;}
.bl-member-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--bl-g1);margin-top:6px;font-weight:600;}
.bl-member-stats-strip{display:flex;background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:24px 0;margin-bottom:24px;}
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
  var blastEditorInited = false;
  $(document).on('click','#bl-member-blast-toggle',function(){
    var panel=$('#bl-member-blast-panel');
    if(panel.is(':visible')){panel.slideUp(200);}
    else{
      panel.slideDown(200,$('html,body').animate({scrollTop:panel.offset().top-60},300));
      // Init TinyMCE the first time the panel opens (can't init inside display:none)
      if(!blastEditorInited && typeof tinymce!=='undefined'){
        blastEditorInited = true;
        tinymce.init({
          selector:'#bl_member_blast_body',
          menubar:false,
          statusbar:false,
          resize:true,
          plugins:'link lists',
          toolbar:'bold italic underline | bullist numlist | link | alignleft aligncenter alignright | removeformat',
          skin:'oxide-dark',
          content_css:'dark',
          height:280
        });
      }
    }
  });
  $(document).on('click','#bl-member-blast-close',function(){
    $('#bl-member-blast-panel').slideUp(200);
  });
  $(document).on('click','#bl-member-blast-send',function(){
    var btn=$(this);
    var subject=$('#bl-member-blast-subject').val().trim();
    // Get content — TinyMCE if active, raw textarea as fallback
    var body='';
    var ed = (typeof tinymce!=='undefined') ? tinymce.get('bl_member_blast_body') : null;
    if(ed){
      ed.save(); // sync editor content back to the textarea
      body = ed.getContent().trim();
    } else {
      body = $('#bl_member_blast_body').val().trim();
    }
    var result=$('#bl-member-blast-result');
    if(!subject||!body){result.removeClass('bl-blast-result--success').addClass('bl-blast-result--error bl-blast-result').text('Please fill in both subject and message.').show();return;}
    btn.prop('disabled',true);
    btn.find('.bl-blast-send-label').hide();
    btn.find('.bl-blast-send-spinner').show();
    result.hide();
    // Collect currently-visible member emails from the reliable data-email attribute
    var visibleEmails = [];
    $('#bl-members-table tbody tr:visible').each(function(){
      var email = $(this).data('email');
      if (email) visibleEmails.push(email);
    });

    $.post(bluAdmin.ajaxUrl,{
      action:'blusiast_send_member_blast',
      nonce:bluAdmin.nonce,
      subject:subject,
      body:body,
      filter_status:$('#bl-member-status-filter').val(),
      filter_state:$('#bl-member-state-filter').val(),
      filtered_emails:visibleEmails
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
        var ed2 = (typeof tinymce!=='undefined') ? tinymce.get('bl_member_blast_body') : null;
        if(ed2){ ed2.setContent(''); } else { $('#bl_member_blast_body').val(''); }
      } else {
        var errMsg = (res.data && res.data.message) ? res.data.message : 'Something went wrong.';
        // Show debug info in console for diagnosis
        if(res.data && res.data.debug) console.log('Blast debug:', res.data.debug);
        result.removeClass('bl-blast-result--success').addClass('bl-blast-result--error bl-blast-result').text(errMsg).show();
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
  // Brevo bulk sync
  $(document).on('click','#bl-brevo-sync-btn',function(){
    var btn=$(this);
    var result=$('#bl-brevo-sync-result');
    btn.prop('disabled',true);
    $('#bl-brevo-sync-label').hide();
    $('#bl-brevo-sync-spinner').show();
    result.hide();

    function syncBatch(offset){
      $.post(bluAdmin.ajaxUrl,{
        action:'blusiast_brevo_bulk_sync',
        nonce:bluAdmin.nonce,
        offset:offset
      },function(res){
        if(!res.success){
          result.css('color','#ff5555').text(res.data&&res.data.message?res.data.message:'Sync failed.').show();
          btn.prop('disabled',false);
          $('#bl-brevo-sync-label').show();
          $('#bl-brevo-sync-spinner').hide();
          return;
        }
        if(res.data.done){
          result.css('color','#5cb85c').text('✓ '+res.data.message).show();
          btn.prop('disabled',false);
          $('#bl-brevo-sync-label').show();
          $('#bl-brevo-sync-spinner').hide();
        } else {
          result.css('color','#f5a623').text(res.data.message).show();
          syncBatch(res.data.next_offset);
        }
      });
    }
    syncBatch(0);
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
        'blusiast-contact'       => 'Contact',
        'blusiast-help'          => 'Help',
        'blusiast-shop-settings' => 'Shop Settings',
    ];
    echo '<div class="bl-crm-tabs">';
    foreach ( $tabs as $page => $label ) {
        $cls = ( $page === $active ) ? ' bl-crm-tab--active' : '';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '" class="bl-crm-tab' . $cls . '">' . esc_html( $label ) . '</a>';
    }
    echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=bl_event' ) ) . '" class="bl-crm-tab">Events</a>';
    echo '</div>';
}

function blusiast_admin_header( $subtitle ) {
    echo '<div class="bl-crm-header"><div>';
    echo '<div class="bl-crm-logo">Blus<span>iast</span> CRM</div>';
    echo '<div class="bl-crm-subtitle">' . esc_html( $subtitle ) . '</div>';
    echo '</div></div>';
}


// ─────────────────────────────────────────
// 7. DASHBOARD — events as clickable cards
// ─────────────────────────────────────────

function blusiast_cms_dashboard() {
    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';

    // Summary stats — use SUM(guest_count) so multi-ticket orders count correctly
    $total_regs = (int) $wpdb->get_var( "SELECT SUM(guest_count) FROM $table" );
    $confirmed  = (int) $wpdb->get_var( "SELECT SUM(guest_count) FROM $table WHERE status='confirmed'" );
    $pending    = (int) $wpdb->get_var( "SELECT SUM(guest_count) FROM $table WHERE status='pending'" );

    // Per-event counts — sum guest_count per event
    $event_counts = $wpdb->get_results(
        "SELECT event_id, SUM(guest_count) as total,
                SUM(CASE WHEN status='confirmed' THEN guest_count ELSE 0 END) as confirmed,
                SUM(CASE WHEN status='pending'   THEN guest_count ELSE 0 END) as pending
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
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Dashboard' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-cms' ); ?>

        <div class="bl-stat-row">
            <div class="bl-stat-card"><div class="bl-stat-label">Total Registrations</div><div class="bl-stat-num bl-stat-num--red"><?php echo $total_regs; ?></div></div>
            <div class="bl-stat-card"><div class="bl-stat-label">Confirmed</div><div class="bl-stat-num"><?php echo $confirmed; ?></div></div>
            <div class="bl-stat-card"><div class="bl-stat-label">Pending</div><div class="bl-stat-num"><?php echo $pending; ?></div></div>
            <div class="bl-stat-card"><div class="bl-stat-label">Active Events</div><div class="bl-stat-num"><?php echo count( $events ); ?></div></div>
        </div>

        <p class="bl-crm-subtitle" style="margin-bottom:16px;">Click an event to view its registrations</p>

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

    $events     = get_posts( [ 'post_type' => 'bl_event', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
    $export_url = admin_url( 'admin.php?page=blusiast-registrations&bl_export=1' . ( $filter_event_id ? '&event_id=' . $filter_event_id : '' ) );
    $ajax_url   = admin_url( 'admin-ajax.php' );
    $nonce      = wp_create_nonce( 'blusiast_reg_nonce' );
    ?>
    <div class="bl-crm-wrap">
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
                            <input type="text" id="bl-blast-subject" class="bl-blast-input" placeholder="e.g. Important update about <?php echo esc_attr( $event_title ); ?>">
                        </div>
                        <div class="bl-blast-row">
                            <label class="bl-blast-label" for="bl-blast-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <span>Message <span class="bl-blast-hint">Use <code>{name}</code> to personalise with first name.</span></span>
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--bl-g2);">
                                    <input type="checkbox" id="bl-blast-html-mode" style="accent-color:#CC0000;width:14px;height:14px;">
                                    HTML Mode
                                </label>
                            </label>
                            <textarea id="bl-blast-body" class="bl-blast-input bl-blast-textarea" rows="8" placeholder="Hey {name}, just wanted to let you know..."></textarea>
                            <p id="bl-blast-html-hint" style="display:none;margin:6px 0 0;font-size:11px;color:#888;line-height:1.5;">&#128274; HTML mode active — paste your full HTML email here. It will be sent exactly as written. <code style="background:#222;padding:1px 5px;border-radius:3px;color:#f5a623;">{name}</code> still works for personalisation.</p>
                        </div>
                        <div id="bl-blast-result" class="bl-blast-result" style="display:none;"></div>
                        <div class="bl-blast-actions">
                            <button type="button" id="bl-blast-send" class="bl-blast-send-btn" data-event-id="<?php echo esc_attr( $filter_event_id ); ?>">
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

            <!-- ── ACCORDION REGISTRATION LIST ── -->
            <div class="bl-reg-accordion" id="bl-reg-accordion">
                <!-- Header row -->
                <div class="bl-reg-accordion__header">
                    <span style="width:30px;">#</span>
                    <span style="flex:2;">Name</span>
                    <span style="flex:3;">Email</span>
                    <?php if ( ! $filter_event_id ) : ?>
                    <span style="flex:2;">Event</span>
                    <?php endif; ?>
                    <span style="width:70px;text-align:center;">Guests</span>
                    <span style="width:120px;">Status</span>
                    <span style="width:90px;">Conduct</span>
                    <span style="width:90px;">Registered</span>
                    <span style="width:80px;"></span>
                </div>

                <?php foreach ( $registrations as $reg ) :
                    $conduct_ok   = ! empty( $reg->conduct_agreed_at );
                    $checked_in   = ! empty( $reg->checked_in_at );
                    $has_stripe   = ! empty( $reg->stripe_payment_intent ) || ! empty( $reg->stripe_session_id );
                    $event_date_r = function_exists('get_field') ? get_field( 'event_date', $reg->event_id ) : '';
                    $fmt_event_date = $event_date_r ? date( 'F j, Y', strtotime( $event_date_r ) ) : '';
                ?>
                <div class="bl-reg-row" data-name="<?php echo esc_attr( strtolower( $reg->first_name . ' ' . $reg->last_name ) ); ?>" data-email="<?php echo esc_attr( strtolower( $reg->email ) ); ?>" data-status="<?php echo esc_attr( $reg->status ); ?>">

                    <!-- ── Summary row (always visible) ── -->
                    <div class="bl-reg-row__summary" onclick="blToggleRow(this)">
                        <span class="bl-reg-row__id"><?php echo (int) $reg->id; ?></span>
                        <span class="bl-reg-row__name"><?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?></span>
                        <span class="bl-reg-row__email"><?php echo esc_html( $reg->email ); ?></span>
                        <?php if ( ! $filter_event_id ) : ?>
                        <span class="bl-reg-row__event"><?php echo esc_html( $reg->event_name ); ?></span>
                        <?php endif; ?>
                        <span class="bl-reg-row__guests"><?php echo (int) $reg->guest_count; ?></span>
                        <span class="bl-reg-row__status">
                            <span class="bl-status bl-status--<?php echo esc_attr( $reg->status ); ?>">
                                <select class="bl-status-select" data-id="<?php echo (int) $reg->id; ?>" onclick="event.stopPropagation()">
                                    <option value="pending"   <?php selected( $reg->status, 'pending'   ); ?>>Pending</option>
                                    <option value="confirmed" <?php selected( $reg->status, 'confirmed' ); ?>>Confirmed</option>
                                    <option value="waitlist"  <?php selected( $reg->status, 'waitlist'  ); ?>>Waitlist</option>
                                    <option value="cancelled" <?php selected( $reg->status, 'cancelled' ); ?>>Cancelled</option>
                                </select>
                            </span>
                        </span>
                        <span class="bl-reg-row__conduct">
                            <?php if ( $conduct_ok ) : ?>
                                <span class="bl-conduct-badge bl-conduct-badge--yes" title="Agreed <?php echo esc_attr( date( 'M j, Y g:i a', strtotime( $reg->conduct_agreed_at ) ) ); ?>">✓ Agreed</span>
                            <?php else : ?>
                                <span class="bl-conduct-badge bl-conduct-badge--no">— Pending</span>
                            <?php endif; ?>
                        </span>
                        <span class="bl-reg-row__date"><?php echo esc_html( date( 'M j, Y', strtotime( $reg->created_at ) ) ); ?></span>
                        <span class="bl-reg-row__toggle">
                            <svg class="bl-reg-chevron" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 5l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </div>

                    <!-- ── Drawer (hidden by default) ── -->
                    <div class="bl-reg-row__drawer" style="display:none;">
                        <div class="bl-reg-drawer-grid">

                            <!-- Contact details -->
                            <div class="bl-reg-drawer-section">
                                <div class="bl-reg-drawer-label">Contact</div>
                                <div class="bl-reg-drawer-val"><a href="mailto:<?php echo esc_attr( $reg->email ); ?>" style="color:var(--bl-red);"><?php echo esc_html( $reg->email ); ?></a></div>
                                <?php if ( $reg->phone ) : ?>
                                <div class="bl-reg-drawer-val"><?php echo esc_html( $reg->phone ); ?></div>
                                <?php endif; ?>
                                <?php if ( $reg->zip ) : ?>
                                <div class="bl-reg-drawer-val" style="color:var(--bl-g1);">Zip: <?php echo esc_html( $reg->zip ); ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Conduct agreed -->
                            <div class="bl-reg-drawer-section">
                                <div class="bl-reg-drawer-label">Code of Conduct</div>
                                <?php if ( $conduct_ok ) : ?>
                                    <div class="bl-reg-drawer-val" style="color:#5cb85c;font-weight:700;">✓ Agreed</div>
                                    <div class="bl-reg-drawer-val" style="color:var(--bl-g1);font-size:11px;">
                                        <?php echo esc_html( date( 'M j, Y \a\t g:i a', strtotime( $reg->conduct_agreed_at ) ) ); ?>
                                    </div>
                                <?php else : ?>
                                    <div class="bl-reg-drawer-val" style="color:#f5a623;">Not yet agreed</div>
                                <?php endif; ?>
                            </div>

                            <!-- Check-in -->
                            <div class="bl-reg-drawer-section">
                                <div class="bl-reg-drawer-label">Door Check-In</div>
                                <?php if ( $checked_in ) : ?>
                                    <div class="bl-reg-drawer-val" style="color:#5cb85c;font-weight:700;">✓ Checked In</div>
                                    <div class="bl-reg-drawer-val" style="color:var(--bl-g1);font-size:11px;">
                                        <?php echo esc_html( date( 'M j, Y \a\t g:i a', strtotime( $reg->checked_in_at ) ) ); ?>
                                    </div>
                                <?php else : ?>
                                    <div class="bl-reg-drawer-val" style="color:var(--bl-g1);">Not yet arrived</div>
                                <?php endif; ?>
                            </div>

                            <!-- Payment -->
                            <?php if ( $has_stripe ) : ?>
                            <div class="bl-reg-drawer-section">
                                <div class="bl-reg-drawer-label">Payment</div>
                                <?php if ( $reg->stripe_payment_intent ) : ?>
                                    <div class="bl-reg-drawer-val" style="font-family:monospace;font-size:11px;color:var(--bl-g1);">
                                        <?php echo esc_html( $reg->stripe_payment_intent ); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ( $reg->stripe_session_id ) : ?>
                                    <div class="bl-reg-drawer-val" style="font-family:monospace;font-size:11px;color:var(--bl-g1);">
                                        Session: <?php echo esc_html( $reg->stripe_session_id ); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Notes -->
                            <div class="bl-reg-drawer-section bl-reg-drawer-section--full">
                                <div class="bl-reg-drawer-label">Notes</div>
                                <textarea class="bl-note-input" data-id="<?php echo (int) $reg->id; ?>" rows="3" placeholder="Add a note…"><?php echo esc_textarea( $reg->notes ); ?></textarea>
                            </div>

                        </div><!-- /.bl-reg-drawer-grid -->

                        <!-- Remove button -->
                        <div class="bl-reg-drawer-actions">
                            <button class="bl-btn-sm bl-btn-danger bl-remove-reg-btn"
                                    data-id="<?php echo (int) $reg->id; ?>"
                                    data-name="<?php echo esc_attr( $reg->first_name . ' ' . $reg->last_name ); ?>"
                                    data-email="<?php echo esc_attr( $reg->email ); ?>"
                                    data-event="<?php echo esc_attr( $reg->event_name ?? $event_title ); ?>">
                                Remove from Event
                            </button>
                        </div>

                    </div><!-- /.bl-reg-row__drawer -->
                </div><!-- /.bl-reg-row -->
                <?php endforeach; ?>
            </div><!-- /.bl-reg-accordion -->

            <?php else : ?>
                <div class="bl-empty"><strong>No Registrations Yet</strong>Sign-ups will appear here once visitors register.</div>
            <?php endif; ?>
        </div>


        <!-- ── REMOVE CONFIRMATION MODAL ── -->
        <div id="bl-remove-modal" style="display:none;position:fixed;inset:0;z-index:99999;align-items:center;justify-content:center;padding:16px;">
            <div id="bl-remove-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.8);cursor:pointer;"></div>
            <div style="position:relative;z-index:1;background:#1a1a1a;border:1px solid #333;border-radius:10px;padding:32px;max-width:480px;width:100%;">
                <h3 style="margin:0 0 8px;font-size:18px;color:#fff;">Remove Registrant?</h3>
                <p id="bl-remove-desc" style="color:#999;font-size:14px;margin-bottom:20px;line-height:1.6;"></p>

                <div style="background:#111;border:1px solid #222;border-radius:6px;padding:16px;margin-bottom:20px;">
                    <label style="display:block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#555;margin-bottom:8px;">
                        Reason (included in removal email)
                    </label>
                    <textarea id="bl-remove-reason" rows="3" placeholder="Optional — e.g. event cancelled, duplicate registration…"
                              style="width:100%;background:#0a0a0a;border:1px solid #2a2a2a;color:#fff;padding:10px 12px;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
                    <p style="font-size:11px;color:#444;margin:8px 0 0;">
                        A notification email will be sent to the registrant automatically.
                    </p>
                </div>

                <div id="bl-remove-error" style="display:none;color:#ff6666;font-size:13px;margin-bottom:12px;"></div>

                <div style="display:flex;gap:10px;">
                    <button id="bl-remove-confirm-btn"
                            style="background:#cc0000;color:#fff;border:none;border-radius:6px;padding:10px 20px;font-weight:700;font-size:13px;cursor:pointer;flex:1;">
                        Remove &amp; Send Email
                    </button>
                    <button id="bl-remove-cancel-btn"
                            style="background:transparent;color:#999;border:1px solid #333;border-radius:6px;padding:10px 20px;font-size:13px;cursor:pointer;">
                        Cancel
                    </button>
                </div>
            </div>
        </div>


    </div><!-- /.bl-crm-wrap -->


    <!-- ── STYLES ── -->
    <style>
    /* Accordion wrapper */
    .bl-reg-accordion { border: 1px solid var(--bl-border); border-radius: 8px; overflow: hidden; }

    /* Header row */
    .bl-reg-accordion__header {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 16px;
        background: var(--bl-surface2);
        border-bottom: 1px solid var(--bl-border);
        font-size: 11px; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; color: var(--bl-g1);
    }

    /* Each registration row */
    .bl-reg-row { border-bottom: 1px solid var(--bl-border); }
    .bl-reg-row:last-child { border-bottom: none; }

    /* Summary bar */
    .bl-reg-row__summary {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 16px;
        cursor: pointer;
        transition: background .15s;
    }
    .bl-reg-row__summary:hover { background: var(--bl-surface2); }
    .bl-reg-row--open .bl-reg-row__summary { background: var(--bl-surface2); }

    .bl-reg-row__id    { width: 30px; font-size: 11px; color: var(--bl-g1); flex-shrink: 0; }
    .bl-reg-row__name  { flex: 2; font-weight: 700; font-size: 13px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bl-reg-row__email { flex: 3; font-size: 12px; color: var(--bl-g2); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bl-reg-row__event { flex: 2; font-size: 12px; color: var(--bl-g2); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bl-reg-row__guests { width: 70px; text-align: center; font-size: 13px; flex-shrink: 0; }
    .bl-reg-row__status { width: 120px; flex-shrink: 0; }
    .bl-reg-row__conduct { width: 90px; flex-shrink: 0; }
    .bl-reg-row__date  { width: 90px; font-size: 11px; color: var(--bl-g1); flex-shrink: 0; }
    .bl-reg-row__toggle { width: 24px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }

    .bl-reg-chevron { color: var(--bl-g1); transition: transform .2s; }
    .bl-reg-row--open .bl-reg-chevron { transform: rotate(180deg); }

    /* Conduct badges */
    .bl-conduct-badge {
        display: inline-block; font-size: 11px; font-weight: 700;
        padding: 3px 8px; border-radius: 4px; letter-spacing: .04em;
    }
    .bl-conduct-badge--yes { background: rgba(92,184,92,.15); color: #5cb85c; }
    .bl-conduct-badge--no  { background: rgba(100,100,100,.15); color: #666; }

    /* Drawer */
    .bl-reg-row__drawer {
        padding: 0 16px 16px 52px;
        border-top: 1px solid var(--bl-border);
        background: var(--bl-surface2);
        animation: bl-drawer-in .18s ease;
    }
    @keyframes bl-drawer-in { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:none; } }

    .bl-reg-drawer-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 20px;
        padding: 16px 0 14px;
    }

    .bl-reg-drawer-section--full { grid-column: 1 / -1; }

    .bl-reg-drawer-label {
        font-size: 10px; font-weight: 700; letter-spacing: .12em;
        text-transform: uppercase; color: var(--bl-g1); margin-bottom: 6px;
    }
    .bl-reg-drawer-val { font-size: 13px; color: #ccc; line-height: 1.5; margin-bottom: 2px; }

    .bl-note-input {
        width: 100%; background: var(--bl-surface1); border: 1px solid var(--bl-border);
        border-radius: 6px; color: #ccc; padding: 10px 12px;
        font-size: 13px; resize: vertical; box-sizing: border-box;
        font-family: inherit; line-height: 1.5;
    }
    .bl-note-input:focus { outline: none; border-color: var(--bl-red); }

    .bl-reg-drawer-actions {
        padding-top: 12px;
        border-top: 1px solid var(--bl-border);
        display: flex; justify-content: flex-end;
    }

    /* Hide rows during search */
    .bl-reg-row.bl-hidden { display: none; }
    </style>


    <!-- ── JAVASCRIPT ── -->
    <script>
    // ── Accordion toggle ──
    function blToggleRow(summaryEl) {
        var row    = summaryEl.closest('.bl-reg-row');
        var drawer = row.querySelector('.bl-reg-row__drawer');
        var isOpen = row.classList.contains('bl-reg-row--open');
        // Close all others
        document.querySelectorAll('.bl-reg-row--open').forEach(function(r) {
            r.classList.remove('bl-reg-row--open');
            r.querySelector('.bl-reg-row__drawer').style.display = 'none';
        });
        if (!isOpen) {
            row.classList.add('bl-reg-row--open');
            drawer.style.display = 'block';
        }
    }

    // ── Search ──
    document.getElementById('bl-reg-search').addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        document.querySelectorAll('.bl-reg-row').forEach(function(row) {
            var name  = row.dataset.name  || '';
            var email = row.dataset.email || '';
            row.classList.toggle('bl-hidden', q && name.indexOf(q) === -1 && email.indexOf(q) === -1);
        });
    });

    // ── Status change ──
    document.querySelectorAll('.bl-status-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var id  = this.dataset.id;
            var val = this.value;
            var wrap = this.closest('.bl-reg-row__status').querySelector('.bl-status');
            wrap.className = 'bl-status bl-status--' + val;
            fetch(<?php echo json_encode( $ajax_url ); ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=blusiast_update_reg_status&nonce=' + <?php echo json_encode( $nonce ); ?> + '&id=' + id + '&status=' + val,
            });
        });
    });

    // ── Notes save ──
    document.querySelectorAll('.bl-note-input').forEach(function(ta) {
        var timer;
        ta.addEventListener('input', function() {
            clearTimeout(timer);
            var id  = this.dataset.id;
            var val = this.value;
            timer = setTimeout(function() {
                fetch(<?php echo json_encode( $ajax_url ); ?>, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=blusiast_update_reg_notes&nonce=' + <?php echo json_encode( $nonce ); ?> + '&id=' + id + '&notes=' + encodeURIComponent(val),
                });
            }, 800);
        });
    });

    // ── Remove with confirmation modal ──
    var removeModal    = document.getElementById('bl-remove-modal');
    var removeDesc     = document.getElementById('bl-remove-desc');
    var removeReason   = document.getElementById('bl-remove-reason');
    var removeConfirm  = document.getElementById('bl-remove-confirm-btn');
    var removeCancel   = document.getElementById('bl-remove-cancel-btn');
    var removeBackdrop = document.getElementById('bl-remove-backdrop');
    var removeError    = document.getElementById('bl-remove-error');
    var currentRemoveId = null;

    document.querySelectorAll('.bl-remove-reg-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            currentRemoveId = this.dataset.id;
            var name  = this.dataset.name;
            var email = this.dataset.email;
            var event = this.dataset.event;
            removeDesc.innerHTML = 'You are about to remove <strong>' + name + '</strong> (' + email + ') from <strong>' + event + '</strong>. A notification email will be sent to them.';
            removeReason.value   = '';
            removeError.style.display = 'none';
            removeModal.style.display = 'flex';
        });
    });

    function closeRemoveModal() {
        removeModal.style.display = 'none';
        currentRemoveId = null;
    }

    removeCancel.addEventListener('click', closeRemoveModal);
    removeBackdrop.addEventListener('click', closeRemoveModal);

    removeConfirm.addEventListener('click', async function() {
        if (!currentRemoveId) return;
        removeConfirm.textContent = 'Removing…';
        removeConfirm.disabled    = true;
        removeError.style.display = 'none';

        try {
            var resp = await fetch(<?php echo json_encode( $ajax_url ); ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=blusiast_remove_registration_email&nonce=' + <?php echo json_encode( $nonce ); ?>
                    + '&id=' + currentRemoveId
                    + '&reason=' + encodeURIComponent(removeReason.value),
            });
            var data = await resp.json();

            if (data.success) {
                // Remove the row from the DOM
                var row = document.querySelector('.bl-reg-row .bl-remove-reg-btn[data-id="' + currentRemoveId + '"]').closest('.bl-reg-row');
                row.style.transition = 'opacity .3s';
                row.style.opacity = '0';
                setTimeout(function() { row.remove(); }, 300);
                closeRemoveModal();
            } else {
                removeError.textContent   = data.data || 'Something went wrong.';
                removeError.style.display = 'block';
            }
        } catch(e) {
            removeError.textContent   = 'Network error. Please try again.';
            removeError.style.display = 'block';
        }

        removeConfirm.textContent = 'Remove & Send Email';
        removeConfirm.disabled    = false;
    });
    </script>
    <?php
}


// ══════════════════════════════════════════════════════════════
// AJAX HANDLER — Remove registration + send email
// Add this alongside the existing AJAX handlers in member-cms.php
// ══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_blusiast_remove_registration_email', 'blusiast_ajax_remove_registration_email' );

function blusiast_ajax_remove_registration_email() {
    if ( ! check_ajax_referer( 'blusiast_reg_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorised.' );
    }

    $id     = absint( $_POST['id'] ?? 0 );
    $reason = sanitize_textarea_field( $_POST['reason'] ?? '' );

    if ( ! $id ) wp_send_json_error( 'Invalid registration ID.' );

    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';

    $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", $id ) );
    if ( ! $reg ) wp_send_json_error( 'Registration not found.' );

    $event_title = get_the_title( $reg->event_id );
    $event_date  = get_post_meta( $reg->event_id, 'event_date', true );
    $fmt_date    = $event_date ? date( 'F j, Y', strtotime( $event_date ) ) : '';
    $portal_url  = function_exists( 'blusiast_portal_url' ) ? blusiast_portal_url() : home_url( '/member-portal' );

    // ── Send removal email ──
    $subject = "Your registration for {$event_title} has been removed";
    $body    = "Hey {$reg->first_name},\n\n"
             . "We're reaching out to let you know that your registration for the following event has been removed:\n\n"
             . "─────────────────────────\n"
             . strtoupper( $event_title ) . "\n"
             . ( $fmt_date ? "📅  {$fmt_date}\n" : '' )
             . "─────────────────────────\n\n"
             . ( $reason ? "Reason: {$reason}\n\n" : '' )
             . "If you believe this is a mistake or have any questions, please reach out to us directly.\n\n"
             . "You can still access your member portal here:\n"
             . "👉  {$portal_url}\n\n"
             . "Ride on,\n"
             . "The Blusiast Crew\n";

    wp_mail(
        $reg->email,
        $subject,
        $body,
        [ 'From: Blusiast <' . get_option( 'admin_email' ) . '>', 'Content-Type: text/plain; charset=UTF-8' ]
    );

    // ── Delete the registration ──
    $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

    // Notify admin
    wp_mail(
        get_option( 'admin_email' ),
        "Registration removed: {$event_title} — {$reg->first_name} {$reg->last_name}",
        "Removed: {$reg->first_name} {$reg->last_name} ({$reg->email})\n"
        . "Event: {$event_title}\n"
        . ( $reason ? "Reason: {$reason}\n" : '' )
        . "Removed by: " . wp_get_current_user()->display_name
    );

    wp_send_json_success( 'Removed.' );
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


// ─────────────────────────────────────────
// MEMBER PROFILE PAGE (admin drill-down)
// ─────────────────────────────────────────

function blusiast_member_profile_page( $member_id ) {
    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $rtable = $wpdb->prefix . 'bl_event_registrations';
    $ptable = $wpdb->prefix . 'bl_photo_submissions';

    $m = $wpdb->get_row( $wpdb->prepare(
        "SELECT m.*, COUNT(DISTINCT r.event_id) as event_count, MAX(r.created_at) as last_event_date
         FROM $mtable m LEFT JOIN $rtable r ON r.email = m.email
         WHERE m.id = %d GROUP BY m.id", $member_id
    ) );
    if ( ! $m ) { echo '<div class="bl-crm-wrap"><p style="color:#888;">Member not found.</p></div>'; return; }

    // Handle email send
    $email_sent = false; $email_error = '';
    if ( isset( $_POST['bl_send_member_email'] ) && check_admin_referer( 'bl_member_email_' . $member_id ) ) {
        $subj = sanitize_text_field( $_POST['bl_email_subject'] ?? '' );
        $body = sanitize_textarea_field( $_POST['bl_email_body'] ?? '' );
        if ( $subj && $body ) {
            $ok = wp_mail( $m->email, $subj, $body, ['Content-Type: text/plain; charset=UTF-8','From: Blusiast <'.get_option('admin_email').'>'] );
            if ( $ok ) $email_sent = true; else $email_error = 'Send failed — check SMTP settings.';
        } else { $email_error = 'Please fill in subject and message.'; }
    }

    $registrations = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.*, p.post_title as event_name FROM $rtable r
         LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
         WHERE r.email = %s ORDER BY r.created_at DESC", $m->email
    ) );
    $photos = [];
    if ( $wpdb->get_var("SHOW TABLES LIKE '$ptable'") === $ptable ) {
        $photos = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $ptable WHERE member_id = %d ORDER BY submitted_at DESC", $m->id
        ) );
    }

    $initials = strtoupper( substr($m->first_name,0,1) . substr($m->last_name,0,1) );
    $joined   = date( 'F j, Y', strtotime($m->joined_at) );
    $last_evt = $m->last_event_date ? date('M j, Y', strtotime($m->last_event_date)) : '—';
    $wp_user  = $m->wp_user_id ? get_userdata($m->wp_user_id) : null;
    $back_url = admin_url('admin.php?page=blusiast-all-members');

    // Inline style tokens
    $s1='#111111'; $s2='#1a1a1a'; $s3='#242424'; $s4='#2e2e2e';
    $red='#CC0000'; $g1='#888'; $g2='#aaa'; $white='#fff';
    $card = "background:$s1;border:1px solid $s3;border-radius:8px;padding:24px;margin-bottom:16px;";
    $label_style = "font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:$g1;display:block;margin-bottom:4px;";
    $value_style = "font-size:14px;color:$white;font-weight:500;";
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header('Member Profile'); ?>
        <?php blusiast_admin_tabs('blusiast-all-members'); ?>
        <a href="<?php echo esc_url($back_url); ?>" class="bl-back">← Back to All Members</a>

        <!-- ════ TWO-COLUMN GRID ════ -->
        <div style="display:grid;grid-template-columns:360px 1fr;gap:24px;align-items:flex-start;margin-top:8px;">

            <!-- ══ LEFT COLUMN ══ -->
            <div style="display:flex;flex-direction:column;gap:0;">

                <!-- IDENTITY CARD -->
                <div style="<?php echo $card; ?>border-top:3px solid <?php echo $red; ?>;">

                    <!-- Avatar + name row -->
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
                        <?php if ($m->avatar_url): ?>
                            <img src="<?php echo esc_url($m->avatar_url); ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid <?php echo $s3; ?>;flex-shrink:0;" alt="">
                        <?php else: ?>
                            <div style="width:72px;height:72px;border-radius:50%;background:<?php echo $red; ?>;color:#fff;font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;text-transform:uppercase;"><?php echo esc_html($initials); ?></div>
                        <?php endif; ?>
                        <div>
                            <div style="font-family:'Barlow Condensed',sans-serif;font-size:26px;font-weight:900;text-transform:uppercase;color:<?php echo $white; ?>;line-height:1.05;"><?php echo esc_html($m->first_name.' '.$m->last_name); ?></div>
                            <?php if ($m->handle): ?><div style="font-size:13px;color:<?php echo $red; ?>;font-weight:600;margin-top:3px;">@<?php echo esc_html($m->handle); ?></div><?php endif; ?>
                            <span class="bl-status bl-acct--<?php echo esc_attr($m->account_status); ?>" style="margin-top:8px;display:inline-block;"><?php echo esc_html(ucfirst($m->account_status)); ?></span>
                        </div>
                    </div>

                    <?php if ($m->bio): ?>
                        <p style="font-size:13px;color:<?php echo $g2; ?>;line-height:1.65;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid <?php echo $s3; ?>;"><?php echo esc_html($m->bio); ?></p>
                    <?php endif; ?>

                    <!-- Stats row -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;padding-top:4px;">
                        <div>
                            <span style="<?php echo $label_style; ?>">Member ID</span>
                            <span class="bl-member-number-badge"><?php echo esc_html(blusiast_get_member_number($m->id)); ?></span>
                        </div>
                        <div>
                            <span style="<?php echo $label_style; ?>">Joined</span>
                            <span style="<?php echo $value_style; ?>font-size:13px;"><?php echo esc_html($joined); ?></span>
                        </div>
                        <div>
                            <span style="<?php echo $label_style; ?>">Events Attended</span>
                            <span style="font-family:'Barlow Condensed',sans-serif;font-size:32px;font-weight:900;color:<?php echo $red; ?>;line-height:1;"><?php echo (int)$m->event_count; ?></span>
                        </div>
                        <div>
                            <span style="<?php echo $label_style; ?>">Last Event</span>
                            <span style="<?php echo $value_style; ?>font-size:13px;"><?php echo esc_html($last_evt); ?></span>
                        </div>
                        <?php if ($m->home_park): ?>
                        <div>
                            <span style="<?php echo $label_style; ?>">Home Park</span>
                            <span style="<?php echo $value_style; ?>font-size:13px;">🏠 <?php echo esc_html($m->home_park); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($m->fave_coaster): ?>
                        <div>
                            <span style="<?php echo $label_style; ?>">Fave Coaster</span>
                            <span style="<?php echo $value_style; ?>font-size:13px;">🎢 <?php echo esc_html($m->fave_coaster); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CONTACT CARD -->
                <div style="<?php echo $card; ?>">
                    <div style="font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:<?php echo $white; ?>;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span style="color:<?php echo $red; ?>;">📬</span> Contact Information
                    </div>

                    <!-- Email -->
                    <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid <?php echo $s3; ?>;">
                        <div style="width:36px;height:36px;background:<?php echo $s2; ?>;border:1px solid <?php echo $s3; ?>;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;">✉️</div>
                        <div style="min-width:0;">
                            <div style="<?php echo $label_style; ?>margin-bottom:2px;">Email</div>
                            <a href="mailto:<?php echo esc_attr($m->email); ?>" style="font-size:14px;color:<?php echo $white; ?>;font-weight:500;text-decoration:none;word-break:break-all;" onmouseover="this.style.color='<?php echo $red; ?>'" onmouseout="this.style.color='<?php echo $white; ?>'">
                                <?php echo esc_html($m->email); ?>
                            </a>
                        </div>
                    </div>

                    <?php if ($m->phone): ?>
                    <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid <?php echo $s3; ?>;">
                        <div style="width:36px;height:36px;background:<?php echo $s2; ?>;border:1px solid <?php echo $s3; ?>;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;">📱</div>
                        <div>
                            <div style="<?php echo $label_style; ?>margin-bottom:2px;">Phone</div>
                            <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/','', $m->phone)); ?>" style="font-size:14px;color:<?php echo $white; ?>;font-weight:500;text-decoration:none;" onmouseover="this.style.color='<?php echo $red; ?>'" onmouseout="this.style.color='<?php echo $white; ?>'">
                                <?php echo esc_html($m->phone); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($m->zip): ?>
                    <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid <?php echo $s3; ?>;">
                        <div style="width:36px;height:36px;background:<?php echo $s2; ?>;border:1px solid <?php echo $s3; ?>;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;">📍</div>
                        <div>
                            <div style="<?php echo $label_style; ?>margin-bottom:2px;">Location</div>
                            <span class="bl-zip-location" data-zip="<?php echo esc_attr($m->zip); ?>" style="font-size:14px;color:<?php echo $white; ?>;font-weight:500;">
                                <?php echo esc_html($m->zip); ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($m->instagram): ?>
                    <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid <?php echo $s3; ?>;">
                        <div style="width:36px;height:36px;background:<?php echo $s2; ?>;border:1px solid <?php echo $s3; ?>;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;">📸</div>
                        <div>
                            <div style="<?php echo $label_style; ?>margin-bottom:2px;">Instagram</div>
                            <a href="https://instagram.com/<?php echo esc_attr(ltrim($m->instagram,'@')); ?>" target="_blank" rel="noopener" style="font-size:14px;color:<?php echo $red; ?>;font-weight:500;text-decoration:none;">
                                @<?php echo esc_html(ltrim($m->instagram,'@')); ?> ↗
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($wp_user): ?>
                    <div style="display:flex;align-items:center;gap:14px;padding:12px 0;">
                        <div style="width:36px;height:36px;background:<?php echo $s2; ?>;border:1px solid <?php echo $s3; ?>;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;">🔑</div>
                        <div>
                            <div style="<?php echo $label_style; ?>margin-bottom:2px;">WP Login</div>
                            <a href="<?php echo esc_url(admin_url('user-edit.php?user_id='.$m->wp_user_id)); ?>" style="font-size:14px;color:<?php echo $g2; ?>;font-weight:500;text-decoration:none;" onmouseover="this.style.color='<?php echo $red; ?>'" onmouseout="this.style.color='<?php echo $g2; ?>'">
                                <?php echo esc_html($wp_user->user_login); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Quick action buttons -->
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;padding-top:16px;border-top:1px solid <?php echo $s3; ?>;">
                        <a href="mailto:<?php echo esc_attr($m->email); ?>" class="bl-btn-sm">✉ Mail Client</a>
                        <?php if ($m->phone): ?><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/','', $m->phone)); ?>" class="bl-btn-sm">📱 Call</a><?php endif; ?>
                        <?php if ($m->instagram): ?><a href="https://instagram.com/<?php echo esc_attr(ltrim($m->instagram,'@')); ?>" target="_blank" rel="noopener" class="bl-btn-sm">📸 Instagram ↗</a><?php endif; ?>
                    </div>
                </div>

                <!-- SEND EMAIL CARD -->
                <div style="<?php echo $card; ?>border-top:2px solid <?php echo $red; ?>;">
                    <div style="font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:<?php echo $white; ?>;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                        <span style="color:<?php echo $red; ?>;">✉</span> Send Direct Email
                    </div>
                    <p style="font-size:12px;color:<?php echo $g1; ?>;margin-bottom:16px;line-height:1.5;">Message <?php echo esc_html($m->first_name); ?> directly. Sends via your configured SMTP.</p>

                    <?php if ($email_sent): ?>
                        <div style="background:#0a2a0a;border:1px solid #1a4a1a;color:#5cb85c;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:12px;">✓ Sent to <?php echo esc_html($m->email); ?></div>
                    <?php elseif ($email_error): ?>
                        <div style="background:#2a0000;border:1px solid #4a0000;color:#ff5555;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:12px;"><?php echo esc_html($email_error); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?php wp_nonce_field('bl_member_email_'.$member_id); ?>
                        <div style="display:flex;flex-direction:column;gap:12px;">
                            <div>
                                <label style="<?php echo $label_style; ?>">To</label>
                                <input type="text" class="bl-search-input" style="width:100%;box-sizing:border-box;opacity:.6;cursor:default;" disabled value="<?php echo esc_attr($m->first_name.' '.$m->last_name.' <'.$m->email.'>'); ?>">
                            </div>
                            <div>
                                <label style="<?php echo $label_style; ?>">Subject</label>
                                <input type="text" name="bl_email_subject" class="bl-search-input" style="width:100%;box-sizing:border-box;" placeholder="e.g. Hey <?php echo esc_attr($m->first_name); ?>, quick update…" value="<?php echo esc_attr($_POST['bl_email_subject'] ?? ''); ?>" required>
                            </div>
                            <div>
                                <label style="<?php echo $label_style; ?>">Message</label>
                                <textarea name="bl_email_body" class="bl-search-input" style="width:100%;box-sizing:border-box;min-height:120px;resize:vertical;" placeholder="Hey <?php echo esc_attr($m->first_name); ?>," required><?php echo esc_textarea($_POST['bl_email_body'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" name="bl_send_member_email" class="bl-blast-send-btn">Send Email</button>
                        </div>
                    </form>
                </div>

            </div><!-- /left col -->

            <!-- ══ RIGHT COLUMN ══ -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- EVENT HISTORY -->
                <div style="background:<?php echo $s1; ?>;border:1px solid <?php echo $s3; ?>;border-radius:8px;overflow:hidden;">
                    <div style="display:flex;align-items:center;padding:14px 20px;border-bottom:1px solid <?php echo $s3; ?>;background:<?php echo $s2; ?>;">
                        <span style="font-family:'Barlow Condensed',sans-serif;font-size:16px;font-weight:800;text-transform:uppercase;color:<?php echo $white; ?>;flex:1;">
                            Event History <span style="color:<?php echo $g1; ?>;font-size:13px;font-family:'Barlow',sans-serif;text-transform:none;font-weight:400;">(<?php echo count($registrations); ?>)</span>
                        </span>
                    </div>
                    <?php if ($registrations): ?>
                    <table class="bl-table">
                        <thead><tr><th>Event</th><th>Status</th><th>Guests</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($registrations as $reg): ?>
                            <tr>
                                <td style="font-weight:600;color:<?php echo $white; ?>;"><?php echo esc_html($reg->event_name ?: '—'); ?></td>
                                <td><span class="bl-status bl-status--<?php echo esc_attr($reg->status); ?>"><?php echo esc_html(ucfirst($reg->status)); ?></span></td>
                                <td style="text-align:center;"><?php echo (int)$reg->guest_count; ?></td>
                                <td style="font-size:12px;white-space:nowrap;color:<?php echo $g2; ?>;"><?php echo esc_html(date('M j, Y', strtotime($reg->created_at))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="bl-empty"><strong>No Events Yet</strong>This member hasn't registered for any events.</div>
                    <?php endif; ?>
                </div>

                <!-- PHOTO SUBMISSIONS -->
                <?php if (!empty($photos)): ?>
                <div style="background:<?php echo $s1; ?>;border:1px solid <?php echo $s3; ?>;border-radius:8px;overflow:hidden;">
                    <div style="padding:14px 20px;border-bottom:1px solid <?php echo $s3; ?>;background:<?php echo $s2; ?>;">
                        <span style="font-family:'Barlow Condensed',sans-serif;font-size:16px;font-weight:800;text-transform:uppercase;color:<?php echo $white; ?>;">Photo Submissions (<?php echo count($photos); ?>)</span>
                    </div>
                    <table class="bl-table">
                        <thead><tr><th>Photo</th><th>Caption</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($photos as $p):
                            $img_url = $p->attachment_id ? wp_get_attachment_image_url($p->attachment_id,'thumbnail') : '';
                        ?>
                            <tr>
                                <td style="width:72px;">
                                    <?php if ($img_url): ?>
                                    <a href="<?php echo esc_url(wp_get_attachment_url($p->attachment_id)); ?>" target="_blank">
                                        <img src="<?php echo esc_url($img_url); ?>" style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid <?php echo $s3; ?>;display:block;" alt="">
                                    </a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td style="font-size:13px;color:<?php echo $g2; ?>;"><?php echo esc_html($p->caption ?: '—'); ?></td>
                                <td><span class="bl-status bl-status--<?php echo $p->status==='approved'?'confirmed':($p->status==='rejected'?'cancelled':'pending'); ?>"><?php echo esc_html(ucfirst($p->status)); ?></span></td>
                                <td style="font-size:12px;white-space:nowrap;color:<?php echo $g2; ?>;"><?php echo esc_html(date('M j, Y', strtotime($p->submitted_at))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- BILLING NOTES + STATUS -->
                <div style="<?php echo $card; ?>margin-bottom:0;">
                    <div style="font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:<?php echo $white; ?>;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
                        <span><span style="color:<?php echo $red; ?>;">📋</span> Admin Notes &amp; Status</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                        <div>
                            <label style="<?php echo $label_style; ?>">Account Status</label>
                            <span class="bl-status bl-acct--<?php echo esc_attr($m->account_status); ?>" style="display:block;">
                                <select class="bl-member-status-select" data-id="<?php echo (int)$m->id; ?>" style="width:100%;">
                                    <option value="free"   <?php selected($m->account_status,'free');   ?>>Free</option>
                                    <option value="active" <?php selected($m->account_status,'active'); ?>>Active</option>
                                    <option value="lapsed" <?php selected($m->account_status,'lapsed'); ?>>Lapsed</option>
                                    <option value="banned" <?php selected($m->account_status,'banned'); ?>>Banned</option>
                                </select>
                            </span>
                        </div>
                        <div>
                            <label style="<?php echo $label_style; ?>">Member Since</label>
                            <span style="<?php echo $value_style; ?>font-size:13px;"><?php echo esc_html($joined); ?></span>
                        </div>
                    </div>
                    <label style="<?php echo $label_style; ?>">Billing Notes</label>
                    <textarea class="bl-note-input bl-member-note-input" data-id="<?php echo (int)$m->id; ?>"
                              rows="4" placeholder="Add billing notes, payment info, membership details…"
                              style="width:100%;box-sizing:border-box;font-size:13px;"><?php echo esc_textarea($m->billing_notes); ?></textarea>
                    <p style="font-size:11px;color:<?php echo $g1; ?>;margin-top:6px;">Notes auto-save on blur.</p>
                </div>

            </div><!-- /right col -->
        </div><!-- /grid -->
    </div>
    <?php
}


function blusiast_all_members_page() {
    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $rtable = $wpdb->prefix . 'bl_event_registrations';

    // Ensure table exists
    blusiast_install_db();

    // ── Member profile drill-down ──
    if ( isset( $_GET['view'] ) && $_GET['view'] === 'profile' && ! empty( $_GET['member_id'] ) ) {
        blusiast_member_profile_page( absint( $_GET['member_id'] ) );
        return;
    }

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

    // Build a zip→state map for all unique zips using the US zip prefix ranges
    // We store state on the member row via JS on page load (zippopotam),
    // but for server-side filtering we use a built-in zip prefix → state table
    function blusiast_zip_to_state( $zip ) {
        $zip = (int) substr( preg_replace('/\D/','', $zip), 0, 5 );
        $ranges = [
            [1,'MA'],[2,'MA'],[3,'ME'],[4,'ME'],[5,'VT'],[6,'CT'],[7,'NJ'],[8,'NJ'],
            [10,'NY'],[11,'NY'],[12,'NY'],[13,'NY'],[14,'NY'],[15,'PA'],[16,'PA'],[17,'PA'],
            [18,'PA'],[19,'PA'],[20,'DC'],[21,'MD'],[22,'VA'],[23,'VA'],[24,'VA'],[25,'WV'],
            [26,'WV'],[27,'NC'],[28,'NC'],[29,'SC'],[30,'GA'],[31,'GA'],[32,'FL'],[33,'FL'],
            [34,'FL'],[35,'AL'],[36,'AL'],[37,'TN'],[38,'TN'],[39,'MS'],[40,'KY'],[41,'KY'],
            [42,'KY'],[43,'OH'],[44,'OH'],[45,'OH'],[46,'IN'],[47,'IN'],[48,'MI'],[49,'MI'],
            [50,'IA'],[51,'IA'],[52,'IA'],[53,'WI'],[54,'WI'],[55,'MN'],[56,'MN'],[57,'SD'],
            [58,'ND'],[59,'MT'],[60,'IL'],[61,'IL'],[62,'IL'],[63,'MO'],[64,'MO'],[65,'MO'],
            [66,'KS'],[67,'KS'],[68,'NE'],[69,'NE'],[70,'LA'],[71,'LA'],[72,'AR'],[73,'OK'],
            [74,'OK'],[75,'TX'],[76,'TX'],[77,'TX'],[78,'TX'],[79,'TX'],[80,'CO'],[81,'CO'],
            [82,'WY'],[83,'ID'],[84,'UT'],[85,'AZ'],[86,'AZ'],[87,'NM'],[88,'NM'],[89,'NV'],
            [90,'CA'],[91,'CA'],[92,'CA'],[93,'CA'],[94,'CA'],[95,'CA'],[96,'CA'],[97,'OR'],
            [98,'WA'],[99,'WA'],[100,'NY'],[200,'DC'],
        ];
        $prefix2 = (int) substr( str_pad($zip,5,'0',STR_PAD_LEFT), 0, 2 );
        $state = '';
        foreach ( $ranges as $r ) {
            if ( $prefix2 >= $r[0] ) $state = $r[1];
            else break;
        }
        return $state;
    }

    // Attach state to each member object
    $states_present = [];
    foreach ( $members as &$m ) {
        $m->state = $m->zip ? blusiast_zip_to_state( $m->zip ) : '';
        if ( $m->state ) $states_present[ $m->state ] = true;
    }
    unset($m);
    ksort( $states_present );

    $export_url = admin_url( 'admin.php?page=blusiast-all-members&bl_export_members=1' );
    ?>
    <div class="bl-crm-wrap">
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

        <!-- ── FILTER BAR ── -->
        <div style="background:var(--bl-s1);border:1px solid var(--bl-s3);border-radius:var(--bl-r);padding:16px 20px;margin-bottom:16px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:180px;">
                <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1);display:block;margin-bottom:6px;">Search</label>
                <input type="search" id="bl-reg-search" class="bl-search-input" placeholder="Name, email, zip…" style="width:100%;box-sizing:border-box;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1);display:block;margin-bottom:6px;">Status</label>
                <select class="bl-filter-select" id="bl-member-status-filter">
                    <option value="">All Statuses</option>
                    <option value="free">Free</option>
                    <option value="active">Active / Paid</option>
                    <option value="lapsed">Lapsed</option>
                    <option value="banned">Banned</option>
                </select>
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--bl-g1);display:block;margin-bottom:6px;">State</label>
                <select class="bl-filter-select" id="bl-member-state-filter">
                    <option value="">All States</option>
                    <?php foreach ( array_keys($states_present) as $st ) : ?>
                    <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding-bottom:1px;">
                <a href="<?php echo esc_url( $export_url ); ?>" class="bl-btn-sm">↓ Export CSV</a>
                <?php if ( $members ) : ?>
                <button type="button" class="bl-btn-sm bl-btn-email-blast" id="bl-member-blast-toggle">✉ Email Filtered Members</button>
                <?php endif; ?>
                <span id="bl-filter-count" style="font-size:12px;color:var(--bl-g1);white-space:nowrap;"></span>
            </div>
        </div>

        <div class="bl-table-wrap">

            <?php if ( $members ) : ?>
            <!-- ── BULK EMAIL PANEL ── -->
            <div id="bl-member-blast-panel" style="display:none;">
                <div class="bl-blast-panel">
                    <div class="bl-blast-panel__header">
                        <div>
                            <div class="bl-blast-panel__title">✉ Bulk Email</div>
                            <div class="bl-blast-panel__sub" id="bl-blast-recipient-desc">Sending to: <strong>all members</strong></div>
                        </div>
                        <button type="button" class="bl-blast-close" id="bl-member-blast-close">✕</button>
                    </div>
                    <div class="bl-blast-panel__body">
                        <!-- Active filters summary -->
                        <div id="bl-blast-filter-summary" style="background:var(--bl-s2);border:1px solid var(--bl-s3);border-left:3px solid var(--bl-red);border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--bl-g2);display:none;">
                            <strong style="color:var(--bl-white);">Active filters:</strong> <span id="bl-blast-filter-tags"></span>
                            — only matching members will receive this email.
                        </div>
                        <div class="bl-blast-row">
                            <label class="bl-blast-label" for="bl-member-blast-subject">Subject</label>
                            <input type="text" id="bl-member-blast-subject" class="bl-blast-input" placeholder="e.g. Exciting news from the Blusiast crew!">
                        </div>
                        <div class="bl-blast-row">
                            <label class="bl-blast-label" for="bl_member_blast_body">
                                Message
                                <span class="bl-blast-hint">Use <code>{name}</code> for each member's first name. Use the toolbar to add bold, links, etc.</span>
                            </label>
                            <textarea id="bl_member_blast_body" name="bl_member_blast_body" class="bl-blast-input bl-blast-textarea" rows="10" style="width:100%;"></textarea>
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
                        <th>Member</th>
                        <th>Member ID</th>
                        <th>Email</th>
                        <th>Events</th>
                        <th>Joined</th>
                        <th>Account</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $members as $m ) :
                    $initials    = strtoupper( substr( $m->first_name, 0, 1 ) . substr( $m->last_name, 0, 1 ) );
                    $joined      = date( 'M j, Y', strtotime( $m->joined_at ) );
                    $profile_url = admin_url( 'admin.php?page=blusiast-all-members&view=profile&member_id=' . (int) $m->id );
                ?>
                    <tr data-status="<?php echo esc_attr( $m->account_status ); ?>" data-state="<?php echo esc_attr( $m->state ); ?>" data-name="<?php echo esc_attr( strtolower($m->first_name.' '.$m->last_name) ); ?>" data-email="<?php echo esc_attr( strtolower($m->email) ); ?>" data-zip="<?php echo esc_attr( $m->zip ); ?>">

                        <!-- Name + avatar — click → profile -->
                        <td>
                            <a href="<?php echo esc_url( $profile_url ); ?>" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
                                <div class="bl-member-avatar" style="width:36px;height:36px;font-size:13px;flex-shrink:0;"><?php echo esc_html( $initials ); ?></div>
                                <div>
                                    <div class="bl-td-name" style="transition:color .15s;"><?php echo esc_html( $m->first_name . ' ' . $m->last_name ); ?></div>
                                    <?php if ( $m->handle ) : ?>
                                        <div style="font-size:11px;color:var(--bl-red);">@<?php echo esc_html( $m->handle ); ?></div>
                                    <?php elseif ( $m->zip ) : ?>
                                        <div style="font-size:11px;color:var(--bl-g1);" class="bl-zip-location" data-zip="<?php echo esc_attr( $m->zip ); ?>">📍 <?php echo esc_html( $m->zip ); ?></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </td>

                        <!-- Member ID badge — also click → profile -->
                        <td>
                            <a href="<?php echo esc_url( $profile_url ); ?>" style="text-decoration:none;">
                                <span class="bl-member-number-badge"><?php echo esc_html( blusiast_get_member_number( $m->id ) ); ?></span>
                            </a>
                        </td>

                        <td>
                            <a href="mailto:<?php echo esc_attr( $m->email ); ?>" style="color:var(--bl-g2);font-size:13px;">
                                <?php echo esc_html( $m->email ); ?>
                            </a>
                        </td>

                        <td style="text-align:center;">
                            <strong style="color:var(--bl-white);font-size:15px;"><?php echo (int) $m->event_count; ?></strong>
                        </td>

                        <td style="font-size:12px;white-space:nowrap;color:var(--bl-g2);">
                            <?php echo esc_html( $joined ); ?>
                        </td>

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
        // ── Unified filter (search + status + state) ──────────────
        function blApplyFilters() {
            var search = $('#bl-reg-search').val().toLowerCase().trim();
            var status = $('#bl-member-status-filter').val();
            var state  = $('#bl-member-state-filter').val();
            var visible = 0;
            $('#bl-members-table tbody tr').each(function(){
                var $tr = $(this);
                var matchSearch = !search ||
                    String($tr.data('name')).indexOf(search) > -1 ||
                    String($tr.data('email')).indexOf(search) > -1 ||
                    String($tr.data('zip')).indexOf(search) > -1;
                var matchStatus = !status || $tr.data('status') === status;
                var matchState  = !state  || $tr.data('state')  === state;
                var show = matchSearch && matchStatus && matchState;
                $tr.toggle(show);
                if (show) visible++;
            });
            // Visible count badge
            var total = $('#bl-members-table tbody tr').length;
            $('#bl-filter-count').text(visible < total ? visible + ' of ' + total + ' shown' : '');
            // Update blast panel description
            var parts = [];
            if (status) parts.push(status + ' members');
            if (state)  parts.push('in ' + state);
            if (search) parts.push('matching "' + search + '"');
            var desc = parts.length ? parts.join(', ') : 'all members';
            $('#bl-blast-recipient-desc').html('Sending to: <strong>' + visible + ' ' + desc + '</strong>');
            if (parts.length) {
                $('#bl-blast-filter-summary').show();
                $('#bl-blast-filter-tags').text(parts.join(' · '));
            } else {
                $('#bl-blast-filter-summary').hide();
            }
        }
        $('#bl-reg-search').on('input', blApplyFilters);
        $('#bl-member-status-filter').on('change', blApplyFilters);
        $('#bl-member-state-filter').on('change', blApplyFilters);

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
        if ( ! empty( $_POST['brevo_api_key'] ) ) {
            update_option( 'bl_brevo_api_key', sanitize_text_field( $_POST['brevo_api_key'] ) );
        }
        update_option( 'bl_brevo_list_id', absint( $_POST['brevo_list_id'] ?? 0 ) );

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
    ?>
    <div class="bl-crm-wrap">
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
            <div class="bl-settings-card">
                <div class="bl-settings-card__head"><h2>Sender Identity</h2></div>
                <div class="bl-settings-card__body" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div>
                        <label class="bl-form-label">From Name</label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="text" name="from_name" value="<?php echo esc_attr(get_option('bl_email_from_name','Blusiast')); ?>">
                    </div>
                    <div>
                        <label class="bl-form-label">From Email Address</label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="email" name="from_address" value="<?php echo esc_attr(get_option('bl_email_from_address', get_option('admin_email'))); ?>">
                    </div>
                </div>
            </div>

            <!-- SMTP -->
            <div class="bl-settings-card">
                <div class="bl-settings-card__head"><h2>SMTP Configuration</h2></div>
                <div class="bl-settings-card__body" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
                    <div>
                        <label class="bl-form-label">SMTP Host</label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="text" name="smtp_host" placeholder="smtp.brevo.com" value="<?php echo esc_attr(get_option('bl_smtp_host','')); ?>">
                    </div>
                    <div>
                        <label class="bl-form-label">Port</label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="number" name="smtp_port" value="<?php echo esc_attr(get_option('bl_smtp_port',587)); ?>">
                    </div>
                    <div>
                        <label class="bl-form-label">Encryption</label>
                        <select class="bl-filter-select" style="width:100%;box-sizing:border-box;" name="smtp_encryption">
                            <option value="tls" <?php selected(get_option('bl_smtp_encryption','tls'),'tls'); ?>>TLS (recommended)</option>
                            <option value="ssl" <?php selected(get_option('bl_smtp_encryption','tls'),'ssl'); ?>>SSL</option>
                            <option value="none" <?php selected(get_option('bl_smtp_encryption','tls'),'none'); ?>>None</option>
                        </select>
                    </div>
                    <div>
                        <label class="bl-form-label">SMTP Username</label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="text" name="smtp_user" value="<?php echo esc_attr(get_option('bl_smtp_user','')); ?>">
                    </div>
                    <div>
                        <label class="bl-form-label">SMTP Password <span style="font-weight:400;text-transform:none;letter-spacing:0;">(leave blank to keep current)</span></label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="password" name="smtp_pass" placeholder="••••••••">
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
            <div class="bl-settings-card">
                <div class="bl-settings-card__head"><h2>Event Registration Confirmation Email</h2></div>
                <div class="bl-settings-card__body" style="display:flex;flex-direction:column;gap:16px;">
                    <div>
                        <label class="bl-form-label">Subject</label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="text" name="reg_subject"
                               value="<?php echo esc_attr(get_option('bl_email_reg_subject', "You're registered: {event} — Blusiast")); ?>">
                    </div>
                    <div>
                        <label class="bl-form-label">Body <span style="font-weight:400;text-transform:none;letter-spacing:0;">— use {name} {event} {date} {location}</span></label>
                        <textarea class="bl-search-input" style="width:100%;box-sizing:border-box;min-height:160px;resize:vertical;" name="reg_body"><?php echo esc_textarea(get_option('bl_email_reg_body', "Hey {name},

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
            <div class="bl-settings-card">
                <div class="bl-settings-card__head"><h2>New Member Welcome Email</h2></div>
                <div class="bl-settings-card__body" style="display:flex;flex-direction:column;gap:16px;">
                    <div>
                        <label class="bl-form-label">Subject</label>
                        <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="text" name="signup_subject"
                               value="<?php echo esc_attr(get_option('bl_email_signup_subject', 'Welcome to Blusiast, {name}!')); ?>">
                    </div>
                    <div>
                        <label class="bl-form-label">Body <span style="font-weight:400;text-transform:none;letter-spacing:0;">— use {name} {portal_url}</span></label>
                        <textarea class="bl-search-input" style="width:100%;box-sizing:border-box;min-height:160px;resize:vertical;" name="signup_body"><?php echo esc_textarea(get_option('bl_email_signup_body', "Hey {name},

You're officially part of the crew!

Your account is ready. Head to your portal:
{portal_url}

Ride on,
The Blusiast Crew")); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="bl-settings-card" style="border-top:3px solid var(--bl-red);">
                <div class="bl-settings-card__head"><h2>🔗 Brevo Contact Sync</h2></div>
                <div class="bl-settings-card__body" style="display:flex;flex-direction:column;gap:16px;">
                    <p style="margin:0;font-size:13px;color:var(--bl-g1);line-height:1.6;">Connect Brevo so every new member is automatically added as a contact. Paste your Brevo API key below — find it in <strong style="color:var(--bl-g2);">Brevo → Account → SMTP &amp; API → API Keys</strong>.</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div>
                            <label class="bl-form-label">Brevo API Key (v3)</label>
                            <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="text" name="brevo_api_key"
                                   placeholder="xkeysib-…"
                                   value="<?php echo esc_attr( get_option('bl_brevo_api_key','') ); ?>">
                            <p style="margin:6px 0 0;font-size:11px;color:var(--bl-g1);">Never share this key. It stays on your server.</p>
                        </div>
                        <div>
                            <label class="bl-form-label">Brevo List ID <span style="font-weight:400;text-transform:none;">(optional)</span></label>
                            <input class="bl-search-input" style="width:100%;box-sizing:border-box;" type="number" name="brevo_list_id"
                                   placeholder="e.g. 3"
                                   value="<?php echo esc_attr( get_option('bl_brevo_list_id','') ); ?>">
                            <p style="margin:6px 0 0;font-size:11px;color:var(--bl-g1);">Find in Brevo → Contacts → Lists. Adds new members to this list automatically.</p>
                        </div>
                    </div>
                    <div style="border-top:1px solid var(--bl-s3);padding-top:16px;">
                        <p style="margin:0 0 10px;font-size:13px;color:var(--bl-g2);"><strong style="color:var(--bl-white);">One-Time Bulk Sync</strong> — push all existing members to Brevo right now. Save your API key first, then click below.</p>
                        <button type="button" id="bl-brevo-sync-btn" class="bl-btn-sm" style="background:var(--bl-red);color:#fff;border-color:var(--bl-red);">
                            <span id="bl-brevo-sync-label">↑ Sync All Members to Brevo</span>
                            <span id="bl-brevo-sync-spinner" style="display:none;">Syncing…</span>
                        </button>
                        <span id="bl-brevo-sync-result" style="margin-left:12px;font-size:13px;display:none;"></span>
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
    <div class="bl-crm-wrap">
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


// ─────────────────────────────────────────
// CONTACT SUBMISSIONS — AJAX HANDLERS
// ─────────────────────────────────────────

add_action( 'wp_ajax_bl_contact_delete',     'bl_contact_delete_handler' );
add_action( 'wp_ajax_bl_contact_delete_all', 'bl_contact_delete_all_handler' );
add_action( 'wp_ajax_bl_contact_status',     'bl_contact_status_handler' );

function bl_contact_delete_handler() {
    check_ajax_referer( 'bl_contact_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'bl_contact_submissions', [ 'id' => absint( $_POST['id'] ) ], [ '%d' ] );
    wp_send_json_success();
}

function bl_contact_delete_all_handler() {
    check_ajax_referer( 'bl_contact_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bl_contact_submissions" );
    wp_send_json_success();
}

function bl_contact_status_handler() {
    check_ajax_referer( 'bl_contact_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
    global $wpdb;
    $status = in_array( $_POST['status'], [ 'new', 'replied' ], true ) ? $_POST['status'] : 'new';
    $wpdb->update( $wpdb->prefix . 'bl_contact_submissions', [ 'status' => $status ], [ 'id' => absint( $_POST['id'] ) ], [ '%s' ], [ '%d' ] );
    wp_send_json_success();
}

// ─────────────────────────────────────────
// CONTACT SUBMISSIONS ADMIN PAGE
// ─────────────────────────────────────────

function blusiast_contact_submissions_page() {
    global $wpdb;
    $ct = $wpdb->prefix . 'bl_contact_submissions';
    blusiast_install_db();

    $submissions = $wpdb->get_results( "SELECT * FROM $ct ORDER BY created_at DESC" );
    $count       = count( $submissions );
    $new_count   = count( array_filter( (array) $submissions, fn($s) => $s->status === 'new' ) );
    $nonce       = wp_create_nonce( 'bl_contact_nonce' );
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Contact Submissions' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-contact' ); ?>

        <div class="bl-table-wrap">
            <div class="bl-table-toolbar">
                <h2>
                    All Submissions (<?php echo $count; ?>)
                    <?php if ( $new_count ) : ?>
                    <span style="margin-left:8px;font-size:12px;background:var(--bl-red,#e63946);color:#fff;padding:2px 8px;border-radius:20px;font-weight:700;"><?php echo $new_count; ?> New</span>
                    <?php endif; ?>
                </h2>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="search" class="bl-search-input" id="bl-contact-search" placeholder="Search name, email, subject…">
                    <?php if ( $submissions ) : ?>
                    <button class="bl-btn-sm" style="background:none;border:1px solid #555;color:#aaa;cursor:pointer;white-space:nowrap;" onclick="blDeleteAll()">🗑 Delete All</button>
                    <?php endif; ?>
                </div>
            </div>

            <style>
                .bl-contact-list { display:flex;flex-direction:column;gap:8px;padding:16px; }
                .bl-contact-item { border:1px solid var(--bl-surface3,#2a2a2a);border-radius:8px;overflow:hidden;background:var(--bl-surface2,#1a1a1a);transition:opacity .3s; }
                .bl-contact-item.is-new { border-left:3px solid var(--bl-red,#e63946); }
                .bl-contact-header { display:grid;grid-template-columns:auto 1fr 1fr 1fr auto auto;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;transition:background .15s; }
                .bl-contact-header:hover { background:rgba(255,255,255,.04); }
                .bl-contact-header.open { background:rgba(255,255,255,.04);border-bottom:1px solid var(--bl-surface3,#2a2a2a); }
                .bl-contact-meta { font-size:11px;color:var(--bl-g1,#888);white-space:nowrap; }
                .bl-contact-subject { font-size:13px;font-weight:600;color:var(--bl-white,#fff); }
                .bl-contact-name { font-size:13px;color:var(--bl-g2,#aaa); }
                .bl-contact-chevron { font-size:12px;color:var(--bl-g1,#888);transition:transform .2s; }
                .bl-contact-header.open .bl-contact-chevron { transform:rotate(180deg); }
                .bl-contact-body { display:none;padding:16px;border-top:1px solid var(--bl-surface3,#2a2a2a); }
                .bl-contact-body.open { display:block; }
                .bl-contact-body p { font-size:13px;color:var(--bl-g2,#bbb);line-height:1.7;margin:0 0 14px;white-space:pre-wrap; }
                .bl-contact-actions { display:flex;gap:10px;align-items:center;flex-wrap:wrap; }
                .bl-contact-btn { font-size:12px;font-weight:700;background:none;border:none;cursor:pointer;padding:0;color:var(--bl-red,#e63946); }
                .bl-contact-btn:hover { text-decoration:underline; }
                .bl-contact-btn--delete { color:#888; }
                .bl-contact-btn--delete:hover { color:#e63946; }
                .bl-status-badge { font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em; }
                .bl-status-badge.new { background:rgba(230,57,70,.15);color:var(--bl-red,#e63946); }
                .bl-status-badge.replied { background:rgba(40,167,69,.15);color:#28a745; }
            </style>

            <?php if ( $submissions ) : ?>
            <div class="bl-contact-list" id="bl-contact-list">
                <?php foreach ( $submissions as $s ) :
                    $is_new = ( $s->status === 'new' );
                    $search = esc_attr( strtolower( $s->name . ' ' . $s->email . ' ' . $s->subject ) );
                ?>
                <div class="bl-contact-item <?php echo $is_new ? 'is-new' : ''; ?>" id="bl-item-<?php echo $s->id; ?>" data-search="<?php echo $search; ?>">
                    <div class="bl-contact-header" onclick="blContactToggle(this)">
                        <span class="bl-status-badge <?php echo esc_attr($s->status); ?>"><?php echo $is_new ? 'New' : 'Replied'; ?></span>
                        <div>
                            <div class="bl-contact-subject"><?php echo esc_html( $s->subject ?: 'General Inquiry' ); ?></div>
                            <div class="bl-contact-name"><?php echo esc_html( $s->name ); ?></div>
                        </div>
                        <div class="bl-contact-name"><?php echo esc_html( $s->email ); ?></div>
                        <div class="bl-contact-meta"><?php echo esc_html( date( 'M j, Y g:ia', strtotime( $s->created_at ) ) ); ?></div>
                        <div class="bl-contact-chevron">▼</div>
                    </div>
                    <div class="bl-contact-body">
                        <p><?php echo esc_html( $s->message ); ?></p>
                        <div class="bl-contact-actions">
                            <button class="bl-contact-btn" onclick="blCopyEmail(this, '<?php echo esc_attr($s->email); ?>')">📋 Copy Email</button>
                            <span style="color:#444;">|</span>
                            <?php if ( $is_new ) : ?>
                            <button class="bl-contact-btn" style="color:#28a745;" onclick="blSetStatus(<?php echo $s->id; ?>, 'replied', this)">✅ Mark as Replied</button>
                            <?php else : ?>
                            <button class="bl-contact-btn" style="color:#888;" onclick="blSetStatus(<?php echo $s->id; ?>, 'new', this)">↩ Mark as New</button>
                            <?php endif; ?>
                            <span style="color:#444;">|</span>
                            <button class="bl-contact-btn bl-contact-btn--delete" onclick="blDeleteOne(<?php echo $s->id; ?>)">🗑 Delete</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <script>
            var blNonce = '<?php echo $nonce; ?>';
            var blAjax  = '<?php echo admin_url('admin-ajax.php'); ?>';

            function blContactToggle(header) {
                var body = header.nextElementSibling;
                var isOpen = header.classList.contains('open');
                document.querySelectorAll('.bl-contact-header').forEach(function(h) {
                    h.classList.remove('open');
                    h.nextElementSibling.classList.remove('open');
                });
                if (!isOpen) { header.classList.add('open'); body.classList.add('open'); }
            }

            function blCopyEmail(btn, email) {
                navigator.clipboard.writeText(email).then(function() {
                    var orig = btn.textContent;
                    btn.textContent = '✅ Copied!';
                    setTimeout(function() { btn.textContent = orig; }, 2000);
                });
            }

            function blDeleteOne(id) {
                if (!confirm('Delete this submission?')) return;
                fetch(blAjax, {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=bl_contact_delete&nonce='+blNonce+'&id='+id
                }).then(function() {
                    var el = document.getElementById('bl-item-'+id);
                    el.style.opacity = '0';
                    setTimeout(function() { el.remove(); }, 300);
                });
            }

            function blDeleteAll() {
                if (!confirm('Delete ALL submissions? This cannot be undone.')) return;
                fetch(blAjax, {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=bl_contact_delete_all&nonce='+blNonce
                }).then(function() { location.reload(); });
            }

            function blSetStatus(id, status, btn) {
                fetch(blAjax, {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=bl_contact_status&nonce='+blNonce+'&id='+id+'&status='+status
                }).then(function() { location.reload(); });
            }

            document.getElementById('bl-contact-search').addEventListener('input', function() {
                var q = this.value.toLowerCase();
                document.querySelectorAll('#bl-contact-list .bl-contact-item').forEach(function(item) {
                    item.style.display = item.dataset.search.indexOf(q) > -1 ? '' : 'none';
                });
            });
            </script>

            <?php else : ?>
            <div class="bl-empty"><strong>No Submissions Yet</strong>Contact form submissions will appear here.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}


// ─────────────────────────────────────────
// SHOP SETTINGS PAGE
// ─────────────────────────────────────────

function blusiast_shop_settings_page() {

    // Helper: build signed auth header
    $bl_sprd_auth = function( $method, $url, $api_key, $secret ) {
        $time = time() * 1000;
        $data = "$method $url $time";
        $sig  = sha1( "$data $secret" );
        return "SprdAuth apiKey=\"$api_key\", data=\"$data\", sig=\"$sig\"";
    };

    // Save
    if ( isset( $_POST['bl_shop_save'] ) && check_admin_referer( 'bl_shop_settings' ) ) {
        update_option( 'bl_spreadshirt_api_key',    sanitize_text_field( $_POST['spreadshirt_api_key']    ?? '' ) );
        update_option( 'bl_spreadshirt_secret_key', sanitize_text_field( $_POST['spreadshirt_secret_key'] ?? '' ) );
        update_option( 'bl_spreadshirt_shop_id',    sanitize_text_field( $_POST['spreadshirt_shop_id']    ?? '1170219' ) );
        update_option( 'bl_spreadshirt_shop_url',   esc_url_raw( $_POST['spreadshirt_shop_url']           ?? 'https://blusiastmerch.myspreadshop.com' ) );
        delete_transient( 'bl_spreadshirt_products_' . get_option( 'bl_spreadshirt_shop_id', '1170219' ) );
        echo '<div class="notice notice-success"><p>Settings saved. Product cache cleared.</p></div>';
    }

    // Test connection
    $test_result = '';
    if ( isset( $_POST['bl_shop_test'] ) && check_admin_referer( 'bl_shop_settings' ) ) {
        $api_key = get_option( 'bl_spreadshirt_api_key', '' );
        $secret  = get_option( 'bl_spreadshirt_secret_key', '' );
        $shop_id = get_option( 'bl_spreadshirt_shop_id', '1170219' );
        if ( ! $api_key || ! $secret ) {
            $test_result = '<span style="color:#ff6666;">⚠ API key and secret key are both required.</span>';
        } else {
            // Use /sellables — the correct endpoint for Partner Area shops
            $url      = "https://api.spreadshirt.com/api/v1/shops/{$shop_id}/sellables?page=0&locale=en_US";
            $auth     = $bl_sprd_auth( 'GET', $url, $api_key, $secret );
            $response = wp_remote_get( $url, [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => $auth,
                    'User-Agent'    => 'Blusiast/1.0 (https://blusiast.org; admin@blusiast.org)',
                ],
            ] );
            $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
            if ( $code === 200 ) {
                $body  = json_decode( wp_remote_retrieve_body( $response ), true );
                $count = $body['count'] ?? 0;
                // Also clear old products cache key now that endpoint has changed
                delete_transient( 'bl_spreadshirt_products_' . $shop_id );
                delete_transient( 'bl_sprd_sellables_' . $shop_id );
                delete_transient( 'bl_sprd_product_types_' . $shop_id );
                $test_result = '<span style="color:#5cb85c;">✓ Connected! ' . intval( $count ) . ' sellable products found in shop.</span>';
            } elseif ( $code === 401 || $code === 403 ) {
                $test_result = '<span style="color:#ff6666;">✗ Authentication failed. Check your API key and secret.</span>';
            } elseif ( $code === 404 ) {
                $test_result = '<span style="color:#ff6666;">✗ Shop not found. Check your Shop ID.</span>';
            } else {
                $msg = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . intval( $code );
                $test_result = '<span style="color:#ff6666;">✗ Error: ' . esc_html( $msg ) . '. Raw: ' . esc_html( is_wp_error($response) ? '' : substr(wp_remote_retrieve_body($response),0,200) ) . '</span>';
            }
        }
    }
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Shop Settings' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-shop-settings' ); ?>

        <form method="post" style="max-width:700px;">
            <?php wp_nonce_field( 'bl_shop_settings' ); ?>

            <!-- Spreadshirt Credentials -->
            <div class="bl-settings-card">
                <div class="bl-settings-card__head">
                    <h2>Spreadshirt API Credentials</h2>
                </div>
                <div class="bl-settings-card__body" style="display:flex;flex-direction:column;gap:20px;">

                    <div class="bl-info-box">
                        <strong>Where to find your keys:</strong> Log in at
                        <a href="https://partner.spreadshirt.com/apiKey" target="_blank" rel="noopener" style="color:var(--bl-red,#e63946);">partner.spreadshirt.com/apiKey</a>
                        — you'll see both an <strong>API Key</strong> and a <strong>Secret Key</strong>. Enter both below.
                        Your Shop ID is <code style="background:var(--bl-s3);padding:2px 6px;border-radius:4px;">1170219</code> — already filled in.
                    </div>

                    <div>
                        <label class="bl-form-label">API Key</label>
                        <input class="bl-search-input"
                               style="width:100%;box-sizing:border-box;font-family:monospace;"
                               type="text"
                               name="spreadshirt_api_key"
                               placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                               value="<?php echo esc_attr( get_option( 'bl_spreadshirt_api_key', '' ) ); ?>">
                    </div>

                    <div>
                        <label class="bl-form-label">Secret Key</label>
                        <input class="bl-search-input"
                               style="width:100%;box-sizing:border-box;font-family:monospace;"
                               type="text"
                               name="spreadshirt_secret_key"
                               placeholder="Your Spreadshirt secret key"
                               value="<?php echo esc_attr( get_option( 'bl_spreadshirt_secret_key', '' ) ); ?>">
                        <p style="font-size:11px;color:var(--bl-g1,#888);margin-top:6px;">
                            Both keys are stored as WordPress options and never exposed on the front end.
                        </p>
                    </div>

                    <div>
                        <label class="bl-form-label">Shop ID</label>
                        <input class="bl-search-input"
                               style="width:240px;box-sizing:border-box;"
                               type="text"
                               name="spreadshirt_shop_id"
                               value="<?php echo esc_attr( get_option( 'bl_spreadshirt_shop_id', '1170219' ) ); ?>">
                    </div>

                    <div>
                        <label class="bl-form-label">Shop URL</label>
                        <input class="bl-search-input"
                               style="width:100%;box-sizing:border-box;"
                               type="url"
                               name="spreadshirt_shop_url"
                               placeholder="https://blusiastmerch.myspreadshop.com"
                               value="<?php echo esc_attr( get_option( 'bl_spreadshirt_shop_url', 'https://blusiastmerch.myspreadshop.com' ) ); ?>">
                        <p style="font-size:11px;color:var(--bl-g1,#888);margin-top:6px;">
                            Your Spreadshop URL — product links and the "View Full Store" button will point here.
                        </p>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button type="submit" name="bl_shop_save" class="bl-btn-sm" style="background:var(--bl-red,#e63946);border-color:var(--bl-red,#e63946);color:#fff;">
                            Save Settings
                        </button>
                        <button type="submit" name="bl_shop_test" class="bl-btn-sm">
                            Test Connection
                        </button>
                        <?php if ( $test_result ) : ?>
                        <span style="font-size:13px;"><?php echo $test_result; ?></span>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <!-- Cache control -->
            <div class="bl-settings-card" style="margin-top:20px;">
                <div class="bl-settings-card__head"><h2>Product Cache</h2></div>
                <div class="bl-settings-card__body">
                    <p style="font-size:13px;color:var(--bl-g2,#aaa);margin-bottom:16px;">
                        Products are cached for <strong>6 hours</strong> to keep the shop fast without hammering the Spreadshirt API.
                        If you've added new products to Spreadshirt and want them to show up immediately, clear the cache below.
                    </p>
                    <?php
                    if ( isset( $_POST['bl_shop_clear_cache'] ) && check_admin_referer( 'bl_shop_settings' ) ) {
                        delete_transient( 'bl_spreadshirt_products_' . get_option( 'bl_spreadshirt_shop_id', '1170219' ) );
                        echo '<div class="notice notice-success" style="margin-bottom:12px;"><p>Cache cleared. Products will be re-fetched on the next shop page load.</p></div>';
                    }
                    ?>
                    <button type="submit" name="bl_shop_clear_cache" class="bl-btn-sm">
                        🗑 Clear Product Cache
                    </button>
                </div>
            </div>

        </form>
    </div>
    <?php
}
