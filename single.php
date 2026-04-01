<?php // single.php — Blog post
get_header(); ?>

<div class="page-hero">
    <div class="container" style="max-width:760px;">
        <p class="bl-label"><?php the_category(', '); ?></p>
        <h1 class="bl-display-md" style="margin-bottom:16px;"><?php the_title(); ?></h1>
        <p class="bl-body-sm"><?php echo get_the_date(); ?> &nbsp;·&nbsp; <?php the_author(); ?></p>
    </div>
</div>

<div class="page-content">
    <div class="container" style="max-width:760px;">
        <?php while ( have_posts() ) : the_post(); ?>
            <?php if ( has_post_thumbnail() ) : ?>
                <?php the_post_thumbnail( 'blusiast-hero', [ 'style' => 'width:100%;border-radius:12px;margin-bottom:40px;', 'alt' => '' ] ); ?>
            <?php endif; ?>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
            <div style="margin-top:48px; padding-top:32px; border-top:1px solid var(--surface-3); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <?php previous_post_link( '<span style="font-size:13px;color:var(--gray-2);">← %link</span>' ); ?>
                <?php next_post_link(     '<span style="font-size:13px;color:var(--gray-2);">%link →</span>' ); ?>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<?php get_footer();
