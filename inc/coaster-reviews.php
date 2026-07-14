<?php
/**
 * Blusiast Coaster Reviews — inc/coaster-reviews.php
 *
 * - DB tables: bl_coaster_reviews, bl_coasters_aggregate
 * - AJAX: submit review, fetch reviews
 * - Shortcode: [bl_review_carousel] for homepage
 * - Template tag: blusiast_reviews_page_content()
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────
// 1. DB
// ─────────────────────────────────────────

add_action( 'after_switch_theme', 'blusiast_reviews_install_db' );
add_action( 'init',               'blusiast_reviews_install_db' );

function blusiast_reviews_install_db() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Individual coaster reviews
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bl_coaster_reviews (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        member_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        wp_user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        coaster_name   VARCHAR(200) NOT NULL DEFAULT '',
        park_name      VARCHAR(200) NOT NULL DEFAULT '',
        rating         TINYINT UNSIGNED NOT NULL DEFAULT 5,
        thrill_level   VARCHAR(20)  NOT NULL DEFAULT '',
        coaster_type   VARCHAR(50)  NOT NULL DEFAULT '',
        review_text    TEXT,
        ride_date      DATE,
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY member_id (member_id),
        KEY coaster_name (coaster_name(100))
    ) $charset;" );

    // Aggregate per coaster (avg rating, review count)
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bl_coasters_agg (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        coaster_name   VARCHAR(200) NOT NULL DEFAULT '',
        park_name      VARCHAR(200) NOT NULL DEFAULT '',
        review_count   INT UNSIGNED NOT NULL DEFAULT 0,
        avg_rating     DECIMAL(3,1) NOT NULL DEFAULT 0.0,
        PRIMARY KEY (id),
        UNIQUE KEY coaster_park (coaster_name(100), park_name(100))
    ) $charset;" );

    // Master parks list — admin-controlled canonical names
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bl_parks (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(200) NOT NULL DEFAULT '',
        location   VARCHAR(200) NOT NULL DEFAULT '',
        added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name(100))
    ) $charset;" );

    // Master coasters list — admin-controlled canonical ride names
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bl_coasters (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name         VARCHAR(200) NOT NULL DEFAULT '',
        park_name    VARCHAR(200) NOT NULL DEFAULT '',
        coaster_type VARCHAR(50)  NOT NULL DEFAULT '',
        status       VARCHAR(20)  NOT NULL DEFAULT 'operating',
        notes        TEXT,
        added_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name_park (name(100), park_name(100))
    ) $charset;" );

    // Seed coasters from existing reviews so nothing is lost
    $existing_coasters = $wpdb->get_results(
        "SELECT DISTINCT coaster_name, park_name, coaster_type FROM {$wpdb->prefix}bl_coaster_reviews
         WHERE coaster_name != '' AND park_name != ''"
    );
    foreach ( $existing_coasters as $ec ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bl_coasters (name, park_name, coaster_type) VALUES (%s, %s, %s)",
            $ec->coaster_name, $ec->park_name, $ec->coaster_type
        ) );
    }

    // Seed from existing reviews so nothing is lost
    $existing = $wpdb->get_col( "SELECT DISTINCT park_name FROM {$wpdb->prefix}bl_coaster_reviews WHERE park_name != '' ORDER BY park_name ASC" );
    foreach ( $existing as $pname ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}bl_parks (name) VALUES (%s)", $pname
        ) );
    }

    update_option( 'blusiast_reviews_db_version', '1.1' );
}

// ─────────────────────────────────────────
// 2. HELPERS
// ─────────────────────────────────────────

function blusiast_update_coaster_aggregate( $coaster_name, $park_name ) {
    global $wpdb;
    $rt  = $wpdb->prefix . 'bl_coaster_reviews';
    $agg = $wpdb->prefix . 'bl_coasters_agg';

    $stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) as cnt, AVG(rating) as avg FROM $rt WHERE coaster_name = %s AND park_name = %s",
        $coaster_name, $park_name
    ) );

    if ( ! $stats || ! $stats->cnt ) return;

    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $agg (coaster_name, park_name, review_count, avg_rating)
         VALUES (%s, %s, %d, %f)
         ON DUPLICATE KEY UPDATE review_count = %d, avg_rating = %f",
        $coaster_name, $park_name, $stats->cnt, $stats->avg,
        $stats->cnt, $stats->avg
    ) );
}

function blusiast_stars_html( $rating, $max = 10 ) {
    $pct = round( ( $rating / $max ) * 100 );
    return '<span class="bl-rating-bar" title="' . esc_attr( $rating ) . '/10">
        <span class="bl-rating-bar__fill" style="width:' . $pct . '%"></span>
    </span> <span class="bl-rating-num">' . esc_html( $rating ) . '<small>/10</small></span>';
}

// ─────────────────────────────────────────
// 3. AJAX — SUBMIT REVIEW
// ─────────────────────────────────────────

add_action( 'wp_ajax_blusiast_submit_review',        'blusiast_submit_review' );
add_action( 'wp_ajax_nopriv_blusiast_fetch_reviews', 'blusiast_fetch_reviews' );
add_action( 'wp_ajax_blusiast_fetch_reviews',        'blusiast_fetch_reviews' );

function blusiast_fetch_reviews() {
    global $wpdb;
    $rt = $wpdb->prefix . 'bl_coaster_reviews';
    $mt = $wpdb->prefix . 'bl_members';

    $coaster = sanitize_text_field( $_POST['coaster_name'] ?? '' );
    $park    = sanitize_text_field( $_POST['park_name']    ?? '' );

    if ( ! $coaster || ! $park ) {
        wp_send_json_error( [ 'message' => 'Missing coaster or park.' ] );
    }

    $reviews = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.rating, r.thrill_level, r.coaster_type, r.review_text, r.ride_date, r.created_at,
                m.first_name, m.last_name, m.handle, m.dir_name_pref
         FROM $rt r
         LEFT JOIN $mt m ON m.id = r.member_id
         WHERE r.coaster_name = %s AND r.park_name = %s
         ORDER BY r.created_at DESC",
        $coaster, $park
    ) );

    $thrill_map = [
        'mild'     => [ 'label' => 'Mild',     'icon' => '🟢' ],
        'moderate' => [ 'label' => 'Moderate', 'icon' => '🟡' ],
        'intense'  => [ 'label' => 'Intense',  'icon' => '🟠' ],
        'extreme'  => [ 'label' => 'Extreme',  'icon' => '🔴' ],
    ];

    $out = [];
    foreach ( $reviews as $r ) {
        $use_handle = ( ! empty( $r->handle ) && ( $r->dir_name_pref ?? 'real' ) === 'handle' );
        $author     = $use_handle ? '@' . $r->handle : ( $r->first_name ? $r->first_name . ' ' . substr( $r->last_name, 0, 1 ) . '.' : 'Anonymous' );
        $thrill     = $thrill_map[ $r->thrill_level ] ?? null;
        $date       = $r->ride_date ? blusiast_format_eastern( $r->created_at, 'M j, Y' ) : '';
        $out[] = [
            'author'      => $author,
            'rating'      => (int) $r->rating,
            'thrill_icon' => $thrill ? $thrill['icon'] . ' ' . $thrill['label'] : '',
            'type'        => $r->coaster_type ? ucwords( $r->coaster_type ) : '',
            'review'      => $r->review_text,
            'ride_date'   => $r->ride_date ?: '',
            'posted'      => blusiast_format_eastern( $r->created_at, 'M j, Y' ),
        ];
    }

    wp_send_json_success( [ 'reviews' => $out, 'coaster' => $coaster, 'park' => $park ] );
}

function blusiast_submit_review() {
    check_ajax_referer( 'blusiast_portal_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'You must be logged in to submit a review.' ] );

    $member = blusiast_get_current_member();

    $coaster_name = sanitize_text_field( $_POST['coaster_name'] ?? '' );
    $park_name    = sanitize_text_field( $_POST['park_name']    ?? '' );
    $rating       = max( 1, min( 10, absint( $_POST['rating']  ?? 5 ) ) );
    $thrill       = sanitize_text_field( $_POST['thrill_level'] ?? '' );
    $type         = sanitize_text_field( $_POST['coaster_type'] ?? '' );
    $review_text  = sanitize_textarea_field( $_POST['review_text'] ?? '' );
    $ride_date    = sanitize_text_field( $_POST['ride_date']    ?? '' );

    if ( ! $coaster_name || ! $park_name ) {
        wp_send_json_error( [ 'message' => 'Coaster name and park are required.' ] );
    }
    if ( ! $review_text ) {
        wp_send_json_error( [ 'message' => 'Please write a review.' ] );
    }

    global $wpdb;
    $rt = $wpdb->prefix . 'bl_coaster_reviews';

    $wpdb->insert( $rt, [
        'member_id'    => $member ? $member->id : 0,
        'wp_user_id'   => get_current_user_id(),
        'coaster_name' => $coaster_name,
        'park_name'    => $park_name,
        'rating'       => $rating,
        'thrill_level' => $thrill,
        'coaster_type' => $type,
        'review_text'  => $review_text,
        'ride_date'    => $ride_date ?: null,
        'created_at'   => blusiast_eastern_now(),
    ], [ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ] );

    blusiast_update_coaster_aggregate( $coaster_name, $park_name );

    wp_send_json_success( [ 'message' => 'Review submitted! Thanks for sharing with the crew.' ] );
}

// ─────────────────────────────────────────
// 3b. REST — Park search & add
// ─────────────────────────────────────────

add_action( 'rest_api_init', function() {
    // Search parks
    register_rest_route( 'blusiast/v1', '/parks', [
        'methods'             => 'GET',
        'callback'            => 'blusiast_rest_parks_search',
        'permission_callback' => '__return_true',
    ] );
    // Add a new park (members only)
    register_rest_route( 'blusiast/v1', '/parks', [
        'methods'             => 'POST',
        'callback'            => 'blusiast_rest_park_add',
        'permission_callback' => 'is_user_logged_in',
    ] );
} );

function blusiast_rest_parks_search( WP_REST_Request $req ) {
    global $wpdb;
    $q      = sanitize_text_field( $req->get_param( 'q' ) ?? '' );
    $table  = $wpdb->prefix . 'bl_parks';
    $rt     = $wpdb->prefix . 'bl_coaster_reviews';

    if ( $q ) {
        $like = '%' . $wpdb->esc_like( $q ) . '%';

        // Parks from official list
        $official = $wpdb->get_results( $wpdb->prepare(
            "SELECT name, location FROM $table WHERE name LIKE %s ORDER BY name ASC LIMIT 20",
            $like
        ) );

        // Distinct park names used in actual reviews (catches freehand entries not in parks table)
        $from_reviews = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT park_name as name, '' as location FROM $rt
             WHERE park_name LIKE %s AND park_name != ''
             ORDER BY park_name ASC LIMIT 20",
            $like
        ) );
    } else {
        $official = $wpdb->get_results(
            "SELECT name, location FROM $table ORDER BY name ASC LIMIT 50"
        );
        $from_reviews = $wpdb->get_results(
            "SELECT DISTINCT park_name as name, '' as location FROM $rt
             WHERE park_name != '' ORDER BY park_name ASC LIMIT 50"
        );
    }

    // Merge: official list first, then review-sourced names, deduped case-insensitively
    $seen   = [];
    $merged = [];

    foreach ( array_merge( $official, $from_reviews ) as $park ) {
        $key = strtolower( trim( $park->name ) );
        if ( $key && ! isset( $seen[ $key ] ) ) {
            $seen[ $key ] = true;
            $merged[]     = [ 'name' => $park->name, 'location' => $park->location ];
        }
    }

    // Sort merged list alphabetically
    usort( $merged, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

    return new WP_REST_Response( $merged, 200 );
}

function blusiast_rest_park_add( WP_REST_Request $req ) {
    if ( ! is_user_logged_in() ) {
        return new WP_REST_Response( [ 'error' => 'Login required.' ], 401 );
    }

    global $wpdb;
    $table    = $wpdb->prefix . 'bl_parks';
    $name     = trim( sanitize_text_field( $req->get_param( 'name' ) ?? '' ) );
    $location = trim( sanitize_text_field( $req->get_param( 'location' ) ?? '' ) );

    if ( ! $name ) {
        return new WP_REST_Response( [ 'error' => 'Park name is required.' ], 400 );
    }

    // Normalize: title case
    $name = mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );

    // Check for near-duplicate (case-insensitive)
    $exists = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, name FROM $table WHERE LOWER(name) = LOWER(%s) LIMIT 1", $name
    ) );

    if ( $exists ) {
        return new WP_REST_Response( [ 'id' => $exists->id, 'name' => $exists->name, 'existed' => true ], 200 );
    }

    $wpdb->insert( $table, [ 'name' => $name, 'location' => $location ], [ '%s', '%s' ] );
    $id = $wpdb->insert_id;

    return new WP_REST_Response( [ 'id' => $id, 'name' => $name, 'existed' => false ], 201 );
}

// ─────────────────────────────────────────
// 3c. REST — Reviews by coaster (for carousel drawer)
// ─────────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'blusiast/v1', '/reviews', [
        'methods'             => 'GET',
        'callback'            => 'blusiast_rest_reviews_by_coaster',
        'permission_callback' => '__return_true',
    ] );
} );

function blusiast_rest_reviews_by_coaster( WP_REST_Request $req ) {
    global $wpdb;
    $rt = $wpdb->prefix . 'bl_coaster_reviews';
    $mt = $wpdb->prefix . 'bl_members';

    $coaster = sanitize_text_field( $req->get_param( 'coaster' ) ?? '' );
    if ( ! $coaster ) {
        return new WP_REST_Response( [], 200 );
    }

    $reviews = $wpdb->get_results( $wpdb->prepare(
        "SELECT r.rating, r.thrill_level, r.review_text, r.created_at,
                m.first_name, m.last_name, m.handle, m.dir_name_pref
         FROM $rt r
         LEFT JOIN $mt m ON m.id = r.member_id
         WHERE r.coaster_name = %s
         ORDER BY r.created_at DESC
         LIMIT 50",
        $coaster
    ) );

    $out = [];
    foreach ( $reviews as $r ) {
        $use_handle = ( ! empty( $r->handle ) && ( $r->dir_name_pref ?? 'real' ) === 'handle' );
        $author     = $use_handle
            ? '@' . $r->handle
            : ( $r->first_name ? $r->first_name . ' ' . substr( $r->last_name, 0, 1 ) . '.' : 'Anonymous' );
        $out[] = [
            'author'       => $author,
            'rating'       => (int) $r->rating,
            'thrill_level' => $r->thrill_level,
            'review_text'  => $r->review_text,
            'created_at'   => $r->created_at,
        ];
    }

    return new WP_REST_Response( $out, 200 );
}


// ─────────────────────────────────────────
// 4. SHORTCODE — homepage carousel
// ─────────────────────────────────────────

add_shortcode( 'bl_review_carousel', 'blusiast_review_carousel_shortcode' );

function blusiast_review_carousel_shortcode() {
    global $wpdb;
    $rt = $wpdb->prefix . 'bl_coaster_reviews';
    $mt = $wpdb->prefix . 'bl_members';

    $reviews = $wpdb->get_results(
        "SELECT r.*, m.first_name, m.last_name, m.handle, m.dir_name_pref
         FROM $rt r
         LEFT JOIN $mt m ON m.id = r.member_id
         ORDER BY r.created_at DESC
         LIMIT 20"
    );

    if ( empty( $reviews ) ) return '';

    ob_start();
    ?>
    <section class="review-carousel section">
        <div class="container">
            <p class="bl-label">The Crew Reviews</p>
            <div class="section-header section-header--inline">
                <h2 class="bl-display-md">Coaster Reviews</h2>
                <?php if ( is_user_logged_in() ) : ?>
                <a href="<?php echo esc_url( blusiast_portal_url('reviews') ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">
                    Write a Review <?php blusiast_icon('arrow-right'); ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="review-carousel__track-wrap">
                <div class="review-carousel__track" id="review-track">
                    <?php foreach ( $reviews as $r ) :
                        $use_handle  = ( ! empty( $r->handle ) && ( $r->dir_name_pref ?? 'real' ) === 'handle' );
                        $author      = $use_handle ? '@' . $r->handle : ( $r->first_name ? $r->first_name . ' ' . substr( $r->last_name, 0, 1 ) . '.' : 'Anonymous' );
                        $thrill_map  = [ 'mild' => '🟢', 'moderate' => '🟡', 'intense' => '🟠', 'extreme' => '🔴' ];
                        $thrill_icon = $thrill_map[ $r->thrill_level ] ?? '';
                    ?>
                    <div
                        class="review-card"
                        data-coaster="<?php echo esc_attr( $r->coaster_name ); ?>"
                        data-park="<?php echo esc_attr( $r->park_name ); ?>"
                        role="button"
                        tabindex="0"
                        style="cursor:pointer;"
                    >
                        <div class="review-card__header">
                            <div class="review-card__coaster"><?php echo esc_html( $r->coaster_name ); ?></div>
                            <div class="review-card__park"><?php echo esc_html( $r->park_name ); ?></div>
                        </div>
                        <div class="review-card__rating">
                            <span class="review-card__score"><?php echo (int) $r->rating; ?><small>/10</small></span>
                            <?php if ( $r->thrill_level ) : ?>
                                <span class="review-card__thrill"><?php echo $thrill_icon; ?> <?php echo esc_html( ucfirst( $r->thrill_level ) ); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="review-card__text"><?php echo esc_html( wp_trim_words( $r->review_text, 25 ) ); ?></p>
                        <div class="review-card__author">— <?php echo esc_html( $author ); ?></div>
                        <span style="display:block;margin-top:10px;font-size:12px;color:#666;font-weight:600;letter-spacing:.04em;">Read full review →</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="review-carousel__btn review-carousel__btn--prev" aria-label="Previous" id="review-prev">&#8592;</button>
                <button class="review-carousel__btn review-carousel__btn--next" aria-label="Next" id="review-next">&#8594;</button>
            </div>
            <div style="text-align:center;margin-top:36px;">
                <a href="<?php echo esc_url( home_url('/coasters') ); ?>" class="bl-btn bl-btn--primary">
                    View All Coaster Rankings &rarr;
                </a>
            </div>
        </div>
    <!-- REVIEW DRAWER (carousel) -->
    <div id="bl-carousel-drawer" aria-hidden="true" role="dialog" aria-modal="true" style="position:fixed;inset:0;z-index:9999;display:flex;justify-content:flex-end;pointer-events:none;opacity:0;transition:opacity .25s ease;">
        <div id="bl-carousel-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.75);cursor:pointer;"></div>
        <div style="position:relative;z-index:2;width:min(520px,100vw);height:100%;background:#141414;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);overflow:hidden;" id="bl-carousel-panel">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:28px 28px 20px;border-bottom:1px solid #222;flex-shrink:0;">
                <div>
                    <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#cc0000;margin:0 0 4px;">Coaster Reviews</p>
                    <h2 id="bl-carousel-drawer-title" style="font-size:1.4rem;font-weight:800;color:#fff;margin:0;line-height:1.2;">—</h2>
                    <p id="bl-carousel-drawer-park" style="font-size:13px;color:#666;margin:4px 0 0;"></p>
                </div>
                <button id="bl-carousel-close" aria-label="Close" style="background:none;border:1px solid #333;color:#999;font-size:22px;line-height:1;width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">&times;</button>
            </div>
            <div id="bl-carousel-drawer-body" style="flex:1;overflow-y:auto;padding:24px 28px 40px;">
                <div style="color:#666;font-size:14px;text-align:center;padding:40px 0;">Loading…</div>
            </div>
        </div>
    </div>
    </section>
    <style>
    .review-card--clickable {
        display: block;
        width: 100%;
        text-align: left;
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .review-card--clickable:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0,0,0,.4);
    }
    .review-card--clickable:hover .review-card__cta { color: #cc0000; }
    .review-card__cta {
        display: block;
        margin-top: 12px;
        font-size: 12px;
        color: #555;
        font-weight: 600;
        letter-spacing: .04em;
        transition: color .15s;
    }
    .bl-crd-review { padding: 24px 0; border-bottom: 1px solid #222; }
    .bl-crd-review:first-child { padding-top: 4px; }
    .bl-crd-review:last-child { border-bottom: none; }
    .bl-crd-top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
    .bl-crd-author { font-weight:700; color:#fff; font-size:15px; }
    .bl-crd-score { font-size:28px; font-weight:900; color:#cc0000; line-height:1; flex-shrink:0; }
    .bl-crd-score small { font-size:13px; color:#666; font-weight:400; }
    .bl-crd-bar-wrap { height:4px; background:#2a2a2a; border-radius:2px; margin-bottom:12px; overflow:hidden; }
    .bl-crd-bar { height:100%; background:#cc0000; border-radius:2px; }
    .bl-crd-tags { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
    .bl-crd-tag { font-size:11px; padding:3px 10px; border-radius:20px; border:1px solid #333; color:#aaa; }
    .bl-crd-text { font-size:15px; line-height:1.7; color:#ccc; margin:0 0 10px; }
    .bl-crd-meta { font-size:11px; color:#555; }
    </style>
    <script>
    (function () {
        'use strict';

        // ── Wait for DOM ready ──────────────────────────────
        function ready(fn) {
            if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
        }

        ready(function () {

            // ── AJAX REST base ──────────────────────────────
            var REST_URL = (window.blusiast_ajax && window.blusiast_ajax.rest_url)
                ? window.blusiast_ajax.rest_url
                : '/wp-json/';

            // ── Elements ────────────────────────────────────
            var track    = document.getElementById('review-track');
            var prevBtn  = document.getElementById('review-prev');
            var nextBtn  = document.getElementById('review-next');
            var drawer   = document.getElementById('bl-carousel-drawer');
            var panel    = document.getElementById('bl-carousel-panel');
            var backdrop = document.getElementById('bl-carousel-backdrop');
            var closeBtn = document.getElementById('bl-carousel-close');
            var drawerTitle = document.getElementById('bl-carousel-drawer-title');
            var drawerPark  = document.getElementById('bl-carousel-drawer-park');
            var drawerBody  = document.getElementById('bl-carousel-drawer-body');

            if (!track) return; // carousel not on this page

            // ── State ────────────────────────────────────────
            var cards     = Array.from(track.querySelectorAll('.review-card'));
            var cardWidth = 0;
            var gap       = 20;
            var visible   = 1;
            var current   = 0;
            var total     = cards.length;

            // ── Measure ──────────────────────────────────────
            function measure() {
                if (!cards.length) return;
                var wrapWidth = track.parentElement.offsetWidth - 64; // 32px padding each side
                cardWidth = cards[0].offsetWidth;
                visible = Math.max(1, Math.round(wrapWidth / (cardWidth + gap)));
                // Clamp current so we never overshoot
                var maxIdx = Math.max(0, total - visible);
                if (current > maxIdx) { current = maxIdx; }
                updateTrack(false);
                updateButtons();
            }

            // ── Move ─────────────────────────────────────────
            function goTo(idx, animate) {
                var maxIdx = Math.max(0, total - visible);
                current = Math.max(0, Math.min(idx, maxIdx));
                updateTrack(animate !== false);
                updateButtons();
            }

            function updateTrack(animate) {
                var offset = current * (cardWidth + gap);
                if (animate === false) {
                    track.style.transition = 'none';
                } else {
                    track.style.transition = 'transform .4s cubic-bezier(.16,1,.3,1)';
                }
                track.style.transform = 'translateX(-' + offset + 'px)';
            }

            function updateButtons() {
                if (!prevBtn || !nextBtn) return;
                var maxIdx = Math.max(0, total - visible);
                prevBtn.disabled = current <= 0;
                nextBtn.disabled = current >= maxIdx;
                prevBtn.style.opacity = current <= 0 ? '0.35' : '1';
                nextBtn.style.opacity = current >= maxIdx ? '0.35' : '1';
            }

            // ── Button clicks ────────────────────────────────
            if (prevBtn) prevBtn.addEventListener('click', function () { goTo(current - 1); });
            if (nextBtn) nextBtn.addEventListener('click', function () { goTo(current + 1); });

            // ── Drag / swipe on track-wrap ───────────────────
            var wrap      = track.parentElement;
            var dragStart = null;
            var dragging  = false;
            var startX    = 0;

            wrap.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return;
                dragStart = e.clientX;
                startX    = e.clientX;
                dragging  = false;
            });

            window.addEventListener('mousemove', function (e) {
                if (dragStart === null) return;
                if (Math.abs(e.clientX - dragStart) > 6) {
                    dragging = true;
                    var delta  = e.clientX - dragStart;
                    var offset = current * (cardWidth + gap) - delta;
                    track.style.transition = 'none';
                    track.style.transform  = 'translateX(-' + offset + 'px)';
                }
            });

            window.addEventListener('mouseup', function (e) {
                if (dragStart === null) return;
                var diff = startX - e.clientX;
                dragStart = null;
                if (Math.abs(diff) > 50) {
                    goTo(diff > 0 ? current + 1 : current - 1);
                } else {
                    goTo(current); // snap back
                }
                setTimeout(function () { dragging = false; }, 10);
            });

            // Touch swipe
            var touchStart = null;
            wrap.addEventListener('touchstart', function (e) {
                touchStart = e.touches[0].clientX;
            }, { passive: true });
            wrap.addEventListener('touchend', function (e) {
                if (touchStart === null) return;
                var diff = touchStart - e.changedTouches[0].clientX;
                touchStart = null;
                if (Math.abs(diff) > 50) { goTo(diff > 0 ? current + 1 : current - 1); }
            }, { passive: true });

            // ── Card click → drawer ──────────────────────────
            function openDrawer(reviewId, coasterName, parkName) {
                if (!drawer || !panel) return;

                // Set header immediately
                if (drawerTitle) drawerTitle.textContent = coasterName || '—';
                if (drawerPark)  drawerPark.textContent  = parkName  || '';
                if (drawerBody)  drawerBody.innerHTML    = '<div style="color:#666;font-size:14px;text-align:center;padding:40px 0;">Loading…</div>';

                // Open drawer
                drawer.setAttribute('aria-hidden', 'false');
                drawer.style.pointerEvents = 'auto';
                drawer.style.opacity       = '1';
                if (panel) panel.style.transform = 'translateX(0)';
                document.body.style.overflow = 'hidden';

                // Fetch reviews for this coaster from REST API
                var url = REST_URL + 'blusiast/v1/reviews?coaster=' + encodeURIComponent(coasterName || '');
                fetch(url)
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!drawerBody) return;
                        if (!Array.isArray(data) || !data.length) {
                            drawerBody.innerHTML = '<div style="color:#666;font-size:14px;text-align:center;padding:40px 0;">No reviews found.</div>';
                            return;
                        }
                        var html = '';
                        data.forEach(function (rev) {
                            var score    = parseInt(rev.rating, 10) || 0;
                            var barPct   = (score / 10 * 100).toFixed(0);
                            var thrill   = rev.thrill_level ? rev.thrill_level.charAt(0).toUpperCase() + rev.thrill_level.slice(1) : '';
                            var thrillMap = { mild:'🟢', moderate:'🟡', intense:'🟠', extreme:'🔴' };
                            var thrillIcon = thrillMap[rev.thrill_level] || '';
                            var tags = [];
                            if (thrill) tags.push(thrillIcon + ' ' + thrill);
                            if (rev.wait_time)     tags.push('⏱ ' + rev.wait_time + ' min wait');
                            if (rev.visited_month) tags.push('📅 ' + rev.visited_month);
                            var tagsHtml = tags.map(function (t) { return '<span class="bl-crd-tag">' + escHtml(t) + '</span>'; }).join('');
                            var dateStr  = rev.created_at ? rev.created_at.split('T')[0] : (rev.created_at || '');
                            html += '<div class="bl-crd-review">'
                                  + '<div class="bl-crd-top">'
                                  + '<span class="bl-crd-author">' + escHtml(rev.author || 'Anonymous') + '</span>'
                                  + '<span class="bl-crd-score">' + score + '<small>/10</small></span>'
                                  + '</div>'
                                  + '<div class="bl-crd-bar-wrap"><div class="bl-crd-bar" style="width:' + barPct + '%;"></div></div>'
                                  + (tags.length ? '<div class="bl-crd-tags">' + tagsHtml + '</div>' : '')
                                  + '<p class="bl-crd-text">' + escHtml(rev.review_text || '') + '</p>'
                                  + '<div class="bl-crd-meta">' + escHtml(dateStr) + '</div>'
                                  + '</div>';
                        });
                        drawerBody.innerHTML = html;
                    })
                    .catch(function () {
                        if (drawerBody) drawerBody.innerHTML = '<div style="color:#666;font-size:14px;text-align:center;padding:40px 0;">Could not load reviews.</div>';
                    });
            }

            function closeDrawer() {
                if (!drawer || !panel) return;
                drawer.setAttribute('aria-hidden', 'true');
                drawer.style.opacity = '0';
                if (panel) panel.style.transform = 'translateX(100%)';
                setTimeout(function () { drawer.style.pointerEvents = 'none'; }, 300);
                document.body.style.overflow = '';
            }

            // Attach card click handlers
            cards.forEach(function (card) {
                card.addEventListener('click', function () {
                    if (dragging) return;
                    var coaster = card.dataset.coaster || '';
                    var park    = card.dataset.park    || '';
                    openDrawer(null, coaster, park);
                });
                card.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        var coaster = card.dataset.coaster || '';
                        var park    = card.dataset.park    || '';
                        openDrawer(null, coaster, park);
                    }
                });
            });

            // Close drawer
            if (closeBtn)  closeBtn.addEventListener('click', closeDrawer);
            if (backdrop)  backdrop.addEventListener('click', closeDrawer);
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

            // ── Keyboard arrow navigation on carousel ────────
            var trackWrap = track.closest('.review-carousel__track-wrap');
            if (trackWrap) {
                trackWrap.setAttribute('tabindex', '0');
                trackWrap.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowLeft')  { e.preventDefault(); goTo(current - 1); }
                    if (e.key === 'ArrowRight') { e.preventDefault(); goTo(current + 1); }
                });
            }

            // ── Helpers ──────────────────────────────────────
            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            // ── Init ─────────────────────────────────────────
            measure();
            window.addEventListener('resize', function () { measure(); });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}


// ─────────────────────────────────────────
// 5. ADMIN — Coaster Reviews CMS page
// ─────────────────────────────────────────

add_action( 'admin_menu', 'blusiast_reviews_menu', 21 );

// Reviews page styling handled by blusiast_admin_enqueue() in member-cms.php
// which matches any hook containing 'blusiast'.

function blusiast_reviews_menu() {
    global $wpdb;
    $rt = $wpdb->prefix . 'bl_coaster_reviews';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$rt'" ) !== $rt ) return;
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $rt" );
    add_submenu_page( 'blusiast-cms', 'Manage Parks',    'Parks',                    'manage_options', 'blusiast-parks',    'blusiast_parks_admin_page' );
    add_submenu_page( 'blusiast-cms', 'Manage Coasters', 'Coasters',                 'manage_options', 'blusiast-coasters', 'blusiast_coasters_admin_page' );
    add_submenu_page( 'blusiast-cms', 'Coaster Reviews', 'Reviews (' . $count . ')', 'manage_options', 'blusiast-reviews',  'blusiast_reviews_admin_page' );
}

function blusiast_reviews_admin_page() {
    global $wpdb;
    $rt  = $wpdb->prefix . 'bl_coaster_reviews';
    $agg = $wpdb->prefix . 'bl_coasters_agg';
    $mt  = $wpdb->prefix . 'bl_members';

    $notice = '';

    // ── Handle: Edit review coaster/park names ──────
    if ( isset( $_POST['bl_edit_review'] ) && check_admin_referer( 'bl_edit_review_nonce' ) ) {
        $review_id       = absint( $_POST['review_id'] ?? 0 );
        $old_coaster     = sanitize_text_field( $_POST['old_coaster_name'] ?? '' );
        $old_park        = sanitize_text_field( $_POST['old_park_name']    ?? '' );
        $new_coaster     = trim( sanitize_text_field( $_POST['new_coaster_name'] ?? '' ) );
        $new_park        = trim( sanitize_text_field( $_POST['new_park_name']    ?? '' ) );

        if ( $review_id && $new_coaster && $new_park ) {
            $wpdb->update(
                $rt,
                [ 'coaster_name' => $new_coaster, 'park_name' => $new_park ],
                [ 'id' => $review_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            // Recalculate aggregates for old and new coaster+park combos
            if ( $old_coaster && $old_park ) {
                blusiast_update_coaster_aggregate( $old_coaster, $old_park );
            }
            blusiast_update_coaster_aggregate( $new_coaster, $new_park );

            $notice = '<div class="notice notice-success is-dismissible"><p>Review #' . $review_id . ' updated — coaster set to <strong>' . esc_html( $new_coaster ) . '</strong> at <strong>' . esc_html( $new_park ) . '</strong>.</p></div>';
        }
    }

    // ── Handle: Delete review ───────────────────────
    if ( isset( $_GET['bl_delete_review'] ) && check_admin_referer( 'bl_delete_review_' . $_GET['bl_delete_review'] ) ) {
        $del_id = absint( $_GET['bl_delete_review'] );
        $old    = $wpdb->get_row( $wpdb->prepare( "SELECT coaster_name, park_name FROM $rt WHERE id = %d", $del_id ) );
        $wpdb->delete( $rt, [ 'id' => $del_id ], [ '%d' ] );
        if ( $old ) blusiast_update_coaster_aggregate( $old->coaster_name, $old->park_name );
        $notice = '<div class="notice notice-success is-dismissible"><p>Review deleted.</p></div>';
    }

    echo $notice;

    $view = sanitize_key( $_GET['rview'] ?? 'all' );

    $reviews = $wpdb->get_results(
        "SELECT r.*, m.first_name, m.last_name, m.handle, m.dir_name_pref
         FROM $rt r LEFT JOIN $mt m ON m.id = r.member_id
         ORDER BY r.created_at DESC"
    );
    $coasters = $wpdb->get_results( "SELECT * FROM $agg ORDER BY avg_rating DESC, review_count DESC" );
    $thrill_map = [ 'mild' => '🟢', 'moderate' => '🟡', 'intense' => '🟠', 'extreme' => '🔴' ];
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Coaster Reviews' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-reviews' ); ?>

        <div class="bl-sub-tabs">
            <a href="?page=blusiast-reviews&rview=all" class="bl-sub-tab <?php echo $view==='all'?'bl-sub-tab--active':''; ?>">All Reviews (<?php echo count($reviews); ?>)</a>
            <a href="?page=blusiast-reviews&rview=coasters" class="bl-sub-tab <?php echo $view==='coasters'?'bl-sub-tab--active':''; ?>">By Coaster (<?php echo count($coasters); ?>)</a>
        </div>

        <?php if ( $view === 'coasters' ) : ?>
        <div class="bl-table-wrap">
            <div class="bl-table-toolbar"><h2>Coaster Leaderboard</h2></div>
            <?php if ( $coasters ) : ?>
            <table class="bl-table">
                <thead><tr><th>Coaster</th><th>Park</th><th>Reviews</th><th>Avg Rating</th></tr></thead>
                <tbody>
                <?php foreach ( $coasters as $c ) : ?>
                <tr>
                    <td class="bl-td-name"><?php echo esc_html($c->coaster_name); ?></td>
                    <td><?php echo esc_html($c->park_name); ?></td>
                    <td style="text-align:center;"><?php echo (int)$c->review_count; ?></td>
                    <td>
                        <span style="font-family:var(--bl-fd);font-size:24px;font-weight:800;color:var(--bl-red);"><?php echo number_format($c->avg_rating,1); ?></span>
                        <span style="font-size:11px;color:var(--bl-g1);">/10</span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?><div class="bl-empty"><strong>No Reviews Yet</strong></div><?php endif; ?>
        </div>

        <?php else : ?>
        <div class="bl-table-wrap">
            <div class="bl-table-toolbar">
                <h2>All Reviews</h2>
                <input type="search" class="bl-search-input" id="bl-reg-search" placeholder="Search coaster, park, member…">
            </div>
            <?php if ( $reviews ) : ?>
            <table class="bl-table">
                <thead><tr><th>Coaster</th><th>Park</th><th>Member</th><th>Rating</th><th>Thrill</th><th>Review</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ( $reviews as $r ) :
                    $use_h  = !empty($r->handle) && ($r->dir_name_pref??'real')==='handle';
                    $author = $use_h ? '@'.$r->handle : ($r->first_name ? $r->first_name.' '.$r->last_name : '—');
                    $row_id = 'bl-rev-edit-' . $r->id;
                ?>
                <tr>
                    <td class="bl-td-name"><?php echo esc_html($r->coaster_name); ?></td>
                    <td style="font-size:12px;"><?php echo esc_html($r->park_name); ?></td>
                    <td style="font-size:13px;"><?php echo esc_html($author); ?></td>
                    <td><span style="font-family:var(--bl-fd);font-size:20px;font-weight:800;color:var(--bl-red);"><?php echo (int)$r->rating; ?></span><span style="font-size:11px;color:var(--bl-g1);">/10</span></td>
                    <td style="font-size:13px;"><?php echo isset($thrill_map[$r->thrill_level]) ? $thrill_map[$r->thrill_level].' '.ucfirst($r->thrill_level) : '—'; ?></td>
                    <td style="font-size:12px;color:var(--bl-g2);max-width:240px;"><?php echo esc_html(wp_trim_words($r->review_text, 20)); ?></td>
                    <td style="font-size:11px;white-space:nowrap;"><?php echo esc_html(blusiast_format_eastern($r->created_at, 'M j, Y')); ?></td>
                    <td style="white-space:nowrap;">
                        <button type="button"
                            onclick="document.getElementById('<?php echo esc_js($row_id); ?>').style.display = document.getElementById('<?php echo esc_js($row_id); ?>').style.display === 'none' ? 'table-row' : 'none';"
                            class="button button-small">✏️ Edit</button>
                        <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=blusiast-reviews&rview=all&bl_delete_review=' . $r->id), 'bl_delete_review_' . $r->id ); ?>"
                           class="button button-small"
                           style="color:#cc0000;border-color:#cc0000;"
                           onclick="return confirm('Delete this review? This cannot be undone.');">🗑</a>
                    </td>
                </tr>
                <!-- Inline edit row -->
                <tr id="<?php echo esc_attr($row_id); ?>" style="display:none;background:#1a0a00;">
                    <td colspan="8" style="padding:16px 20px;">
                        <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                            <?php wp_nonce_field('bl_edit_review_nonce'); ?>
                            <input type="hidden" name="review_id"        value="<?php echo esc_attr($r->id); ?>">
                            <input type="hidden" name="old_coaster_name" value="<?php echo esc_attr($r->coaster_name); ?>">
                            <input type="hidden" name="old_park_name"    value="<?php echo esc_attr($r->park_name); ?>">
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <label style="color:#cc8800;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Coaster Name</label>
                                <input type="text" name="new_coaster_name" value="<?php echo esc_attr($r->coaster_name); ?>"
                                    required style="background:#0d0d0d;border:1px solid #cc8800;color:#fff;padding:7px 10px;border-radius:4px;width:260px;font-size:14px;"
                                    placeholder="e.g. Steel Vengeance">
                            </div>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <label style="color:#cc8800;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Park Name</label>
                                <input type="text" name="new_park_name" value="<?php echo esc_attr($r->park_name); ?>"
                                    required style="background:#0d0d0d;border:1px solid #cc8800;color:#fff;padding:7px 10px;border-radius:4px;width:260px;font-size:14px;"
                                    placeholder="e.g. Cedar Point">
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <button type="submit" name="bl_edit_review" class="button button-primary" style="background:#cc8800;border-color:#aa6600;">Save Changes</button>
                                <button type="button" onclick="document.getElementById('<?php echo esc_js($row_id); ?>').style.display='none';" class="button">Cancel</button>
                            </div>
                            <p style="width:100%;margin:6px 0 0;color:#888;font-size:11px;">⚠️ Editing the coaster or park name here updates <em>only this review</em>. Use the <a href="<?php echo admin_url('admin.php?page=blusiast-parks'); ?>" style="color:#cc8800;">Parks manager</a> to bulk-rename or merge duplicates across all reviews.</p>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?><div class="bl-empty"><strong>No Reviews Yet</strong>Members can submit reviews from their portal.</div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ─────────────────────────────────────────
// PARKS ADMIN PAGE
// ─────────────────────────────────────────

function blusiast_parks_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'bl_parks';
    $rt    = $wpdb->prefix . 'bl_coaster_reviews';
    $agg   = $wpdb->prefix . 'bl_coasters_agg';

    $notice = '';

    // ── Handle: Add park ────────────────────────────
    if ( isset( $_POST['bl_add_park'] ) && check_admin_referer( 'bl_parks_nonce' ) ) {
        $name     = trim( sanitize_text_field( $_POST['park_name'] ?? '' ) );
        $location = trim( sanitize_text_field( $_POST['park_location'] ?? '' ) );
        if ( $name ) {
            $name   = mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE LOWER(name)=LOWER(%s) LIMIT 1", $name ) );
            if ( ! $exists ) {
                $wpdb->insert( $table, [ 'name' => $name, 'location' => $location ], [ '%s', '%s' ] );
                $notice = '<div class="notice notice-success"><p>Park <strong>' . esc_html( $name ) . '</strong> added.</p></div>';
            } else {
                $notice = '<div class="notice notice-warning"><p>A park with that name already exists.</p></div>';
            }
        }
    }

    // ── Handle: Delete park ─────────────────────────
    if ( isset( $_GET['bl_delete_park'] ) && check_admin_referer( 'bl_delete_park_' . $_GET['bl_delete_park'] ) ) {
        $wpdb->delete( $table, [ 'id' => absint( $_GET['bl_delete_park'] ) ], [ '%d' ] );
        $notice = '<div class="notice notice-success"><p>Park removed from list.</p></div>';
    }


    // ── Handle: Edit park (name + location) ────────
    if ( isset( $_POST['bl_edit_park'] ) && check_admin_referer( 'bl_parks_nonce' ) ) {
        $id       = absint( $_POST['park_id'] ?? 0 );
        $newname  = mb_convert_case( trim( sanitize_text_field( $_POST['park_new_name']  ?? '' ) ), MB_CASE_TITLE, 'UTF-8' );
        $oldname  = sanitize_text_field( $_POST['park_old_name'] ?? '' );
        $location = trim( sanitize_text_field( $_POST['park_location'] ?? '' ) );
        if ( $id && $newname ) {
            $wpdb->update( $table, [ 'name' => $newname, 'location' => $location ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
            if ( $oldname && $oldname !== $newname ) {
                $r = $wpdb->update( $rt,  [ 'park_name' => $newname ], [ 'park_name' => $oldname ], [ '%s' ], [ '%s' ] );
                $wpdb->update( $agg, [ 'park_name' => $newname ], [ 'park_name' => $oldname ], [ '%s' ], [ '%s' ] );
                $notice = '<div class="notice notice-success"><p>Park updated. Renamed to <strong>' . esc_html( $newname ) . '</strong> — ' . (int) $r . ' review(s) updated.</p></div>';
            } else {
                $notice = '<div class="notice notice-success"><p>Park <strong>' . esc_html( $newname ) . '</strong> updated.</p></div>';
            }
        }
    }

    // ── Handle: Rename park (updates reviews + agg) ─
    if ( isset( $_POST['bl_rename_park'] ) && check_admin_referer( 'bl_parks_nonce' ) ) {
        $id      = absint( $_POST['park_id'] );
        $newname = mb_convert_case( trim( sanitize_text_field( $_POST['park_new_name'] ?? '' ) ), MB_CASE_TITLE, 'UTF-8' );
        $oldname = sanitize_text_field( $_POST['park_old_name'] ?? '' );
        if ( $id && $newname && $oldname ) {
            $wpdb->update( $table, [ 'name' => $newname ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
            $r = $wpdb->update( $rt,  [ 'park_name' => $newname ], [ 'park_name' => $oldname ], [ '%s' ], [ '%s' ] );
            $wpdb->update( $agg, [ 'park_name' => $newname ], [ 'park_name' => $oldname ], [ '%s' ], [ '%s' ] );
            $notice = '<div class="notice notice-success"><p>Renamed to <strong>' . esc_html( $newname ) . '</strong>. ' . (int) $r . ' review(s) updated.</p></div>';
        }
    }

    // ── Handle: Merge coaster names across all reviews ──
    if ( isset( $_POST['bl_merge_coaster'] ) && check_admin_referer( 'bl_parks_nonce' ) ) {
        $from_coaster = sanitize_text_field( $_POST['merge_coaster_from'] ?? '' );
        $from_park    = sanitize_text_field( $_POST['merge_coaster_from_park'] ?? '' );
        $into_coaster = sanitize_text_field( $_POST['merge_coaster_into'] ?? '' );
        $into_park    = sanitize_text_field( $_POST['merge_coaster_into_park'] ?? '' );

        if ( $from_coaster && $from_park && $into_coaster && $into_park
             && ( $from_coaster !== $into_coaster || $from_park !== $into_park ) ) {
            $r = $wpdb->update(
                $rt,
                [ 'coaster_name' => $into_coaster, 'park_name' => $into_park ],
                [ 'coaster_name' => $from_coaster, 'park_name' => $from_park ],
                [ '%s', '%s' ],
                [ '%s', '%s' ]
            );
            blusiast_update_coaster_aggregate( $from_coaster, $from_park );
            blusiast_update_coaster_aggregate( $into_coaster, $into_park );
            $notice = '<div class="notice notice-success"><p>Merged <strong>' . esc_html("$from_coaster @ $from_park") . '</strong> into <strong>' . esc_html("$into_coaster @ $into_park") . '</strong>. ' . (int)$r . ' review(s) updated.</p></div>';
        }
    }

    // ── Handle: Merge reviews into canonical park ───
    if ( isset( $_POST['bl_merge_park'] ) && check_admin_referer( 'bl_parks_nonce' ) ) {
        $from = sanitize_text_field( $_POST['merge_from'] ?? '' );
        $into = sanitize_text_field( $_POST['merge_into'] ?? '' );
        if ( $from && $into && $from !== $into ) {
            $r = $wpdb->update( $rt,  [ 'park_name' => $into ], [ 'park_name' => $from ], [ '%s' ], [ '%s' ] );
            $wpdb->update( $agg, [ 'park_name' => $into ], [ 'park_name' => $from ], [ '%s' ], [ '%s' ] );
            // Remove the "from" entry from parks table if it exists
            $wpdb->delete( $table, [ 'name' => $from ], [ '%s' ] );
            // Recalculate aggregate for the target park
            $coasters = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT coaster_name FROM $rt WHERE park_name = %s", $into ) );
            foreach ( $coasters as $coaster ) {
                blusiast_update_coaster_aggregate( $coaster, $into );
            }
            $notice = '<div class="notice notice-success"><p>Merged <strong>' . esc_html( $from ) . '</strong> into <strong>' . esc_html( $into ) . '</strong>. ' . (int) $r . ' review(s) updated.</p></div>';
        }
    }

    // ── Data ────────────────────────────────────────
    // All parks in the official list
    $parks = $wpdb->get_results(
        "SELECT p.*, (SELECT COUNT(*) FROM $rt r WHERE r.park_name = p.name) as review_count
         FROM $table p ORDER BY p.name ASC"
    );

    // All distinct park names used in actual reviews (may include orphans not in parks table)
    $review_parks = $wpdb->get_results(
        "SELECT park_name as name, COUNT(*) as review_count
         FROM $rt WHERE park_name != ''
         GROUP BY park_name ORDER BY park_name ASC"
    );

    // Find orphans — in reviews but not in the parks table
    $canonical_names = array_map( fn($p) => strtolower( $p->name ), $parks );
    $orphans = array_filter( $review_parks, fn($p) => ! in_array( strtolower( $p->name ), $canonical_names ) );

    echo $notice;
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Manage Parks' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-parks' ); ?>

        <div style="display:grid;grid-template-columns:320px 1fr;gap:24px;margin-top:20px;align-items:start;">

            <!-- ── Left column ──────────────────── -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Add Park -->
                <div style="background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">Add a Park</h3>
                    <form method="post">
                        <?php wp_nonce_field( 'bl_parks_nonce' ); ?>
                        <p style="margin:0 0 10px;">
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Park Name *</label>
                            <input type="text" name="park_name" required style="width:100%;background:#0d0d0d;border:1px solid #444;color:#fff;padding:8px;border-radius:4px;box-sizing:border-box;" placeholder="e.g. Six Flags Magic Mountain">
                        </p>
                        <p style="margin:0 0 12px;">
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Location (optional)</label>
                            <input type="text" name="park_location" style="width:100%;background:#0d0d0d;border:1px solid #444;color:#fff;padding:8px;border-radius:4px;box-sizing:border-box;" placeholder="e.g. Valencia, CA">
                        </p>
                        <button type="submit" name="bl_add_park" class="button button-primary">Add Park</button>
                    </form>
                </div>

                <!-- Merge Tool -->
                <div style="background:#1a0a0a;border:1px solid #cc0000;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;color:#cc0000;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">🔀 Merge Park Names</h3>
                    <p style="color:#999;font-size:12px;margin:0 0 14px;">Fix duplicates — move all reviews from one park name into another. The "from" name is then removed.</p>
                    <form method="post">
                        <?php wp_nonce_field( 'bl_parks_nonce' ); ?>
                        <p style="margin:0 0 10px;">
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">From (the typo / duplicate)</label>
                            <select name="merge_from" required style="width:100%;background:#0d0d0d;border:1px solid #444;color:#fff;padding:8px;border-radius:4px;box-sizing:border-box;">
                                <option value="">— choose —</option>
                                <?php foreach ( $review_parks as $rp ) : ?>
                                <option value="<?php echo esc_attr( $rp->name ); ?>">
                                    <?php echo esc_html( $rp->name ); ?> (<?php echo (int) $rp->review_count; ?> reviews)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p style="margin:0 0 12px;">
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Into (the correct canonical name)</label>
                            <select name="merge_into" required style="width:100%;background:#0d0d0d;border:1px solid #444;color:#fff;padding:8px;border-radius:4px;box-sizing:border-box;">
                                <option value="">— choose —</option>
                                <?php foreach ( $review_parks as $rp ) : ?>
                                <option value="<?php echo esc_attr( $rp->name ); ?>">
                                    <?php echo esc_html( $rp->name ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <button type="submit" name="bl_merge_park" class="button button-primary" style="background:#cc0000;border-color:#aa0000;"
                            onclick="return confirm('This will move ALL reviews from the selected park into the target. This cannot be undone. Continue?');">
                            Merge Reviews
                        </button>
                    </form>
                </div>

                <!-- Merge Coaster Names -->
                <div style="background:#0a0a1a;border:1px solid #3333cc;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;color:#6699ff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">🎢 Merge Coaster Names</h3>
                    <p style="color:#999;font-size:12px;margin:0 0 14px;">Fix duplicate ride names (e.g. "Steel Vengeance" vs "Steel Vengance"). Moves all reviews from one coaster+park combo into another.</p>
                    <?php
                    // Build list of all coaster+park combos from reviews
                    $coaster_combos = $wpdb->get_results(
                        "SELECT coaster_name, park_name, COUNT(*) as review_count
                         FROM $rt WHERE coaster_name != '' AND park_name != ''
                         GROUP BY coaster_name, park_name ORDER BY park_name ASC, coaster_name ASC"
                    );
                    ?>
                    <form method="post">
                        <?php wp_nonce_field( 'bl_parks_nonce' ); ?>
                        <p style="margin:0 0 10px;">
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">From (the typo / duplicate)</label>
                            <select name="merge_coaster_from" id="bl-merge-coaster-from" required style="width:100%;background:#0d0d0d;border:1px solid #444;color:#fff;padding:8px;border-radius:4px;box-sizing:border-box;">
                                <option value="">— choose —</option>
                                <?php foreach ( $coaster_combos as $cc ) : ?>
                                <option value="<?php echo esc_attr($cc->coaster_name); ?>"
                                        data-park="<?php echo esc_attr($cc->park_name); ?>">
                                    <?php echo esc_html("{$cc->coaster_name} @ {$cc->park_name}"); ?> (<?php echo (int)$cc->review_count; ?> reviews)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="merge_coaster_from_park" id="bl-merge-coaster-from-park" value="">
                        </p>
                        <p style="margin:0 0 12px;">
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Into (the correct canonical name)</label>
                            <select name="merge_coaster_into" id="bl-merge-coaster-into" required style="width:100%;background:#0d0d0d;border:1px solid #444;color:#fff;padding:8px;border-radius:4px;box-sizing:border-box;">
                                <option value="">— choose —</option>
                                <?php foreach ( $coaster_combos as $cc ) : ?>
                                <option value="<?php echo esc_attr($cc->coaster_name); ?>"
                                        data-park="<?php echo esc_attr($cc->park_name); ?>">
                                    <?php echo esc_html("{$cc->coaster_name} @ {$cc->park_name}"); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="merge_coaster_into_park" id="bl-merge-coaster-into-park" value="">
                        </p>
                        <button type="submit" name="bl_merge_coaster" class="button button-primary" style="background:#3333cc;border-color:#2222aa;"
                            onclick="return confirm('This will move ALL reviews from the selected coaster into the target coaster. This cannot be undone. Continue?');">
                            Merge Coaster Reviews
                        </button>
                    </form>
                    <script>
                    (function() {
                        function syncPark(selectId, hiddenId) {
                            var sel = document.getElementById(selectId);
                            var hid = document.getElementById(hiddenId);
                            if (!sel || !hid) return;
                            sel.addEventListener('change', function() {
                                var opt = sel.options[sel.selectedIndex];
                                hid.value = opt ? (opt.dataset.park || '') : '';
                            });
                        }
                        syncPark('bl-merge-coaster-from',  'bl-merge-coaster-from-park');
                        syncPark('bl-merge-coaster-into', 'bl-merge-coaster-into-park');
                    })();
                    </script>
                </div>

            </div><!-- /left col -->

            <!-- ── Right column ─────────────────── -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Orphaned park names warning -->
                <?php if ( $orphans ) : ?>
                <div style="background:#1a1000;border:1px solid #cc8800;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;color:#cc8800;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">⚠️ Unregistered Park Names in Reviews</h3>
                    <p style="color:#999;font-size:12px;margin:0 0 12px;">These park names appear in reviews but aren't in the official parks list. They won't show up in the search typeahead. Use Merge to consolidate them into a canonical name, or Add them to the official list.</p>
                    <table class="widefat" style="background:#0d0d0d;color:#fff;">
                        <thead style="background:#1a1a1a;">
                            <tr>
                                <th style="color:#cc8800;">Park Name in Reviews</th>
                                <th style="color:#cc8800;">Reviews</th>
                                <th style="color:#cc8800;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $orphans as $o ) : ?>
                        <tr style="border-top:1px solid #222;">
                            <td style="color:#fff;font-weight:600;"><?php echo esc_html( $o->name ); ?></td>
                            <td style="color:#999;"><?php echo (int) $o->review_count; ?></td>
                            <td>
                                <!-- Quick-add to official list -->
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field( 'bl_parks_nonce' ); ?>
                                    <input type="hidden" name="park_name" value="<?php echo esc_attr( $o->name ); ?>">
                                    <button type="submit" name="bl_add_park" class="button button-small">Add to List</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Official parks list with inline edit -->
                <div style="background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
                        Official Park List <span style="color:#666;font-weight:400;">(<?php echo count( $parks ); ?>)</span>
                    </h3>
                    <?php if ( empty( $parks ) ) : ?>
                        <p style="color:#888;">No parks yet. Add one to get started.</p>
                    <?php else : ?>
                    <table class="widefat" style="background:#0d0d0d;color:#fff;">
                        <thead style="background:#1a1a1a;">
                            <tr>
                                <th style="color:#ccc;">Park Name</th>
                                <th style="color:#ccc;">Location</th>
                                <th style="color:#ccc;">Reviews</th>
                                <th style="color:#ccc;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $parks as $park ) :
                            $park_row_id = 'bl-park-edit-' . $park->id;
                        ?>
                            <tr style="border-top:1px solid #222;">
                                <td style="color:#fff;font-weight:600;"><?php echo esc_html( $park->name ); ?></td>
                                <td style="color:#999;font-size:12px;"><?php echo esc_html( $park->location ?: '—' ); ?></td>
                                <td style="color:#999;"><?php echo (int) $park->review_count; ?></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="button button-small"
                                        onclick="document.getElementById('<?php echo esc_js($park_row_id); ?>').style.display = document.getElementById('<?php echo esc_js($park_row_id); ?>').style.display === 'none' ? 'table-row' : 'none';">
                                        ✏️ Edit
                                    </button>
                                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=blusiast-parks&bl_delete_park=' . $park->id ), 'bl_delete_park_' . $park->id ); ?>"
                                       class="button button-small" style="color:#cc0000;border-color:#cc0000;"
                                       onclick="return confirm('Remove this park from the list? Reviews will NOT be deleted.');">🗑</a>
                                </td>
                            </tr>
                            <!-- Inline edit row -->
                            <tr id="<?php echo esc_attr($park_row_id); ?>" style="display:none;background:#0d1a0d;">
                                <td colspan="4" style="padding:16px 20px;">
                                    <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                                        <?php wp_nonce_field( 'bl_parks_nonce' ); ?>
                                        <input type="hidden" name="park_id"       value="<?php echo esc_attr( $park->id ); ?>">
                                        <input type="hidden" name="park_old_name" value="<?php echo esc_attr( $park->name ); ?>">
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <label style="color:#4caf50;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Park Name</label>
                                            <input type="text" name="park_new_name" value="<?php echo esc_attr( $park->name ); ?>"
                                                required style="background:#0d0d0d;border:1px solid #4caf50;color:#fff;padding:7px 10px;border-radius:4px;width:240px;font-size:14px;"
                                                placeholder="e.g. Six Flags Magic Mountain">
                                        </div>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <label style="color:#4caf50;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Location</label>
                                            <input type="text" name="park_location" value="<?php echo esc_attr( $park->location ); ?>"
                                                style="background:#0d0d0d;border:1px solid #4caf50;color:#fff;padding:7px 10px;border-radius:4px;width:200px;font-size:14px;"
                                                placeholder="e.g. Valencia, CA">
                                        </div>
                                        <div style="display:flex;gap:8px;align-items:center;">
                                            <button type="submit" name="bl_edit_park" class="button button-primary" style="background:#4caf50;border-color:#388e3c;">Save Changes</button>
                                            <button type="button" onclick="document.getElementById('<?php echo esc_js($park_row_id); ?>').style.display='none';" class="button">Cancel</button>
                                        </div>
                                        <?php if ( $park->review_count > 0 ) : ?>
                                        <p style="width:100%;margin:6px 0 0;color:#888;font-size:11px;">⚠️ Renaming this park will update all <?php echo (int)$park->review_count; ?> reviews that reference it.</p>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

            </div><!-- /right col -->
        </div>
    </div>
    <?php
}

// ─────────────────────────────────────────
// COASTERS ADMIN PAGE
// ─────────────────────────────────────────

function blusiast_coasters_admin_page() {
    global $wpdb;
    $ct  = $wpdb->prefix . 'bl_coasters';
    $rt  = $wpdb->prefix . 'bl_coaster_reviews';
    $agg = $wpdb->prefix . 'bl_coasters_agg';
    $pt  = $wpdb->prefix . 'bl_parks';

    $notice = '';

    // ── Handle: Add coaster ─────────────────────────
    if ( isset( $_POST['bl_add_coaster'] ) && check_admin_referer( 'bl_coasters_nonce' ) ) {
        $name  = trim( sanitize_text_field( $_POST['coaster_name']  ?? '' ) );
        $park  = trim( sanitize_text_field( $_POST['coaster_park']  ?? '' ) );
        $type  = sanitize_text_field( $_POST['coaster_type']        ?? '' );
        $status = sanitize_text_field( $_POST['coaster_status']     ?? 'operating' );
        $notes = sanitize_textarea_field( $_POST['coaster_notes']   ?? '' );
        if ( $name && $park ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $ct WHERE LOWER(name)=LOWER(%s) AND LOWER(park_name)=LOWER(%s) LIMIT 1", $name, $park
            ) );
            if ( ! $exists ) {
                $wpdb->insert( $ct, [ 'name' => $name, 'park_name' => $park, 'coaster_type' => $type, 'status' => $status, 'notes' => $notes ], [ '%s', '%s', '%s', '%s', '%s' ] );
                $notice = '<div class="notice notice-success"><p>Coaster <strong>' . esc_html( $name ) . '</strong> added.</p></div>';
            } else {
                $notice = '<div class="notice notice-warning"><p>A coaster with that name already exists at that park.</p></div>';
            }
        } else {
            $notice = '<div class="notice notice-error"><p>Coaster name and park are required.</p></div>';
        }
    }

    // ── Handle: Edit coaster ────────────────────────
    if ( isset( $_POST['bl_edit_coaster'] ) && check_admin_referer( 'bl_coasters_nonce' ) ) {
        $id      = absint( $_POST['coaster_id']   ?? 0 );
        $oldname = sanitize_text_field( $_POST['old_coaster_name'] ?? '' );
        $oldpark = sanitize_text_field( $_POST['old_coaster_park'] ?? '' );
        $newname = trim( sanitize_text_field( $_POST['coaster_name']   ?? '' ) );
        $newpark = trim( sanitize_text_field( $_POST['coaster_park']   ?? '' ) );
        $type    = sanitize_text_field( $_POST['coaster_type']         ?? '' );
        $status  = sanitize_text_field( $_POST['coaster_status']       ?? 'operating' );
        $notes   = sanitize_textarea_field( $_POST['coaster_notes']    ?? '' );
        if ( $id && $newname && $newpark ) {
            $wpdb->update( $ct, [ 'name' => $newname, 'park_name' => $newpark, 'coaster_type' => $type, 'status' => $status, 'notes' => $notes ],
                [ 'id' => $id ], [ '%s', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            // Cascade name changes to reviews + agg if name or park changed
            if ( $oldname && $oldpark && ( $oldname !== $newname || $oldpark !== $newpark ) ) {
                $r = $wpdb->update( $rt,  [ 'coaster_name' => $newname, 'park_name' => $newpark ], [ 'coaster_name' => $oldname, 'park_name' => $oldpark ], [ '%s', '%s' ], [ '%s', '%s' ] );
                $wpdb->update( $agg, [ 'coaster_name' => $newname, 'park_name' => $newpark ], [ 'coaster_name' => $oldname, 'park_name' => $oldpark ], [ '%s', '%s' ], [ '%s', '%s' ] );
                blusiast_update_coaster_aggregate( $newname, $newpark );
                $notice = '<div class="notice notice-success"><p>Coaster updated. ' . (int)$r . ' review(s) cascaded.</p></div>';
            } else {
                $notice = '<div class="notice notice-success"><p>Coaster <strong>' . esc_html( $newname ) . '</strong> updated.</p></div>';
            }
        }
    }

    // ── Handle: Delete coaster ──────────────────────
    if ( isset( $_GET['bl_delete_coaster'] ) && check_admin_referer( 'bl_delete_coaster_' . $_GET['bl_delete_coaster'] ) ) {
        $wpdb->delete( $ct, [ 'id' => absint( $_GET['bl_delete_coaster'] ) ], [ '%d' ] );
        $notice = '<div class="notice notice-success"><p>Coaster removed from list. Existing reviews are unaffected.</p></div>';
    }

    echo $notice;

    // ── Data ────────────────────────────────────────
    $coasters = $wpdb->get_results(
        "SELECT c.*, (SELECT COUNT(*) FROM $rt r WHERE r.coaster_name = c.name AND r.park_name = c.park_name) as review_count,
                     (SELECT AVG(r.rating) FROM $rt r WHERE r.coaster_name = c.name AND r.park_name = c.park_name) as avg_rating
         FROM $ct c ORDER BY c.park_name ASC, c.name ASC"
    );

    // Parks for the dropdown
    $parks = $wpdb->get_col( "SELECT name FROM $pt ORDER BY name ASC" );

    // Orphaned coasters — in reviews but not in bl_coasters
    $review_coasters = $wpdb->get_results(
        "SELECT coaster_name, park_name, COUNT(*) as review_count
         FROM $rt WHERE coaster_name != '' AND park_name != ''
         GROUP BY coaster_name, park_name ORDER BY park_name ASC, coaster_name ASC"
    );
    $registered = array_map( fn($c) => strtolower($c->name) . '|' . strtolower($c->park_name), $coasters );
    $orphans = array_filter( $review_coasters, fn($rc) => ! in_array( strtolower($rc->coaster_name) . '|' . strtolower($rc->park_name), $registered ) );

    $type_options    = [ '' => '— Unknown —', 'steel' => 'Steel', 'wooden' => 'Wooden', 'hybrid' => 'Hybrid (RMC)', 'inverted' => 'Inverted', 'launch' => 'Launch', 'family' => 'Family', 'mine_train' => 'Mine Train', 'spinning' => 'Spinning', 'other' => 'Other' ];
    $status_options  = [ 'operating' => '🟢 Operating', 'sbno' => '🟡 SBNO', 'seasonal' => '🔵 Seasonal', 'removed' => '🔴 Removed' ];

    $inp_style = 'background:#0d0d0d;border:1px solid #555;color:#fff;padding:7px 10px;border-radius:4px;width:100%;box-sizing:border-box;font-size:13px;';
    $sel_style = $inp_style;
    ?>
    <div class="bl-crm-wrap">
        <?php blusiast_admin_header( 'Manage Coasters' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-coasters' ); ?>

        <div style="display:grid;grid-template-columns:300px 1fr;gap:24px;margin-top:20px;align-items:start;">

            <!-- ── Left: Add coaster ─────────────────── -->
            <div style="display:flex;flex-direction:column;gap:16px;">
                <div style="background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">➕ Add a Coaster</h3>
                    <form method="post" style="display:flex;flex-direction:column;gap:10px;">
                        <?php wp_nonce_field( 'bl_coasters_nonce' ); ?>
                        <div>
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Coaster Name *</label>
                            <input type="text" name="coaster_name" required style="<?php echo $inp_style; ?>" placeholder="e.g. Steel Vengeance">
                        </div>
                        <div>
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Park *</label>
                            <input type="text" name="coaster_park" required list="bl-parks-datalist" style="<?php echo $inp_style; ?>" placeholder="e.g. Cedar Point">
                            <datalist id="bl-parks-datalist">
                                <?php foreach ( $parks as $p ) : ?>
                                <option value="<?php echo esc_attr($p); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div>
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Type</label>
                            <select name="coaster_type" style="<?php echo $sel_style; ?>">
                                <?php foreach ( $type_options as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Status</label>
                            <select name="coaster_status" style="<?php echo $sel_style; ?>">
                                <?php foreach ( $status_options as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="color:#ccc;display:block;margin-bottom:4px;font-size:12px;">Notes (internal)</label>
                            <textarea name="coaster_notes" rows="2" style="<?php echo $inp_style; ?>" placeholder="Optional admin notes"></textarea>
                        </div>
                        <button type="submit" name="bl_add_coaster" class="button button-primary">Add Coaster</button>
                    </form>
                </div>

                <?php if ( $orphans ) : ?>
                <div style="background:#1a1000;border:1px solid #cc8800;border-radius:8px;padding:20px;">
                    <h3 style="margin-top:0;color:#cc8800;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">⚠️ Unregistered Coasters in Reviews</h3>
                    <p style="color:#999;font-size:12px;margin:0 0 12px;">These coasters appear in reviews but aren't in the official list.</p>
                    <table class="widefat" style="background:#0d0d0d;color:#fff;">
                        <thead style="background:#1a1a1a;">
                            <tr><th style="color:#cc8800;">Coaster</th><th style="color:#cc8800;">Park</th><th style="color:#cc8800;">Reviews</th><th style="color:#cc8800;">Add</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $orphans as $o ) : ?>
                        <tr style="border-top:1px solid #222;">
                            <td style="color:#fff;font-size:12px;font-weight:600;"><?php echo esc_html($o->coaster_name); ?></td>
                            <td style="color:#999;font-size:11px;"><?php echo esc_html($o->park_name); ?></td>
                            <td style="color:#999;"><?php echo (int)$o->review_count; ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field( 'bl_coasters_nonce' ); ?>
                                    <input type="hidden" name="coaster_name" value="<?php echo esc_attr($o->coaster_name); ?>">
                                    <input type="hidden" name="coaster_park" value="<?php echo esc_attr($o->park_name); ?>">
                                    <input type="hidden" name="coaster_status" value="operating">
                                    <button type="submit" name="bl_add_coaster" class="button button-small">Add</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div><!-- /left col -->

            <!-- ── Right: Coaster list ──────────────── -->
            <div>
                <div style="background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                        <h3 style="margin:0;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.06em;">
                            Official Coaster List <span style="color:#666;font-weight:400;">(<?php echo count($coasters); ?>)</span>
                        </h3>
                        <input type="search" id="bl-coaster-search" placeholder="Filter coasters…"
                            style="background:#0d0d0d;border:1px solid #444;color:#fff;padding:6px 12px;border-radius:4px;width:200px;font-size:13px;">
                    </div>
                    <?php if ( empty( $coasters ) ) : ?>
                        <p style="color:#888;">No coasters yet. Add one to get started.</p>
                    <?php else : ?>
                    <table class="widefat" id="bl-coasters-table" style="background:#0d0d0d;color:#fff;">
                        <thead style="background:#1a1a1a;">
                            <tr>
                                <th style="color:#ccc;">Coaster</th>
                                <th style="color:#ccc;">Park</th>
                                <th style="color:#ccc;">Type</th>
                                <th style="color:#ccc;">Status</th>
                                <th style="color:#ccc;">Reviews</th>
                                <th style="color:#ccc;">Avg</th>
                                <th style="color:#ccc;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $coasters as $c ) :
                            $c_row_id = 'bl-coaster-edit-' . $c->id;
                            $status_label = $status_options[ $c->status ] ?? $c->status;
                        ?>
                            <tr class="bl-coaster-row" style="border-top:1px solid #222;">
                                <td style="color:#fff;font-weight:600;"><?php echo esc_html($c->name); ?></td>
                                <td style="color:#999;font-size:12px;"><?php echo esc_html($c->park_name); ?></td>
                                <td style="color:#888;font-size:12px;"><?php echo esc_html( $type_options[$c->coaster_type] ?? ucwords(str_replace('_',' ',$c->coaster_type)) ); ?></td>
                                <td style="font-size:12px;"><?php echo esc_html($status_label); ?></td>
                                <td style="color:#999;text-align:center;"><?php echo (int)$c->review_count; ?></td>
                                <td style="color:var(--bl-red,#cc0000);font-weight:700;"><?php echo $c->avg_rating ? number_format($c->avg_rating,1) : '—'; ?></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="button button-small"
                                        onclick="document.getElementById('<?php echo esc_js($c_row_id); ?>').style.display = document.getElementById('<?php echo esc_js($c_row_id); ?>').style.display === 'none' ? 'table-row' : 'none';">
                                        ✏️ Edit
                                    </button>
                                    <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=blusiast-coasters&bl_delete_coaster=' . $c->id), 'bl_delete_coaster_' . $c->id ); ?>"
                                       class="button button-small" style="color:#cc0000;border-color:#cc0000;"
                                       onclick="return confirm('Remove this coaster from the list? Reviews will NOT be deleted.');">🗑</a>
                                </td>
                            </tr>
                            <!-- Inline edit row -->
                            <tr id="<?php echo esc_attr($c_row_id); ?>" style="display:none;background:#0d0d1a;">
                                <td colspan="7" style="padding:16px 20px;">
                                    <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                                        <?php wp_nonce_field( 'bl_coasters_nonce' ); ?>
                                        <input type="hidden" name="coaster_id"       value="<?php echo esc_attr($c->id); ?>">
                                        <input type="hidden" name="old_coaster_name" value="<?php echo esc_attr($c->name); ?>">
                                        <input type="hidden" name="old_coaster_park" value="<?php echo esc_attr($c->park_name); ?>">
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <label style="color:#6699ff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Coaster Name</label>
                                            <input type="text" name="coaster_name" value="<?php echo esc_attr($c->name); ?>"
                                                required style="background:#0d0d0d;border:1px solid #6699ff;color:#fff;padding:7px 10px;border-radius:4px;width:220px;font-size:14px;">
                                        </div>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <label style="color:#6699ff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Park</label>
                                            <input type="text" name="coaster_park" value="<?php echo esc_attr($c->park_name); ?>"
                                                required list="bl-parks-datalist" style="background:#0d0d0d;border:1px solid #6699ff;color:#fff;padding:7px 10px;border-radius:4px;width:200px;font-size:14px;">
                                        </div>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <label style="color:#6699ff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Type</label>
                                            <select name="coaster_type" style="background:#0d0d0d;border:1px solid #6699ff;color:#fff;padding:7px 10px;border-radius:4px;width:150px;font-size:13px;">
                                                <?php foreach ( $type_options as $val => $lbl ) : ?>
                                                <option value="<?php echo esc_attr($val); ?>" <?php selected($c->coaster_type, $val); ?>><?php echo esc_html($lbl); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <label style="color:#6699ff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Status</label>
                                            <select name="coaster_status" style="background:#0d0d0d;border:1px solid #6699ff;color:#fff;padding:7px 10px;border-radius:4px;width:150px;font-size:13px;">
                                                <?php foreach ( $status_options as $val => $lbl ) : ?>
                                                <option value="<?php echo esc_attr($val); ?>" <?php selected($c->status, $val); ?>><?php echo esc_html($lbl); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:200px;">
                                            <label style="color:#6699ff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Notes</label>
                                            <input type="text" name="coaster_notes" value="<?php echo esc_attr($c->notes); ?>"
                                                style="background:#0d0d0d;border:1px solid #6699ff;color:#fff;padding:7px 10px;border-radius:4px;width:100%;font-size:13px;" placeholder="Internal notes">
                                        </div>
                                        <div style="display:flex;gap:8px;align-items:center;">
                                            <button type="submit" name="bl_edit_coaster" class="button button-primary" style="background:#3333cc;border-color:#2222aa;">Save Changes</button>
                                            <button type="button" onclick="document.getElementById('<?php echo esc_js($c_row_id); ?>').style.display='none';" class="button">Cancel</button>
                                        </div>
                                        <?php if ( $c->review_count > 0 ) : ?>
                                        <p style="width:100%;margin:6px 0 0;color:#888;font-size:11px;">⚠️ Changing the name or park will cascade to <?php echo (int)$c->review_count; ?> existing review(s).</p>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div><!-- /right col -->
        </div><!-- /grid -->
    </div>
    <script>
    (function() {
        var input = document.getElementById('bl-coaster-search');
        if (!input) return;
        input.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#bl-coasters-table .bl-coaster-row').forEach(function(row) {
                var text = row.textContent.toLowerCase();
                var match = !q || text.indexOf(q) !== -1;
                row.style.display = match ? '' : 'none';
                // also hide/show the sibling edit row
                var next = row.nextElementSibling;
                if (next && next.id && next.id.indexOf('bl-coaster-edit-') === 0) {
                    if (!match) next.style.display = 'none';
                }
            });
        });
    })();
    </script>
    <?php
}
