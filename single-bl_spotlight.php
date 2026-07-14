<?php
/**
 * single-bl_spotlight.php — Member Spotlight detail page
 * No author byline. Pulls tagline, fave coaster, stats, quote from ACF.
 */

get_header();

while ( have_posts() ) : the_post();

    $spotlight_id = get_the_ID();
    $subtitle     = function_exists('get_field') ? get_field( 'spotlight_subtitle',      $spotlight_id ) : '';
    $fave         = function_exists('get_field') ? get_field( 'spotlight_fave_coaster',  $spotlight_id ) : '';
    $parks        = function_exists('get_field') ? get_field( 'spotlight_parks_visited', $spotlight_id ) : '';
    $years        = function_exists('get_field') ? get_field( 'spotlight_years_member',  $spotlight_id ) : '';
    $quote        = function_exists('get_field') ? get_field( 'spotlight_quote',         $spotlight_id ) : '';
    $thumb        = get_the_post_thumbnail_url( $spotlight_id, 'full' );

?>
<style>
.spot-hero {
    background: var(--black);
    padding: 56px 0 0;
}
.spot-hero__inner {
    display: flex;
    align-items: flex-end;
    gap: 48px;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 32px;
}
.spot-hero__text {
    flex: 0 0 auto;
    padding-bottom: 40px;
    min-width: 0;
}
.spot-hero__img {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
}
.spot-hero__img img {
    display: block;
    max-height: 480px;
    width: auto;
    max-width: 100%;
    object-fit: contain;
    object-position: bottom;
}

/* ── META STRIP ─────────────────────────────── */
.spot-meta {
    display: flex;
    flex-wrap: wrap;
    background: var(--surface-1);
    border-top: 1px solid var(--surface-2);
    border-bottom: 1px solid var(--surface-2);
    margin-top: 0;
}
.spot-meta__item {
    flex: 1 1 140px;
    padding: 20px 32px;
    border-right: 1px solid var(--surface-2);
}
.spot-meta__item:last-child { border-right: none; }
.spot-meta__label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--red);
    margin-bottom: 6px;
}
.spot-meta__value {
    display: block;
    font-size: 15px;
    font-weight: 700;
    color: var(--white);
    line-height: 1.3;
}

/* ── BODY ───────────────────────────────────── */
.spot-body {
    max-width: 720px;
    margin: 0 auto;
    padding: 56px 24px 80px;
}
.spot-quote {
    margin: 0 0 44px;
    padding: 28px 36px;
    border-left: 4px solid var(--red);
    background: var(--surface-1);
    border-radius: 0 8px 8px 0;
}
.spot-quote p {
    font-size: 1.25rem;
    font-style: italic;
    color: var(--gray-1);
    line-height: 1.6;
    margin: 0;
}
.spot-body .entry-content p {
    font-size: 1.0625rem;
    line-height: 1.8;
    color: var(--gray-1);
    margin-bottom: 1.5em;
}
.spot-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--gray-3);
    text-decoration: none;
    margin-bottom: 40px;
    transition: color .2s;
}
.spot-back:hover { color: var(--white); }
.spot-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 56px;
    padding-top: 32px;
    border-top: 1px solid var(--surface-2);
}

@media (max-width: 768px) {
    .spot-hero__inner { flex-direction: column; align-items: flex-start; gap: 24px; padding: 0 20px; }
    .spot-hero__img { width: 100%; justify-content: center; }
    .spot-hero__img img { max-height: 320px; }
    .spot-meta__item { flex-basis: 100%; border-right: none; border-bottom: 1px solid var(--surface-2); }
    .spot-meta__item:last-child { border-bottom: none; }
}
</style>

<!-- ── HERO ─────────────────────────────────── -->
<div class="spot-hero">
    <div class="spot-hero__inner">

        <div class="spot-hero__text">
            <p class="bl-label" style="color:var(--red);margin-bottom:16px;">
                <?php esc_html_e( 'Member Spotlight', 'blusiast' ); ?>
            </p>
            <h1 class="bl-display-lg" style="margin-bottom:<?php echo $subtitle ? '12px' : '0'; ?>;">
                <?php the_title(); ?>
            </h1>
            <?php if ( $subtitle ) : ?>
                <p style="font-size:1.125rem;color:var(--gray-2);margin:0;"><?php echo esc_html( $subtitle ); ?></p>
            <?php endif; ?>
        </div>

        <?php if ( $thumb ) : ?>
        <div class="spot-hero__img">
            <img src="<?php echo esc_url( $thumb ); ?>"
                 alt="<?php echo esc_attr( get_the_title() ); ?>">
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── META STRIP ───────────────────────────── -->
<?php if ( $fave || $parks || $years ) : ?>
<div class="spot-meta">
    <?php if ( $fave ) : ?>
        <div class="spot-meta__item">
            <span class="spot-meta__label"><?php esc_html_e( 'Favorite Coaster', 'blusiast' ); ?></span>
            <span class="spot-meta__value"><?php echo esc_html( $fave ); ?></span>
        </div>
    <?php endif; ?>
    <?php if ( $parks ) : ?>
        <div class="spot-meta__item">
            <span class="spot-meta__label"><?php esc_html_e( 'Parks Visited', 'blusiast' ); ?></span>
            <span class="spot-meta__value"><?php echo esc_html( $parks ); ?></span>
        </div>
    <?php endif; ?>
    <?php if ( $years ) : ?>
        <div class="spot-meta__item">
            <span class="spot-meta__label"><?php esc_html_e( 'Blusiast Member', 'blusiast' ); ?></span>
            <span class="spot-meta__value"><?php echo esc_html( $years ); ?> <?php esc_html_e( 'yrs', 'blusiast' ); ?></span>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── BODY ─────────────────────────────────── -->
<div class="spot-body">

    <a href="<?php echo esc_url( home_url('/') ); ?>" class="spot-back">
        &#8592; <?php esc_html_e( 'Back to Home', 'blusiast' ); ?>
    </a>

    <?php if ( $quote ) : ?>
        <div class="spot-quote">
            <p>&ldquo;<?php echo esc_html( $quote ); ?>&rdquo;</p>
        </div>
    <?php endif; ?>

    <div class="entry-content">
        <?php the_content(); ?>
    </div>

    <div class="spot-nav">
        <?php previous_post_link( '<span style="font-size:13px;color:var(--gray-2);">&#8592; %link</span>' ); ?>
        <?php next_post_link(     '<span style="font-size:13px;color:var(--gray-2);">%link &#8594;</span>' ); ?>
    </div>

</div>

<?php endwhile; ?>

<?php get_footer();
