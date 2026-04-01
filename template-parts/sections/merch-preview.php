<?php // template-parts/sections/merch-preview.php
// Shows 3 featured WooCommerce products if WC is active.
// Falls back to placeholder cards if WC not installed yet.
$has_woo = function_exists( 'wc_get_featured_product_ids' );
$products = [];
if ( $has_woo ) {
    $products = wc_get_products([
        'limit'    => 3,
        'status'   => 'publish',
        'featured' => true,
    ]);
    if ( empty( $products ) ) {
        $products = wc_get_products([ 'limit' => 3, 'status' => 'publish' ]);
    }
}
?>
<section class="merch-preview section">
    <div class="container">
        <div class="section-header section-header--inline">
            <div>
                <p class="bl-label"><?php esc_html_e( 'Blusiast Gear', 'blusiast' ); ?></p>
                <h2 class="bl-display-md"><?php esc_html_e( 'Rep the Culture', 'blusiast' ); ?></h2>
            </div>
            <?php if ( $has_woo ) : ?>
            <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
               class="bl-btn bl-btn--ghost bl-btn--sm">
                <?php esc_html_e( 'Shop All', 'blusiast' ); ?>
                <?php blusiast_icon( 'arrow-right' ); ?>
            </a>
            <?php endif; ?>
        </div>

        <div class="merch-grid">
            <?php if ( ! empty( $products ) ) : ?>
                <?php foreach ( $products as $product ) :
                    $img_id  = $product->get_image_id();
                    $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'blusiast-card' ) : '';
                    $price   = $product->get_price_html();
                    $url     = $product->get_permalink();
                    $title   = $product->get_name();
                    $short   = $product->get_short_description();
                ?>
                <div class="merch-card">
                    <a href="<?php echo esc_url( $url ); ?>" class="merch-card__img-wrap" tabindex="-1" aria-hidden="true">
                        <?php if ( $img_url ) : ?>
                            <img src="<?php echo esc_url( $img_url ); ?>" alt="" loading="lazy">
                        <?php else : ?>
                            <div class="merch-card__img-placeholder" aria-hidden="true"></div>
                        <?php endif; ?>
                    </a>
                    <div class="merch-card__body">
                        <h3 class="merch-card__title">
                            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
                        </h3>
                        <?php if ( $short ) : ?>
                            <p class="merch-card__desc"><?php echo esc_html( wp_strip_all_tags( $short ) ); ?></p>
                        <?php endif; ?>
                        <div class="merch-card__footer">
                            <span class="merch-card__price"><?php echo wp_kses_post( $price ); ?></span>
                            <a href="<?php echo esc_url( $url ); ?>" class="bl-btn bl-btn--primary bl-btn--sm">
                                <?php esc_html_e( 'Shop Now', 'blusiast' ); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else : ?>
                <?php
                $placeholders = [
                    [ 'title' => 'Classic Logo Tee',  'desc' => 'Black · Red · S–3XL' ],
                    [ 'title' => 'Blusiast Hoodie',   'desc' => 'Heavyweight · S–3XL' ],
                    [ 'title' => 'Snapback Cap',       'desc' => 'One Size Adjustable' ],
                ];
                foreach ( $placeholders as $p ) : ?>
                <div class="merch-card">
                    <div class="merch-card__img-wrap">
                        <div class="merch-card__img-placeholder" aria-hidden="true"></div>
                    </div>
                    <div class="merch-card__body">
                        <h3 class="merch-card__title"><?php echo esc_html( $p['title'] ); ?></h3>
                        <p class="merch-card__desc"><?php echo esc_html( $p['desc'] ); ?></p>
                        <div class="merch-card__footer">
                            <span class="merch-card__price">$—</span>
                            <span class="bl-btn bl-btn--primary bl-btn--sm" aria-disabled="true">
                                <?php esc_html_e( 'Coming Soon', 'blusiast' ); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
