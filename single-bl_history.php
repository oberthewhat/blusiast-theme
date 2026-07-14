<?php
/**
 * single-bl_history.php — Black History article detail page
 * Magazine-style: dark background, bold headlines, full-width featured image, pull quotes.
 * Public — no login required.
 */

get_header();

while ( have_posts() ) : the_post();

    $post_id      = get_the_ID();
    $subtitle     = function_exists('get_field') ? get_field( 'history_subtitle',              $post_id ) : get_post_meta( $post_id, 'history_subtitle', true );
    $era          = function_exists('get_field') ? get_field( 'history_era',                   $post_id ) : get_post_meta( $post_id, 'history_era', true );
    $pull_quote   = function_exists('get_field') ? get_field( 'history_pull_quote',            $post_id ) : get_post_meta( $post_id, 'history_pull_quote', true );
    $further_url  = function_exists('get_field') ? get_field( 'history_further_reading_url',   $post_id ) : get_post_meta( $post_id, 'history_further_reading_url', true );
    $further_lbl  = function_exists('get_field') ? get_field( 'history_further_reading_label', $post_id ) : get_post_meta( $post_id, 'history_further_reading_label', true );
    $further_lbl  = $further_lbl ?: 'Further Reading';
    $cats         = get_the_terms( $post_id, 'history_category' );
    $cat_name     = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';
    $cat_link     = ( $cats && ! is_wp_error( $cats ) ) ? get_term_link( $cats[0] ) : '';
    $thumb        = get_the_post_thumbnail_url( $post_id, 'full' );
    $author_name  = get_the_author();

?>
<style>

/* ══════════════════════════════════════
   HISTORY SINGLE — STYLES
   ══════════════════════════════════════ */

