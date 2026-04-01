<?php
/**
 * Section: Member Spotlight
 * Pulls the active spotlight post.
 */

$spotlight = blusiast_get_active_spotlight();
if ( ! $spotlight ) return;

$quote        = function_exists('get_field') ? get_field( 'spotlight_quote',          $spotlight->ID ) : '';
$parks        = function_exists('get_field') ? get_field( 'spotlight_parks_visited',  $spotlight->ID ) : '';
$years        = function_exists('get_field') ? get_field( 'spotlight_years_member',   $spotlight->ID ) : '';
$fave         = function_exists('get_field') ? get_field( 'spotlight_fave_coaster',   $spotlight->ID ) : '';
$subtitle     = function_exists('get_field') ? get_field( 'spotlight_subtitle',       $spotlight->ID ) : '';
$thumb        = get_the_post_thumbnail_url( $spotlight->ID, 'blusiast-portrait' );
?>

<section class="spotlight section">
    <div class="container">
        <p class="bl-label"><?php esc_html_e( 'Community Spotlight', 'blusiast' ); ?></p>

        <div class="spotlight__card">

            <div class="spotlight__img-wrap">
                <?php if ( $thumb ) : ?>
                    <img src="<?php echo esc_url( $thumb ); ?>"
                         alt="<?php echo esc_attr( get_the_title( $spotlight->ID ) ); ?>"
                         class="spotlight__img"
                         loading="lazy">
                <?php else : ?>
                    <div class="spotlight__img-placeholder" aria-hidden="true"></div>
                <?php endif; ?>
            </div>

            <div class="spotlight__body">
                <span class="spotlight__kicker">
                    <?php esc_html_e( 'Member of the Month', 'blusiast' ); ?>
                </span>
                <h2 class="bl-display-md spotlight__name">
                    <?php echo esc_html( get_the_title( $spotlight->ID ) ); ?>
                </h2>
                <?php if ( $subtitle ) : ?>
                    <p class="spotlight__subtitle"><?php echo esc_html( $subtitle ); ?></p>
                <?php endif; ?>

                <?php if ( $quote ) : ?>
                    <blockquote class="spotlight__quote">
                        <?php echo esc_html( $quote ); ?>
                    </blockquote>
                <?php else : ?>
                    <div class="spotlight__excerpt">
                        <?php echo wp_kses_post( wp_trim_words( get_post_field( 'post_content', $spotlight->ID ), 40 ) ); ?>
                    </div>
                <?php endif; ?>

                <div class="spotlight__stats">
                    <?php if ( $parks ) : ?>
                    <div class="spotlight__stat">
                        <span class="spotlight__stat-num"><?php echo esc_html( $parks ); ?></span>
                        <span class="spotlight__stat-label"><?php esc_html_e( 'Parks Visited', 'blusiast' ); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $years ) : ?>
                    <div class="spotlight__stat">
                        <span class="spotlight__stat-num"><?php echo esc_html( $years ); ?> <?php esc_html_e( 'yrs', 'blusiast' ); ?></span>
                        <span class="spotlight__stat-label"><?php esc_html_e( 'Blusiast Member', 'blusiast' ); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $fave ) : ?>
                    <div class="spotlight__stat">
                        <span class="spotlight__stat-num spotlight__stat-num--sm"><?php echo esc_html( $fave ); ?></span>
                        <span class="spotlight__stat-label"><?php esc_html_e( 'Favorite Coaster', 'blusiast' ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <a href="<?php echo esc_url( get_permalink( $spotlight->ID ) ); ?>"
                   class="bl-btn bl-btn--ghost bl-btn--sm">
                    <?php esc_html_e( "Read Their Story", 'blusiast' ); ?>
                    <?php blusiast_icon( 'arrow-right' ); ?>
                </a>
            </div>

        </div>
    </div>
</section>
