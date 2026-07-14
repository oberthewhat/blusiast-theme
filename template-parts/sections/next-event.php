<?php
/**
 * Section: Next Event
 * Big feature block for the single soonest upcoming event.
 * Sits directly under the Mission section on the homepage.
 */

$next_q = blusiast_get_upcoming_events( 1 );
if ( ! $next_q->have_posts() ) {
    wp_reset_postdata();
    return;
}

$next_q->the_post();

$eid          = get_the_ID();
$date         = function_exists( 'get_field' ) ? get_field( 'event_date' )         : '';
$location     = function_exists( 'get_field' ) ? get_field( 'event_location' )     : '';
$time         = function_exists( 'get_field' ) ? get_field( 'event_time' )         : '';
$reg_url      = function_exists( 'get_field' ) ? get_field( 'event_reg_url' )      : get_permalink();
$members_only = function_exists( 'get_field' ) ? get_field( 'event_members_only' ) : false;
$sold_out     = function_exists( 'get_field' ) ? get_field( 'event_sold_out' )     : false;
$fmt          = blusiast_format_event_date( $date );
$excerpt      = get_the_excerpt();

// Lowest available price across tiers
$ph_cents     = (int) get_post_meta( $eid, 'passholder_price_cents',    true );
$nph_cents    = (int) get_post_meta( $eid, 'nonpassholder_price_cents', true );
$legacy_cents = (int) get_post_meta( $eid, 'ticket_price_cents',        true );
$candidates   = array_filter( [ $ph_cents, $nph_cents, $legacy_cents ] );
if ( $candidates ) {
    $lowest  = min( $candidates );
    $dollars = $lowest / 100;
    $price   = ( $ph_cents && $nph_cents && $ph_cents !== $nph_cents )
        ? 'From $' . number_format( $dollars, 2 )
        : '$' . number_format( $dollars, 2 );
} else {
    $price = 'Free';
}

// Countdown
$days_out = '';
if ( $date ) {
    $diff = ( strtotime( $date ) - strtotime( date( 'Y-m-d' ) ) ) / DAY_IN_SECONDS;
    $diff = (int) round( $diff );
    if ( $diff === 0 )      $days_out = __( 'Today', 'blusiast' );
    elseif ( $diff === 1 )  $days_out = __( 'Tomorrow', 'blusiast' );
    elseif ( $diff > 1 )    $days_out = sprintf( __( '%d days away', 'blusiast' ), $diff );
}
?>

<section class="next-event section" aria-labelledby="next-event-title">
    <div class="container">

        <div class="next-event__card">

            <div class="next-event__media">
                <a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true" class="next-event__media-link">
                    <?php if ( has_post_thumbnail() ) : ?>
                        <?php the_post_thumbnail( 'blusiast-hero', [ 'class' => 'next-event__img', 'alt' => '' ] ); ?>
                    <?php else : ?>
                        <div class="next-event__img-placeholder"></div>
                    <?php endif; ?>
                    <?php if ( $sold_out ) : ?>
                        <div class="next-event__sold-out-banner"><span><?php esc_html_e( 'Sold Out', 'blusiast' ); ?></span></div>
                    <?php endif; ?>
                </a>

                <div class="next-event__datebox" aria-label="<?php echo esc_attr( $fmt['full'] ); ?>">
                    <span class="next-event__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                    <span class="next-event__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                </div>
            </div>

            <div class="next-event__body">

                <div class="next-event__eyebrow">
                    <p class="bl-label"><?php esc_html_e( 'Next Up', 'blusiast' ); ?></p>
                    <?php if ( $days_out ) : ?>
                        <span class="next-event__countdown"><?php echo esc_html( $days_out ); ?></span>
                    <?php endif; ?>
                </div>

                <h2 class="bl-display-md next-event__title" id="next-event-title">
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                </h2>

                <div class="next-event__badges">
                    <?php if ( $members_only ) : ?>
                        <span class="bl-badge bl-badge--red bl-badge--dot"><?php esc_html_e( 'Members Only', 'blusiast' ); ?></span>
                    <?php else : ?>
                        <span class="bl-badge bl-badge--white bl-badge--dot"><?php esc_html_e( 'Open to All', 'blusiast' ); ?></span>
                    <?php endif; ?>
                    <?php if ( $sold_out ) : ?>
                        <span class="bl-badge bl-badge--white"><?php esc_html_e( 'Sold Out', 'blusiast' ); ?></span>
                    <?php endif; ?>
                </div>

                <div class="next-event__meta">
                    <?php if ( $location ) : ?>
                        <span class="next-event__meta-item">
                            <?php blusiast_icon( 'location' ); ?>
                            <?php echo esc_html( $location ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $time ) : ?>
                        <span class="next-event__meta-item">
                            <?php blusiast_icon( 'calendar' ); ?>
                            <?php echo esc_html( $time ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="next-event__meta-item next-event__meta-item--price<?php echo $price === 'Free' ? ' next-event__meta-item--free' : ''; ?>">
                        <?php echo esc_html( $price ); ?>
                    </span>
                </div>

                <?php if ( $excerpt ) : ?>
                    <p class="bl-body-lg next-event__excerpt">
                        <?php echo esc_html( wp_trim_words( $excerpt, 28 ) ); ?>
                    </p>
                <?php endif; ?>

                <div class="next-event__actions">
                    <?php if ( $sold_out ) : ?>
                        <span class="bl-btn bl-btn--ghost" aria-disabled="true"><?php esc_html_e( 'Sold Out', 'blusiast' ); ?></span>
                    <?php elseif ( $reg_url ) : ?>
                        <a href="<?php echo esc_url( $reg_url ); ?>" class="bl-btn bl-btn--primary">
                            <?php esc_html_e( 'Register & Pay', 'blusiast' ); ?>
                            <?php blusiast_icon( 'arrow-right' ); ?>
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_event' ) ); ?>" class="bl-btn bl-btn--ghost">
                        <?php esc_html_e( 'All Events', 'blusiast' ); ?>
                    </a>
                </div>

            </div><!-- /.next-event__body -->

        </div><!-- /.next-event__card -->

    </div>
</section>

<?php wp_reset_postdata(); ?>
