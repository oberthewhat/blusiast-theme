<?php // archive.php — Archive (blog, events, coasters)
get_header();

$post_type  = get_post_type();
$pt_obj     = get_post_type_object( $post_type );
$is_events  = ( $post_type === 'bl_event' );
$is_coaster = ( $post_type === 'bl_coaster' );
?>

<div class="page-hero">
    <div class="container">
        <p class="bl-label">
            <?php echo $is_events ? esc_html__( 'Trips & Meetups', 'blusiast' ) : esc_html__( 'Latest', 'blusiast' ); ?>
        </p>
        <h1 class="bl-display-lg">
            <?php
            if ( is_category() )    single_cat_title();
            elseif ( is_tag() )     single_tag_title();
            elseif ( is_author() )  the_author();
            elseif ( is_date() )    echo get_the_date( 'F Y' );
            elseif ( $pt_obj )      echo esc_html( $pt_obj->labels->name );
            else                    esc_html_e( 'Archive', 'blusiast' );
            ?>
        </h1>
    </div>
</div>

<div class="page-content">
    <div class="container">
        <?php if ( have_posts() ) : ?>

            <?php if ( $is_events ) : ?>

                <!-- Filter pills -->
                <div class="archive-filters">
                    <span class="archive-filter archive-filter--active"><?php esc_html_e( 'All', 'blusiast' ); ?></span>
                    <span class="archive-filter"><?php esc_html_e( 'Open', 'blusiast' ); ?></span>
                    <span class="archive-filter"><?php esc_html_e( 'Members Only', 'blusiast' ); ?></span>
                </div>

                <div class="events-list events-list--full">
                    <?php while ( have_posts() ) : the_post();
                        $date         = function_exists( 'get_field' ) ? get_field( 'event_date' )         : '';
                        $location     = function_exists( 'get_field' ) ? get_field( 'event_location' )     : '';
                        $time         = function_exists( 'get_field' ) ? get_field( 'event_time' )         : '';
                        $price        = function_exists( 'get_field' ) ? get_field( 'event_price' )        : '';
                        $reg_url      = function_exists( 'get_field' ) ? get_field( 'event_reg_url' )      : get_permalink();
                        $members_only = function_exists( 'get_field' ) ? get_field( 'event_members_only' ) : false;
                        $sold_out     = function_exists( 'get_field' ) ? get_field( 'event_sold_out' )     : false;
                        $fmt          = blusiast_format_event_date( $date );
                        $excerpt      = get_the_excerpt();
                    ?>

                        <article class="event-card event-card--full" aria-label="<?php the_title_attribute(); ?>">

                            <!-- Left: Featured image -->
                            <a href="<?php the_permalink(); ?>"
                               class="event-card__img-wrap"
                               tabindex="-1"
                               aria-hidden="true">

                                <?php if ( has_post_thumbnail() ) : ?>
                                    <?php the_post_thumbnail( 'blusiast-hero', [
                                        'class' => 'event-card__img',
                                        'alt'   => '',
                                    ] ); ?>
                                <?php else : ?>
                                    <div class="event-card__img-placeholder"></div>
                                <?php endif; ?>

                                <?php if ( $sold_out ) : ?>
                                    <div class="event-card__sold-out-banner" aria-hidden="true">
                                        <span><?php esc_html_e( 'Sold Out', 'blusiast' ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="event-card__date" aria-label="<?php echo esc_attr( $fmt['full'] ); ?>">
                                    <span class="event-card__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                                    <span class="event-card__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                                </div>

                            </a>

                            <!-- Right: Details -->
                            <div class="event-card__info">

                                <div class="event-card__badges">
                                    <?php if ( $members_only ) : ?>
                                        <span class="bl-badge bl-badge--red bl-badge--dot">
                                            <?php esc_html_e( 'Members Only', 'blusiast' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="bl-badge bl-badge--white bl-badge--dot">
                                            <?php esc_html_e( 'Open to All', 'blusiast' ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $sold_out ) : ?>
                                        <span class="bl-badge bl-badge--white">
                                            <?php esc_html_e( 'Sold Out', 'blusiast' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h2 class="event-card__title">
                                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h2>

                                <div class="event-card__meta">
                                    <?php if ( $location ) : ?>
                                        <span class="event-card__meta-item">
                                            <?php blusiast_icon( 'location' ); ?>
                                            <?php echo esc_html( $location ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $time ) : ?>
                                        <span class="event-card__meta-sep" aria-hidden="true"></span>
                                        <span class="event-card__meta-item">
                                            <?php blusiast_icon( 'calendar' ); ?>
                                            <?php echo esc_html( $time ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $price ) : ?>
                                        <span class="event-card__meta-sep" aria-hidden="true"></span>
                                        <span class="event-card__meta-item event-card__meta-item--price">
                                            <?php echo esc_html( $price ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if ( $excerpt ) : ?>
                                    <p class="event-card__excerpt event-card__excerpt--full">
                                        <?php echo esc_html( wp_trim_words( $excerpt, 30 ) ); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="event-card__actions">
                                    <?php if ( $sold_out ) : ?>
                                        <span class="bl-btn bl-btn--ghost" aria-disabled="true">
                                            <?php esc_html_e( 'Sold Out', 'blusiast' ); ?>
                                        </span>
                                    <?php elseif ( $reg_url ) : ?>
                                        <a href="<?php echo esc_url( $reg_url ); ?>"
                                           class="bl-btn bl-btn--primary">
                                            <?php esc_html_e( 'Register & Pay', 'blusiast' ); ?>
                                            <?php blusiast_icon( 'arrow-right' ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php the_permalink(); ?>" class="bl-btn bl-btn--ghost">
                                        <?php esc_html_e( 'Event Details', 'blusiast' ); ?>
                                    </a>
                                </div>

                            </div><!-- /.event-card__info -->

                        </article>

                    <?php endwhile; ?>
                </div>

            <?php else : ?>

                <div class="post-grid">
                    <?php while ( have_posts() ) : the_post(); ?>
                        <article class="post-card">
                            <?php if ( has_post_thumbnail() ) : ?>
                                <a href="<?php the_permalink(); ?>" class="post-card__img-wrap" tabindex="-1">
                                    <?php the_post_thumbnail( 'blusiast-card', [ 'class' => 'post-card__img', 'alt' => '' ] ); ?>
                                </a>
                            <?php endif; ?>
                            <div class="post-card__body">
                                <div class="post-card__meta">
                                    <?php the_category( ', ' ); ?> &nbsp;·&nbsp; <?php echo get_the_date(); ?>
                                </div>
                                <h2 class="post-card__title">
                                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h2>
                                <p class="post-card__excerpt"><?php echo wp_trim_words( get_the_excerpt(), 20 ); ?></p>
                                <a href="<?php the_permalink(); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">
                                    <?php esc_html_e( 'Read More', 'blusiast' ); ?>
                                    <?php blusiast_icon( 'arrow-right' ); ?>
                                </a>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>

            <?php endif; ?>

            <?php
            $pagination = paginate_links( [
                'prev_text' => '&larr; ' . __( 'Previous', 'blusiast' ),
                'next_text' => __( 'Next', 'blusiast' ) . ' &rarr;',
                'type'      => 'array',
            ] );
            if ( $pagination ) :
            ?>
                <nav class="archive-pagination" aria-label="<?php esc_attr_e( 'Page navigation', 'blusiast' ); ?>">
                    <?php echo implode( '', $pagination ); ?>
                </nav>
            <?php endif; ?>

        <?php else : ?>
            <p class="bl-body-md"><?php esc_html_e( 'No events found.', 'blusiast' ); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php get_footer();