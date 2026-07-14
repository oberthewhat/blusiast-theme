<?php
/**
 * Template Name: Coasters
 *
 * /coasters — community-ranked roller coaster leaderboard.
 * Rankings are driven by bl_coasters_agg (avg of member ratings).
 * Visitors can filter by park, type, and thrill level.
 * Logged-in members can submit reviews inline.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

global $wpdb;
$agg = $wpdb->prefix . 'bl_coasters_agg';
$rt  = $wpdb->prefix . 'bl_coaster_reviews';

// ── Filters ─────────────────────────────
$filter_park   = sanitize_text_field( $_GET['park']   ?? '' );
$filter_thrill = sanitize_text_field( $_GET['thrill'] ?? '' );
$filter_type   = sanitize_text_field( $_GET['type']   ?? '' );
$sort          = sanitize_key( $_GET['sort'] ?? 'rating' );
$page_num      = max( 1, absint( $_GET['pg'] ?? 1 ) );
$per_page      = 12;

// ── Park list for filter dropdown ────────
$parks = $wpdb->get_col( "SELECT DISTINCT park_name FROM $agg ORDER BY park_name ASC" );

// ── Coaster types from reviews ────────────
$types = $wpdb->get_col( "SELECT DISTINCT coaster_type FROM $rt WHERE coaster_type != '' ORDER BY coaster_type ASC" );

// ── Build coaster query ──────────────────
$where_parts = [];
$where_vals  = [];

if ( $filter_park ) {
    $where_parts[] = 'a.park_name = %s';
    $where_vals[]  = $filter_park;
}

$where_sql = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

$order_sql = match( $sort ) {
    'reviews' => 'ORDER BY a.review_count DESC, a.avg_rating DESC',
    'name'    => 'ORDER BY a.coaster_name ASC',
    default   => 'ORDER BY a.avg_rating DESC, a.review_count DESC',
};

// Pull from aggregate, join for thrill/type filter if needed
if ( $filter_thrill || $filter_type ) {
    $sub_where = [];
    if ( $filter_thrill ) { $sub_where[] = "r.thrill_level = '" . esc_sql( $filter_thrill ) . "'"; }
    if ( $filter_type )   { $sub_where[] = "r.coaster_type = '" . esc_sql( $filter_type )   . "'"; }
    $sub_sql   = 'WHERE ' . implode( ' AND ', $sub_where );

    $coasters_sql = "
        SELECT a.*,
               COALESCE(sub.dominant_thrill,'') AS dominant_thrill,
               COALESCE(sub.dominant_type,'')  AS dominant_type
        FROM $agg a
        INNER JOIN (
            SELECT coaster_name, park_name,
                   (SELECT thrill_level FROM $rt WHERE coaster_name = r.coaster_name AND park_name = r.park_name AND thrill_level != '' GROUP BY thrill_level ORDER BY COUNT(*) DESC LIMIT 1) AS dominant_thrill,
                   (SELECT coaster_type FROM $rt WHERE coaster_name = r.coaster_name AND park_name = r.park_name AND coaster_type  != '' GROUP BY coaster_type  ORDER BY COUNT(*) DESC LIMIT 1) AS dominant_type
            FROM $rt r $sub_sql
            GROUP BY coaster_name, park_name
        ) sub ON sub.coaster_name = a.coaster_name AND sub.park_name = a.park_name
        " . ( $where_parts ? str_replace( 'a.park_name', 'a.park_name', $where_sql ) : '' ) . "
        $order_sql";
} else {
    $coasters_sql = "
        SELECT a.*,
               (SELECT thrill_level FROM $rt r WHERE r.coaster_name=a.coaster_name AND r.park_name=a.park_name AND r.thrill_level!='' GROUP BY r.thrill_level ORDER BY COUNT(*) DESC LIMIT 1) AS dominant_thrill,
               (SELECT coaster_type  FROM $rt r WHERE r.coaster_name=a.coaster_name AND r.park_name=a.park_name AND r.coaster_type !='' GROUP BY r.coaster_type  ORDER BY COUNT(*) DESC LIMIT 1) AS dominant_type
        FROM $agg a
        $where_sql
        $order_sql";
}

$all_coasters  = $where_vals ? $wpdb->get_results( $wpdb->prepare( $coasters_sql, ...$where_vals ) ) : $wpdb->get_results( $coasters_sql );
$total         = count( $all_coasters );
$total_pages   = max( 1, ceil( $total / $per_page ) );
$coasters      = array_slice( $all_coasters, ( $page_num - 1 ) * $per_page, $per_page );

// ── Stats bar ───────────────────────────
$stats = $wpdb->get_row( "SELECT COUNT(*) as total_reviews, COUNT(DISTINCT coaster_name) as total_coasters FROM $rt" );

// ── Thrill colour map ─────────────────────
$thrill_map = [
    'mild'     => [ 'label' => 'Mild',     'icon' => '🟢', 'cls' => 'thrill--mild' ],
    'moderate' => [ 'label' => 'Moderate', 'icon' => '🟡', 'cls' => 'thrill--moderate' ],
    'intense'  => [ 'label' => 'Intense',  'icon' => '🟠', 'cls' => 'thrill--intense' ],
    'extreme'  => [ 'label' => 'Extreme',  'icon' => '🔴', 'cls' => 'thrill--extreme' ],
];

// ── Recent reviews for sidebar ───────────
$recent_reviews = $wpdb->get_results(
    "SELECT r.*, m.first_name, m.last_name, m.handle, m.dir_name_pref
     FROM $rt r
     LEFT JOIN {$wpdb->prefix}bl_members m ON m.id = r.member_id
     ORDER BY r.created_at DESC LIMIT 5"
);

// ── Helper: build filter URL ─────────────
function bl_coasters_url( $overrides = [] ) {
    $base = [ 'park' => '', 'thrill' => '', 'type' => '', 'sort' => 'rating', 'pg' => 1 ];
    $args = array_filter( array_merge( $base, array_map( 'sanitize_text_field', $_GET ), $overrides ) );
    unset( $args['pg'] ); // reset page on filter changes unless explicitly set
    if ( isset( $overrides['pg'] ) ) $args['pg'] = $overrides['pg'];
    return add_query_arg( $args, get_permalink() );
}
?>

<div class="coasters-page">

    <!-- ══ HERO BANNER ═══════════════════════════════ -->
    <section class="coasters-hero">
        <div class="coasters-hero__bg"></div>
        <div class="container">
            <p class="bl-label">Community Rankings</p>
            <h1 class="bl-display-xl coasters-hero__title">
                Coaster<br><span class="coasters-hero__accent">Rankings</span>
            </h1>
            <p class="coasters-hero__sub">
                Rated by the Blusiast crew. Real rides. Real reviews. No cap.
            </p>

            <!-- Stat pills -->
            <div class="coasters-stats-row">
                <div class="coasters-stat">
                    <span class="coasters-stat__num"><?php echo number_format( (int) $stats->total_coasters ); ?></span>
                    <span class="coasters-stat__lbl">Coasters Rated</span>
                </div>
                <div class="coasters-stat-divider"></div>
                <div class="coasters-stat">
                    <span class="coasters-stat__num"><?php echo number_format( (int) $stats->total_reviews ); ?></span>
                    <span class="coasters-stat__lbl">Crew Reviews</span>
                </div>
                <div class="coasters-stat-divider"></div>
                <div class="coasters-stat">
                    <span class="coasters-stat__num"><?php echo count( $parks ) ?: '—'; ?></span>
                    <span class="coasters-stat__lbl">Parks</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ MAIN LAYOUT ════════════════════════════════ -->
    <div class="container coasters-layout">

        <!-- ── FILTERS + RANKINGS ─────────────────── -->
        <div class="coasters-main">

            <!-- ── NEW REVIEW CARD (above filters) ─── -->
            <?php if ( is_user_logged_in() ) : ?>
            <div class="coasters-new-review-card" id="top-new-review-wrap">
                <div class="coasters-new-review-card__left">
                    <span class="coasters-new-review-card__icon">🎢</span>
                    <div>
                        <p class="bl-label" style="margin:0 0 2px;">Blusiast Crew</p>
                        <h3 class="coasters-new-review-card__heading">Reviewed a ride lately?</h3>
                        <p class="coasters-new-review-card__sub">Add your rating and drop it on the leaderboard.</p>
                    </div>
                </div>
                <button class="bl-btn bl-btn--primary coasters-new-review-card__btn" data-open-review-modal>
                    + New Review
                </button>
            </div>
            <?php else : ?>
            <div class="coasters-new-review-card coasters-new-review-card--guest">
                <div class="coasters-new-review-card__left">
                    <span class="coasters-new-review-card__icon">🎢</span>
                    <div>
                        <p class="bl-label" style="margin:0 0 2px;">Blusiast Crew</p>
                        <h3 class="coasters-new-review-card__heading">Got a ride to rate?</h3>
                        <p class="coasters-new-review-card__sub">Log in to add your reviews to the leaderboard.</p>
                    </div>
                </div>
                <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="bl-btn bl-btn--primary coasters-new-review-card__btn">
                    Log In to Review
                </a>
            </div>
            <?php endif; ?>

            <!-- Filter bar -->
            <div class="coasters-filters" id="coasters-filters">
                <form method="GET" action="<?php echo esc_url( get_permalink() ); ?>" class="coasters-filters__form">

                    <!-- Park -->
                    <div class="coasters-filters__group">
                        <label class="coasters-filters__label" for="cf-park">Park</label>
                        <select name="park" id="cf-park" class="coasters-filters__select" onchange="this.form.submit()">
                            <option value="">All Parks</option>
                            <?php foreach ( $parks as $p ) : ?>
                            <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $filter_park, $p ); ?>>
                                <?php echo esc_html( $p ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Thrill -->
                    <div class="coasters-filters__group">
                        <label class="coasters-filters__label" for="cf-thrill">Thrill</label>
                        <select name="thrill" id="cf-thrill" class="coasters-filters__select" onchange="this.form.submit()">
                            <option value="">All Levels</option>
                            <?php foreach ( $thrill_map as $key => $t ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_thrill, $key ); ?>>
                                <?php echo esc_html( $t['icon'] . ' ' . $t['label'] ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Type -->
                    <?php if ( $types ) : ?>
                    <div class="coasters-filters__group">
                        <label class="coasters-filters__label" for="cf-type">Type</label>
                        <select name="type" id="cf-type" class="coasters-filters__select" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php foreach ( $types as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $filter_type, $t ); ?>>
                                <?php echo esc_html( ucwords( $t ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Sort -->
                    <div class="coasters-filters__group coasters-filters__group--sort">
                        <label class="coasters-filters__label" for="cf-sort">Sort</label>
                        <select name="sort" id="cf-sort" class="coasters-filters__select" onchange="this.form.submit()">
                            <option value="rating"  <?php selected( $sort, 'rating' ); ?>>Highest Rated</option>
                            <option value="reviews" <?php selected( $sort, 'reviews' ); ?>>Most Reviewed</option>
                            <option value="name"    <?php selected( $sort, 'name' ); ?>>A – Z</option>
                        </select>
                    </div>

                    <?php if ( $filter_park || $filter_thrill || $filter_type || $sort !== 'rating' ) : ?>
                    <a href="<?php echo esc_url( get_permalink() ); ?>" class="coasters-filters__clear">
                        ✕ Clear
                    </a>
                    <?php endif; ?>

                    <noscript><button type="submit" class="bl-btn bl-btn--sm">Filter</button></noscript>
                </form>

                <!-- Results count -->
                <p class="coasters-filters__count">
                    <?php if ( $total ) : ?>
                        Showing <strong><?php echo ( ( $page_num - 1 ) * $per_page ) + 1; ?>–<?php echo min( $page_num * $per_page, $total ); ?></strong>
                        of <strong><?php echo $total; ?></strong> coasters
                    <?php else : ?>
                        No coasters match your filters yet.
                    <?php endif; ?>
                </p>
            </div>

            <!-- ── RANKINGS LIST ──────────────────── -->
            <?php if ( $coasters ) : ?>
            <ol class="coasters-list" start="<?php echo ( ( $page_num - 1 ) * $per_page ) + 1; ?>">
                <?php foreach ( $coasters as $i => $c ) :
                    $rank          = ( ( $page_num - 1 ) * $per_page ) + $i + 1;
                    $thrill_key    = $c->dominant_thrill ?? '';
                    $thrill_data   = $thrill_map[ $thrill_key ] ?? null;
                    $bar_width     = round( ( $c->avg_rating / 10 ) * 100 );
                    $is_top3       = ( $rank <= 3 && $page_num === 1 && ! $filter_park && ! $filter_thrill && ! $filter_type );
                    $medal         = [ 1 => '🥇', 2 => '🥈', 3 => '🥉' ][ $rank ] ?? null;

                    // Pull latest review snippet
                    $snippet = $wpdb->get_var( $wpdb->prepare(
                        "SELECT review_text FROM $rt WHERE coaster_name=%s AND park_name=%s AND review_text!='' ORDER BY created_at DESC LIMIT 1",
                        $c->coaster_name, $c->park_name
                    ) );
                ?>
                <li class="coaster-card <?php echo $is_top3 ? 'coaster-card--top3' : ''; ?>" data-rank="<?php echo $rank; ?>">

                    <!-- Rank badge -->
                    <div class="coaster-card__rank">
                        <?php if ( $medal ) : ?>
                            <span class="coaster-card__medal"><?php echo $medal; ?></span>
                        <?php endif; ?>
                        <span class="coaster-card__rank-num"><?php echo $rank; ?></span>
                    </div>

                    <!-- Body -->
                    <div class="coaster-card__body">
                        <div class="coaster-card__header">
                            <div>
                                <h2 class="coaster-card__name"><?php echo esc_html( $c->coaster_name ); ?></h2>
                                <p class="coaster-card__park"><?php echo esc_html( $c->park_name ); ?></p>
                            </div>
                            <div class="coaster-card__badges">
                                <?php if ( $thrill_data ) : ?>
                                <span class="bl-badge <?php echo esc_attr( $thrill_data['cls'] ); ?>">
                                    <?php echo $thrill_data['icon']; ?> <?php echo esc_html( $thrill_data['label'] ); ?>
                                </span>
                                <?php endif; ?>
                                <?php if ( $c->dominant_type ) : ?>
                                <span class="bl-badge bl-badge--outline"><?php echo esc_html( ucwords( $c->dominant_type ) ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Rating bar -->
                        <div class="coaster-card__rating-row">
                            <div class="coaster-card__bar-wrap">
                                <div class="coaster-card__bar" style="--bar-w: <?php echo $bar_width; ?>%"></div>
                            </div>
                            <span class="coaster-card__score">
                                <?php echo number_format( $c->avg_rating, 1 ); ?><small>/10</small>
                            </span>
                        </div>

                        <!-- Review count + snippet + read reviews -->
                        <div class="coaster-card__meta-row">
                            <span class="coaster-card__review-count">
                                <?php echo (int) $c->review_count; ?> <?php echo $c->review_count === '1' ? 'review' : 'reviews'; ?>
                            </span>
                            <?php if ( $snippet ) : ?>
                            <p class="coaster-card__snippet">
                                "<?php echo esc_html( wp_trim_words( $snippet, 18, '…' ) ); ?>"
                            </p>
                            <?php endif; ?>
                            <?php if ( (int) $c->review_count > 0 ) : ?>
                            <button
                                class="coaster-card__read-reviews bl-btn bl-btn--ghost bl-btn--sm"
                                data-coaster="<?php echo esc_attr( $c->coaster_name ); ?>"
                                data-park="<?php echo esc_attr( $c->park_name ); ?>"
                                data-count="<?php echo (int) $c->review_count; ?>"
                                style="margin-top:10px;"
                            >
                                📖 Read <?php echo (int) $c->review_count === 1 ? 'the Review' : 'All ' . (int) $c->review_count . ' Reviews'; ?>
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Write a review CTA -->
                        <?php if ( is_user_logged_in() ) : ?>
                        <button
                            class="bl-btn bl-btn--ghost bl-btn--sm coaster-card__review-btn"
                            data-coaster="<?php echo esc_attr( $c->coaster_name ); ?>"
                            data-park="<?php echo esc_attr( $c->park_name ); ?>"
                            aria-expanded="false"
                        >
                            + Write a Review
                        </button>

                        <!-- Inline review form (hidden) -->
                        <div class="coaster-inline-form" hidden>
                            <form class="coaster-inline-form__inner" data-coaster="<?php echo esc_attr( $c->coaster_name ); ?>" data-park="<?php echo esc_attr( $c->park_name ); ?>">
                                <?php wp_nonce_field( 'blusiast_portal_nonce', 'nonce' ); ?>
                                <input type="hidden" name="coaster_name" value="<?php echo esc_attr( $c->coaster_name ); ?>">
                                <input type="hidden" name="park_name"    value="<?php echo esc_attr( $c->park_name ); ?>">

                                <div class="cif-row">
                                    <div class="cif-field cif-field--rating">
                                        <label class="cif-label">Your Rating</label>
                                        <div class="cif-rating-picker" role="group" aria-label="Rating out of 10">
                                            <?php for ( $n = 1; $n <= 10; $n++ ) : ?>
                                            <button type="button" class="cif-rating-btn" data-val="<?php echo $n; ?>" aria-label="<?php echo $n; ?> out of 10">
                                                <?php echo $n; ?>
                                            </button>
                                            <?php endfor; ?>
                                        </div>
                                        <input type="hidden" name="rating" value="7" class="cif-rating-val">
                                    </div>

                                    <div class="cif-field">
                                        <label class="cif-label" for="cif-thrill-<?php echo $rank; ?>">Thrill Level</label>
                                        <select name="thrill_level" id="cif-thrill-<?php echo $rank; ?>" class="cif-select">
                                            <option value="">Choose…</option>
                                            <?php foreach ( $thrill_map as $key => $t ) : ?>
                                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="cif-field">
                                        <label class="cif-label" for="cif-date-<?php echo $rank; ?>">Ride Date</label>
                                        <input type="date" name="ride_date" id="cif-date-<?php echo $rank; ?>" class="cif-input">
                                    </div>
                                </div>

                                <div class="cif-field">
                                    <label class="cif-label" for="cif-text-<?php echo $rank; ?>">Your Review</label>
                                    <textarea name="review_text" id="cif-text-<?php echo $rank; ?>" class="cif-textarea" rows="3" placeholder="Tell the crew what you thought…" required></textarea>
                                </div>

                                <div class="cif-actions">
                                    <button type="submit" class="bl-btn bl-btn--primary bl-btn--sm">Submit Review</button>
                                    <button type="button" class="cif-cancel bl-btn bl-btn--ghost bl-btn--sm">Cancel</button>
                                </div>
                                <p class="cif-msg" hidden></p>
                            </form>
                        </div>
                        <?php elseif ( ! is_user_logged_in() ) : ?>
                        <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="coaster-card__login-cta">
                            Log in to review →
                        </a>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ol>

            <!-- ── EMPTY STATE ───────────────────── -->
            <?php else : ?>
            <div class="coasters-empty">
                <p class="coasters-empty__icon">🎢</p>
                <h2>No Coasters Yet</h2>
                <?php if ( $filter_park || $filter_thrill ) : ?>
                <p>No results for those filters. <a href="<?php echo esc_url( get_permalink() ); ?>">Clear filters</a></p>
                <?php else : ?>
                <p>Be the first to <a href="<?php echo esc_url( is_user_logged_in() ? blusiast_portal_url('reviews') : wp_login_url( get_permalink() ) ); ?>">submit a review</a> and start the leaderboard.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── PAGINATION ─────────────────────── -->
            <?php if ( $total_pages > 1 ) : ?>
            <nav class="coasters-pagination" aria-label="Rankings pages">
                <?php if ( $page_num > 1 ) : ?>
                <a href="<?php echo esc_url( bl_coasters_url( [ 'pg' => $page_num - 1 ] ) ); ?>" class="coasters-pagination__btn" aria-label="Previous page">← Prev</a>
                <?php endif; ?>

                <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                <a href="<?php echo esc_url( bl_coasters_url( [ 'pg' => $p ] ) ); ?>"
                   class="coasters-pagination__btn <?php echo $p === $page_num ? 'coasters-pagination__btn--active' : ''; ?>"
                   aria-current="<?php echo $p === $page_num ? 'page' : 'false'; ?>">
                    <?php echo $p; ?>
                </a>
                <?php endfor; ?>

                <?php if ( $page_num < $total_pages ) : ?>
                <a href="<?php echo esc_url( bl_coasters_url( [ 'pg' => $page_num + 1 ] ) ); ?>" class="coasters-pagination__btn" aria-label="Next page">Next →</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

        </div><!-- /.coasters-main -->

        <!-- ── SIDEBAR ────────────────────────────── -->
        <aside class="coasters-sidebar">

            <!-- Write a review CTA (logged-out) -->
            <?php if ( ! is_user_logged_in() ) : ?>
            <div class="coasters-sidebar-card coasters-sidebar-card--cta">
                <p class="bl-label">Got Opinions?</p>
                <h3>Rate Your Rides</h3>
                <p>Members rate and review every coaster they ride. Join the crew to add yours.</p>
                <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="bl-btn bl-btn--primary">Log In to Review</a>
            </div>
            <?php else : ?>
            <!-- Quick-add for logged in users -->
            <div class="coasters-sidebar-card coasters-sidebar-card--cta" id="quick-add-wrap">
                <p class="bl-label">Add a Coaster</p>
                <h3>Reviewed one lately?</h3>
                <p>Don't see your ride on the list? Add it below.</p>
                <button class="bl-btn bl-btn--primary" data-open-review-modal>+ New Review</button>

                <!-- Quick-add form -->
                <form class="coasters-quick-form" id="quick-add-form" hidden>
                    <?php wp_nonce_field( 'blusiast_portal_nonce', 'nonce' ); ?>
                    <div class="cif-field">
                        <label class="cif-label" for="qa-coaster">Coaster Name *</label>
                        <input type="text" name="coaster_name" id="qa-coaster" class="cif-input" required placeholder="e.g. Millennium Force">
                    </div>
                    <div class="cif-field">
                        <label class="cif-label" for="qa-park-search">Park *</label>
                        <div class="bl-park-picker" id="bl-park-picker">
                            <input type="text" id="qa-park-search" class="cif-input bl-park-search-input" autocomplete="off" placeholder="Search or add a park…" required>
                            <ul class="bl-park-dropdown" id="bl-park-dropdown" hidden></ul>
                            <input type="hidden" name="park_name" id="qa-park" class="bl-park-value">
                            <p class="bl-park-hint" id="bl-park-hint" hidden style="font-size:12px;color:#999;margin:4px 0 0;">
                                No existing park matched — <a href="#" class="bl-park-add-link" id="bl-park-add-link">Add <span></span></a> as a new park.
                            </p>
                        </div>
                    </div>
                    <div class="cif-field">
                        <label class="cif-label">Your Rating</label>
                        <div class="cif-rating-picker" role="group">
                            <?php for ( $n = 1; $n <= 10; $n++ ) : ?>
                            <button type="button" class="cif-rating-btn" data-val="<?php echo $n; ?>"><?php echo $n; ?></button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" value="7" class="cif-rating-val">
                    </div>
                    <div class="cif-row">
                        <div class="cif-field">
                            <label class="cif-label" for="qa-thrill">Thrill</label>
                            <select name="thrill_level" id="qa-thrill" class="cif-select">
                                <option value="">Choose…</option>
                                <?php foreach ( $thrill_map as $key => $t ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cif-field">
                            <label class="cif-label" for="qa-type">Type</label>
                            <select name="coaster_type" id="qa-type" class="cif-select">
                                <option value="">Choose…</option>
                                <option>Steel</option><option>Wooden</option><option>Hybrid</option>
                                <option>Inverted</option><option>Launched</option><option>Wing</option>
                                <option>Dive</option><option>Stand-Up</option><option>Suspended</option>
                                <option>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="cif-field">
                        <label class="cif-label" for="qa-text">Review *</label>
                        <textarea name="review_text" id="qa-text" class="cif-textarea" rows="3" required placeholder="What made it memorable?"></textarea>
                    </div>
                    <div class="cif-field">
                        <label class="cif-label" for="qa-date">Ride Date</label>
                        <input type="date" name="ride_date" id="qa-date" class="cif-input">
                    </div>
                    <div class="cif-actions">
                        <button type="submit" class="bl-btn bl-btn--primary bl-btn--sm">Submit</button>
                        <button type="button" id="quick-add-cancel" class="bl-btn bl-btn--ghost bl-btn--sm">Cancel</button>
                    </div>
                    <p class="cif-msg" hidden></p>
                </form>
            </div>
            <?php endif; ?>

            <!-- Recent reviews -->
            <?php if ( $recent_reviews ) : ?>
            <div class="coasters-sidebar-card">
                <p class="bl-label">Fresh Drops</p>
                <h3>Latest Reviews</h3>
                <ul class="coasters-recent-list">
                    <?php foreach ( $recent_reviews as $r ) :
                        $use_handle = ( ! empty( $r->handle ) && ( $r->dir_name_pref ?? 'real' ) === 'handle' );
                        $author     = $use_handle ? '@' . $r->handle : ( $r->first_name ? $r->first_name . ' ' . substr( $r->last_name, 0, 1 ) . '.' : 'Anonymous' );
                    ?>
                    <li class="coasters-recent-item">
                        <div class="coasters-recent-item__top">
                            <strong class="coasters-recent-item__name"><?php echo esc_html( $r->coaster_name ); ?></strong>
                            <span class="coasters-recent-item__score"><?php echo (int) $r->rating; ?><small>/10</small></span>
                        </div>
                        <p class="coasters-recent-item__park"><?php echo esc_html( $r->park_name ); ?></p>
                        <?php if ( $r->review_text ) : ?>
                        <p class="coasters-recent-item__text">"<?php echo esc_html( wp_trim_words( $r->review_text, 12, '…' ) ); ?>"</p>
                        <?php endif; ?>
                        <p class="coasters-recent-item__author">— <?php echo esc_html( $author ); ?></p>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Thrill key -->
            <div class="coasters-sidebar-card">
                <p class="bl-label">Legend</p>
                <h3>Thrill Levels</h3>
                <ul class="coasters-thrill-key">
                    <?php foreach ( $thrill_map as $t ) : ?>
                    <li><span><?php echo $t['icon']; ?></span> <strong><?php echo esc_html( $t['label'] ); ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        </aside><!-- /.coasters-sidebar -->

    </div><!-- /.coasters-layout -->

</div><!-- /.coasters-page -->

<!-- ══ REVIEWS DRAWER ════════════════════════════════ -->
<div id="bl-reviews-drawer" class="bl-reviews-drawer" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Coaster Reviews">
    <div class="bl-reviews-drawer__backdrop"></div>
    <div class="bl-reviews-drawer__panel">
        <div class="bl-reviews-drawer__header">
            <div>
                <p class="bl-label" style="color:var(--red);margin:0 0 4px;">Coaster Reviews</p>
                <h2 class="bl-reviews-drawer__title" id="bl-drawer-title">—</h2>
                <p class="bl-reviews-drawer__park" id="bl-drawer-park"></p>
            </div>
            <button class="bl-reviews-drawer__close" aria-label="Close reviews">&times;</button>
        </div>
        <div class="bl-reviews-drawer__body" id="bl-drawer-body">
            <div class="bl-drawer-loading">Loading reviews…</div>
        </div>
    </div>
</div>

<style>
/* ── NEW REVIEW CARD ────────────────────────────── */
.coasters-new-review-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: linear-gradient(135deg, #1a0a0a 0%, #1c1c1c 100%);
    border: 1px solid #CC0000;
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 24px;
}
.coasters-new-review-card--guest {
    border-color: #333;
    background: #141414;
}
.coasters-new-review-card__left {
    display: flex;
    align-items: center;
    gap: 16px;
}
.coasters-new-review-card__icon {
    font-size: 2rem;
    line-height: 1;
    flex-shrink: 0;
}
.coasters-new-review-card__heading {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--white);
    margin: 0 0 2px;
    line-height: 1.2;
}
.coasters-new-review-card__sub {
    font-size: 13px;
    color: #999;
    margin: 0;
}
.coasters-new-review-card__btn {
    flex-shrink: 0;
}
.coasters-new-review-panel {
    background: #141414;
    border: 1px solid #2a2a2a;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}
.coasters-new-review-panel__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
@media (max-width: 600px) {
    .coasters-new-review-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 14px;
    }
    .coasters-new-review-card__btn {
        width: 100%;
        text-align: center;
    }
    .coasters-new-review-panel__grid {
        grid-template-columns: 1fr;
    }
}

