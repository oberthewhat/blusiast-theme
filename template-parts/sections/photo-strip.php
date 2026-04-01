<?php
/**
 * Section: Photo Strip
 * Pulls curated homepage images from individual ACF image fields.
 */

$eyebrow = function_exists('get_field') ? get_field('hp_photo_strip_eyebrow') : '';
$heading = function_exists('get_field') ? get_field('hp_photo_strip_heading') : '';

$eyebrow = $eyebrow ?: 'The Moments';
$heading = $heading ?: 'From the Community';

$gallery_images = [];

for ( $i = 1; $i <= 7; $i++ ) {
    $image = function_exists('get_field') ? get_field( 'hp_photo_' . $i ) : null;
    if ( $image ) {
        $gallery_images[] = $image;
    }
}
?>

<section class="photo-strip" aria-label="<?php esc_attr_e( 'Community Photos', 'blusiast' ); ?>">
    <div class="container">
        <div class="photo-strip__header">
            <p class="bl-label"><?php echo esc_html( $eyebrow ); ?></p>
            <h2 class="bl-display-md"><?php echo esc_html( $heading ); ?></h2>
        </div>
    </div>

    <div class="photo-strip__grid">
        <?php if ( ! empty( $gallery_images ) ) : ?>
            <?php foreach ( $gallery_images as $i => $img ) :
                $src = '';
                $alt = '';

                if ( is_array( $img ) ) {
                    $src = ! empty( $img['sizes']['blusiast-gallery'] ) ? $img['sizes']['blusiast-gallery'] : $img['url'];
                    $alt = ! empty( $img['alt'] ) ? $img['alt'] : 'Blusiast community photo';
                } elseif ( is_numeric( $img ) ) {
                    $src = wp_get_attachment_image_url( $img, 'blusiast-gallery' );
                    $alt = get_post_meta( $img, '_wp_attachment_image_alt', true ) ?: 'Blusiast community photo';
                } elseif ( is_string( $img ) ) {
                    $src = $img;
                    $alt = 'Blusiast community photo';
                }
            ?>
                <?php if ( $src ) : ?>
                    <div class="photo-strip__cell <?php echo $i === 0 ? 'photo-strip__cell--tall' : ''; ?>">
                        <img
                            src="<?php echo esc_url( $src ); ?>"
                            alt="<?php echo esc_attr( $alt ); ?>"
                            loading="lazy">
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else : ?>
            <?php for ( $i = 0; $i < 7; $i++ ) : ?>
                <div class="photo-strip__cell <?php echo $i === 0 ? 'photo-strip__cell--tall' : ''; ?> photo-strip__cell--placeholder" aria-hidden="true">
                    <span>Photo</span>
                </div>
            <?php endfor; ?>
        <?php endif; ?>
    </div>

    <div class="container" style="margin-top:2rem; text-align:right;">
        <a href="<?php echo esc_url( get_permalink( get_page_by_path( 'gallery' ) ) ); ?>"
           class="bl-btn bl-btn--ghost bl-btn--sm">
            <?php esc_html_e( 'View Full Gallery', 'blusiast' ); ?>
            <?php blusiast_icon( 'arrow-right' ); ?>
        </a>
    </div>
</section>