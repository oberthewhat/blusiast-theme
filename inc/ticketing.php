<?php
/**
 * Blusiast — Native Stripe Ticketing System (No-Composer Edition)
 * inc/ticketing.php
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  NO COMPOSER REQUIRED                                   │
 * │  Uses WordPress's built-in wp_remote_post() to talk    │
 * │  directly to the Stripe API. Just drop this file in    │
 * │  /inc/ and add one require line to functions.php.      │
 * ├─────────────────────────────────────────────────────────┤
 * │  SETUP                                                  │
 * │                                                         │
 * │  1. Upload this file to:                                │
 * │       wp-content/themes/blusiast/inc/ticketing.php     │
 * │                                                         │
 * │  2. In functions.php, in the "LOAD ADDITIONAL INC      │
 * │     FILES" block, add ONE line at the end:             │
 * │       require_once BLUSIAST_DIR . '/inc/ticketing.php';│
 * │     (No autoload line needed — no Composer required)   │
 * │                                                         │
 * │  3. Go to WP Admin → Blusiast CMS → Stripe Settings   │
 * │     and paste your Stripe keys.                        │
 * │                                                         │
 * │  4. In Stripe Dashboard → Developers → Webhooks:       │
 * │     Add endpoint:                                       │
 * │       https://yoursite.com/wp-json/blusiast/v1/stripe-webhook │
 * │     Event: checkout.session.completed                  │
 * │                                                         │
 * │  5. Create a WP page with slug "event-checkin" and     │
 * │     add shortcode: [blusiast_checkin]                  │
 * │                                                         │
 * │  6. On each bl_event post, set the ACF field           │
 * │     "ticket_price_cents" (e.g. 2500 = $25.00).        │
 * │     Leave 0 or empty for free events.                  │
 * └─────────────────────────────────────────────────────────┘
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BL_TICKET_VERSION', '1.3' );


// ═══════════════════════════════════════════════════════════
// 0.  HELPERS
// ═══════════════════════════════════════════════════════════

function blusiast_stripe_secret_key() {
    return get_option( 'blusiast_stripe_secret_key', '' );
}

function blusiast_stripe_webhook_secret() {
    return get_option( 'blusiast_stripe_webhook_secret', '' );
}

function blusiast_ticket_log( $msg ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[BL-Ticket] ' . $msg );
    }
}

/**
 * Make an authenticated request to the Stripe API.
 * Replaces the Stripe PHP SDK entirely.
 *
 * @param  string $method   GET | POST
 * @param  string $endpoint e.g. '/v1/checkout/sessions'
 * @param  array  $body     Key-value pairs (will be form-encoded)
 * @return array|WP_Error   Decoded JSON array or WP_Error
 */
function blusiast_stripe_request( $method, $endpoint, $body = [] ) {
    $secret = blusiast_stripe_secret_key();
    if ( ! $secret ) {
        return new WP_Error( 'no_key', 'Stripe secret key not configured.' );
    }

    $args = [
        'method'  => strtoupper( $method ),
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode( $secret . ':' ),
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Stripe-Version'=> '2024-04-10',
        ],
    ];

    if ( ! empty( $body ) ) {
        // Stripe expects nested arrays as bracket notation: metadata[key]=value
        $args['body'] = blusiast_stripe_encode( $body );
    }

    $response = wp_remote_request( 'https://api.stripe.com' . $endpoint, $args );

    if ( is_wp_error( $response ) ) {
        blusiast_ticket_log( 'Stripe HTTP error: ' . $response->get_error_message() );
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $json = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code >= 400 ) {
        $msg = $json['error']['message'] ?? 'Stripe API error (HTTP ' . $code . ')';
        blusiast_ticket_log( 'Stripe API error: ' . $msg );
        return new WP_Error( 'stripe_error', $msg );
    }

    return $json;
}

/**
 * Recursively encode a nested array into Stripe's bracket notation.
 * e.g. ['metadata' => ['foo' => 'bar']] → 'metadata[foo]=bar'
 */
function blusiast_stripe_encode( $data, $prefix = '' ) {
    $parts = [];
    foreach ( $data as $key => $value ) {
        $full_key = $prefix ? $prefix . '[' . $key . ']' : $key;
        if ( is_array( $value ) ) {
            $parts[] = blusiast_stripe_encode( $value, $full_key );
        } else {
            $parts[] = urlencode( $full_key ) . '=' . urlencode( $value );
        }
    }
    return implode( '&', $parts );
}

/**
 * Verify a Stripe webhook signature without the SDK.
 * Stripe signs payloads with HMAC-SHA256.
 *
 * @param  string $payload    Raw request body
 * @param  string $sig_header Value of Stripe-Signature header
 * @param  string $secret     Webhook signing secret (whsec_...)
 * @return bool
 */
function blusiast_stripe_verify_webhook( $payload, $sig_header, $secret ) {
    if ( ! $sig_header || ! $secret ) return false;

    // Parse the header: t=timestamp,v1=signature
    $parts = [];
    foreach ( explode( ',', $sig_header ) as $part ) {
        [ $k, $v ] = array_pad( explode( '=', $part, 2 ), 2, '' );
        $parts[ trim( $k ) ] = trim( $v );
    }

    if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) return false;

    // Reject timestamps older than 5 minutes (replay attack protection)
    if ( abs( time() - (int) $parts['t'] ) > 300 ) {
        blusiast_ticket_log( 'Webhook: timestamp too old.' );
        return false;
    }

    $signed_payload = $parts['t'] . '.' . $payload;
    $expected       = hash_hmac( 'sha256', $signed_payload, $secret );

    return hash_equals( $expected, $parts['v1'] );
}


// ═══════════════════════════════════════════════════════════
// 1.  DB MIGRATION
//     Adds Stripe + check-in columns to bl_event_registrations
//     without touching any existing data.
// ═══════════════════════════════════════════════════════════

add_action( 'after_switch_theme', 'blusiast_ticket_db_migrate' );
add_action( 'init',               'blusiast_ticket_db_migrate' );

function blusiast_ticket_db_migrate() {
    if ( get_option( 'blusiast_ticket_db_version' ) === BL_TICKET_VERSION ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'bl_event_registrations';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) return;

    $cols = $wpdb->get_col( "DESCRIBE $table", 0 );

    if ( ! in_array( 'stripe_session_id', $cols ) )
        $wpdb->query( "ALTER TABLE $table ADD COLUMN stripe_session_id VARCHAR(200) NOT NULL DEFAULT '' AFTER notes" );

    if ( ! in_array( 'stripe_payment_intent', $cols ) )
        $wpdb->query( "ALTER TABLE $table ADD COLUMN stripe_payment_intent VARCHAR(200) NOT NULL DEFAULT '' AFTER stripe_session_id" );

    if ( ! in_array( 'checked_in_at', $cols ) )
        $wpdb->query( "ALTER TABLE $table ADD COLUMN checked_in_at DATETIME NULL DEFAULT NULL AFTER stripe_payment_intent" );

    if ( ! in_array( 'conduct_agreed_at', $cols ) )
        $wpdb->query( "ALTER TABLE $table ADD COLUMN conduct_agreed_at DATETIME NULL DEFAULT NULL AFTER checked_in_at" );

    if ( ! in_array( 'ticket_type', $cols ) )
        $wpdb->query( "ALTER TABLE $table ADD COLUMN ticket_type VARCHAR(20) NOT NULL DEFAULT 'passholder' AFTER conduct_agreed_at" );

    update_option( 'blusiast_ticket_db_version', BL_TICKET_VERSION );
    blusiast_ticket_log( 'DB migration v' . BL_TICKET_VERSION . ' complete.' );
}