/* ── DRAWER ─────────────────────────────────────── */
.bl-reviews-drawer {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    justify-content: flex-end;
    pointer-events: none;
    opacity: 0;
    transition: opacity .25s ease;
}
.bl-reviews-drawer.is-open {
    pointer-events: all;
    opacity: 1;
}
.bl-reviews-drawer__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.7);
    cursor: pointer;
}
.bl-reviews-drawer__panel {
    position: relative;
    z-index: 2;
    width: min(520px, 100vw);
    height: 100%;
    background: #141414;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform .3s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
}
.bl-reviews-drawer.is-open .bl-reviews-drawer__panel {
    transform: translateX(0);
}
.bl-reviews-drawer__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 28px 28px 20px;
    border-bottom: 1px solid #222;
    flex-shrink: 0;
}
.bl-reviews-drawer__title {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--white);
    margin: 0;
    line-height: 1.2;
}
.bl-reviews-drawer__park {
    font-size: 13px;
    color: var(--gray-3);
    margin: 4px 0 0;
}
.bl-reviews-drawer__close {
    background: none;
    border: 1px solid #333;
    color: #999;
    font-size: 22px;
    line-height: 1;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: color .2s, border-color .2s;
}
.bl-reviews-drawer__close:hover { color: #fff; border-color: #666; }
.bl-reviews-drawer__body {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 24px 28px 40px;
}
.bl-drawer-loading {
    color: #666;
    font-size: 14px;
    text-align: center;
    padding: 40px 0;
}

/* ── INDIVIDUAL REVIEW CARD ──────────────────────── */
.bl-drawer-review {
    padding: 24px 0;
    border-bottom: 1px solid #222;
}
.bl-drawer-review:first-child { padding-top: 4px; }
.bl-drawer-review:last-child { border-bottom: none; }
.bl-drawer-review__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.bl-drawer-review__author {
    font-weight: 700;
    color: var(--white);
    font-size: 15px;
}
.bl-drawer-review__score {
    font-family: var(--font-display, sans-serif);
    font-size: 28px;
    font-weight: 900;
    color: var(--red);
    line-height: 1;
    flex-shrink: 0;
}
.bl-drawer-review__score small {
    font-size: 13px;
    color: #666;
    font-weight: 400;
}
.bl-drawer-review__bar-wrap {
    height: 4px;
    background: #2a2a2a;
    border-radius: 2px;
    margin-bottom: 14px;
    overflow: hidden;
}
.bl-drawer-review__bar {
    height: 100%;
    background: var(--red);
    border-radius: 2px;
    transition: width .4s ease;
}
.bl-drawer-review__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}
.bl-drawer-review__tag {
    font-size: 11px;
    padding: 3px 10px;
    border-radius: 20px;
    border: 1px solid #333;
    color: #aaa;
}
.bl-drawer-review__text {
    font-size: 15px;
    line-height: 1.7;
    color: #ccc;
    margin: 0 0 12px;
}
.bl-drawer-review__meta {
    font-size: 11px;
    color: #555;
}
</style>

