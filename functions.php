<?php
/**
 * Blusiast Theme — functions.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BLUSIAST_VERSION', '1.0.2' );
define( 'BLUSIAST_DIR',     get_template_directory() );
define( 'BLUSIAST_URI',     get_template_directory_uri() );


// ─────────────────────────────────────────
// 1. THEME SETUP
// ─────────────────────────────────────────

add_action( 'after_setup_theme', 'blusiast_setup' );

function blusiast_setup() {
    load_theme_textdomain( 'blusiast', BLUSIAST_DIR . '/languages' );

    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] );
    add_theme_support( 'custom-logo', [
        'height'      => 60,
        'width'       => 220,
        'flex-width'  => true,
        'flex-height' => true,
    ] );
    add_theme_support( 'align-wide' );
    add_theme_support( 'responsive-embeds' );

    // Image sizes
    add_image_size( 'blusiast-hero',      1400, 700,  true );
    add_image_size( 'blusiast-card',       600, 400,  true );
    add_image_size( 'blusiast-thumb',      400, 400,  true );
    add_image_size( 'blusiast-portrait',   600, 750,  true );
    add_image_size( 'blusiast-gallery',    800, 600,  true );

    // Nav menus
    register_nav_menus( [
        'primary' => __( 'Primary Navigation', 'blusiast' ),
        'footer'  => __( 'Footer Navigation',  'blusiast' ),
        'social'  => __( 'Social Links',        'blusiast' ),
    ] );
}


// ─────────────────────────────────────────
// 2. ENQUEUE STYLES & SCRIPTS
// ─────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'blusiast_enqueue' );

function blusiast_enqueue() {
    // Google Fonts
    wp_enqueue_style(
        'blusiast-fonts',
        'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=Barlow:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&display=swap',
        [],
        null
    );

    // Main stylesheet
    wp_enqueue_style(
        'blusiast-main',
        BLUSIAST_URI . '/assets/css/main.css',
        [ 'blusiast-fonts' ],
        BLUSIAST_VERSION
    );

    // Main JS (deferred)
    wp_enqueue_script(
        'blusiast-main',
        BLUSIAST_URI . '/assets/js/main.js',
        [],
        BLUSIAST_VERSION,
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    // Pass PHP data to JS
    wp_localize_script( 'blusiast-main', 'bluSite', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'blusiast_nonce' ),
        'siteUrl' => get_site_url(),
    ] );

    // Inner page styles (archive, post grid, event detail, tiers)
    wp_enqueue_style(
        'blusiast-inner',
        BLUSIAST_URI . '/assets/css/inner-pages.css',
        [ 'blusiast-main' ],
        BLUSIAST_VERSION
    );

    // WooCommerce styles — only load on shop pages
    if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
        wp_enqueue_style(
            'blusiast-shop',
            BLUSIAST_URI . '/assets/css/shop.css',
            [ 'blusiast-main' ],
            BLUSIAST_VERSION
        );
    }

    // Comment reply script (only on singular posts with comments open)
    if ( is_singular() && comments_open() ) {
        wp_enqueue_script( 'comment-reply' );
    }
}


// ─────────────────────────────────────────
// 3. CUSTOM POST TYPES
// ─────────────────────────────────────────

add_action( 'init', 'blusiast_register_post_types' );

function blusiast_register_post_types() {

    // EVENTS
    register_post_type( 'bl_event', [
        'labels' => [
            'name'               => 'Events',
            'singular_name'      => 'Event',
            'add_new_item'       => 'Add New Event',
            'edit_item'          => 'Edit Event',
            'view_item'          => 'View Event',
            'all_items'          => 'All Events',
            'search_items'       => 'Search Events',
            'not_found'          => 'No events found.',
            'not_found_in_trash' => 'No events in trash.',
        ],
        'public'             => true,
        'has_archive'        => true,
        'show_in_rest'       => true,
        'menu_icon'          => 'dashicons-calendar-alt',
        'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
        'rewrite'            => [ 'slug' => 'events', 'with_front' => false ],
        'menu_position'      => 5,
    ] );

    // GALLERY GROUPS
    register_post_type( 'bl_gallery', [
        'labels' => [
            'name'          => 'Galleries',
            'singular_name' => 'Gallery',
            'add_new_item'  => 'Add New Gallery',
            'edit_item'     => 'Edit Gallery',
            'all_items'     => 'All Galleries',
            'not_found'     => 'No galleries found.',
        ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-format-gallery',
        'supports'      => [ 'title', 'editor', 'page-attributes' ],
        'rewrite'       => false,
        'menu_position' => 5,
    ] );

    // MEMBER SPOTLIGHTS
    register_post_type( 'bl_spotlight', [
        'labels' => [
            'name'          => 'Member Spotlights',
            'singular_name' => 'Spotlight',
            'add_new_item'  => 'Add New Spotlight',
            'edit_item'     => 'Edit Spotlight',
            'all_items'     => 'All Spotlights',
        ],
        'public'        => true,
        'has_archive'   => false,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-awards',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'spotlight', 'with_front' => false ],
        'menu_position' => 6,
    ] );

    // COASTER REVIEWS
    register_post_type( 'bl_coaster', [
        'labels' => [
            'name'          => 'Coaster Reviews',
            'singular_name' => 'Coaster Review',
            'add_new_item'  => 'Add New Review',
            'edit_item'     => 'Edit Review',
            'all_items'     => 'All Reviews',
        ],
        'public'        => true,
        'show_in_menu'  => false,
        'has_archive'   => true,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-star-filled',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'coaster-review', 'with_front' => false ],
        'menu_position' => 7,
    ] );

    // BLACK HISTORY ARTICLES
    // Registered here for consistency; full logic lives in inc/black-history.php
    register_post_type( 'bl_history', [
        'labels' => [
            'name'               => 'Black History',
            'singular_name'      => 'History Article',
            'add_new'            => 'Add New Article',
            'add_new_item'       => 'Add New History Article',
            'edit_item'          => 'Edit History Article',
            'view_item'          => 'View Article',
            'all_items'          => 'All History Articles',
            'search_items'       => 'Search History',
            'not_found'          => 'No history articles found.',
            'not_found_in_trash' => 'No articles in trash.',
        ],
        'public'            => true,
        'has_archive'       => 'history',
        'show_in_rest'      => true,
        'menu_icon'         => 'dashicons-book-alt',
        'supports'          => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields', 'revisions' ],
        'rewrite'           => [ 'slug' => 'history', 'with_front' => false ],
        'menu_position'     => 6,
        'capability_type'   => 'post',
        'map_meta_cap'      => true,
        'show_in_nav_menus' => true,
    ] );
}


// ─────────────────────────────────────────
// 4. CUSTOM TAXONOMIES
// ─────────────────────────────────────────

add_action( 'init', 'blusiast_register_taxonomies' );

function blusiast_register_taxonomies() {

    register_taxonomy( 'event_type', 'bl_event', [
        'labels'       => [ 'name' => 'Event Types', 'singular_name' => 'Event Type' ],
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite'      => [ 'slug' => 'event-type' ],
    ] );

    register_taxonomy( 'park', [ 'bl_event', 'bl_coaster' ], [
        'labels'       => [ 'name' => 'Parks', 'singular_name' => 'Park' ],
        'hierarchical' => false,
        'show_in_rest' => true,
        'rewrite'      => [ 'slug' => 'park' ],
    ] );
}


// ─────────────────────────────────────────
// 5. ACF FIELD GROUPS (programmatic)
//    Requires ACF Pro or free ACF plugin.
//    These register via PHP so fields travel
//    with the theme, not the database.
// ─────────────────────────────────────────

add_action( 'acf/init', 'blusiast_register_acf_fields' );

function blusiast_register_acf_fields() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    // ── EVENTS ──
    acf_add_local_field_group( [
        'key'      => 'group_bl_event',
        'title'    => 'Event Details',
        'fields'   => [
            [ 'key' => 'field_event_date',      'label' => 'Event Date',          'name' => 'event_date',      'type' => 'date_picker',  'display_format' => 'F j, Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_event_end_date',  'label' => 'End Date (optional)', 'name' => 'event_end_date',  'type' => 'date_picker',  'display_format' => 'F j, Y', 'return_format' => 'Y-m-d' ],
            [ 'key' => 'field_event_time',      'label' => 'Time',                'name' => 'event_time',      'type' => 'text',         'placeholder' => '9:00am – Close' ],
            [ 'key' => 'field_event_location',  'label' => 'Location',            'name' => 'event_location',  'type' => 'text',         'placeholder' => 'Cedar Point, Sandusky OH' ],
            [ 'key' => 'field_ticket_price_cents', 'label' => 'Single Ticket Price (cents)', 'name' => 'ticket_price_cents', 'type' => 'number', 'instructions' => 'For non-tiered events only (one price for everyone). Enter in cents — e.g. $25.00 = 2500. Set to 0 if using Passholder / General Admission pricing below.', 'placeholder' => '0' ],
            [ 'key' => 'field_passholder_price_cents',     'label' => 'Passholder Ticket Price (cents)',   'name' => 'passholder_price_cents',     'type' => 'number',  'instructions' => 'Season passholder ticket price in cents. e.g. $74.00 = 7400',        'placeholder' => '7400' ],
            [ 'key' => 'field_nonpassholder_price_cents',  'label' => 'General Admission Price (cents)',   'name' => 'nonpassholder_price_cents',  'type' => 'number',  'instructions' => 'General admission price in cents (includes park entry). e.g. $125.00 = 12500', 'placeholder' => '12500' ],
            [ 'key' => 'field_passholder_stripe_price_id', 'label' => 'Passholder Stripe Price ID',        'name' => 'passholder_stripe_price_id', 'type' => 'text',    'instructions' => 'Stripe Price ID for passholder tier. e.g. price_abc123. Create in Stripe Dashboard → Products.', 'placeholder' => 'price_...' ],
            [ 'key' => 'field_nonpassholder_stripe_price_id', 'label' => 'General Admission Stripe Price ID', 'name' => 'nonpassholder_stripe_price_id', 'type' => 'text', 'instructions' => 'Stripe Price ID for general admission tier. e.g. price_xyz789.', 'placeholder' => 'price_...' ],
            [ 'key' => 'field_event_reg_url',   'label' => 'Registration URL',    'name' => 'event_reg_url',   'type' => 'url' ],
            [ 'key' => 'field_event_capacity',  'label' => 'Capacity',            'name' => 'event_capacity',  'type' => 'number',       'placeholder' => '50' ],
            [ 'key' => 'field_event_members',   'label' => 'Members Only?',       'name' => 'event_members_only', 'type' => 'true_false', 'ui' => 1 ],
            [ 'key' => 'field_event_sold_out',  'label' => 'Sold Out?',           'name' => 'event_sold_out',  'type' => 'true_false',   'ui' => 1 ],

        ],
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'bl_event' ] ] ],
        'menu_order' => 0,
    ] );

    // ── MEMBER SPOTLIGHTS ──
    acf_add_local_field_group( [
        'key'    => 'group_bl_spotlight',
        'title'  => 'Spotlight Details',
        'fields' => [
            [ 'key' => 'field_spot_subtitle',  'label' => 'Tagline / Subtitle',   'name' => 'spotlight_subtitle',       'type' => 'text' ],
            [ 'key' => 'field_spot_homepark',  'label' => 'Home Park',             'name' => 'spotlight_home_park',      'type' => 'text' ],
            [ 'key' => 'field_spot_visited',   'label' => 'Parks Visited',         'name' => 'spotlight_parks_visited',  'type' => 'number' ],
            [ 'key' => 'field_spot_years',     'label' => 'Years as Member',       'name' => 'spotlight_years_member',   'type' => 'number' ],
            [ 'key' => 'field_spot_fave',      'label' => 'Favorite Coaster',      'name' => 'spotlight_fave_coaster',   'type' => 'text' ],
            [ 'key' => 'field_spot_quote',     'label' => 'Feature Quote',         'name' => 'spotlight_quote',          'type' => 'textarea', 'rows' => 3 ],
            [ 'key' => 'field_spot_active',    'label' => 'Current Month Feature', 'name' => 'spotlight_is_active',      'type' => 'true_false', 'ui' => 1, 'instructions' => 'Toggle ON for the member you want displayed on the homepage. Only one should be active at a time.' ],
        ],
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'bl_spotlight' ] ] ],
        'menu_order' => 0,
    ] );

    // ── ABOUT PAGE ──
    acf_add_local_field_group( [
        'key'    => 'group_bl_about',
        'title'  => 'About Page Settings',
        'fields' => [
            [ 'key' => 'field_about_leadership_photo', 'label' => 'Leadership Section Photo', 'name' => 'about_leadership_photo', 'type' => 'image', 'return_format' => 'array', 'preview_size' => 'medium', 'instructions' => 'Optional photo displayed above the Leadership heading. Recommended width: 1200px+.' ],
        ],
        'location' => [ [ [ 'param' => 'page_template', 'operator' => '==', 'value' => 'page-about.php' ] ] ],
        'menu_order' => 0,
    ] );

    // ── COASTER REVIEWS ──
    acf_add_local_field_group( [
        'key'    => 'group_bl_coaster',
        'title'  => 'Coaster Details',
        'fields' => [
            [ 'key' => 'field_cstr_park',      'label' => 'Park Name',       'name' => 'coaster_park',       'type' => 'text' ],
            [ 'key' => 'field_cstr_rating',    'label' => 'Blusiast Rating', 'name' => 'coaster_rating',     'type' => 'number', 'min' => 1, 'max' => 10, 'instructions' => 'Score out of 10' ],
            [ 'key' => 'field_cstr_thrill',    'label' => 'Thrill Level',    'name' => 'coaster_thrill',     'type' => 'select', 'choices' => [ 'mild' => 'Mild', 'moderate' => 'Moderate', 'intense' => 'Intense', 'extreme' => 'Extreme' ] ],
            [ 'key' => 'field_cstr_type',      'label' => 'Coaster Type',    'name' => 'coaster_type',       'type' => 'text',   'placeholder' => 'Steel / Wood / Hybrid' ],
            [ 'key' => 'field_cstr_height',    'label' => 'Height (ft)',     'name' => 'coaster_height',     'type' => 'number' ],
            [ 'key' => 'field_cstr_speed',     'label' => 'Top Speed (mph)', 'name' => 'coaster_speed',      'type' => 'number' ],
            [ 'key' => 'field_cstr_recommend', 'label' => 'Blusiast Pick?',  'name' => 'coaster_pick',       'type' => 'true_false', 'ui' => 1 ],
        ],
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'bl_coaster' ] ] ],
        'menu_order' => 0,
    ] );

    // ── HOME PAGE OPTIONS ──
    acf_add_local_field_group( [
        'key'    => 'group_bl_homepage',
        'title'  => 'Homepage Settings',
        'fields' => [
            [ 'key' => 'field_hp_hero_headline',  'label' => 'Hero Headline Line 1', 'name' => 'hp_hero_headline',  'type' => 'text', 'default_value' => 'The Culture' ],
            [ 'key' => 'field_hp_hero_line2',     'label' => 'Hero Headline Line 2', 'name' => 'hp_hero_line2',     'type' => 'text', 'default_value' => 'Rides' ],
            [ 'key' => 'field_hp_hero_line3',     'label' => 'Hero Headline Line 3', 'name' => 'hp_hero_line3',     'type' => 'text', 'default_value' => 'With Us' ],
            [ 'key' => 'field_hp_hero_body',      'label' => 'Hero Body Text',       'name' => 'hp_hero_body',      'type' => 'textarea', 'rows' => 2 ],
            [ 'key' => 'field_hp_stat_1_num',     'label' => 'Stat 1 Number',        'name' => 'hp_stat_1_num',     'type' => 'text', 'default_value' => '2022' ],
            [ 'key' => 'field_hp_stat_1_label',   'label' => 'Stat 1 Label',         'name' => 'hp_stat_1_label',   'type' => 'text', 'default_value' => 'Founded' ],
            [ 'key' => 'field_hp_stat_2_num',     'label' => 'Stat 2 Number',        'name' => 'hp_stat_2_num',     'type' => 'text', 'default_value' => 'Global' ],
            [ 'key' => 'field_hp_stat_2_label',   'label' => 'Stat 2 Label',         'name' => 'hp_stat_2_label',   'type' => 'text', 'default_value' => 'Reach' ],
            [ 'key' => 'field_hp_stat_3_num',     'label' => 'Stat 3 Number',        'name' => 'hp_stat_3_num',     'type' => 'text', 'default_value' => '100+' ],
            [ 'key' => 'field_hp_stat_3_label',   'label' => 'Stat 3 Label',         'name' => 'hp_stat_3_label',   'type' => 'text', 'default_value' => 'Members' ],
        ],
        'location' => [ [ [ 'param' => 'page_type', 'operator' => '==', 'value' => 'front_page' ] ] ],
        'menu_order' => 0,
    ] );
}


// ─────────────────────────────────────────
// 6. HELPER FUNCTIONS
//    Used across templates.
// ─────────────────────────────────────────

/**
 * Get the current active spotlight member.
 * Returns WP_Post or null.
 */