/* ── BACK BAR ── */
.hist-back-bar {
    background: var(--surface-1);
    border-bottom: 1px solid var(--surface-2);
    padding: 0;
}
.hist-back-bar__inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 14px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.hist-back-link {
    font-size: 13px;
    color: var(--gray-3);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color .2s;
}
.hist-back-link:hover { color: var(--white); }
.hist-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--gray-4, #555);
}
.hist-breadcrumb a { color: var(--gray-3); text-decoration: none; }
.hist-breadcrumb a:hover { color: var(--white); }
.hist-breadcrumb span { color: var(--gray-4, #555); }

/* ── HERO AREA ── */
.hist-s-hero {
    background: var(--black);
    padding: 64px 0 0;
    border-bottom: none;
}
.hist-s-hero__inner {
    max-width: 860px;
    margin: 0 auto;
    padding: 0 32px;
}
.hist-s-hero__cat {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--red);
    text-decoration: none;
    margin-bottom: 20px;
}
.hist-s-hero__cat::before {
    content: '';
    display: block;
    width: 28px;
    height: 2px;
    background: var(--red);
    flex-shrink: 0;
}
.hist-s-hero__title {
    font-size: clamp(2rem, 5vw, 3.25rem);
    font-weight: 900;
    line-height: 1.1;
    color: var(--white);
    margin: 0 0 20px;
    letter-spacing: -.01em;
}
.hist-s-hero__subtitle {
    font-size: 1.1875rem;
    line-height: 1.6;
    color: var(--gray-2);
    margin: 0 0 32px;
}
.hist-s-hero__meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    font-size: 12px;
    color: var(--gray-4, #555);
    padding-bottom: 40px;
    border-bottom: 1px solid var(--surface-2);
}
.hist-s-hero__meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
.hist-s-hero__era-badge {
    background: var(--red);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 3px 10px;
    border-radius: 3px;
}

/* ── FEATURED IMAGE ── */
.hist-s-featured-img {
    max-width: 860px;
    margin: 40px auto 0;
    padding: 0 32px;
}
.hist-s-featured-img img {
    width: 100%;
    max-height: 480px;
    object-fit: cover;
    border-radius: 10px;
    display: block;
}
.hist-s-featured-img figcaption {
    font-size: 11px;
    color: var(--gray-4, #555);
    margin-top: 10px;
    text-align: center;
    font-style: italic;
}

/* ── ARTICLE BODY ── */
.hist-s-body {
    max-width: 720px;
    margin: 0 auto;
    padding: 56px 32px 96px;
}

/* Prose typography */
.hist-s-body .entry-content p {
    font-size: 1.0625rem;
    line-height: 1.85;
    color: var(--gray-1);
    margin-bottom: 1.75em;
}
.hist-s-body .entry-content h2 {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--white);
    margin: 2.5em 0 .75em;
    padding-bottom: .5em;
    border-bottom: 1px solid var(--surface-2);
}
.hist-s-body .entry-content h3 {
    font-size: 1.1875rem;
    font-weight: 700;
    color: var(--white);
    margin: 2em 0 .5em;
}
.hist-s-body .entry-content a {
    color: var(--red);
    text-decoration: underline;
    text-decoration-color: rgba(204,0,0,.4);
    text-underline-offset: 3px;
    transition: text-decoration-color .2s;
}
.hist-s-body .entry-content a:hover {
    text-decoration-color: var(--red);
}
.hist-s-body .entry-content blockquote {
    margin: 2em 0;
    padding: 24px 32px;
    border-left: 4px solid var(--red);
    background: var(--surface-1);
    border-radius: 0 8px 8px 0;
}
.hist-s-body .entry-content blockquote p {
    font-size: 1.125rem;
    font-style: italic;
    color: var(--gray-1);
    margin: 0;
}
.hist-s-body .entry-content ul,
.hist-s-body .entry-content ol {
    padding-left: 1.5em;
    margin-bottom: 1.75em;
}
.hist-s-body .entry-content li {
    font-size: 1.0625rem;
    line-height: 1.8;
    color: var(--gray-1);
    margin-bottom: .5em;
}

/* ── PULL QUOTE ── */
.hist-pull-quote {
    margin: 2.5em 0;
    padding: 36px 40px;
    background: var(--surface-1);
    border-radius: 10px;
    border-left: 5px solid var(--red);
    position: relative;
}
.hist-pull-quote::before {
    content: '\201C';
    position: absolute;
    top: -8px;
    left: 32px;
    font-size: 5rem;
    line-height: 1;
    color: var(--red);
    opacity: .2;
    font-family: Georgia, serif;
}
.hist-pull-quote__text {
    font-size: 1.25rem;
    font-style: italic;
    font-weight: 500;
    line-height: 1.6;
    color: var(--white);
    margin: 0;
    position: relative;
    z-index: 1;
}

/* ── FURTHER READING ── */
.hist-further {
    margin-top: 2.5em;
    padding: 24px 28px;
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}
.hist-further__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 4px;
}
.hist-further__title {
    font-size: .9375rem;
    font-weight: 600;
    color: var(--white);
}

/* ── DIVIDER ── */
.hist-divider {
    border: none;
    border-top: 1px solid var(--surface-2);
    margin: 48px 0;
}

/* ── ARTICLE NAV ── */
.hist-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 48px;
}
.hist-nav__link {
    font-size: 13px;
    color: var(--gray-3);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color .2s;
}
.hist-nav__link:hover { color: var(--white); }

/* ── RESPONSIVE ── */
@media (max-width: 600px) {
    .hist-s-hero__inner { padding: 0 20px; }
    .hist-s-featured-img { padding: 0 20px; }
    .hist-s-body { padding: 40px 20px 64px; }
    .hist-pull-quote { padding: 24px 24px; }
    .hist-back-bar__inner { padding: 12px 20px; }
    .hist-s-hero__title { font-size: 1.875rem; }
}
</style>