<script>
(function () {
    var drawer     = document.getElementById('bl-reviews-drawer');
    var drawerBody = document.getElementById('bl-drawer-body');
    var drawerTitle = document.getElementById('bl-drawer-title');
    var drawerPark  = document.getElementById('bl-drawer-park');
    var closeBtn   = drawer.querySelector('.bl-reviews-drawer__close');
    var backdrop   = drawer.querySelector('.bl-reviews-drawer__backdrop');

    function openDrawer(coaster, park, count) {
        drawerTitle.textContent = coaster;
        drawerPark.textContent  = park;
        drawerBody.innerHTML    = '<div class="bl-drawer-loading">Loading reviews…</div>';
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        closeBtn.focus();

        var restUrl = '<?php echo esc_url( rest_url("blusiast/v1/reviews") ); ?>' + '?coaster=' + encodeURIComponent(coaster);

        fetch(restUrl, { credentials: 'same-origin' })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(reviews) {
            if (!reviews || !reviews.length) {
                drawerBody.innerHTML = '<p style="color:#666;text-align:center;padding:40px 0;">No reviews yet for this coaster.</p>';
                return;
            }

            var thrillMap = {
                mild:     '🟢 Mild',
                moderate: '🟡 Moderate',
                intense:  '🟠 Intense',
                extreme:  '🔴 Extreme'
            };

            var html = '';
            reviews.forEach(function(r) {
                var barW   = Math.round((r.rating / 10) * 100);
                var thrill = thrillMap[r.thrill_level] || '';
                var date   = r.created_at ? r.created_at.substring(0, 10) : '';

                html += '<div class="bl-drawer-review">';
                html +=   '<div class="bl-drawer-review__top">';
                html +=     '<span class="bl-drawer-review__author">' + r.author.replace(/</g,'&lt;') + '</span>';
                html +=     '<span class="bl-drawer-review__score">' + r.rating + '<small>/10</small></span>';
                html +=   '</div>';
                html +=   '<div class="bl-drawer-review__bar-wrap"><div class="bl-drawer-review__bar" style="width:' + barW + '%"></div></div>';
                if (thrill) html += '<div class="bl-drawer-review__tags"><span class="bl-drawer-review__tag">' + thrill + '</span></div>';
                if (r.review_text) html += '<p class="bl-drawer-review__text">' + r.review_text.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</p>';
                html +=   '<p class="bl-drawer-review__meta">Posted ' + date + '</p>';
                html += '</div>';
            });
            drawerBody.innerHTML = html;
        })
        .catch(function(err) {
            drawerBody.innerHTML = '<p style="color:#c00;text-align:center;padding:40px 0;">Could not load reviews. Please try again.</p>';
            console.error('Drawer fetch error:', err);
        });
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    // Delegate click on read-reviews buttons OR the entire coaster card
    document.addEventListener('click', function(e) {
        // Don't open drawer if clicking the inline review form button or inside a form
        if (e.target.closest('.coaster-card__review-btn') ||
            e.target.closest('.coaster-inline-form') ||
            e.target.closest('.coaster-card__login-cta')) return;

        var btn = e.target.closest('.coaster-card__read-reviews');
        if (btn) {
            openDrawer(btn.dataset.coaster, btn.dataset.park, btn.dataset.count);
            return;
        }

        // Click anywhere on the card opens the drawer
        var card = e.target.closest('.coaster-card');
        if (card) {
            var readBtn = card.querySelector('.coaster-card__read-reviews');
            if (readBtn) {
                openDrawer(readBtn.dataset.coaster, readBtn.dataset.park, readBtn.dataset.count);
            }
        }
    });

    closeBtn.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer();
    });
})();
</script>