/**
 * Return the current date/time as a MySQL-format string in US Eastern time.
 * Use this instead of current_time('mysql') so all timestamps stored in
 * custom tables reflect Eastern time (ET/EST/EDT).
 */
function blusiast_eastern_now() {
    $dt = new DateTime( 'now', new DateTimeZone( 'America/New_York' ) );
    return $dt->format( 'Y-m-d H:i:s' );
}

/**
 * Format a stored MySQL datetime string for display in Eastern time.
 *
 * @param string $mysql_str   A 'Y-m-d H:i:s' value as stored in the DB.
 * @param string $format      PHP date() format string. Default: 'M j, Y g:i a T'
 * @return string
 */
function blusiast_format_eastern( $mysql_str, $format = 'M j, Y g:i a T' ) {
    if ( ! $mysql_str ) return '';
    try {
        $dt = new DateTime( $mysql_str, new DateTimeZone( 'America/New_York' ) );
        return $dt->format( $format );
    } catch ( Exception $e ) {
        return $mysql_str;
    }
}

function blusiast_get_active_spotlight() {
    if ( ! function_exists( 'get_field' ) ) return null;

    $query = new WP_Query( [
        'post_type'      => 'bl_spotlight',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'meta_query'     => [ [ 'key' => 'spotlight_is_active', 'value' => '1' ] ],
    ] );

    return $query->have_posts() ? $query->posts[0] : null;
}