// ═══════════════════════════════════════════════════════════
// 2.  REST ENDPOINT — CREATE STRIPE CHECKOUT SESSION
//
//     POST /wp-json/blusiast/v1/buy-ticket
//     Body: { event_id: 42 }
//     Requires: logged-in member
// ═══════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
    register_rest_route( 'blusiast/v1', '/buy-ticket', [
        'methods'             => 'POST',
        'callback'            => 'blusiast_rest_buy_ticket',
        'permission_callback' => 'is_user_logged_in',
    ] );
} );

function blusiast_rest_buy_ticket( WP_REST_Request $req ) {

    // ── Get member ──
    $member = blusiast_get_current_member();
    if ( ! $member ) {
        return new WP_REST_Response( [ 'error' => 'Member account not found. Please complete your profile first.' ], 404 );
    }

    $event_id = absint( $req->get_param( 'event_id' ) );
    if ( ! $event_id ) {
        return new WP_REST_Response( [ 'error' => 'Missing event.' ], 400 );
    }

    $event = get_post( $event_id );
    if ( ! $event || $event->post_type !== 'bl_event' || $event->post_status !== 'publish' ) {
        return new WP_REST_Response( [ 'error' => 'Event not found.' ], 404 );
    }

    global $wpdb;
    $reg_table = $wpdb->prefix . 'bl_event_registrations';

    // ── Require conduct agreement ──
    if ( ! $req->get_param( 'conduct_agreed' ) ) {
        return new WP_REST_Response( [ 'error' => 'You must agree to the Code of Conduct.' ], 422 );
    }

    // ── Determine ticket type and price ──
    $raw_ticket_type = sanitize_key( $req->get_param( 'ticket_type' ) ?? 'passholder' );
    $ticket_type     = in_array( $raw_ticket_type, [ 'passholder', 'nonpassholder' ], true )
                       ? $raw_ticket_type
                       : 'passholder';

    $is_passholder = ( $ticket_type === 'passholder' );

    // Check whether this event uses tiered pricing (Stripe Price IDs set)
    $ph_price_id  = trim( get_post_meta( $event_id, 'passholder_stripe_price_id',     true ) );
    $nph_price_id = trim( get_post_meta( $event_id, 'nonpassholder_stripe_price_id',  true ) );
    $use_price_ids = $ph_price_id && $nph_price_id;

    // Cent amounts (used when no Price IDs set, or as display fallback)
    $ph_cents  = (int) get_post_meta( $event_id, 'passholder_price_cents',    true );
    $nph_cents = (int) get_post_meta( $event_id, 'nonpassholder_price_cents', true );

    // Legacy single-price fallback
    $legacy_cents = (int) get_post_meta( $event_id, 'ticket_price_cents', true );

    $price_cents = $is_passholder
        ? ( $ph_cents  ?: $legacy_cents )
        : ( $nph_cents ?: $legacy_cents );

    $stripe_price_id = $use_price_ids
        ? ( $is_passholder ? $ph_price_id : $nph_price_id )
        : null;

    $event_title = get_the_title( $event_id );
    $event_date  = get_post_meta( $event_id, 'event_date', true );
    $fmt_date    = $event_date ? date( 'F j, Y', strtotime( $event_date ) ) : '';
    $member_num  = blusiast_get_member_number( $member->id );

    // ── Per-tier quantities from request ──
    $ph_qty  = max( 0, (int) ( $req->get_param( 'ph_quantity'  ) ?? 0 ) );
    $nph_qty = max( 0, (int) ( $req->get_param( 'nph_quantity' ) ?? 0 ) );

    // Fallback: if new params not sent, use legacy quantity + ticket_type
    if ( $ph_qty === 0 && $nph_qty === 0 ) {
        $legacy_qty = max( 1, (int) ( $req->get_param( 'quantity' ) ?? 1 ) );
        if ( $is_passholder ) $ph_qty  = $legacy_qty;
        else                  $nph_qty = $legacy_qty;
    }

    $total_qty = $ph_qty + $nph_qty;

    if ( $total_qty === 0 ) {
        return new WP_REST_Response( [ 'error' => 'Please select at least one ticket.' ], 422 );
    }

    // ── Free event: skip Stripe ──
    if ( $price_cents <= 0 && ! $stripe_price_id ) {
        return blusiast_ticket_register_free( $member, $event_id, $ticket_type, $total_qty );
    }

    // ── Build Stripe Checkout Session via REST ──
    $success_url = add_query_arg( [ 'bl_ticket' => 'success', 'event_id' => $event_id ], get_permalink( $event_id ) );
    $cancel_url  = add_query_arg( 'bl_ticket', 'cancelled', get_permalink( $event_id ) );

    $event_date_base = $fmt_date ? "Blusiast event — {$fmt_date}" : 'Blusiast event ticket';

    // Build line items array dynamically based on what was ordered
    $line_items = [];

    if ( $ph_qty > 0 ) {
        if ( $ph_price_id ) {
            $line_items[] = [ 'price' => $ph_price_id, 'quantity' => $ph_qty ];
        } else {
            $line_items[] = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $ph_cents,
                    'product_data' => [
                        'name'        => "{$event_title} — Member Access · Pass Holder",
                        'description' => $event_date_base . ' · Season pass or valid Six Flags park ticket required. Park admission not included.',
                    ],
                ],
                'quantity' => $ph_qty,
            ];
        }
        // Pass Holder service fee: $3 each
        $line_items[] = [
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => 300,
                'product_data' => [ 'name' => 'Service Fee (Pass Holder)', 'description' => 'Per-ticket service fee' ],
            ],
            'quantity' => $ph_qty,
        ];
    }

    if ( $nph_qty > 0 ) {
        if ( $nph_price_id ) {
            $line_items[] = [ 'price' => $nph_price_id, 'quantity' => $nph_qty ];
        } else {
            $line_items[] = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $nph_cents,
                    'product_data' => [
                        'name'        => "{$event_title} — Member Access · Full Admission",
                        'description' => $event_date_base . ' · Includes Six Flags Magic Mountain park admission.',
                    ],
                ],
                'quantity' => $nph_qty,
            ];
        }
        // Full Admission service fee: $4 each
        $line_items[] = [
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => 400,
                'product_data' => [ 'name' => 'Service Fee (Full Admission)', 'description' => 'Per-ticket service fee' ],
            ],
            'quantity' => $nph_qty,
        ];
    }

    $session = blusiast_stripe_request( 'POST', '/v1/checkout/sessions', [
        'mode'                 => 'payment',
        'payment_method_types' => [ 'card', 'zip' ],
        'customer_email'       => $member->email,
        'client_reference_id'  => $member_num,
        'line_items'           => $line_items,
        'metadata'             => [
            'bl_event_id'    => $event_id,
            'bl_member_id'   => $member->id,
            'bl_member_num'  => $member_num,
            'wp_user_id'     => get_current_user_id(),
            'bl_ticket_type' => $ph_qty > 0 ? 'mixed' : 'nonpassholder',
            'bl_ph_qty'      => $ph_qty,
            'bl_nph_qty'     => $nph_qty,
            'bl_first_name'      => $member->first_name,
            'bl_last_name'       => $member->last_name,
            'bl_email'           => $member->email,
            'bl_phone'           => $member->phone ?? '',
            'bl_zip'             => $member->zip ?? '',
            'bl_conduct_agreed'  => blusiast_eastern_now(),
            'bl_quantity'        => $total_qty,
            'bl_add_to_existing' => $req->get_param( 'add_to_existing' ) ? '1' : '0',
        ],
        'success_url' => $success_url,
        'cancel_url'  => $cancel_url,
        'expires_at'  => time() + ( 30 * 60 ),
    ] );

    if ( is_wp_error( $session ) ) {
        return new WP_REST_Response( [ 'error' => $session->get_error_message() ], 502 );
    }

    // ── No pending row written here ──
    // Registration is only inserted by the Stripe webhook (checkout.session.completed)
    // once payment is confirmed. This prevents abandoned checkouts from appearing
    // in the CRM as pending registrations.

    return new WP_REST_Response( [ 'url' => $session['url'] ], 200 );
}