<!-- ── BACK BAR ── -->
<div class="hist-back-bar">
    <div class="hist-back-bar__inner">
        <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_history' ) ); ?>" class="hist-back-link">
            ← Back to Black History
        </a>
        <nav class="hist-breadcrumb" aria-label="Breadcrumb">
            <a href="<?php echo esc_url( home_url('/') ); ?>">Home</a>
            <span aria-hidden="true">›</span>
            <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_history' ) ); ?>">Black History</a>
            <?php if ( $cat_name ) : ?>
                <span aria-hidden="true">›</span>
                <a href="<?php echo esc_url( $cat_link ); ?>"><?php echo esc_html( $cat_name ); ?></a>
            <?php endif; ?>
            <span aria-hidden="true">›</span>
            <span><?php echo wp_trim_words( get_the_title(), 6, '…' ); ?></span>
        </nav>
    </div>
</div>


<!-- ── HERO ── -->
<section class="hist-s-hero">
    <div class="hist-s-hero__inner">

        <?php if ( $cat_name ) : ?>
            <a href="<?php echo esc_url( $cat_link ); ?>" class="hist-s-hero__cat"><?php echo esc_html( $cat_name ); ?></a>
        <?php endif; ?>

        <h1 class="hist-s-hero__title"><?php the_title(); ?></h1>

        <?php if ( $subtitle ) : ?>
            <p class="hist-s-hero__subtitle"><?php echo esc_html( $subtitle ); ?></p>
        <?php endif; ?>

        <div class="hist-s-hero__meta">
            <?php if ( $era ) : ?>
                <span class="hist-s-hero__era-badge"><?php echo esc_html( $era ); ?></span>
            <?php endif; ?>
            <span class="hist-s-hero__meta-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?php echo get_the_date(); ?>
            </span>
            <span class="hist-s-hero__meta-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?php echo esc_html( $author_name ); ?>
            </span>
            <span class="hist-s-hero__meta-item">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <?php
                $word_count  = str_word_count( strip_tags( get_the_content() ) );
                $read_time   = max( 1, round( $word_count / 230 ) );
                echo $read_time . ' min read';
                ?>
            </span>
        </div>
    </div>
</section>


<!-- ── FEATURED IMAGE ── -->
<?php if ( $thumb ) : ?>
<figure class="hist-s-featured-img">
    <img src="<?php echo esc_url( $thumb ); ?>"
         alt="<?php echo esc_attr( get_the_title() ); ?>">
</figure>
<?php endif; ?>


<!-- ── ARTICLE BODY ── -->
<article class="hist-s-body">

    <!-- Pull quote (before body) -->
    <?php if ( $pull_quote ) : ?>
        <div class="hist-pull-quote">
            <p class="hist-pull-quote__text"><?php echo esc_html( $pull_quote ); ?></p>
        </div>
    <?php endif; ?>

    <div class="entry-content">
        <?php the_content(); ?>
    </div>

    <!-- Further reading link -->
    <?php if ( $further_url ) : ?>
        <div class="hist-further">
            <div>
                <div class="hist-further__label">Further Reading</div>
                <div class="hist-further__title"><?php echo esc_html( $further_lbl ); ?></div>
            </div>
            <a href="<?php echo esc_url( $further_url ); ?>"
               target="_blank"
               rel="noopener noreferrer"
               class="bl-btn bl-btn--ghost bl-btn--sm">
                Visit Source →
            </a>
        </div>
    <?php endif; ?>

    <hr class="hist-divider">

    <!-- Article nav -->
    <nav class="hist-nav" aria-label="Article navigation">
        <?php previous_post_link(
            '<a href="%link" class="hist-nav__link">← %title</a>',
            '%title', false, '', 'bl_history'
        ); ?>
        <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_history' ) ); ?>" class="hist-nav__link" style="color:var(--red);">
            All Articles
        </a>
        <?php next_post_link(
            '<a href="%link" class="hist-nav__link">%title →</a>',
            '%title', false, '', 'bl_history'
        ); ?>
    </nav>

</article>

<?php endwhile; ?>

<?php get_footer(); ?>