/**
 * Get upcoming events (future dates only).
 */
function blusiast_get_upcoming_events( $limit = 3 ) {
    return new WP_Query( [
        'post_type'      => 'bl_event',
        'posts_per_page' => $limit,
        'post_status'    => 'publish',
        'meta_key'       => 'event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
        'meta_query'     => [ [
            'key'     => 'event_date',
            'value'   => date( 'Y-m-d' ),
            'compare' => '>=',
            'type'    => 'DATE',
        ] ],
    ] );
}

/**
 * Get past events (event_date < today), most recent first.
 */
function blusiast_get_past_events( $limit = -1 ) {
    return new WP_Query( [
        'post_type'      => 'bl_event',
        'posts_per_page' => $limit,
        'post_status'    => 'publish',
        'meta_key'       => 'event_date',
        'orderby'        => 'meta_value',
        'order'          => 'DESC',
        'meta_type'      => 'DATE',
        'meta_query'     => [ [
            'key'     => 'event_date',
            'value'   => date( 'Y-m-d' ),
            'compare' => '<',
            'type'    => 'DATE',
        ] ],
    ] );
}

/**
 * Simple array of past events for <select> menus.
 * Returns [ [ 'id' => 12, 'title' => '…', 'date' => 'Y-m-d' ], … ]
 */
