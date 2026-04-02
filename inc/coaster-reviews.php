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
    if ( get_option( 'blusiast_reviews_db_version' ) === '1.0' ) return;

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

    update_option( 'blusiast_reviews_db_version', '1.0' );
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

add_action( 'wp_ajax_blusiast_submit_review', 'blusiast_submit_review' );

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
        'created_at'   => current_time( 'mysql' ),
    ], [ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ] );

    blusiast_update_coaster_aggregate( $coaster_name, $park_name );

    wp_send_json_success( [ 'message' => 'Review submitted! Thanks for sharing with the crew.' ] );
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
                    <div class="review-card">
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
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="review-carousel__btn review-carousel__btn--prev" aria-label="Previous" id="review-prev">&#8592;</button>
                <button class="review-carousel__btn review-carousel__btn--next" aria-label="Next" id="review-next">&#8594;</button>
            </div>
        </div>
    </section>
    <script>
    (function(){
        var track = document.getElementById('review-track');
        var prev  = document.getElementById('review-prev');
        var next  = document.getElementById('review-next');
        if(!track||!prev||!next) return;
        var idx = 0;
        function cardW(){ return track.children[0] ? track.children[0].offsetWidth + 20 : 300; }
        function go(n){
            var max = track.children.length - Math.floor(track.parentElement.offsetWidth / cardW());
            idx = Math.max(0, Math.min(n, max));
            track.style.transform = 'translateX(-' + (idx * cardW()) + 'px)';
        }
        prev.addEventListener('click', function(){ go(idx - 1); });
        next.addEventListener('click', function(){ go(idx + 1); });
    })();
    </script>
    <?php
    return ob_get_clean();
}


// ─────────────────────────────────────────
// 5. ADMIN — Coaster Reviews CMS page
// ─────────────────────────────────────────

add_action( 'admin_menu', 'blusiast_reviews_menu', 21 );

function blusiast_reviews_menu() {
    global $wpdb;
    $rt = $wpdb->prefix . 'bl_coaster_reviews';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$rt'" ) !== $rt ) return;
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $rt" );
    add_submenu_page( 'blusiast-cms', 'Coaster Reviews', 'Reviews (' . $count . ')', 'manage_options',
        'blusiast-reviews', 'blusiast_reviews_admin_page' );
}

function blusiast_reviews_admin_page() {
    global $wpdb;
    $rt  = $wpdb->prefix . 'bl_coaster_reviews';
    $agg = $wpdb->prefix . 'bl_coasters_agg';
    $mt  = $wpdb->prefix . 'bl_members';

    $view = sanitize_key( $_GET['rview'] ?? 'all' );

    $reviews = $wpdb->get_results(
        "SELECT r.*, m.first_name, m.last_name, m.handle, m.dir_name_pref
         FROM $rt r LEFT JOIN $mt m ON m.id = r.member_id
         ORDER BY r.created_at DESC"
    );
    $coasters = $wpdb->get_results( "SELECT * FROM $agg ORDER BY avg_rating DESC, review_count DESC" );
    $thrill_map = [ 'mild' => '🟢', 'moderate' => '🟡', 'intense' => '🟠', 'extreme' => '🔴' ];
    ?>
    <div class="bl-cms-wrap">
        <?php blusiast_admin_header( 'Coaster Reviews' ); ?>
        <?php blusiast_admin_tabs( 'blusiast-reviews' ); ?>

        <div style="display:flex;gap:8px;margin-bottom:20px;">
            <a href="?page=blusiast-reviews&rview=all" class="bl-btn-sm <?php echo $view==='all'?'':''; ?>" style="<?php echo $view==='all'?'background:var(--bl-red);color:#fff;border-color:var(--bl-red);':'' ?>">All Reviews (<?php echo count($reviews); ?>)</a>
            <a href="?page=blusiast-reviews&rview=coasters" class="bl-btn-sm" style="<?php echo $view==='coasters'?'background:var(--bl-red);color:#fff;border-color:var(--bl-red);':'' ?>">By Coaster (<?php echo count($coasters); ?>)</a>
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
                <thead><tr><th>Coaster</th><th>Park</th><th>Member</th><th>Rating</th><th>Thrill</th><th>Review</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ( $reviews as $r ) :
                    $use_h = !empty($r->handle) && ($r->dir_name_pref??'real')==='handle';
                    $author = $use_h ? '@'.$r->handle : ($r->first_name ? $r->first_name.' '.$r->last_name : '—');
                ?>
                <tr>
                    <td class="bl-td-name"><?php echo esc_html($r->coaster_name); ?></td>
                    <td style="font-size:12px;"><?php echo esc_html($r->park_name); ?></td>
                    <td style="font-size:13px;"><?php echo esc_html($author); ?></td>
                    <td><span style="font-family:var(--bl-fd);font-size:20px;font-weight:800;color:var(--bl-red);"><?php echo (int)$r->rating; ?></span><span style="font-size:11px;color:var(--bl-g1);">/10</span></td>
                    <td style="font-size:13px;"><?php echo isset($thrill_map[$r->thrill_level]) ? $thrill_map[$r->thrill_level].' '.ucfirst($r->thrill_level) : '—'; ?></td>
                    <td style="font-size:12px;color:var(--bl-g2);max-width:240px;"><?php echo esc_html(wp_trim_words($r->review_text, 20)); ?></td>
                    <td style="font-size:11px;white-space:nowrap;"><?php echo esc_html(date('M j, Y', strtotime($r->created_at))); ?></td>
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
