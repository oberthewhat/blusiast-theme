<?php // page.php — Standard page template
get_header(); ?>

<div class="page-hero">
    <div class="container">
        <p class="bl-label"><?php echo esc_html( get_post_type_object( get_post_type() )->labels->singular_name ?? '' ); ?></p>
        <h1 class="bl-display-lg"><?php the_title(); ?></h1>
    </div>
</div>

<div class="page-content">
    <div class="container">
        <?php while ( have_posts() ) : the_post(); ?>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<?php get_footer();