// ═══════════════════════════════════════════════════════════
// 2b. FREE EVENT REGISTRATION
// ═══════════════════════════════════════════════════════════

function blusiast_ticket_register_free( $member, $event_id, $ticket_type = 'passholder', $quantity = 1 ) {
    global $wpdb;
    $reg_table = $wpdb->prefix . 'bl_event_registrations';

    $wpdb->insert( $reg_table, [
        'event_id'    => $event_id,
        'first_name'  => $member->first_name,
        'last_name'   => $member->last_name,
        'email'       => $member->email,
        'phone'       => $member->phone ?? '',
        'guest_count' => max( 1, (int) $quantity ),
        'zip'         => $member->zip ?? '',
        'status'      => 'confirmed',
        'notes'       => 'Free event — auto-confirmed',
        'ticket_type' => $ticket_type,
        'wp_user_id'  => get_current_user_id(),
        'conduct_agreed_at' => blusiast_eastern_now(),
        'created_at'  => blusiast_eastern_now(),
    ], [ '%d','%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%s','%s' ] );

    blusiast_ticket_send_confirmation( $member, $event_id );

    return new WP_REST_Response( [ 'status' => 'confirmed', 'free' => true ], 200 );
}


// ═══════════════════════════════════════════════════════════
// 3.  STRIPE WEBHOOK
//
//     POST /wp-json/blusiast/v1/stripe-webhook
//     Verifies signature, confirms registration, sends email.
// ═══════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
    register_rest_route( 'blusiast/v1', '/stripe-webhook', [
        'methods'             => 'POST',
        'callback'            => 'blusiast_rest_stripe_webhook',
        'permission_callback' => '__return_true',
    ] );
} );

function blusiast_rest_stripe_webhook( WP_REST_Request $req ) {

    $payload    = $req->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $secret     = blusiast_stripe_webhook_secret();

    // ── Verify signature ──
    if ( ! blusiast_stripe_verify_webhook( $payload, $sig_header, $secret ) ) {
        blusiast_ticket_log( 'Webhook: signature verification failed.' );
        return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
    }

    $event = json_decode( $payload, true );
    blusiast_ticket_log( 'Webhook received: ' . ( $event['type'] ?? 'unknown' ) );

    if ( ( $event['type'] ?? '' ) !== 'checkout.session.completed' ) {
        return new WP_REST_Response( [ 'ok' => true, 'note' => 'Ignored.' ], 200 );
    }

    $session    = $event['data']['object'] ?? [];
    $session_id = $session['id'] ?? '';
    $intent_id  = $session['payment_intent'] ?? '';
    $meta       = $session['metadata'] ?? [];

    global $wpdb;
    $reg_table = $wpdb->prefix . 'bl_event_registrations';
    $mem_table = $wpdb->prefix . 'bl_members';

    // ── Pull registration data from Stripe metadata ──
    $event_id    = (int) ( $meta['bl_event_id']   ?? 0 );
    $wp_user_id  = (int) ( $meta['wp_user_id']    ?? 0 );
    $ticket_type = sanitize_key( $meta['bl_ticket_type'] ?? 'passholder' );

    if ( ! $event_id || ! $wp_user_id ) {
        blusiast_ticket_log( "Webhook: missing metadata in session {$session_id}" );
        return new WP_REST_Response( [ 'error' => 'Missing metadata.' ], 400 );
    }

    // ── Guard: don't double-insert if webhook fires twice ──
    $already = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $reg_table WHERE stripe_session_id = %s LIMIT 1",
        $session_id
    ) );

    if ( $already ) {
        blusiast_ticket_log( "Webhook: registration already exists for session {$session_id} — skipping." );
        return new WP_REST_Response( [ 'ok' => true, 'note' => 'Already confirmed.' ], 200 );
    }

    $additional_qty   = max( 1, (int) ( $meta['bl_quantity'] ?? 1 ) );
    $add_to_existing  = ! empty( $meta['bl_add_to_existing'] ) && $meta['bl_add_to_existing'] === '1';

    // ── If this is an add-on purchase, update the existing confirmed row ──
    if ( $add_to_existing ) {
        $existing_reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, guest_count FROM $reg_table
             WHERE event_id = %d AND wp_user_id = %d AND status = 'confirmed'
             LIMIT 1",
            $event_id, $wp_user_id
        ) );

        if ( $existing_reg ) {
            $new_count = (int) $existing_reg->guest_count + $additional_qty;
            $wpdb->update(
                $reg_table,
                [
                    'guest_count'           => $new_count,
                    'stripe_session_id'     => $session_id,   // record latest Stripe session
                    'stripe_payment_intent' => $intent_id,
                    'notes'                 => 'Additional tickets added via Stripe — ' . $session_id,
                ],
                [ 'id' => $existing_reg->id ],
                [ '%d', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            blusiast_ticket_log( "Webhook: updated guest_count to {$new_count} for reg #{$existing_reg->id} (event {$event_id})" );

            $member = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}bl_members WHERE wp_user_id = %d LIMIT 1",
                $wp_user_id
            ) );
            if ( $member ) {
                blusiast_ticket_send_confirmation( $member, $event_id );
            }

            return new WP_REST_Response( [ 'ok' => true, 'note' => 'Guest count updated.' ], 200 );
        }
        // If no existing reg found, fall through and create a normal one
    }

    // ── Clean up any orphaned pending rows for this user/event (edge cases) ──
    $wpdb->delete( $reg_table, [
        'event_id'   => $event_id,
        'wp_user_id' => $wp_user_id,
        'status'     => 'pending',
    ], [ '%d', '%d', '%s' ] );

    // ── Insert confirmed registration directly — no pending state ──
    $wpdb->insert( $reg_table, [
        'event_id'              => $event_id,
        'wp_user_id'            => $wp_user_id,
        'first_name'            => sanitize_text_field( $meta['bl_first_name'] ?? '' ),
        'last_name'             => sanitize_text_field( $meta['bl_last_name']  ?? '' ),
        'email'                 => sanitize_email( $meta['bl_email'] ?? '' ),
        'phone'                 => sanitize_text_field( $meta['bl_phone'] ?? '' ),
        'zip'                   => sanitize_text_field( $meta['bl_zip']   ?? '' ),
        'guest_count'           => max( 1, (int) $additional_qty ),
        'ticket_type'           => sanitize_key( $meta['bl_ticket_type'] ?? 'passholder' ),
        'status'                => 'confirmed',
        'ticket_type'           => $ticket_type,
        'stripe_session_id'     => $session_id,
        'stripe_payment_intent' => $intent_id,
        'conduct_agreed_at'     => sanitize_text_field( $meta['bl_conduct_agreed'] ?? blusiast_eastern_now() ),
        'notes'                 => 'Stripe payment confirmed — ' . $session_id,
        'created_at'            => blusiast_eastern_now(),
    ], [ '%d','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s' ] );

    $reg_id = $wpdb->insert_id;
    blusiast_ticket_log( "Webhook: inserted confirmed registration #{$reg_id} for event {$event_id}" );

    // ── Load the full reg row so the email function has what it needs ──
    $reg = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $reg_table WHERE id = %d LIMIT 1",
        $reg_id
    ) );

    // ── Send confirmation email ──
    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $mem_table WHERE wp_user_id = %d LIMIT 1",
        $reg->wp_user_id
    ) );

    if ( $member ) {
        blusiast_ticket_send_confirmation( $member, $reg->event_id );
    }

    return new WP_REST_Response( [ 'ok' => true ], 200 );
}


