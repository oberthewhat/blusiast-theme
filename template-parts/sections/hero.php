<?php
/**
 * Section: Hero
 * Homepage hero with headline, body, CTAs, and hero media.
 */

$hero_line1 = function_exists('get_field') ? get_field('hp_hero_headline') ?: 'The Culture' : 'The Culture';
$hero_line2 = function_exists('get_field') ? get_field('hp_hero_line2') ?: 'Rides' : 'Rides';
$hero_line3 = function_exists('get_field') ? get_field('hp_hero_line3') ?: 'With Us' : 'With Us';
$hero_body  = function_exists('get_field') ? get_field('hp_hero_body') ?: 'A global family of diverse theme park and roller coaster enthusiasts — celebrating joy, inclusion, and the thrill of the ride together.' : 'A global family of diverse theme park and roller coaster enthusiasts — celebrating joy, inclusion, and the thrill of the ride together.';

$hero_bg_image   = function_exists('get_field') ? get_field('hp_hero_bg_image') : '';
$hero_logo_image = function_exists('get_field') ? get_field('hp_hero_logo_image') : '';

$hero_bg_url = '';
if ( ! empty( $hero_bg_image ) ) {
    if ( is_array( $hero_bg_image ) && ! empty( $hero_bg_image['url'] ) ) {
        $hero_bg_url = $hero_bg_image['url'];
    } elseif ( is_numeric( $hero_bg_image ) ) {
        $hero_bg_url = wp_get_attachment_image_url( $hero_bg_image, 'full' );
    } elseif ( is_string( $hero_bg_image ) ) {
        $hero_bg_url = $hero_bg_image;
    }
}

$hero_logo_url = '';
$hero_logo_alt = 'Blusiast logo';

if ( ! empty( $hero_logo_image ) ) {
    if ( is_array( $hero_logo_image ) ) {
        $hero_logo_url = ! empty( $hero_logo_image['url'] ) ? $hero_logo_image['url'] : '';
        $hero_logo_alt = ! empty( $hero_logo_image['alt'] ) ? $hero_logo_image['alt'] : $hero_logo_alt;
    } elseif ( is_numeric( $hero_logo_image ) ) {
        $hero_logo_url = wp_get_attachment_image_url( $hero_logo_image, 'full' );
        $hero_logo_alt = get_post_meta( $hero_logo_image, '_wp_attachment_image_alt', true ) ?: $hero_logo_alt;
    } elseif ( is_string( $hero_logo_image ) ) {
        $hero_logo_url = $hero_logo_image;
    }
}
?>

<section class="hero" aria-label="<?php esc_attr_e( 'Welcome to Blusiast', 'blusiast' ); ?>"<?php if ( $hero_bg_url ) : ?> style="background-image: url('<?php echo esc_url( $hero_bg_url ); ?>');"<?php endif; ?>>
    <div class="hero__overlay" aria-hidden="true"></div>

    <div class="container">
        <div class="hero__grid">

            <div class="hero__content">


                <h1 class="hero__headline bl-animate" style="animation-delay:.1s">
                    <span class="hero__headline-1"><?php echo esc_html( $hero_line1 ); ?></span>
                    <span class="hero__headline-2"><?php echo esc_html( $hero_line2 ); ?></span>
                    <span class="hero__headline-3"><?php echo esc_html( $hero_line3 ); ?></span>
                </h1>

                <?php if ( $hero_body ) : ?>
                    <p class="hero__body bl-animate" style="animation-delay:.2s">
                        <?php echo esc_html( $hero_body ); ?>
                    </p>
                <?php endif; ?>

                <div class="hero__actions bl-animate" style="animation-delay:.3s">
                    <a href="<?php echo esc_url( get_permalink( get_page_by_path( 'membership' ) ) ); ?>" class="bl-btn bl-btn--primary">
                        <?php esc_html_e( 'Join the Community', 'blusiast' ); ?>
                        <?php blusiast_icon( 'arrow-right' ); ?>
                    </a>

                    <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_event' ) ); ?>" class="bl-btn bl-btn--ghost">
                        <?php esc_html_e( 'Explore Events', 'blusiast' ); ?>
                    </a>
                </div>
            </div>

            <div class="hero__visual bl-animate" style="animation-delay:.2s" aria-hidden="true">
                <div class="hero__badge-wrap">
                    <?php if ( $hero_logo_url ) : ?>
                        <img
                            class="hero__badge-img"
                            src="<?php echo esc_url( $hero_logo_url ); ?>"
                            alt="<?php echo esc_attr( $hero_logo_alt ); ?>">
                    <?php else : ?>
                        <div class="hero__badge-placeholder">
                            <span><?php esc_html_e( 'BLUSIAST', 'blusiast' ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</section>