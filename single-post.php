<?php
// single-post.php — Single blog post template
get_header();

while ( have_posts() ) : the_post();
?>

    <!-- Post Hero -->
    <div class="post-hero">
        <div class="container">
            <div class="post-hero__inner">

                <?php
                $categories = get_the_category();
                if ( $categories ) :
                    $cat = $categories[0];
                ?>
                    <a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>" class="post-hero__tag">
                        <?php echo esc_html( $cat->name ); ?>
                    </a>
                <?php endif; ?>

                <h1 class="post-hero__title"><?php the_title(); ?></h1>

                <div class="post-hero__meta">
                    <span><?php echo get_the_date( 'F j, Y' ); ?></span>
                    <span class="post-hero__meta-sep" aria-hidden="true"></span>
                    <span><?php echo esc_html( get_the_author() ); ?></span>
                    <span class="post-hero__meta-sep" aria-hidden="true"></span>
                    <span><?php echo ceil( str_word_count( get_the_content() ) / 200 ); ?> min read</span>
                </div>

            </div>
        </div>
    </div>

    <!-- Featured Image -->
    <?php if ( has_post_thumbnail() ) : ?>
        <div class="post-featured-img">
            <div class="container">
                <?php the_post_thumbnail( 'full', [ 'class' => 'post-featured-img__img', 'alt' => get_the_title() ] ); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Post Body -->
    <div class="post-body">
        <div class="container">
            <div class="post-content">
                <?php the_content(); ?>
            </div>

            <!-- Post Footer -->
            <div class="post-footer">
                <?php if ( $categories ) : ?>
                    <div class="post-footer__cats">
                        <?php foreach ( $categories as $cat ) : ?>
                            <a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>" class="post-footer__cat-link">
                                <?php echo esc_html( $cat->name ); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Back to Blog -->
            <div class="post-nav">
                <a href="<?php echo esc_url( get_post_type_archive_link( 'post' ) ); ?>" class="bl-btn bl-btn--ghost">
                    &larr; <?php esc_html_e( 'Back to Blog & News', 'blusiast' ); ?>
                </a>
            </div>

        </div>
    </div>

<?php
endwhile;

get_footer();