// ═══════════════════════════════════════════════════════════
// 3b. CONFIRMATION EMAIL
// ═══════════════════════════════════════════════════════════

function blusiast_ticket_send_confirmation( $member, $event_id ) {
    $event_title = get_the_title( $event_id );
    $event_date  = get_post_meta( $event_id, 'event_date',     true );
    $event_loc   = get_post_meta( $event_id, 'event_location', true );
    $fmt_date    = $event_date ? date( 'F j, Y', strtotime( $event_date ) ) : '';
    $member_num  = blusiast_get_member_number( $member->id );
    $portal_url  = function_exists( 'blusiast_portal_url' ) ? blusiast_portal_url() : home_url( '/member-portal' );

    $subject = "✅ You're in! Ticket confirmed for {$event_title}";
    $body    = "Hey {$member->first_name},\n\n"
             . "Your ticket is CONFIRMED. See you there!\n\n"
             . "─────────────────────────\n"
             . strtoupper( $event_title ) . "\n"
             . ( $fmt_date  ? "📅  {$fmt_date}\n"  : '' )
             . ( $event_loc ? "📍  {$event_loc}\n" : '' )
             . "─────────────────────────\n\n"
             . "YOUR MEMBER NUMBER: {$member_num}\n\n"
             . "At the door, a Blusiast crew member will scan the QR\n"
             . "code on your Member ID card. That IS your ticket —\n"
             . "no need to print anything extra.\n\n"
             . "Your ID card lives in your member portal:\n"
             . "👉  {$portal_url}\n\n"
             . "Log in, scroll to Member ID, and have the QR ready\n"
             . "on your phone when you arrive.\n\n"
             . "Ride on,\n"
             . "The Blusiast Crew\n";

    wp_mail( $member->email, $subject, $body, [
        'From: Blusiast <' . get_option( 'admin_email' ) . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ] );

    wp_mail(
        get_option( 'admin_email' ),
        "🎟 New ticket: {$event_title} — {$member->first_name} {$member->last_name}",
        "Member:  {$member->first_name} {$member->last_name} ({$member_num})\n"
        . "Email:   {$member->email}\n"
        . "Event:   {$event_title}\n"
        . ( $fmt_date ? "Date:    {$fmt_date}\n" : '' )
        . "\nView: " . admin_url( 'admin.php?page=blusiast-checkin&event_id=' . $event_id )
    );
}


// ═══════════════════════════════════════════════════════════
// 4.  REST ENDPOINT — QR DOOR SCAN
//
//     POST /wp-json/blusiast/v1/door-scan
//     Body: { member_number: "BLS-000042", event_id: 42 }
//     Requires: manage_options capability
// ═══════════════════════════════════════════════════════════

add_action( 'rest_api_init', function () {
    register_rest_route( 'blusiast/v1', '/door-scan', [
        'methods'             => 'POST',
        'callback'            => 'blusiast_rest_door_scan',
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ] );
} );

function blusiast_rest_door_scan( WP_REST_Request $req ) {
    $member_number = strtoupper( sanitize_text_field( $req->get_param( 'member_number' ) ?? '' ) );
    $event_id      = absint( $req->get_param( 'event_id' ) ?? 0 );

    if ( ! $member_number || ! $event_id ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Missing member number or event.' ], 400 );
    }

    global $wpdb;
    $mem_table = $wpdb->prefix . 'bl_members';
    $reg_table = $wpdb->prefix . 'bl_event_registrations';

    // Look up member by their stored member_number
    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $mem_table WHERE member_number = %s LIMIT 1", $member_number
    ) );

    if ( ! $member ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Member not found.' ], 404 );
    }

    // ── Find confirmed ticket ──
    $reg = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $reg_table
         WHERE event_id = %d AND wp_user_id = %d AND status = 'confirmed'
         LIMIT 1",
        $event_id, $member->wp_user_id
    ) );

    if ( ! $reg ) {
        return new WP_REST_Response( [
            'ok'     => false,
            'error'  => 'No confirmed ticket for this member.',
            'member' => [
                'name'   => $member->first_name . ' ' . $member->last_name,
                'number' => blusiast_get_member_number( $member->id ),
            ],
        ], 403 );
    }

    $already = ! empty( $reg->checked_in_at );

    if ( ! $already ) {
        $wpdb->update(
            $reg_table,
            [ 'checked_in_at' => blusiast_eastern_now() ],
            [ 'id' => $reg->id ],
            [ '%s' ], [ '%d' ]
        );
    }

    blusiast_ticket_log( ( $already ? 'Re-scan' : 'Check-in' ) . ": {$member->first_name} {$member->last_name} ({$member_number}) event {$event_id}" );

    return new WP_REST_Response( [
        'ok'                 => true,
        'already_checked_in' => $already,
        'checked_in_at'      => $already ? $reg->checked_in_at : blusiast_eastern_now(),
        'member'             => [
            'name'        => $member->first_name . ' ' . $member->last_name,
            'number'      => blusiast_get_member_number( $member->id ),
            'email'       => $member->email,
            'status'      => $member->account_status,
            'guest_count' => (int) $reg->guest_count,
        ],
    ], 200 );
}


// ═══════════════════════════════════════════════════════════
// 5.  STAFF CHECK-IN PAGE  [blusiast_checkin]
//
//     Mobile-first camera QR scanner.
//     Add shortcode [blusiast_checkin] to a WP page.
//     Only accessible to logged-in admins.
// ═══════════════════════════════════════════════════════════

add_shortcode( 'blusiast_checkin', 'blusiast_checkin_shortcode' );

