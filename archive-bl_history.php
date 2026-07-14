<?php
/**
 * archive-bl_history.php — Black History archive page
 * URL: /history
 *
 * Editorial card layout organized by category.
 * Public — no login required.
 */

get_header();

// ── Gather all published articles, grouped by taxonomy term
$all_terms = get_terms( [
    'taxonomy'   => 'history_category',
    'hide_empty' => true,
] );

$featured = new WP_Query( [
    'post_type'      => 'bl_history',
    'posts_per_page' => 1,
    'orderby'        => 'date',
    'order'          => 'DESC',
] );

?>

<style>

/* ══════════════════════════════════════
   HISTORY ARCHIVE — STYLES
   ══════════════════════════════════════ */

.hist-hero {
    background: var(--black);
    border-bottom: 1px solid var(--surface-2);
    padding: 80px 0 60px;
    position: relative;
    overflow: hidden;
}
.hist-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 70% 50%, rgba(204,0,0,.08) 0%, transparent 70%);
    pointer-events: none;
}
.hist-hero__eyebrow {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}
.hist-hero__eyebrow-line {
    display: block;
    width: 36px;
    height: 2px;
    background: var(--red);
}
.hist-hero__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: var(--red);
}
.hist-hero__title {
    font-size: clamp(2.25rem, 6vw, 4rem);
    font-weight: 900;
    line-height: 1.1;
    color: var(--white);
    margin: 0 0 20px;
    max-width: 640px;
}
.hist-hero__desc {
    font-size: 1.0625rem;
    line-height: 1.75;
    color: var(--gray-2);
    max-width: 600px;
    margin: 0;
}

/* ── CATEGORY FILTER TABS ── */
.hist-tabs {
    background: var(--surface-1);
    border-bottom: 1px solid var(--surface-2);
    position: sticky;
    top: 0;
    z-index: 40;
}
.hist-tabs__inner {
    display: flex;
    align-items: center;
    gap: 0;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    padding: 0 32px;
    max-width: 1200px;
    margin: 0 auto;
}
.hist-tabs__inner::-webkit-scrollbar { display: none; }
.hist-tab {
    flex-shrink: 0;
    padding: 16px 22px;
    font-size: 13px;
    font-weight: 600;
    color: var(--gray-3);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    transition: color .2s, border-color .2s;
    white-space: nowrap;
}
.hist-tab:hover,
.hist-tab--active {
    color: var(--white);
    border-bottom-color: var(--red);
}

/* ── FEATURED ARTICLE ── */
.hist-featured {
    padding: 64px 0 0;
    background: var(--black);
}
.hist-featured__inner {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border: 1px solid var(--surface-2);
    border-radius: 12px;
    overflow: hidden;
}
.hist-featured__img {
    position: relative;
    min-height: 360px;
    background: var(--surface-1);
}
.hist-featured__img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.hist-featured__img-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-1);
    font-size: 4rem;
    color: var(--surface-2);
}
.hist-featured__body {
    padding: 48px 48px 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    background: var(--surface-1);
}
.hist-featured__tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 16px;
}
.hist-featured__title {
    font-size: clamp(1.5rem, 3vw, 2rem);
    font-weight: 800;
    line-height: 1.2;
    color: var(--white);
    margin: 0 0 16px;
    text-decoration: none;
    display: block;
    transition: color .2s;
}
.hist-featured__title:hover { color: var(--red); }
.hist-featured__excerpt {
    font-size: 1rem;
    line-height: 1.7;
    color: var(--gray-2);
    margin: 0 0 28px;
    flex: 1;
}
.hist-featured__meta {
    font-size: 12px;
    color: var(--gray-4, #666);
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.hist-featured__badge {
    display: inline-block;
    background: var(--red);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 3px 10px;
    border-radius: 3px;
}

/* ── CATEGORY SECTIONS ── */
.hist-section {
    padding: 64px 0 0;
}
.hist-section__header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 32px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--surface-2);
}
.hist-section__title {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--white);
    display: flex;
    align-items: center;
    gap: 12px;
}
.hist-section__title::before {
    content: '';
    display: block;
    width: 4px;
    height: 1.1em;
    background: var(--red);
    border-radius: 2px;
    flex-shrink: 0;
}
.hist-section__count {
    font-size: 12px;
    color: var(--gray-3);
    font-weight: 400;
}