function blusiast_past_events_list( $limit = 50 ) {
    $q   = blusiast_get_past_events( $limit );
    $out = [];
    foreach ( $q->posts as $p ) {
        $d = get_post_meta( $p->ID, 'event_date', true );
        $out[] = [
            'id'    => $p->ID,
            'title' => $p->post_title,
            'date'  => $d,
            'label' => $p->post_title . ( $d ? ' — ' . date( 'M Y', strtotime( $d ) ) : '' ),
        ];
    }
    wp_reset_postdata();
    return $out;
}

/**
 * Approved member photo submissions for a given event.
 */
function blusiast_get_event_photos( $event_id ) {
    global $wpdb;
    $ptable = $wpdb->prefix . 'bl_photo_submissions';
    $mtable = $wpdb->prefix . 'bl_members';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT p.*, m.first_name, m.last_name, m.handle, m.dir_name_pref
         FROM $ptable p LEFT JOIN $mtable m ON m.id = p.member_id
         WHERE p.event_id = %d AND p.status = 'approved'
         ORDER BY p.submitted_at DESC",
        $event_id
    ) );
}

/**
 * Count of approved member photos for an event.
 */
function blusiast_count_event_photos( $event_id ) {
    global $wpdb;
    $ptable = $wpdb->prefix . 'bl_photo_submissions';
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $ptable WHERE event_id = %d AND status = 'approved'",
        $event_id
    ) );
}

/**
 * Deep link to the gallery upload form, pre-selected to an event.
 */
function blusiast_event_upload_url( $event_id ) {
    $gallery = get_page_by_path( 'gallery' );
    $base    = $gallery ? get_permalink( $gallery ) : home_url( '/gallery/' );
    return add_query_arg( 'event', (int) $event_id, $base ) . '#gallery-upload';
}

/**
 * Format event date for display.
 * Returns array: ['month' => 'Apr', 'day' => '12']
 */
function blusiast_format_event_date( $date_string ) {
    if ( ! $date_string ) return [ 'month' => '—', 'day' => '—' ];
    $ts = strtotime( $date_string );
    return [
        'month' => date( 'M', $ts ),
        'day'   => date( 'j', $ts ),
        'full'  => date( 'F j, Y', $ts ),
    ];
}

/**
 * Render SVG icon inline.
 * Usage: blusiast_icon( 'arrow-right' );
 */
function blusiast_icon( $name, $class = '' ) {
    $icons = [
        'arrow-right' => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12M11 5l5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'arrow-up-right' => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 13L13 3M13 3H6M13 3v7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'calendar'    => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1" y="3" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M1 7h14M5 1v4M11 1v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
        'location'    => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1C5.79 1 4 2.79 4 5c0 3.5 4 9 4 9s4-5.5 4-9c0-2.21-1.79-4-4-4z" stroke="currentColor" stroke-width="1.5"/><circle cx="8" cy="5" r="1.5" stroke="currentColor" stroke-width="1.5"/></svg>',
        'lock'        => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="3" y="7" width="10" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M5 7V5a3 3 0 016 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
        'check'       => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 8.5L6 12l7.5-8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    ];

    $svg = $icons[ $name ] ?? '';
    if ( $class ) {
        $svg = str_replace( '<svg ', '<svg class="' . esc_attr( $class ) . '" ', $svg );
    }
    echo $svg;
}

/**
 * Output body classes for current template.
 * Adds our own classes alongside WP's defaults.
 */
function blusiast_body_classes( $classes ) {
    if ( is_singular( 'bl_event' ) )             $classes[] = 'template-event';
    if ( is_singular( 'bl_spotlight' ) )         $classes[] = 'template-spotlight';
    if ( is_singular( 'bl_coaster' ) )           $classes[] = 'template-coaster';
    if ( is_singular( 'bl_history' ) )           $classes[] = 'template-history-single';
    if ( is_post_type_archive( 'bl_history' ) )  $classes[] = 'template-history-archive';
    if ( is_front_page() )                       $classes[] = 'template-home';
    return $classes;
}
add_filter( 'body_class', 'blusiast_body_classes' );


// ─────────────────────────────────────────
// 7. SHORTCODES
// ─────────────────────────────────────────

add_shortcode( 'bl_year',         fn() => date('Y') );
add_shortcode( 'bl_member_count', fn() => '100+' );

add_shortcode( 'bl_btn', function( $atts, $content ) {
    $a = shortcode_atts( [ 'url' => '#', 'style' => 'primary', 'size' => '' ], $atts );
    $size_class = $a['size'] ? ' bl-btn--' . esc_attr( $a['size'] ) : '';
    return '<a href="' . esc_url( $a['url'] ) . '" class="bl-btn bl-btn--' . esc_attr( $a['style'] ) . $size_class . '">' . esc_html( $content ) . '</a>';
} );


// ─────────────────────────────────────────
// 8. WOOCOMMERCE SUPPORT
// ─────────────────────────────────────────

add_action( 'after_setup_theme', 'blusiast_woo_support' );