function blusiast_checkin_shortcode() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '<p style="color:#cc0000;font-weight:700;font-family:monospace;">⛔ Access denied. Staff only.</p>';
    }

    $events = get_posts( [
        'post_type'      => 'bl_event',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_key'       => 'event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
    ] );

    $event_options = '';
    foreach ( $events as $ev ) {
        $raw   = get_post_meta( $ev->ID, 'event_date', true );
        $label = esc_html( $ev->post_title );
        if ( $raw ) $label .= ' — ' . blusiast_format_eastern( $raw, 'M j, Y' );
        $event_options .= '<option value="' . esc_attr( $ev->ID ) . '">' . $label . '</option>';
    }

    $scan_url  = esc_url( rest_url( 'blusiast/v1/door-scan' ) );
    $nonce_val = wp_create_nonce( 'wp_rest' );

    ob_start();
    ?>
    <div id="bl-checkin-app">

        <div class="blci-header">
            <div class="blci-logo">BLUSIAST</div>
            <div class="blci-title">Door Check-In</div>
            <a href="<?php echo esc_url( admin_url('admin.php?page=blusiast-checkin') ); ?>" class="blci-roster-link">📋 View Roster</a>
        </div>
        <style>
        .blci-roster-link {
            display: block;
            text-align: center;
            font-size: 12px;
            color: rgba(255,255,255,.65);
            text-decoration: none;
            padding: 6px 0 2px;
            letter-spacing: .08em;
        }
        .blci-roster-link:hover { color: #fff; }
        </style>

        <div class="blci-event-row">
            <label class="blci-label" for="bl-event-select">Select Event</label>
            <select id="bl-event-select" class="blci-select">
                <option value="">— choose event —</option>
                <?php echo $event_options; ?>
            </select>
        </div>

        <div id="bl-scanner-wrap" class="blci-scanner-wrap" hidden>

            <div class="blci-video-box">
                <video id="bl-qr-video" playsinline autoplay muted></video>
                <div class="blci-reticle">
                    <span class="blci-corner blci-tl"></span>
                    <span class="blci-corner blci-tr"></span>
                    <span class="blci-corner blci-bl"></span>
                    <span class="blci-corner blci-br"></span>
                    <span class="blci-scanline" id="blci-scanline"></span>
                </div>
                <div class="blci-hint">Hold QR code steady inside the frame</div>
            </div>

            <!-- Camera error message shown when getUserMedia fails (permission denied, no camera, etc.) -->
            <div id="bl-cam-status" style="display:none;"></div>

            <div class="blci-manual">
                <span class="blci-manual-label">Or type member number:</span>
                <div class="blci-manual-row">
                    <input type="text"
                           id="bl-manual-input"
                           class="blci-manual-input"
                           placeholder="BLS-A7X2K9QR"
                           autocapitalize="characters"
                           autocorrect="off"
                           spellcheck="false">
                    <button id="bl-manual-btn" class="blci-manual-btn">Go</button>
                </div>
            </div>
        </div>

        <div id="bl-result" class="blci-result" hidden>
            <div class="blci-result-icon" id="bl-result-icon"></div>
            <div class="blci-result-name" id="bl-result-name"></div>
            <div class="blci-result-num"  id="bl-result-num"></div>
            <div class="blci-result-meta" id="bl-result-meta"></div>
            <button class="blci-next-btn" id="bl-next-btn">Scan Next Person</button>
        </div>

        <div class="blci-tally" id="bl-tally" hidden>
            <span class="blci-tally-num" id="bl-tally-num">0</span>
            <span class="blci-tally-label">checked in</span>
        </div>

    </div>

    <style>
    #bl-checkin-app {
        font-family: 'Courier New', Courier, monospace;
        background: #0d0d0d;
        color: #fff;
        min-height: 100vh;
        max-width: 480px;
        margin: -20px auto 0;
        padding-bottom: 40px;
        box-sizing: border-box;
    }
    .blci-header {
        background: #cc0000;
        padding: 18px 20px 14px;
        text-align: center;
    }
    .blci-logo  { font-size: 10px; letter-spacing: .3em; opacity: .7; margin-bottom: 2px; }
    .blci-title { font-size: 20px; font-weight: 900; letter-spacing: .05em; text-transform: uppercase; }

    .blci-event-row { padding: 20px; border-bottom: 1px solid #1f1f1f; }
    .blci-label { display: block; font-size: 10px; letter-spacing: .15em; text-transform: uppercase; color: #555; margin-bottom: 8px; }
    .blci-select {
        width: 100%; background: #1a1a1a; color: #fff; border: 1px solid #333;
        border-radius: 6px; padding: 12px 14px; font-family: inherit; font-size: 14px;
        cursor: pointer; appearance: none; -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23cc0000'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 14px center;
    }

    .blci-scanner-wrap { padding: 20px; }
    .blci-video-box {
        position: relative; width: 100%; aspect-ratio: 1; background: #111;
        border-radius: 12px; overflow: hidden; border: 1px solid #222;
    }
    #bl-qr-video { width: 100%; height: 100%; object-fit: cover; display: block; }

    .blci-reticle { position: absolute; inset: 0; }
    .blci-reticle::before {
        content: ''; position: absolute;
        top: 20%; left: 20%; right: 20%; bottom: 20%;
        box-shadow: 0 0 0 9999px rgba(0,0,0,.55);
        border-radius: 4px;
    }
    .blci-corner {
        position: absolute; width: 22px; height: 22px;
        border-color: #cc0000; border-style: solid;
    }
    .blci-tl { top: 20%; left: 20%; border-width: 3px 0 0 3px; border-radius: 3px 0 0 0; }
    .blci-tr { top: 20%; right: 20%; border-width: 3px 3px 0 0; border-radius: 0 3px 0 0; }
    .blci-bl { bottom: 20%; left: 20%; border-width: 0 0 3px 3px; border-radius: 0 0 0 3px; }
    .blci-br { bottom: 20%; right: 20%; border-width: 0 3px 3px 0; border-radius: 0 0 3px 0; }

    .blci-scanline {
        position: absolute; left: 20%; right: 20%; height: 2px;
        background: linear-gradient(90deg, transparent, #cc0000, transparent);
        animation: blci-scan 2s linear infinite;
    }
    @keyframes blci-scan {
        0%   { top: 20%; opacity: 1; }
        90%  { top: 80%; opacity: 1; }
        100% { top: 20%; opacity: 0; }
    }
    .blci-hint {
        position: absolute; bottom: 14px; left: 0; right: 0;
        text-align: center; font-size: 11px; color: rgba(255,255,255,.45); letter-spacing: .04em;
    }

    .blci-manual { margin-top: 16px; padding-top: 16px; border-top: 1px solid #1a1a1a; }
    .blci-manual-label { display: block; font-size: 11px; color: #444; margin-bottom: 8px; }
    .blci-manual-row { display: flex; gap: 8px; }
    .blci-manual-input {
        flex: 1; background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 6px;
        color: #fff; padding: 11px 14px; font-family: inherit; font-size: 14px;
        letter-spacing: .08em; text-transform: uppercase;
    }
    .blci-manual-input:focus { outline: none; border-color: #cc0000; }
    .blci-manual-btn {
        background: #cc0000; color: #fff; border: none; border-radius: 6px;
        padding: 11px 20px; font-family: inherit; font-weight: 900; font-size: 13px;
        letter-spacing: .05em; cursor: pointer;
    }
    .blci-manual-btn:active { background: #aa0000; }

    .blci-result {
        margin: 0 20px; padding: 28px 24px; border-radius: 12px;
        text-align: center; animation: blci-pop .25s ease;
    }
    @keyframes blci-pop { from { transform: scale(.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .blci-result--ok     { background: rgba(92,184,92,.1);  border: 1px solid rgba(92,184,92,.35); }
    .blci-result--rescan { background: rgba(245,166,35,.1); border: 1px solid rgba(245,166,35,.35); }
    .blci-result--denied { background: rgba(204,0,0,.1);    border: 1px solid rgba(204,0,0,.35); }

    .blci-result-icon { font-size: 56px; line-height: 1; margin-bottom: 10px; }
    .blci-result-name { font-size: 22px; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
    .blci-result-num  { font-size: 12px; color: #cc0000; letter-spacing: .14em; margin-bottom: 10px; }
    .blci-result-meta { font-size: 13px; color: #999; line-height: 1.6; }
    .blci-next-btn {
        margin-top: 20px; background: transparent; border: 1px solid #2a2a2a;
        border-radius: 6px; color: #777; padding: 10px 28px;
        font-family: inherit; font-size: 12px; letter-spacing: .1em;
        text-transform: uppercase; cursor: pointer;
    }
    .blci-next-btn:hover { border-color: #cc0000; color: #fff; }

    .blci-tally {
        display: flex; align-items: center; gap: 14px;
        margin: 20px; padding: 14px 20px;
        background: #111; border: 1px solid #1a1a1a; border-radius: 8px;
    }
    .blci-tally-num   { font-size: 36px; font-weight: 900; color: #cc0000; line-height: 1; min-width: 48px; text-align: center; }
    .blci-tally-label { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: #444; }
    </style>

    <script>
    (function(){
        'use strict';

        const SCAN_URL = <?php echo json_encode( $scan_url ); ?>;
        const NONCE    = <?php echo json_encode( $nonce_val ); ?>;

        let selectedEvent = 0, scanning = false, count = 0, stream = null, raf = null;

        const $select     = document.getElementById('bl-event-select');
        const $wrap       = document.getElementById('bl-scanner-wrap');
        const $result     = document.getElementById('bl-result');
        const $icon       = document.getElementById('bl-result-icon');
        const $name       = document.getElementById('bl-result-name');
        const $num        = document.getElementById('bl-result-num');
        const $meta       = document.getElementById('bl-result-meta');
        const $next       = document.getElementById('bl-next-btn');
        const $video      = document.getElementById('bl-qr-video');
        const $manual     = document.getElementById('bl-manual-input');
        const $manualBtn  = document.getElementById('bl-manual-btn');
        const $tally      = document.getElementById('bl-tally');
        const $tallyNum   = document.getElementById('bl-tally-num');

        $select.addEventListener('change', function() {
            selectedEvent = parseInt(this.value) || 0;
            if (selectedEvent) { $wrap.hidden = false; $result.hidden = true; startCam(); }
            else               { $wrap.hidden = true;  stopCam(); }
        });

        function stopCam() {
            scanning = false;
            if (raf) { cancelAnimationFrame(raf); raf = null; }
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        }

        // QR detection — BarcodeDetector (Chrome/Android) with jsQR fallback
        // jsQR is pre-loaded immediately for non-BarcodeDetector browsers (Safari, Brave, Firefox)
        // so it's ready before the first scan frame rather than lazy-loading mid-scan.
        let detector = null, jsqrReady = false;
        if ('BarcodeDetector' in window) {
            try { detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch(e) {}
        }

        // Show a small debug line under the video so we can see what's happening
        const $dbg = document.createElement('div');
        $dbg.style.cssText = 'font-size:10px;color:#444;text-align:center;margin-top:6px;letter-spacing:.04em;min-height:14px;';
        document.getElementById('bl-scanner-wrap').appendChild($dbg);
        function dbg(msg) { $dbg.textContent = msg; }

        // Pre-load jsQR now for browsers without BarcodeDetector so the
        // library is ready before the first scan frame. Chrome skips this entirely.
        if (!detector) {
            dbg('Loading QR library…');
            loadScript(<?php echo json_encode( get_template_directory_uri() . '/assets/js/jsqr.js' ); ?>)
                .then(() => { jsqrReady = true; dbg('QR library ready — tap to scan'); })
                .catch(() => { dbg('QR library failed to load — try refreshing'); });
        }

        async function startCam() {
            if (stream) return;
            dbg('Starting camera…');
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
                });
                $video.srcObject = stream;
                await $video.play();
                scanning = true;
                dbg(detector ? 'BarcodeDetector ready' : 'jsQR mode — hold steady');
                loop();
            } catch(e) {
                // Issue 7 — user-friendly camera error messages across all browsers
                const $camStatus = document.getElementById('bl-cam-status');
                let msg;
                if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
                    msg = 'Camera permission denied. Tap the camera icon in your browser bar and select Allow, then refresh.';
                } else if (e.name === 'NotFoundError' || e.name === 'DevicesNotFoundError') {
                    msg = 'No camera found on this device. Use the manual entry field below.';
                } else if (e.name === 'NotReadableError' || e.name === 'TrackStartError') {
                    msg = 'Camera is in use by another app. Close it and refresh this page.';
                } else {
                    msg = 'Camera unavailable (' + e.message + '). Use manual entry below.';
                }
                if ($camStatus) {
                    $camStatus.textContent = msg;
                    $camStatus.style.cssText = 'display:block;color:#ff6666;font-size:13px;text-align:center;padding:12px;background:rgba(204,0,0,.1);border:1px solid rgba(204,0,0,.3);border-radius:8px;margin-top:8px;';
                }
                dbg('Camera error: ' + e.name);
            }
        }

        async function loop() {
            if (!scanning) return;
            if ($video.readyState >= 2 && $video.videoWidth > 0) {
                let val = null;
                try {
                    if (detector) {
                        const hits = await detector.detect($video);
                        if (hits.length) val = hits[0].rawValue;
                    } else {
                        // jsQR path — library already pre-loaded above, just wait if still in flight
                        if (!jsqrReady) {
                            // Still loading — skip this frame and try next
                            requestAnimationFrame(loop);
                            return;
                        }
                        const c = document.createElement('canvas');
                        c.width = $video.videoWidth; c.height = $video.videoHeight;
                        const cx = c.getContext('2d');
                        cx.drawImage($video, 0, 0);
                        const img = cx.getImageData(0, 0, c.width, c.height);
                        // attemptBoth handles inverted QR codes (white-on-dark like our ID card)
                        const qr = jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
                        if (qr) val = qr.data;
                    }
                } catch(e) { dbg('Scan error: ' + e.message); }

                if (val) {
                    dbg('Read: ' + val);
                    if (/^BLS-[A-Z0-9]+$/i.test(val)) {
                        scanning = false;
                        doScan(val.toUpperCase());
                        return;
                    } else {
                        dbg('Not a BLS code: ' + val);
                    }
                }
            }
            raf = requestAnimationFrame(loop);
        }

        function loadScript(src) {
            return new Promise((res, rej) => {
                const s = document.createElement('script');
                s.src = src; s.onload = res;
                s.onerror = () => rej(new Error('Failed to load ' + src));
                document.head.appendChild(s);
            });
        }

        $manualBtn.addEventListener('click', () => { const v = $manual.value.trim().toUpperCase(); if (v) doScan(v); });
        $manual.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $manualBtn.click(); } });

        $next.addEventListener('click', () => {
            $result.hidden = true;
            $result.className = 'blci-result';
            $manual.value = '';
            $wrap.hidden = false;
            scanning = true;
            loop();
        });

        async function doScan(memberNum) {
            showResult('⏳', memberNum, '', 'Checking…', '');
            $wrap.hidden = true;

            try {
                const r    = await fetch(SCAN_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body: JSON.stringify({ member_number: memberNum, event_id: selectedEvent }),
                });
                const data = await r.json();

                if (r.ok && data.ok) {
                    const m = data.member;
                    const g = m.guest_count > 1 ? ` · Party of ${m.guest_count}` : '';
                    if (data.already_checked_in) {
                        showResult('⚠️', m.name, m.number, `Already checked in at ${fmtTime(data.checked_in_at)}${g}`, 'rescan');
                    } else {
                        count++;
                        $tallyNum.textContent = count;
                        $tally.hidden = false;
                        showResult('✅', m.name, m.number, `Welcome!${g}`, 'ok');
                        if (navigator.vibrate) navigator.vibrate([80, 40, 80]);
                    }
                } else {
                    const nm = data.member ? data.member.name : memberNum;
                    showResult('❌', nm, memberNum, data.error || 'Not on the list.', 'denied');
                    if (navigator.vibrate) navigator.vibrate([300]);
                }
            } catch(e) {
                showResult('❌', 'Error', '', 'Network issue. Try manual entry.', 'denied');
            }
        }

        function showResult(icon, name, num, meta, type) {
            $icon.textContent = icon; $name.textContent = name;
            $num.textContent  = num;  $meta.textContent = meta;
            $result.className = 'blci-result' + (type ? ' blci-result--' + type : '');
            $result.hidden = false;
        }

        function fmtTime(dt) {
            if (!dt) return '';
            return new Date(dt.replace(' ','T')).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
        }

    })();
    </script>
    <?php
    return ob_get_clean();
}


// ═══════════════════════════════════════════════════════════
// 6.  ADMIN — EVENT CHECK-IN LIST
//     Blusiast CMS → Event Check-In
// ═══════════════════════════════════════════════════════════

add_action( 'admin_menu', 'blusiast_ticket_admin_menu' );

function blusiast_ticket_admin_menu() {
    add_submenu_page(
        'blusiast-cms',
        'Event Check-In',
        'Event Check-In',
        'manage_options',
        'blusiast-checkin',
        'blusiast_ticket_checkin_page'
    );
    // Stripe Settings registered via the central Settings hub in member-cms.php
}

add_action( 'admin_post_blusiast_manual_checkin', 'blusiast_admin_manual_checkin' );

function blusiast_admin_manual_checkin() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
    check_admin_referer( 'blusiast_manual_checkin' );

    $reg_id   = absint( $_POST['reg_id']         ?? 0 );
    $action   = sanitize_key( $_POST['checkin_action'] ?? 'checkin' );
    $event_id = absint( $_POST['event_id']        ?? 0 );

    if ( $reg_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bl_event_registrations';
        if ( $action === 'undo' ) {
            $wpdb->update( $table, [ 'checked_in_at' => null ],                       [ 'id' => $reg_id ], [ '%s' ], [ '%d' ] );
        } else {
            $wpdb->update( $table, [ 'checked_in_at' => blusiast_eastern_now() ], [ 'id' => $reg_id ], [ '%s' ], [ '%d' ] );
        }
    }

    wp_redirect( admin_url( 'admin.php?page=blusiast-checkin&event_id=' . $event_id ) );
    exit;
}

function blusiast_ticket_checkin_page() {
    global $wpdb;
    $reg_table = $wpdb->prefix . 'bl_event_registrations';
    $mem_table = $wpdb->prefix . 'bl_members';

    $events = get_posts( [
        'post_type'      => 'bl_event',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_key'       => 'event_date',
        'orderby'        => 'meta_value',
        'order'          => 'DESC',
    ] );

    $sel   = absint( $_GET['event_id'] ?? 0 );
    $regs  = [];
    $total = $in = 0;

    if ( $sel ) {
        $regs = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, m.id as member_db_id
             FROM $reg_table r
             LEFT JOIN $mem_table m ON m.wp_user_id = r.wp_user_id
             WHERE r.event_id = %d AND r.status != 'cancelled'
             ORDER BY r.last_name ASC, r.first_name ASC",
            $sel
        ) );
        $total = count( $regs );
        $in    = count( array_filter( $regs, fn($r) => ! empty( $r->checked_in_at ) ) );
    }
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:12px;">
            <span style="background:#cc0000;color:#fff;padding:3px 10px;border-radius:4px;font-size:13px;letter-spacing:.1em;">BLUSIAST</span>
            Event Check-In
        </h1>

        <form method="get" style="margin:16px 0 24px;">
            <input type="hidden" name="page" value="blusiast-checkin">
            <select name="event_id" onchange="this.form.submit()" style="min-width:320px;padding:8px 12px;font-size:14px;">
                <option value="">— Select an event —</option>
                <?php foreach ( $events as $ev ) :
                    $raw = get_post_meta( $ev->ID, 'event_date', true );
                    $lbl = esc_html( $ev->post_title ) . ( $raw ? ' — ' . blusiast_format_eastern( $raw, 'M j, Y' ) : '' );
                ?>
                    <option value="<?php echo $ev->ID; ?>" <?php selected( $sel, $ev->ID ); ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ( $sel ) : $not_in = $total - $in; $pct = $total ? round( $in / $total * 100 ) : 0; ?>

        <div style="display:flex;gap:14px;margin-bottom:24px;flex-wrap:wrap;">
            <?php foreach ( [
                [ 'Registered', $total,  '#888' ],
                [ 'Checked In', $in,     '#5cb85c' ],
                [ 'Not Yet',    $not_in, '#f5a623' ],
            ] as [$lbl,$val,$col] ) : ?>
                <div style="background:#1a1a1a;border:1px solid #222;border-radius:8px;padding:14px 22px;text-align:center;min-width:110px;">
                    <div style="font-size:30px;font-weight:900;color:<?php echo $col; ?>;font-family:'Courier New',monospace;"><?php echo $val; ?></div>
                    <div style="font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#444;margin-top:4px;"><?php echo $lbl; ?></div>
                </div>
            <?php endforeach; ?>

            <?php if ( $total ) : ?>
            <div style="flex:1;min-width:200px;background:#1a1a1a;border:1px solid #222;border-radius:8px;padding:14px 22px;display:flex;align-items:center;gap:16px;">
                <div style="flex:1;">
                    <div style="font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#444;margin-bottom:8px;">Arrival Progress</div>
                    <div style="background:#111;border-radius:999px;height:8px;overflow:hidden;">
                        <div style="background:#cc0000;height:100%;width:<?php echo $pct; ?>%;border-radius:999px;"></div>
                    </div>
                </div>
                <div style="font-size:26px;font-weight:900;color:#cc0000;font-family:'Courier New',monospace;"><?php echo $pct; ?>%</div>
            </div>
            <?php endif; ?>
        </div>

        <p style="margin-bottom:10px;">
            <a href="<?php echo esc_url( home_url( '/event-checkin' ) ); ?>" target="_blank"
               style="background:#cc0000;color:#fff;padding:8px 16px;border-radius:5px;text-decoration:none;font-size:13px;font-weight:700;">
                📱 Open Staff Door Scanner
            </a>
            &nbsp;
            <button onclick="location.reload()" style="padding:8px 16px;font-size:13px;cursor:pointer;border-radius:5px;border:1px solid #ccc;background:#fff;">
                ↻ Refresh
            </button>
        </p>

        <style>
            .bl-checkin-list { list-style:none; margin:0; padding:0; }
            .bl-checkin-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                background: #1a1a1a;
                border: 1px solid #2a2a2a;
                border-left: 4px solid #333;
                border-radius: 8px;
                padding: 14px 16px;
                margin-bottom: 10px;
                transition: border-color .2s;
            }
            .bl-checkin-card.is-checked-in {
                border-left-color: #5cb85c;
                background: #141f14;
            }
            .bl-checkin-card-info { flex: 1; min-width: 0; }
            .bl-checkin-name {
                font-size: 17px;
                font-weight: 800;
                color: #fff;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                margin-bottom: 4px;
            }
            .bl-checkin-meta {
                font-size: 13px;
                color: #888;
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .bl-checkin-group {
                background: #2a2a2a;
                color: #ccc;
                border-radius: 4px;
                padding: 2px 8px;
                font-weight: 700;
                font-size: 12px;
                white-space: nowrap;
            }
            .bl-checkin-status-badge {
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .06em;
                white-space: nowrap;
            }
            .bl-checkin-status-badge.in  { color: #5cb85c; }
            .bl-checkin-status-badge.out { color: #888; }
            .bl-checkin-card-action { flex-shrink: 0; }
            .bl-checkin-btn {
                display: block;
                min-width: 90px;
                padding: 10px 14px;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 800;
                text-align: center;
                cursor: pointer;
                letter-spacing: .04em;
                border: none;
                line-height: 1;
            }
            .bl-checkin-btn.do-checkin {
                background: #cc0000;
                color: #fff;
            }
            .bl-checkin-btn.do-undo {
                background: transparent;
                color: #555;
                border: 1px solid #333;
                font-weight: 600;
                font-size: 12px;
            }
            .bl-checkin-time {
                display: block;
                font-size: 11px;
                color: #5cb85c;
                margin-top: 3px;
                text-align: center;
            }
            .bl-checkin-empty {
                text-align: center;
                padding: 40px 20px;
                color: #555;
                font-size: 15px;
            }
        </style>

        <?php if ( empty( $regs ) ) : ?>
            <div class="bl-checkin-empty">No registrations yet.</div>
        <?php else : ?>
        <ul class="bl-checkin-list">
        <?php
            foreach ( $regs as $r ) :
                $done  = ! empty( $r->checked_in_at );
                $time  = $done ? blusiast_format_eastern( $r->checked_in_at, 'g:i a T' ) : '';
                $group = (int) $r->guest_count;
                $group_label = $group === 1 ? '1 person' : $group . ' people';
        ?>
            <li class="bl-checkin-card <?php echo $done ? 'is-checked-in' : ''; ?>">
                <div class="bl-checkin-card-info">
                    <div class="bl-checkin-name"><?php echo esc_html( $r->first_name . ' ' . $r->last_name ); ?></div>
                    <div class="bl-checkin-meta">
                        <span class="bl-checkin-group">👥 <?php echo esc_html( $group_label ); ?></span>
                        <?php if ( $done ) : ?>
                            <span class="bl-checkin-status-badge in">✓ Checked In</span>
                        <?php else : ?>
                            <span class="bl-checkin-status-badge out">Not yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bl-checkin-card-action">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('blusiast_manual_checkin'); ?>
                        <input type="hidden" name="action"         value="blusiast_manual_checkin">
                        <input type="hidden" name="reg_id"         value="<?php echo $r->id; ?>">
                        <input type="hidden" name="event_id"       value="<?php echo $sel; ?>">
                        <input type="hidden" name="checkin_action" value="<?php echo $done ? 'undo' : 'checkin'; ?>">
                        <button type="submit" class="bl-checkin-btn <?php echo $done ? 'do-undo' : 'do-checkin'; ?>">
                            <?php echo $done ? 'Undo' : 'Check In'; ?>
                        </button>
                        <?php if ( $done && $time ) : ?>
                            <span class="bl-checkin-time"><?php echo esc_html( $time ); ?></span>
                        <?php endif; ?>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php
}


// ═══════════════════════════════════════════════════════════
// 7.  STRIPE SETTINGS PAGE
// ═══════════════════════════════════════════════════════════

add_action( 'admin_post_blusiast_save_stripe', 'blusiast_save_stripe_settings' );

function blusiast_save_stripe_settings() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nope.' );
    check_admin_referer( 'blusiast_stripe_settings' );
    update_option( 'blusiast_stripe_secret_key',     sanitize_text_field( $_POST['stripe_secret_key']     ?? '' ) );
    update_option( 'blusiast_stripe_webhook_secret', sanitize_text_field( $_POST['stripe_webhook_secret'] ?? '' ) );
    wp_redirect( admin_url( 'admin.php?page=blusiast-stripe&saved=1' ) );
    exit;
}

function blusiast_ticket_stripe_settings_page() {
    $saved   = isset( $_GET['saved'] );
    $sk      = get_option( 'blusiast_stripe_secret_key',     '' );
    $whsec   = get_option( 'blusiast_stripe_webhook_secret', '' );
    $wh_url  = rest_url( 'blusiast/v1/stripe-webhook' );
    $is_live = str_starts_with( $sk, 'sk_live_' );
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Stripe Settings' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-stripe' ); ?>
        <p style="margin:0 0 20px;">
            <a href="<?php echo esc_url( admin_url('admin.php?page=blusiast-settings') ); ?>" style="color:#888;font-size:12px;text-decoration:none;">← Back to Settings</a>
        </p>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible"><p>✅ Stripe settings saved.</p></div>
        <?php endif; ?>

        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;padding:28px;max-width:660px;margin-top:20px;color:#ccc;font-family:'Courier New',monospace;">

            <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid #222;">
                <?php if ($sk) : ?>
                    <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $is_live ? '#5cb85c' : '#f5a623'; ?>;flex-shrink:0;display:inline-block;"></span>
                    <strong style="color:<?php echo $is_live ? '#5cb85c' : '#f5a623'; ?>;"><?php echo $is_live ? 'LIVE MODE' : 'TEST MODE'; ?></strong>
                    <span style="color:#444;font-size:12px;">— <?php echo $is_live ? 'Real payments active.' : 'Use Stripe test cards. No real charges.'; ?></span>
                <?php else : ?>
                    <span style="width:10px;height:10px;border-radius:50%;background:#333;flex-shrink:0;display:inline-block;"></span>
                    <span style="color:#444;">NOT CONFIGURED</span>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('blusiast_stripe_settings'); ?>
                <input type="hidden" name="action" value="blusiast_save_stripe">

                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:10px;letter-spacing:.15em;text-transform:uppercase;color:#555;margin-bottom:8px;">
                        Stripe Secret Key
                    </label>
                    <input type="password" name="stripe_secret_key"
                           value="<?php echo esc_attr($sk); ?>"
                           placeholder="sk_live_... or sk_test_..."
                           style="width:100%;background:#111;border:1px solid #333;color:#fff;padding:11px 14px;border-radius:6px;font-family:inherit;font-size:13px;box-sizing:border-box;"
                           autocomplete="off">
                    <p style="font-size:11px;color:#444;margin:6px 0 0;">
                        Stripe Dashboard → Developers → API keys → Secret key
                    </p>
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:block;font-size:10px;letter-spacing:.15em;text-transform:uppercase;color:#555;margin-bottom:8px;">
                        Webhook Signing Secret
                    </label>
                    <input type="password" name="stripe_webhook_secret"
                           value="<?php echo esc_attr($whsec); ?>"
                           placeholder="whsec_..."
                           style="width:100%;background:#111;border:1px solid #333;color:#fff;padding:11px 14px;border-radius:6px;font-family:inherit;font-size:13px;box-sizing:border-box;"
                           autocomplete="off">
                </div>

                <div style="background:#111;border:1px solid #1f1f1f;border-radius:6px;padding:16px;margin-bottom:24px;">
                    <div style="font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#444;margin-bottom:8px;">
                        Webhook URL — paste into Stripe Dashboard
                    </div>
                    <code style="background:#0a0a0a;color:#cc0000;padding:10px 14px;border-radius:4px;display:block;word-break:break-all;font-size:12px;border:1px solid #1a1a1a;">
                        <?php echo esc_html($wh_url); ?>
                    </code>
                    <p style="font-size:11px;color:#333;margin:10px 0 0;">
                        Stripe Dashboard → Developers → Webhooks → Add endpoint → Event: <strong style="color:#555;">checkout.session.completed</strong>
                    </p>
                </div>

                <div style="background:#111;border:1px solid #1f1f1f;border-radius:6px;padding:16px;margin-bottom:24px;">
                    <div style="font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#444;margin-bottom:8px;">💸 When do you get paid?</div>
                    <p style="font-size:12px;color:#666;line-height:1.8;margin:0;">
                        Money hits your <strong style="color:#aaa;">Stripe balance instantly</strong> when the card is charged —
                        visible in your dashboard right away.<br>
                        It hits your <strong style="color:#aaa;">bank account in 2 business days</strong> on the default schedule.<br>
                        Need it faster? Enable <strong style="color:#aaa;">Instant Payouts</strong> in Stripe (~1% extra fee, arrives within 30 min).<br>
                        Standard fee: <strong style="color:#aaa;">2.9% + $0.30</strong> per transaction. No monthly fee.
                    </p>
                </div>

                <button type="submit"
                        style="background:#cc0000;color:#fff;border:none;border-radius:6px;padding:12px 28px;font-family:inherit;font-weight:900;font-size:13px;letter-spacing:.08em;cursor:pointer;">
                    SAVE SETTINGS
                </button>
            </form>
        </div>
    </div>
    <?php
}