/* ── ARTICLE GRID ── */
.hist-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}
.hist-card {
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-radius: 10px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color .2s, transform .2s;
    text-decoration: none;
}
.hist-card:hover {
    border-color: var(--red);
    transform: translateY(-3px);
}
.hist-card__img {
    aspect-ratio: 16/9;
    background: var(--surface-2);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: var(--surface-3, #333);
}
.hist-card__img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .3s;
}
.hist-card:hover .hist-card__img img { transform: scale(1.04); }
.hist-card__body {
    padding: 24px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.hist-card__era {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 10px;
}
.hist-card__title {
    font-size: 1.0625rem;
    font-weight: 700;
    line-height: 1.35;
    color: var(--white);
    margin: 0 0 10px;
}
.hist-card__excerpt {
    font-size: .875rem;
    line-height: 1.65;
    color: var(--gray-3);
    flex: 1;
    margin: 0 0 16px;
}
.hist-card__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11px;
    color: var(--gray-4, #555);
    border-top: 1px solid var(--surface-2);
    padding-top: 14px;
    margin-top: auto;
}
.hist-card__read-more {
    color: var(--red);
    font-weight: 600;
    font-size: 11px;
    letter-spacing: .05em;
}

/* ── EMPTY STATE ── */
.hist-empty {
    text-align: center;
    padding: 64px 24px;
    color: var(--gray-3);
}
.hist-empty__icon { font-size: 3rem; margin-bottom: 16px; }
.hist-empty__title { font-size: 1.25rem; font-weight: 700; color: var(--white); margin-bottom: 8px; }
.hist-empty__body  { font-size: .9375rem; color: var(--gray-2); }

/* ── CTA STRIP ── */
.hist-cta {
    margin-top: 80px;
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-radius: 12px;
    padding: 48px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    flex-wrap: wrap;
}
.hist-cta__text h3 { font-size: 1.375rem; font-weight: 800; color: var(--white); margin: 0 0 8px; }
.hist-cta__text p  { font-size: .9375rem; color: var(--gray-2); margin: 0; }

.hist-bottom-pad { padding-bottom: 96px; }

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
    .hist-featured__inner { grid-template-columns: 1fr; }
    .hist-featured__img   { min-height: 220px; }
    .hist-featured__body  { padding: 32px; }
    .hist-grid            { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .hist-grid             { grid-template-columns: 1fr; }
    .hist-tabs__inner      { padding: 0 16px; }
    .hist-cta              { flex-direction: column; text-align: center; }
}
</style>


<!-- ══════════════════════════════════════
     HERO
     ══════════════════════════════════════ -->
<section class="hist-hero">
    <div class="container" style="position:relative;z-index:2;">
        <div class="hist-hero__eyebrow">
            <span class="hist-hero__eyebrow-line" aria-hidden="true"></span>
            <span class="hist-hero__label">Blusiast Editorial</span>
        </div>
        <h1 class="hist-hero__title">
            Black History in<br>
            <span style="color:var(--red);">Theme Parks &amp; Coasters</span>
        </h1>
        <p class="hist-hero__desc">
            Stories, milestones, and the pioneers who shaped the roller coaster and theme park world — with a focus on the Black experience and the fight for belonging in spaces of joy.
        </p>
    </div>
</section>


<!-- ══════════════════════════════════════
     CATEGORY TABS
     ══════════════════════════════════════ -->
<?php if ( $all_terms && ! is_wp_error( $all_terms ) ) : ?>
<nav class="hist-tabs" aria-label="History categories">
    <div class="hist-tabs__inner">
        <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_history' ) ); ?>"
           class="hist-tab hist-tab--active">All Articles</a>
        <?php foreach ( $all_terms as $term ) : ?>
            <a href="<?php echo esc_url( get_term_link( $term ) ); ?>"
               class="hist-tab"><?php echo esc_html( $term->name ); ?></a>
        <?php endforeach; ?>
    </div>
</nav>
<?php endif; ?>


<div class="container hist-bottom-pad">

    <!-- ══════════════════════════════════════
         FEATURED — most recent article
         ══════════════════════════════════════ -->
    <?php if ( $featured->have_posts() ) : $featured->the_post(); ?>
    <div class="hist-featured">
        <div class="hist-featured__inner">

            <!-- image -->
            <div class="hist-featured__img">
                <?php if ( has_post_thumbnail() ) : ?>
                    <?php the_post_thumbnail( 'large' ); ?>
                <?php else : ?>
                    <div class="hist-featured__img-placeholder" aria-hidden="true">📖</div>
                <?php endif; ?>
            </div>

            <!-- body -->
            <div class="hist-featured__body">
                <span class="hist-featured__badge">Featured</span>

                <?php
                $cats = get_the_terms( get_the_ID(), 'history_category' );
                if ( $cats && ! is_wp_error( $cats ) ) :
                ?>
                    <span class="hist-featured__tag"><?php echo esc_html( $cats[0]->name ); ?></span>
                <?php endif; ?>

                <a href="<?php the_permalink(); ?>" class="hist-featured__title"><?php the_title(); ?></a>

                <p class="hist-featured__excerpt">
                    <?php echo wp_trim_words( get_the_excerpt() ?: get_the_content(), 30, '…' ); ?>
                </p>

                <div class="hist-featured__meta">
                    <span><?php echo get_the_date(); ?></span>
                    <?php
                    $era = function_exists('get_field') ? get_field( 'history_era' ) : get_post_meta( get_the_ID(), 'history_era', true );
                    if ( $era ) echo '<span>Era: ' . esc_html( $era ) . '</span>';
                    ?>
                    <a href="<?php the_permalink(); ?>" class="bl-btn bl-btn--primary bl-btn--sm" style="margin-left:auto;">Read Story →</a>
                </div>
            </div>
        </div>
    </div>
    <?php wp_reset_postdata(); endif; ?>


    <!-- ══════════════════════════════════════
         ARTICLES BY CATEGORY
         ══════════════════════════════════════ -->
    <?php if ( $all_terms && ! is_wp_error( $all_terms ) ) : ?>
        <?php foreach ( $all_terms as $term ) : ?>

            <?php
            $cat_query = new WP_Query( [
                'post_type'      => 'bl_history',
                'posts_per_page' => 6,
                'tax_query'      => [ [
                    'taxonomy' => 'history_category',
                    'field'    => 'term_id',
                    'terms'    => $term->term_id,
                ] ],
            ] );
            if ( ! $cat_query->have_posts() ) continue;
            ?>

            <section class="hist-section" id="<?php echo esc_attr( $term->slug ); ?>">
                <div class="hist-section__header">
                    <h2 class="hist-section__title">
                        <?php echo esc_html( $term->name ); ?>
                        <span class="hist-section__count"><?php echo esc_html( $cat_query->found_posts ); ?> <?php echo $cat_query->found_posts === 1 ? 'article' : 'articles'; ?></span>
                    </h2>
                    <?php if ( $cat_query->found_posts > 6 ) : ?>
                        <a href="<?php echo esc_url( get_term_link( $term ) ); ?>"
                           style="font-size:12px;color:var(--red);font-weight:600;text-decoration:none;">
                            View all →
                        </a>
                    <?php endif; ?>
                </div>

                <div class="hist-grid">
                    <?php while ( $cat_query->have_posts() ) : $cat_query->the_post(); ?>
                        <?php
                        $era     = function_exists('get_field') ? get_field( 'history_era' ) : get_post_meta( get_the_ID(), 'history_era', true );
                        $excerpt = wp_trim_words( get_the_excerpt() ?: get_the_content(), 20, '…' );
                        ?>
                        <a href="<?php the_permalink(); ?>" class="hist-card">
                            <div class="hist-card__img">
                                <?php if ( has_post_thumbnail() ) : ?>
                                    <?php the_post_thumbnail( 'medium' ); ?>
                                <?php else : ?>
                                    📖
                                <?php endif; ?>
                            </div>
                            <div class="hist-card__body">
                                <?php if ( $era ) : ?>
                                    <div class="hist-card__era"><?php echo esc_html( $era ); ?></div>
                                <?php endif; ?>
                                <h3 class="hist-card__title"><?php the_title(); ?></h3>
                                <p class="hist-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
                                <div class="hist-card__foot">
                                    <span><?php echo get_the_date( 'M j, Y' ); ?></span>
                                    <span class="hist-card__read-more">Read →</span>
                                </div>
                            </div>
                        </a>
                    <?php endwhile; wp_reset_postdata(); ?>
                </div>
            </section>

        <?php endforeach; ?>
    <?php else : ?>
        <!-- No categories / no posts yet -->
        <div class="hist-section">
            <div class="hist-empty">
                <div class="hist-empty__icon">📖</div>
                <div class="hist-empty__title">Stories Coming Soon</div>
                <p class="hist-empty__body">We're building out this section. Check back soon for powerful stories about Black history in the theme park world.</p>
            </div>
        </div>
    <?php endif; ?>


    <!-- ══════════════════════════════════════
         CTA — contribute / share
         ══════════════════════════════════════ -->
    <div class="hist-cta">
        <div class="hist-cta__text">
            <h3>Know a Story That Should Be Told?</h3>
            <p>Blusiast is looking for contributors to help document Black history in the coaster community. If you have a story, reach out.</p>
        </div>
        <a href="<?php echo esc_url( home_url( '/contact' ) ); ?>" class="bl-btn bl-btn--primary">Get in Touch</a>
    </div>

</div><!-- /.container -->

<?php get_footer(); ?>