function blusiast_woo_support() {
    add_theme_support( 'woocommerce' );
    add_theme_support( 'wc-product-gallery-zoom' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    add_theme_support( 'wc-product-gallery-slider' );
}

// Remove WooCommerce default styles (we style everything ourselves)
add_filter( 'woocommerce_enqueue_styles', '__return_empty_array' );


// ─────────────────────────────────────────
// 9. CLEAN UP WP HEAD
// ─────────────────────────────────────────

remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );

// Remove emoji scripts (unnecessary overhead)
remove_action( 'wp_head',           'print_emoji_detection_script', 7 );

// ─────────────────────────────────────────
// GOOGLE ANALYTICS 4
// ─────────────────────────────────────────

add_action( 'wp_head', 'blusiast_google_analytics', 1 );

function blusiast_google_analytics() {
    // Skip tracking for logged-in admins so your own visits don't skew data
    if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return;
    ?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-Y1VC2NNRT5"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-Y1VC2NNRT5', {
    // Redact member email/name from URL query strings (privacy)
    'cookie_flags': 'SameSite=None;Secure',
  });

  // ── Blusiast custom event helper ─────────────────────────────────────
  // Call window.blGA(eventName, params) anywhere in JS to fire a GA4 event.
  // All events automatically include membership status so you can filter
  // reports by member vs. guest.
  window.blGA = function(eventName, params) {
    if (typeof gtag !== 'function') return;
    gtag('event', eventName, Object.assign({
      site: 'blusiast',
    }, params || {}));
  };

  // ── Automatic event tracking ──────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function() {

    // 1. MEMBER REGISTRATION — fires when the register AJAX returns success
    //    Event: sign_up  (GA4 recommended event name)
    //    Picked up automatically in GA4 under Acquisition > User acquisition
    document.addEventListener('blusiast:registered', function() {
      blGA('sign_up', { method: 'blusiast_portal' });
    });

    // 2. MEMBER LOGIN
    //    Event: login
    document.addEventListener('blusiast:logged_in', function() {
      blGA('login', { method: 'blusiast_portal' });
    });

    // 3. TICKET PURCHASE — fires after Stripe redirects back with success
    //    Event: purchase  (GA4 ecommerce recommended event)
    //    Shows up in GA4 under Monetization > Ecommerce purchases
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('ticket') === 'success') {
      var eventName = document.querySelector('[data-event-title]')
        ? document.querySelector('[data-event-title]').dataset.eventTitle
        : document.title;
      blGA('purchase', {
        transaction_id: urlParams.get('session_id') || '',
        event_name:     eventName,
        currency:       'USD',
      });
    }

    // 4. COASTER REVIEW SUBMITTED
    //    Event: review_submitted
    document.addEventListener('blusiast:review_submitted', function(e) {
      blGA('review_submitted', {
        coaster_name: e.detail && e.detail.coaster ? e.detail.coaster : '',
        park_name:    e.detail && e.detail.park    ? e.detail.park    : '',
      });
    });

    // 5. CONTACT FORM — fires on the thank-you state appearing
    //    Event: generate_lead  (GA4 recommended event name)
    var contactSuccess = document.querySelector('.contact-success');
    if (contactSuccess) {
      blGA('generate_lead', { form: 'contact' });
    }

    // 6. PHOTO SUBMISSION
    document.addEventListener('blusiast:photo_submitted', function() {
      blGA('photo_submitted', {});
    });

    // 7. EVENT PAGE VIEW — track which events get the most interest
    //    Fires on any single event page
    var eventHero = document.querySelector('[data-event-id]');
    if (eventHero) {
      blGA('event_page_view', {
        event_id:   eventHero.dataset.eventId   || '',
        event_name: eventHero.dataset.eventTitle || document.title,
      });
    }

    // 8. MEMBER DIRECTORY SEARCH — fires when someone searches the directory
    var dirSearch = document.getElementById('bl-dir-search');
    if (dirSearch) {
      var dirTimer;
      dirSearch.addEventListener('input', function() {
        clearTimeout(dirTimer);
        dirTimer = setTimeout(function() {
          if (dirSearch.value.trim().length > 1) {
            blGA('search', { search_term: dirSearch.value.trim(), context: 'member_directory' });
          }
        }, 800);
      });
    }

    // 9. COASTER SEARCH — fires when someone searches coasters/parks
    var coasterSearch = document.querySelector('.bl-park-search-input');
    if (coasterSearch) {
      var csTimer;
      coasterSearch.addEventListener('input', function() {
        clearTimeout(csTimer);
        csTimer = setTimeout(function() {
          if (coasterSearch.value.trim().length > 1) {
            blGA('search', { search_term: coasterSearch.value.trim(), context: 'coaster_search' });
          }
        }, 800);
      });
    }

    // 10. OUTBOUND LINKS — tracks clicks to external sites (social, parks, etc.)
    document.querySelectorAll('a[href]').forEach(function(link) {
      if (link.hostname && link.hostname !== window.location.hostname) {
        link.addEventListener('click', function() {
          blGA('click', {
            link_url:    link.href,
            link_domain: link.hostname,
            link_text:   link.innerText.trim().substring(0, 50),
          });
        });
      }
    });

  });
</script>
    <?php
}
remove_action( 'wp_print_styles',   'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );


// ─────────────────────────────────────────
// 10. LOAD ADDITIONAL INC FILES
// ─────────────────────────────────────────

require_once BLUSIAST_DIR . '/inc/nav-walker.php';
require_once BLUSIAST_DIR . '/inc/member-id.php';
require_once BLUSIAST_DIR . '/inc/member-cms.php';
require_once BLUSIAST_DIR . '/inc/member-portal.php';
require_once BLUSIAST_DIR . '/inc/coaster-reviews.php';
require_once BLUSIAST_DIR . '/inc/black-history.php';
require_once BLUSIAST_DIR . '/inc/sso.php';
require_once BLUSIAST_DIR . '/inc/ticketing.php';

function blusiast_customize_register( $wp_customize ) {
    $wp_customize->add_section( 'blusiast_branding', [
        'title'    => __( 'Blusiast Branding', 'blusiast' ),
        'priority' => 30,
    ] );

    $wp_customize->add_setting( 'blusiast_footer_logo', [
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ] );

    $wp_customize->add_control(
        new WP_Customize_Image_Control(
            $wp_customize,
            'blusiast_footer_logo',
            [
                'label'   => __( 'Footer Logo', 'blusiast' ),
                'section' => 'blusiast_branding',
                'settings'=> 'blusiast_footer_logo',
            ]
        )
    );
}
add_action( 'customize_register', 'blusiast_customize_register' );

add_action( 'pre_get_posts', 'blusiast_order_events_archive' );

function blusiast_order_events_archive( $query ) {
    if ( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'bl_event' ) ) {
        $query->set( 'meta_key',  'event_date' );
        $query->set( 'orderby',   'meta_value' );
        $query->set( 'order',     'ASC' );
        $query->set( 'meta_type', 'DATE' );
        $query->set( 'meta_query', [ [
    'key'     => 'event_date',
    'value'   => date( 'Y-m-d' ),
    'compare' => '>=',
    'type'    => 'DATE',
] ] );
    }
}


