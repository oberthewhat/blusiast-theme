<?php
/**
 * Section: Past Events
 * Renders below the upcoming events list on the bl_event archive.
 * Each card links to the event and offers members a photo-upload deep link.
 */

$past_q = blusiast_get_past_events( 12 );
if ( ! $past_q->have_posts() ) {
    wp_reset_postdata();
    return;
}
?>

<section class="past-events section" id="past-events">

    <div class="section-header section-header--inline past-events__header">
        <div>
            <p class="bl-label"><?php esc_html_e( 'The Archive', 'blusiast' ); ?></p>
            <h2 class="bl-display-md"><?php esc_html_e( 'Past Events', 'blusiast' ); ?></h2>
        </div>
        <p class="bl-body-md past-events__desc">
            <?php esc_html_e( 'Were you there? Add your photos and help build the gallery.', 'blusiast' ); ?>
        </p>
    </div>

    <div class="past-events__grid">
        <?php while ( $past_q->have_posts() ) : $past_q->the_post();
            $eid       = get_the_ID();
            $date      = function_exists( 'get_field' ) ? get_field( 'event_date' )     : '';
            $location  = function_exists( 'get_field' ) ? get_field( 'event_location' ) : '';
            $fmt       = blusiast_format_event_date( $date );
            $photo_cnt = function_exists( 'blusiast_count_event_photos' ) ? blusiast_count_event_photos( $eid ) : 0;
        ?>

        <article class="past-event-card" aria-label="<?php the_title_attribute(); ?>">

            <a href="<?php the_permalink(); ?>" class="past-event-card__img-wrap" tabindex="-1" aria-hidden="true">
                <?php if ( has_post_thumbnail() ) : ?>
                    <?php the_post_thumbnail( 'blusiast-card', [ 'class' => 'past-event-card__img', 'alt' => '' ] ); ?>
                <?php else : ?>
                    <div class="past-event-card__img-placeholder"></div>
                <?php endif; ?>
                <div class="past-event-card__date" aria-label="<?php echo esc_attr( $fmt['full'] ); ?>">
                    <span class="past-event-card__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                    <span class="past-event-card__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                </div>
            </a>

            <div class="past-event-card__info">

                <div class="past-event-card__badges">
                    <span class="bl-badge bl-badge--white"><?php esc_html_e( 'Past', 'blusiast' ); ?></span>
                    <?php if ( $photo_cnt ) : ?>
                        <span class="bl-badge bl-badge--red bl-badge--dot">
                            <?php
                            printf(
                                esc_html( _n( '%s photo', '%s photos', $photo_cnt, 'blusiast' ) ),
                                esc_html( number_format_i18n( $photo_cnt ) )
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h3 class="past-event-card__title">
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                </h3>

                <div class="past-event-card__meta">
                    <?php if ( $location ) : ?>
                        <span class="past-event-card__meta-item">
                            <?php blusiast_icon( 'location' ); ?>
                            <?php echo esc_html( $location ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $date ) : ?>
                        <span class="past-event-card__meta-sep" aria-hidden="true"></span>
                        <span class="past-event-card__meta-item">
                            <?php blusiast_icon( 'calendar' ); ?>
                            <?php echo esc_html( $fmt['full'] ); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="past-event-card__actions">
                    <?php if ( is_user_logged_in() ) : ?>
                        <a href="<?php echo esc_url( blusiast_event_upload_url( $eid ) ); ?>"
                           class="bl-btn bl-btn--primary bl-btn--sm">
                            <?php esc_html_e( 'Add Your Photos', 'blusiast' ); ?>
                            <?php blusiast_icon( 'arrow-right' ); ?>
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( blusiast_portal_url() ); ?>"
                           class="bl-btn bl-btn--ghost bl-btn--sm">
                            <?php esc_html_e( 'Sign In to Add Photos', 'blusiast' ); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ( $photo_cnt ) : ?>
                        <a href="<?php echo esc_url( blusiast_event_upload_url( $eid ) ); ?>#gallery-events"
                           class="bl-btn bl-btn--ghost bl-btn--sm">
                            <?php esc_html_e( 'View Gallery', 'blusiast' ); ?>
                        </a>
                    <?php endif; ?>
                </div>

            </div><!-- /.past-event-card__info -->

        </article>

        <?php endwhile; wp_reset_postdata(); ?>
    </div>

</section>
