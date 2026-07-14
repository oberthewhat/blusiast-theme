<?php
/**
 * Blusiast Black History — inc/black-history.php
 *
 * Registers the bl_history custom post type, category taxonomy,
 * ACF field group, admin columns, and contributor role helpers.
 *
 * Post type slug : /history
 * Archive        : /history  (editorial card layout by category)
 * Single         : /history/{slug}  (magazine-style article)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────
// 1. POST TYPE
// ─────────────────────────────────────────

add_action( 'init', 'blusiast_register_history_post_type' );

function blusiast_register_history_post_type() {

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
// 2. TAXONOMY — history_category
// ─────────────────────────────────────────

add_action( 'init', 'blusiast_register_history_taxonomy' );

function blusiast_register_history_taxonomy() {

    register_taxonomy( 'history_category', 'bl_history', [
        'labels' => [
            'name'              => 'History Categories',
            'singular_name'     => 'Category',
            'search_items'      => 'Search Categories',
            'all_items'         => 'All Categories',
            'edit_item'         => 'Edit Category',
            'update_item'       => 'Update Category',
            'add_new_item'      => 'Add New Category',
            'new_item_name'     => 'New Category Name',
            'menu_name'         => 'Categories',
        ],
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => [ 'slug' => 'history-category' ],
    ] );

}

// Seed default categories on theme activation
add_action( 'after_switch_theme', 'blusiast_seed_history_categories' );

function blusiast_seed_history_categories() {
    $defaults = [
        'Theme Park History'    => 'Stories and milestones in the history of theme parks and the coaster industry.',
        'Notable Figures'       => 'Black enthusiasts, pioneers, and trailblazers in the coaster community.',
        'Civil Rights & Parks'  => 'Historical park segregation, desegregation milestones, and civil rights in public leisure.',
    ];
    foreach ( $defaults as $name => $desc ) {
        if ( ! term_exists( $name, 'history_category' ) ) {
            wp_insert_term( $name, 'history_category', [ 'description' => $desc ] );
        }
    }
}


// ─────────────────────────────────────────
// 3. ACF FIELD GROUP
// ─────────────────────────────────────────

add_action( 'acf/init', 'blusiast_history_acf_fields' );

function blusiast_history_acf_fields() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    acf_add_local_field_group( [
        'key'      => 'group_bl_history',
        'title'    => 'History Article Details',
        'fields'   => [
            [
                'key'           => 'field_hist_subtitle',
                'label'         => 'Subtitle',
                'name'          => 'history_subtitle',
                'type'          => 'text',
                'instructions'  => 'Short deck / subheading shown beneath the article title.',
                'required'      => 0,
            ],
            [
                'key'           => 'field_hist_era',
                'label'         => 'Era / Time Period',
                'name'          => 'history_era',
                'type'          => 'text',
                'instructions'  => 'e.g. "1950s–1960s" or "Civil Rights Era". Shown in article meta.',
                'required'      => 0,
            ],
            [
                'key'           => 'field_hist_pull_quote',
                'label'         => 'Pull Quote',
                'name'          => 'history_pull_quote',
                'type'          => 'textarea',
                'rows'          => 3,
                'instructions'  => 'A key quote or sentence to highlight in the article. Optional.',
                'required'      => 0,
            ],
            [
                'key'           => 'field_hist_external_link',
                'label'         => 'Further Reading URL',
                'name'          => 'history_further_reading_url',
                'type'          => 'url',
                'instructions'  => 'Optional — link to an external source for readers who want to dive deeper.',
                'required'      => 0,
            ],
            [
                'key'           => 'field_hist_external_label',
                'label'         => 'Further Reading Label',
                'name'          => 'history_further_reading_label',
                'type'          => 'text',
                'instructions'  => 'Button label for the external link, e.g. "Read the full story at NPR".',
                'required'      => 0,
                'default_value' => 'Further Reading',
            ],
        ],
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'bl_history' ] ] ],
        'menu_order'            => 0,
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
    ] );
}


// ─────────────────────────────────────────
// 4. ADMIN COLUMNS
// ─────────────────────────────────────────

add_filter( 'manage_bl_history_posts_columns', 'blusiast_history_admin_columns' );

function blusiast_history_admin_columns( $cols ) {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) {
            $new['history_category'] = 'Category';
            $new['history_era']      = 'Era';
        }
    }
    return $new;
}

add_action( 'manage_bl_history_posts_custom_column', 'blusiast_history_admin_column_values', 10, 2 );

function blusiast_history_admin_column_values( $col, $post_id ) {
    if ( $col === 'history_category' ) {
        $terms = get_the_terms( $post_id, 'history_category' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            $labels = wp_list_pluck( $terms, 'name' );
            echo esc_html( implode( ', ', $labels ) );
        } else {
            echo '—';
        }
    }
    if ( $col === 'history_era' ) {
        $era = function_exists('get_field') ? get_field( 'history_era', $post_id ) : get_post_meta( $post_id, 'history_era', true );
        echo $era ? esc_html( $era ) : '—';
    }
}


// ─────────────────────────────────────────
// 5. CONTRIBUTOR ROLE
// ─────────────────────────────────────────

add_action( 'init', 'blusiast_register_contributor_role' );

function blusiast_register_contributor_role() {
    // bl_contributor: can create and edit their own history articles, but not publish.
    if ( ! get_role( 'bl_contributor' ) ) {
        add_role( 'bl_contributor', 'History Contributor', [
            'read'                  => true,
            'edit_posts'            => true,
            'delete_posts'          => false,
            'publish_posts'         => false,
            'upload_files'          => true,
        ] );
    }
}


// ─────────────────────────────────────────
// 6. RESTRICT CONTRIBUTOR ACCESS
//    Contributors only see their own articles
// ─────────────────────────────────────────

add_action( 'pre_get_posts', 'blusiast_history_contributor_scope' );

function blusiast_history_contributor_scope( $query ) {
    if ( ! is_admin() ) return;
    $user = wp_get_current_user();
    if ( ! in_array( 'bl_contributor', (array) $user->roles, true ) ) return;
    if ( $query->get( 'post_type' ) !== 'bl_history' ) return;
    $query->set( 'author', $user->ID );
}


// ─────────────────────────────────────────
// 7. BODY CLASS
// ─────────────────────────────────────────

add_filter( 'body_class', function( $classes ) {
    if ( is_singular( 'bl_history' ) )          $classes[] = 'template-history-single';
    if ( is_post_type_archive( 'bl_history' ) )  $classes[] = 'template-history-archive';
    return $classes;
} );