/**
 * Gallery AJAX Comment Handler
 * ─────────────────────────────────────────────────────────────
 * Add this block to your theme's functions.php.
 *
 * It handles:
 *  1. Enqueuing gallery.css + gallery.js only on the gallery page
 *  2. Passing the AJAX URL to JS via wp_localize_script
 *  3. Receiving AJAX comment submissions and storing them as
 *     standard WordPress comments with a custom meta key
 *     `_gallery_image_id` so the gallery page can group them
 *     by image.
 */


/* ── 1. ENQUEUE GALLERY ASSETS ─────────────────────────────── */

add_action( 'wp_enqueue_scripts', 'blusiast_gallery_enqueue' );

function blusiast_gallery_enqueue() {
    /* Only load on the page whose slug is "gallery" */
    if ( ! is_page( 'gallery' ) ) return;

    wp_enqueue_style(
        'blusiast-gallery',
        get_template_directory_uri() . '/assets/css/gallery.css',
        [ 'blusiast-main', 'blusiast-inner' ],
        wp_get_theme()->get( 'Version' )
    );

    wp_enqueue_script(
        'blusiast-gallery',
        get_template_directory_uri() . '/assets/js/gallery.js',
        [],
        wp_get_theme()->get( 'Version' ),
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    /* Pass AJAX URL to gallery.js */
    wp_localize_script( 'blusiast-gallery', 'blusiast_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
    ] );
}


/* ── 2. AJAX COMMENT HANDLER ───────────────────────────────── */

add_action( 'wp_ajax_blusiast_gallery_comment',        'blusiast_handle_gallery_comment' );
/* Note: nopriv intentionally omitted — login required to comment */

function blusiast_handle_gallery_comment() {
    /* Verify nonce */
    if ( ! check_ajax_referer( 'blusiast_gallery_comment', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.', 403 );
    }

    /* Must be logged in */
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'You must be logged in to comment.', 401 );
    }

    $post_id  = absint( $_POST['post_id']          ?? 0 );
    $img_id   = sanitize_text_field( $_POST['gallery_image_id'] ?? '' );
    $content  = sanitize_textarea_field( $_POST['comment_content'] ?? '' );

    if ( ! $post_id || ! $img_id || ! $content ) {
        wp_send_json_error( 'Missing required fields.' );
    }

    /* Verify the post exists and is the gallery page */
    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'page' ) {
        wp_send_json_error( 'Invalid post.' );
    }

    $user = wp_get_current_user();

    $comment_id = wp_insert_comment( [
        'comment_post_ID'      => $post_id,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_content'      => $content,
        'comment_type'         => '',
        'comment_approved'     => 1,           /* auto-approve; adjust if moderation desired */
        'user_id'              => $user->ID,
    ] );

    if ( ! $comment_id ) {
        wp_send_json_error( 'Could not save comment.' );
    }

    /* Store which gallery image this comment belongs to */
    update_comment_meta( $comment_id, '_gallery_image_id', $img_id );

    wp_send_json_success( [
        'id'      => $comment_id,
        'author'  => esc_html( $user->display_name ),
        'avatar'  => get_avatar_url( $user->user_email, [ 'size' => 32 ] ),
        'date'    => 'just now',
        'content' => esc_html( $content ),
    ] );
}


// ─────────────────────────────────────────
// GALLERY PAGE — MEMBER PHOTO UPLOAD
// Logged-in members can submit photos from
// the public gallery page. Photos go into
// bl_photo_submissions with status=pending
// and require admin approval before display.
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_gallery_upload', 'blusiast_handle_gallery_upload' );

function blusiast_handle_gallery_upload() {

    if ( ! check_ajax_referer( 'blusiast_gallery_upload', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'You must be logged in to submit photos.' ], 401 );
    }

    if ( empty( $_FILES['photo'] ) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'message' => 'No file received or upload error.' ] );
    }

    // Validate image type
    $allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
    $finfo         = new finfo( FILEINFO_MIME_TYPE );
    $mime          = $finfo->file( $_FILES['photo']['tmp_name'] );
    if ( ! in_array( $mime, $allowed_types, true ) ) {
        wp_send_json_error( [ 'message' => 'Only JPG, PNG, GIF, and WEBP images are allowed.' ] );
    }

    // File size cap: 10 MB
    if ( $_FILES['photo']['size'] > 10 * 1024 * 1024 ) {
        wp_send_json_error( [ 'message' => 'File is too large. Maximum size is 10 MB.' ] );
    }

    global $wpdb;
    $mtable = $wpdb->prefix . 'bl_members';
    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $mtable WHERE wp_user_id = %d AND account_status != 'banned' LIMIT 1",
        get_current_user_id()
    ) );

    if ( ! $member ) {
        wp_send_json_error( [ 'message' => 'Member record not found. Please complete your profile first.' ] );
    }

    // Grant upload capability temporarily
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

    // Optional event tie-in — must be a real published event post
    $event_id = absint( $_POST['event_id'] ?? 0 );
    if ( $event_id && get_post_type( $event_id ) !== 'bl_event' ) {
        $event_id = 0;
    }

    $wpdb->insert(
        $wpdb->prefix . 'bl_photo_submissions',
        [
            'member_id'     => $member->id,
            'wp_user_id'    => get_current_user_id(),
            'attachment_id' => $attachment_id,
            'event_id'      => $event_id,
            'caption'       => $caption,
            'status'        => 'pending',
            'submitted_at'  => blusiast_eastern_now(),
        ],
        [ '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
    );

    $event_line = $event_id ? "\nEvent: " . get_the_title( $event_id ) : "\nEvent: (none — general gallery)";

    // Notify admin
    wp_mail(
        get_option( 'admin_email' ),
        'New Gallery Submission — ' . $member->first_name . ' ' . $member->last_name,
        "A member submitted a photo for gallery review.\n\nMember: {$member->first_name} {$member->last_name}{$event_line}\nCaption: {$caption}\n\nApprove or reject in your admin panel: " . admin_url( 'admin.php?page=blusiast-photos' )
    );

    wp_send_json_success( [ 'message' => 'Photo submitted! An admin will review it before it goes live.' ] );
}