<?php if ( is_user_logged_in() ) : ?>
<!-- ══ NEW REVIEW DRAWER ══════════════════════════ -->
<div id="bl-write-drawer" class="bl-reviews-drawer" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Write a Review">
    <div class="bl-reviews-drawer__backdrop"></div>
    <div class="bl-reviews-drawer__panel">
        <div class="bl-reviews-drawer__header">
            <div>
                <p class="bl-label" style="color:var(--red);margin:0 0 4px;">Blusiast Crew</p>
                <h2 class="bl-reviews-drawer__title">Write a Review</h2>
            </div>
            <button class="bl-reviews-drawer__close" aria-label="Close">&times;</button>
        </div>
        <div class="bl-reviews-drawer__body">
            <form id="bl-write-drawer-form">
                <?php wp_nonce_field( 'blusiast_portal_nonce', 'nonce' ); ?>

                <div class="cif-field">
                    <label class="cif-label" for="wd-coaster">Coaster Name *</label>
                    <input type="text" name="coaster_name" id="wd-coaster" class="cif-input" placeholder="e.g. Millennium Force">
                </div>

                <div class="cif-field">
                    <label class="cif-label" for="wd-park-search">Park *</label>
                    <div class="bl-park-picker bl-park-picker--inline">
                        <input type="text" id="wd-park-search" class="cif-input bl-park-search-input" autocomplete="off" placeholder="Type to search parks…">
                        <ul class="bl-park-dropdown" hidden></ul>
                        <input type="hidden" name="park_name" class="bl-park-value">
                        <p class="bl-park-hint" hidden>
                            No existing park matched — <a href="#" class="bl-park-add-link">Add <span></span></a> as a new park.
                        </p>
                    </div>
                </div>

                <div class="cif-field">
                    <label class="cif-label">Your Rating *</label>
                    <div class="cif-rating-picker" role="group" aria-label="Rating out of 10">
                        <?php for ( $n = 1; $n <= 10; $n++ ) : ?>
                        <button type="button" class="cif-rating-btn" data-val="<?php echo $n; ?>"><?php echo $n; ?></button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating" value="7" class="cif-rating-val">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="cif-field">
                        <label class="cif-label" for="wd-thrill">Thrill Level</label>
                        <select name="thrill_level" id="wd-thrill" class="cif-select">
                            <option value="">Choose…</option>
                            <?php foreach ( $thrill_map as $key => $t ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $t['icon'] . ' ' . $t['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cif-field">
                        <label class="cif-label" for="wd-type">Type</label>
                        <select name="coaster_type" id="wd-type" class="cif-select">
                            <option value="">Choose…</option>
                            <option>Steel</option><option>Wooden</option><option>Hybrid</option>
                            <option>Inverted</option><option>Launched</option><option>Wing</option>
                            <option>Dive</option><option>Stand-Up</option><option>Suspended</option>
                            <option>Other</option>
                        </select>
                    </div>
                </div>

                <div class="cif-field">
                    <label class="cif-label" for="wd-date">Ride Date</label>
                    <input type="date" name="ride_date" id="wd-date" class="cif-input" style="max-width:200px;">
                </div>

                <div class="cif-field">
                    <label class="cif-label" for="wd-text">Your Review *</label>
                    <textarea name="review_text" id="wd-text" class="cif-textarea" rows="4" placeholder="Tell the crew what you thought…"></textarea>
                </div>

                <p class="cif-msg" hidden></p>

                <div class="cif-actions">
                    <button type="submit" class="bl-btn bl-btn--primary">Submit Review</button>
                    <button type="button" class="bl-btn bl-btn--ghost" id="bl-write-drawer-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var PARKS_URL = '<?php echo esc_js( rest_url( 'blusiast/v1/parks' ) ); ?>';
    var AJAX_URL  = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
    var NONCE     = '<?php echo esc_js( wp_create_nonce( 'blusiast_portal_nonce' ) ); ?>';

    /* ── Drawer open/close ─────────────────────── */
    var drawer    = document.getElementById('bl-write-drawer');
    var backdrop  = drawer.querySelector('.bl-reviews-drawer__backdrop');
    var closeBtn  = drawer.querySelector('.bl-reviews-drawer__close');
    var cancelBtn = document.getElementById('bl-write-drawer-cancel');

    function openDrawer() {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var first = drawer.querySelector('.cif-input');
            if (first) first.focus();
        }, 100);
    }
    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-open-review-modal]').forEach(function(btn) {
        btn.addEventListener('click', openDrawer);
    });
    closeBtn.addEventListener('click', closeDrawer);
    cancelBtn.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer();
    });

    /* ── Rating picker ─────────────────────────── */
    var ratingBtns = drawer.querySelectorAll('.cif-rating-btn');
    var ratingVal  = drawer.querySelector('.cif-rating-val');
    ratingBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            ratingBtns.forEach(function(b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            if (ratingVal) ratingVal.value = btn.dataset.val;
        });
    });
    // Default select 7
    var defaultBtn = drawer.querySelector('.cif-rating-btn[data-val="7"]');
    if (defaultBtn) defaultBtn.classList.add('is-active');

    /* ── Park typeahead ────────────────────────── */
    var picker      = drawer.querySelector('.bl-park-picker');
    var searchInput = picker.querySelector('.bl-park-search-input');
    var dropdown    = picker.querySelector('.bl-park-dropdown');
    var hiddenInput = picker.querySelector('.bl-park-value');
    var hint        = picker.querySelector('.bl-park-hint');
    var addLink     = picker.querySelector('.bl-park-add-link');

    var debounce    = null;
    var lastQ       = '';
    var hasResults  = false;
    var picking     = false;

    function showDropdown(parks) {
        dropdown.innerHTML = '';
        if (!parks.length) {
            dropdown.hidden = true;
            hasResults = false;
            return;
        }
        hasResults = true;
        parks.forEach(function(park) {
            var li = document.createElement('li');
            li.className = 'bl-park-option';
            li.textContent = park.name + (park.location ? ' — ' + park.location : '');
            li.addEventListener('mousedown', function(e) {
                e.preventDefault();
                pickPark(park.name);
            });
            li.addEventListener('touchstart', function(e) {
                e.preventDefault();
                pickPark(park.name);
            }, { passive: false });
            dropdown.appendChild(li);
        });
        dropdown.hidden = false;
    }

    function pickPark(name) {
        picking = true;
        searchInput.value = name;
        hiddenInput.value = name;
        dropdown.hidden   = true;
        hideHint();
        setTimeout(function() { picking = false; }, 400);
    }

    function hideHint() { if (hint) hint.hidden = true; }

    function showHint(q) {
        if (!hint || !addLink) return;
        var span = addLink.querySelector('span');
        if (span) span.textContent = '"' + q + '"';
        addLink.dataset.name = q;
        hint.hidden = false;
    }

    function fetchParks(q) {
        lastQ = q;
        var url = PARKS_URL + (q ? '?q=' + encodeURIComponent(q) : '');
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(parks) {
                if (q !== lastQ) return;
                showDropdown(parks);
                if (q && !parks.length) showHint(q);
                else hideHint();
            })
            .catch(function() { dropdown.hidden = true; });
    }

    function addNewPark(name) {
        if (!name) return;
        pickPark(name); // optimistic
        fetch(PARKS_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            body: JSON.stringify({ name: name }),
        })
        .then(function(r) { return r.json(); })
        .then(function(res) { if (res.name) pickPark(res.name); })
        .catch(function() {});
    }

    searchInput.addEventListener('focus', function() {
        if (!hiddenInput.value) fetchParks('');
    });

    searchInput.addEventListener('input', function() {
        hiddenInput.value = '';
        hideHint();
        clearTimeout(debounce);
        var q = searchInput.value.trim();
        if (!q) { dropdown.hidden = true; fetchParks(''); return; }
        debounce = setTimeout(function() { fetchParks(q); }, 220);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { dropdown.hidden = true; hideHint(); }
        if (e.key === 'Enter') {
            e.preventDefault();
            var focused = dropdown.querySelector('.bl-park-option.is-focused');
            if (focused) { pickPark(focused.dataset && focused.textContent.split(' — ')[0]); return; }
            if (!hiddenInput.value && searchInput.value.trim() && !hasResults) {
                addNewPark(searchInput.value.trim());
            }
        }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var items = Array.from(dropdown.querySelectorAll('.bl-park-option'));
            var cur = items.indexOf(dropdown.querySelector('.is-focused'));
            items.forEach(function(i) { i.classList.remove('is-focused'); });
            var next = e.key === 'ArrowDown' ? cur + 1 : cur - 1;
            next = Math.max(0, Math.min(items.length - 1, next));
            if (items[next]) items[next].classList.add('is-focused');
        }
    });

    searchInput.addEventListener('blur', function() {
        setTimeout(function() {
            if (picking) return;
            dropdown.hidden = true;
            if (!hiddenInput.value && searchInput.value.trim() && !hasResults) {
                showHint(searchInput.value.trim());
            }
        }, 300);
    });

    if (addLink) {
        addLink.addEventListener('click', function(e) {
            e.preventDefault();
            addNewPark(addLink.dataset.name || searchInput.value.trim());
        });
        addLink.addEventListener('touchstart', function(e) {
            e.preventDefault();
            addNewPark(addLink.dataset.name || searchInput.value.trim());
        }, { passive: false });
    }

    /* ── Form submit ───────────────────────────── */
    var form  = document.getElementById('bl-write-drawer-form');
    var msgEl = form.querySelector('.cif-msg');

    function showMsg(type, txt) {
        msgEl.hidden = false;
        msgEl.className = 'cif-msg is-' + type;
        msgEl.textContent = txt;
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var data = new FormData(form);
        data.set('action', 'blusiast_submit_review');

        var coaster = (data.get('coaster_name') || '').trim();
        var park    = (data.get('park_name')    || '').trim();
        var text    = (data.get('review_text')  || '').trim();

        if (!coaster || !park) { showMsg('error', 'Coaster and park are required.'); return; }
        if (!text)              { showMsg('error', 'Please write a review.');        return; }

        var submitBtn = form.querySelector('[type="submit"]');
        submitBtn.disabled    = true;
        submitBtn.textContent = 'Submitting…';

        fetch(AJAX_URL, {
            method:  'POST',
            body:    data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                showMsg('success', 'Review submitted! Thanks for sharing with the crew.');
                setTimeout(function() { closeDrawer(); location.reload(); }, 2000);
            } else {
                showMsg('error', (res.data && res.data.message) || 'Something went wrong.');
            }
        })
        .catch(function() { showMsg('error', 'Network error. Check your connection.'); })
        .finally(function() {
            submitBtn.disabled    = false;
            submitBtn.textContent = 'Submit Review';
        });
    });
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
