<?php
/**
 * Blusiast Theme — functions.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BLUSIAST_VERSION', '1.0.0' );
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
        'has_archive'   => true,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-star-filled',
        'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
        'rewrite'       => [ 'slug' => 'coasters', 'with_front' => false ],
        'menu_position' => 7,
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
            [ 'key' => 'field_event_price',     'label' => 'Price',               'name' => 'event_price',     'type' => 'text',         'placeholder' => '$45 or Free' ],
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
    if ( is_singular( 'bl_event' ) )     $classes[] = 'template-event';
    if ( is_singular( 'bl_spotlight' ) ) $classes[] = 'template-spotlight';
    if ( is_singular( 'bl_coaster' ) )   $classes[] = 'template-coaster';
    if ( is_front_page() )               $classes[] = 'template-home';
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
remove_action( 'wp_print_styles',   'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );


// ─────────────────────────────────────────
// 10. LOAD ADDITIONAL INC FILES
// ─────────────────────────────────────────

require_once BLUSIAST_DIR . '/inc/nav-walker.php';


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