// ─────────────────────────────────────────
// EVENT ADMIN — Custom columns, sorting,
// and Upcoming / Past / Cancelled / Draft
// status filter tabs
// ─────────────────────────────────────────

// ── 1. Replace default columns ──
add_filter( 'manage_bl_event_posts_columns', function( $cols ) {
    return [
        'cb'          => $cols['cb'],
        'title'       => 'Event',
        'event_date'  => 'Date',
        'event_loc'   => 'Location',
        'event_price' => 'Price',
        'event_status'=> 'Status',
        'registrations'=> 'RSVPs',
        'date'        => 'Published',
    ];
} );

// ── 2. Populate custom columns ──
add_action( 'manage_bl_event_posts_custom_column', function( $col, $post_id ) {
    switch ( $col ) {

        case 'event_date':
            $raw = get_post_meta( $post_id, 'event_date', true );
            if ( $raw ) {
                $ts      = strtotime( $raw );
                $today   = strtotime( date('Y-m-d') );
                $display = date( 'M j, Y', $ts );
                $color   = $ts >= $today ? '#5cb85c' : '#888';
                echo '<span style="color:' . $color . ';font-weight:600;">' . esc_html( $display ) . '</span>';
            } else {
                echo '<span style="color:#555;">—</span>';
            }
            break;

        case 'event_loc':
            $loc = get_post_meta( $post_id, 'event_location', true );
            echo $loc ? esc_html( $loc ) : '<span style="color:#555;">—</span>';
            break;

        case 'event_price':
            $price = get_post_meta( $post_id, 'event_price', true );
            if ( $price ) {
                $lower = strtolower( trim( $price ) );
                $color = ( $lower === 'free' ) ? '#5bc0de' : '#f5a623';
                echo '<span style="color:' . $color . ';font-weight:600;">' . esc_html( $price ) . '</span>';
            } else {
                echo '<span style="color:#555;">—</span>';
            }
            break;

        case 'event_status':
            $post      = get_post( $post_id );
            $sold_out  = get_post_meta( $post_id, 'event_sold_out',     true );
            $cancelled = get_post_meta( $post_id, 'event_cancelled',    true );
            $raw_date  = get_post_meta( $post_id, 'event_date',         true );
            $today     = strtotime( date('Y-m-d') );
            $event_ts  = $raw_date ? strtotime( $raw_date ) : 0;

            if ( $post->post_status === 'draft' ) {
                echo '<span class="bl-ev-badge" style="background:rgba(100,100,100,.2);color:#aaa;">Draft</span>';
            } elseif ( $cancelled ) {
                echo '<span class="bl-ev-badge" style="background:rgba(204,0,0,.15);color:#ff6666;">Cancelled</span>';
            } elseif ( $sold_out ) {
                echo '<span class="bl-ev-badge" style="background:rgba(245,166,35,.15);color:#f5a623;">Sold Out</span>';
            } elseif ( $event_ts && $event_ts < $today ) {
                echo '<span class="bl-ev-badge" style="background:rgba(100,100,100,.15);color:#888;">Past</span>';
            } elseif ( $event_ts && $event_ts >= $today ) {
                echo '<span class="bl-ev-badge" style="background:rgba(92,184,92,.15);color:#5cb85c;">Upcoming</span>';
            } else {
                echo '<span class="bl-ev-badge" style="background:rgba(100,100,100,.15);color:#888;">—</span>';
            }
            break;

        case 'registrations':
            global $wpdb;
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bl_event_registrations WHERE event_id = %d AND status != 'cancelled'",
                $post_id
            ) );
            $url = admin_url( 'admin.php?page=blusiast-registrations&event_id=' . $post_id );
            echo '<a href="' . esc_url( $url ) . '" style="color:#cc0000;font-weight:700;font-size:16px;">' . (int) $count . '</a>';
            break;
    }
}, 10, 2 );

// ── 3. Make Event Date column sortable ──
add_filter( 'manage_edit-bl_event_sortable_columns', function( $cols ) {
    $cols['event_date'] = 'event_date';
    return $cols;
} );

// ── 4. Handle sorting by event_date meta ──
add_action( 'pre_get_posts', function( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return;
    if ( $query->get('post_type') !== 'bl_event' ) return;

    // Default sort: upcoming first (ASC by event_date), then by date published
    if ( ! $query->get('orderby') ) {
        $query->set( 'meta_key',  'event_date' );
        $query->set( 'orderby',   'meta_value' );
        $query->set( 'order',     'ASC' );
    }

    if ( $query->get('orderby') === 'event_date' ) {
        $query->set( 'meta_key', 'event_date' );
        $query->set( 'orderby',  'meta_value' );
    }

    // ── Status filter tabs ──
    $filter = sanitize_key( $_GET['bl_event_status'] ?? '' );
    $today  = date('Y-m-d');

    switch ( $filter ) {
        case 'upcoming':
            $query->set( 'post_status', 'publish' );
            $query->set( 'meta_query', [ [
                'key'     => 'event_date',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE',
            ] ] );
            break;

        case 'past':
            $query->set( 'post_status', 'publish' );
            $query->set( 'meta_query', [ [
                'key'     => 'event_date',
                'value'   => $today,
                'compare' => '<',
                'type'    => 'DATE',
            ] ] );
            break;

        case 'cancelled':
            $query->set( 'post_status', 'publish' );
            $query->set( 'meta_query', [ [
                'key'   => 'event_cancelled',
                'value' => '1',
            ] ] );
            break;

        case 'draft':
            $query->set( 'post_status', 'draft' );
            break;
    }
} );

// ── 5. Filter tab links above the list table ──
add_filter( 'views_edit-bl_event', function( $views ) {
    global $wpdb;
    $today    = date('Y-m-d');
    $base_url = admin_url( 'edit.php?post_type=bl_event' );
    $current  = sanitize_key( $_GET['bl_event_status'] ?? '' );

    // Count upcoming
    $upcoming = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'event_date'
         WHERE p.post_type = 'bl_event' AND p.post_status = 'publish'
         AND pm.meta_value >= %s",
        $today
    ) );

    // Count past
    $past = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'event_date'
         WHERE p.post_type = 'bl_event' AND p.post_status = 'publish'
         AND pm.meta_value < %s",
        $today
    ) );

    // Count cancelled
    $cancelled = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'event_cancelled' AND pm.meta_value = '1'
         WHERE p.post_type = 'bl_event' AND p.post_status = 'publish'"
    );

    // Count drafts
    $drafts = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'bl_event' AND post_status = 'draft'"
    );

    $tabs = [
        ''          => [ 'label' => 'All Events',  'count' => $upcoming + $past ],
        'upcoming'  => [ 'label' => 'Upcoming',    'count' => $upcoming ],
        'past'      => [ 'label' => 'Past',        'count' => $past ],
        'cancelled' => [ 'label' => 'Cancelled',   'count' => $cancelled ],
        'draft'     => [ 'label' => 'Drafts',      'count' => $drafts ],
    ];

    $new_views = [];
    foreach ( $tabs as $key => $tab ) {
        $url    = $key ? add_query_arg( 'bl_event_status', $key, $base_url ) : $base_url;
        $active = ( $current === $key ) ? ' class="current"' : '';
        $new_views[ $key ?: 'all' ] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url( $url ),
            $active,
            esc_html( $tab['label'] ),
            $tab['count']
        );
    }

    return $new_views;
} );

// ── 6. Badge CSS in admin ──
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'bl_event' ) return;
    echo '<style>
    .bl-ev-badge {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        padding: 3px 9px;
        border-radius: 100px;
        white-space: nowrap;
    }
    .column-event_date  { width: 110px; }
    .column-event_loc   { width: 160px; }
    .column-event_price { width: 80px; }
    .column-event_status{ width: 100px; }
    .column-registrations { width: 70px; text-align: center; }
    </style>';
} );

// ── 7. Register event_cancelled ACF field ──
// Adds a "Cancelled?" toggle alongside Sold Out in the event editor
add_action( 'acf/init', function() {
    if ( ! function_exists( 'acf_add_local_field' ) ) return;
    acf_add_local_field( [
        'key'     => 'field_event_cancelled',
        'label'   => 'Cancelled?',
        'name'    => 'event_cancelled',
        'type'    => 'true_false',
        'ui'      => 1,
        'parent'  => 'group_bl_event',
    ] );
} );


/* ═══════════════════════════════════════════════════════════════
   COASTERS PAGE — assets + AJAX
═══════════════════════════════════════════════════════════════ */

/* ── 1. Enqueue coasters assets ──────────────────────────────── */

add_action( 'wp_enqueue_scripts', 'blusiast_coasters_enqueue' );

function blusiast_coasters_enqueue() {
    // Check by slug OR by the assigned page template — handles any page slug
    $is_coasters = is_page( 'coasters' )
                || is_page( 'coaster-reviews' )
                || ( is_page() && get_page_template_slug() === 'page-coasters.php' );
    if ( ! $is_coasters ) return;

    wp_enqueue_style(
        'blusiast-coasters',
        get_template_directory_uri() . '/assets/css/coasters.css',
        [ 'blusiast-main', 'blusiast-inner' ],
        wp_get_theme()->get( 'Version' )
    );

    wp_enqueue_script(
        'blusiast-coasters',
        get_template_directory_uri() . '/assets/js/coasters.js',
        [],
        wp_get_theme()->get( 'Version' ),
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    wp_localize_script( 'blusiast-coasters', 'blusiast_ajax', [
        'url'      => admin_url( 'admin-ajax.php' ),
        'rest_url' => rest_url( 'blusiast/v1/' ),
        'nonce'    => wp_create_nonce( 'blusiast_portal_nonce' ),
    ] );
}

/* ── 2. Require coaster-reviews if not already loaded ──────── */

add_action( 'after_setup_theme', function() {
    // coaster-reviews.php registers DB tables + AJAX handlers;
    // functions.php may already require it — guard against double-load.
    if ( ! function_exists( 'blusiast_reviews_install_db' ) ) {
        require_once get_template_directory() . '/inc/coaster-reviews.php';
    }
} );


// ─────────────────────────────────────────
// PWA MANIFEST — for Add to Home Screen
// ─────────────────────────────────────────

add_action( 'init', function() {
    add_rewrite_rule( '^blusiast-manifest\.json$', 'index.php?blusiast_manifest=1', 'top' );
} );

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'blusiast_manifest';
    return $vars;
} );

add_action( 'template_redirect', function() {
    if ( ! get_query_var( 'blusiast_manifest' ) ) return;

    $portal_url = function_exists( 'blusiast_portal_url' ) ? blusiast_portal_url( 'id-card' ) : home_url( '/member-portal/?tab=id-card' );
    $logo_url   = has_custom_logo() ? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : home_url( '/wp-content/themes/blusiast/assets/images/icon-192.png' );

    $manifest = [
        'name'             => 'Blusiast',
        'short_name'       => 'Blusiast',
        'description'      => 'Black roller coaster & theme park enthusiasts',
        'start_url'        => $portal_url,
        'display'          => 'standalone',
        'background_color' => '#0d0d0d',
        'theme_color'      => '#cc0000',
        'icons'            => [
            [ 'src' => $logo_url, 'sizes' => '192x192', 'type' => 'image/png' ],
            [ 'src' => $logo_url, 'sizes' => '512x512', 'type' => 'image/png' ],
        ],
        'shortcuts'        => [
            [
                'name'      => 'My Member Card',
                'short_name' => 'My Card',
                'url'       => $portal_url,
                'description' => 'Show your Blusiast QR code',
            ],
        ],
    ];

    header( 'Content-Type: application/manifest+json' );
    echo json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    exit;
} );