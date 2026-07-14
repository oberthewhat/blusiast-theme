<?php
/**
 * Template Name: Shop
 *
 * page-shop.php — Blusiast merch shop powered by the Spreadshirt Public Shop API.
 *
 * Uses the /sellables endpoint (the correct one for Partner Area shops).
 * Products are fetched server-side, cached for 6 hours via WordPress transients.
 * Settings stored in CRM → Shop Settings.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

// ── Config ─────────────────────────────────────────────────────────────────
$api_key  = get_option( 'bl_spreadshirt_api_key', '' );
$secret   = get_option( 'bl_spreadshirt_secret_key', '' );
$shop_id  = get_option( 'bl_spreadshirt_shop_id', '1170219' );
$shop_url = rtrim( get_option( 'bl_spreadshirt_shop_url', 'https://blusiastmerch.myspreadshop.com' ), '/' );
$base     = 'https://api.spreadshirt.com/api/v1';

// ── Signed auth header ──────────────────────────────────────────────────────
function bl_sprd_auth( $method, $url, $api_key, $secret ) {
    $time = time() * 1000;
    $data = "$method $url $time";
    $sig  = sha1( "$data $secret" );
    return "SprdAuth apiKey=\"{$api_key}\", data=\"{$data}\", sig=\"{$sig}\"";
}

function bl_sprd_get( $url, $api_key, $secret ) {
    return wp_remote_get( $url, [
        'timeout' => 15,
        'headers' => [
            'Authorization' => bl_sprd_auth( 'GET', $url, $api_key, $secret ),
            'User-Agent'    => 'Blusiast/1.0 (https://blusiast.org; admin@blusiast.org)',
        ],
    ] );
}

// ── Active filter ───────────────────────────────────────────────────────────
$active_filter = sanitize_text_field( $_GET['type'] ?? 'all' );

// ── Fetch & cache sellables ─────────────────────────────────────────────────
$cache_key = 'bl_sprd_sellables_' . $shop_id;
$products  = get_transient( $cache_key );

if ( false === $products && $api_key && $secret ) {
    $products = [];

    // Fetch up to 3 pages (144 items) — more than enough for most shops
    for ( $page = 0; $page <= 2; $page++ ) {
        $url      = "{$base}/shops/{$shop_id}/sellables?page={$page}&locale=en_US";
        $response = bl_sprd_get( $url, $api_key, $secret );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) break;

        $body      = json_decode( wp_remote_retrieve_body( $response ), true );
        $sellables = $body['sellables'] ?? [];
        if ( empty( $sellables ) ) break;

        foreach ( $sellables as $s ) {
            $products[] = [
                'id'            => $s['sellableId'] ?? '',
                'idea_id'       => $s['ideaId'] ?? '',
                'name'          => $s['name'] ?? 'Untitled',
                'price'         => (float) ( $s['price']['amount'] ?? 0 ),
                'img'           => $s['previewImage']['url'] ?? '',
                'product_type'  => $s['productTypeId'] ?? '',
                'appearance_id' => $s['defaultAppearanceId'] ?? '',
            ];
        }

        // If fewer than 48 returned, we got all of them
        if ( count( $sellables ) < 48 ) break;
    }

    if ( ! empty( $products ) ) {
        set_transient( $cache_key, $products, 6 * HOUR_IN_SECONDS );
    }
}

if ( ! is_array( $products ) ) $products = [];

// ── Fetch product type names for filter labels (cached separately) ──────────
$pt_cache_key  = 'bl_sprd_product_types_' . $shop_id;
$product_types = get_transient( $pt_cache_key );

if ( false === $product_types && $api_key && $secret ) {
    $product_types = [];
    $pt_ids        = array_unique( array_column( $products, 'product_type' ) );

    foreach ( $pt_ids as $pt_id ) {
        if ( ! $pt_id ) continue;
        $url      = "{$base}/shops/{$shop_id}/productTypes/{$pt_id}?locale=en_US";
        $response = bl_sprd_get( $url, $api_key, $secret );
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $product_types[ $pt_id ] = $body['name'] ?? "Type {$pt_id}";
        }
    }

    if ( ! empty( $product_types ) ) {
        set_transient( $pt_cache_key, $product_types, 24 * HOUR_IN_SECONDS );
    }
}

if ( ! is_array( $product_types ) ) $product_types = [];

// ── Build filter options (unique type names) ────────────────────────────────
$type_counts = [];
foreach ( $products as $p ) {
    $label = $product_types[ $p['product_type'] ] ?? null;
    if ( $label ) {
        $type_counts[ $label ] = ( $type_counts[ $label ] ?? 0 ) + 1;
    }
}
ksort( $type_counts );

// ── Apply filter ────────────────────────────────────────────────────────────
$filtered = $products;
if ( $active_filter !== 'all' ) {
    $filtered = array_values( array_filter( $products, function( $p ) use ( $active_filter, $product_types ) {
        return ( $product_types[ $p['product_type'] ] ?? '' ) === $active_filter;
    } ) );
}

// ── Deep link to Spreadshirt product page ──────────────────────────────────
function bl_sprd_product_url( $shop_url, $item ) {
    // Link directly to the shop's own myspreadshop domain
    $base_url = rtrim( $shop_url, '/' ) . '/ideas/' . urlencode( $item['idea_id'] );
    return add_query_arg( [
        'productType' => $item['product_type'],
        'appearance'  => $item['appearance_id'],
    ], $base_url );
}

$has_credentials = $api_key && $secret;
?>

<!-- ── HERO ── -->
<div class="page-hero">
    <div class="container">
        <p class="bl-label">Blusiast Gear</p>
        <h1 class="bl-display-lg">Rep the Culture</h1>
        <p style="margin-top:12px;color:var(--gray-2);max-width:520px;font-size:16px;line-height:1.6;">
            Official Blusiast merch. Every purchase supports the community.
        </p>
    </div>
</div>

<!-- ── SHOP BODY ── -->
<div class="page-content" style="background:var(--black);padding:64px 0 100px;">
    <div class="container">

        <?php if ( ! $has_credentials ) : ?>
        <div style="background:var(--surface-1);border:1px solid var(--surface-3);border-radius:var(--radius-lg);padding:48px 32px;text-align:center;max-width:500px;margin:0 auto;">
            <div style="font-size:40px;margin-bottom:16px;">🛍️</div>
            <h2 class="bl-display-sm" style="margin-bottom:12px;">Shop Coming Soon</h2>
            <p style="color:var(--gray-1);font-size:14px;">The Blusiast store is being set up. Check back soon.</p>
        </div>

        <?php elseif ( empty( $products ) ) : ?>
        <div style="background:var(--surface-1);border:1px solid var(--surface-3);border-radius:var(--radius-lg);padding:48px 32px;text-align:center;max-width:500px;margin:0 auto;">
            <div style="font-size:40px;margin-bottom:16px;">🛍️</div>
            <h2 class="bl-display-sm" style="margin-bottom:12px;">No Products Found</h2>
            <p style="color:var(--gray-1);font-size:14px;">Make sure your shop has published products in the Spreadshirt Partner Area, and your API credentials are correct in CRM → Shop Settings.</p>
        </div>

        <?php else : ?>

        <!-- ── FILTER BAR ── -->
        <?php if ( count( $type_counts ) > 1 ) : ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:36px;align-items:center;">
            <a href="<?php echo esc_url( get_permalink() ); ?>"
               class="bl-shop-filter-btn <?php echo $active_filter === 'all' ? 'active' : ''; ?>">
                All (<?php echo count( $products ); ?>)
            </a>
            <?php foreach ( $type_counts as $label => $count ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'type', urlencode( $label ), get_permalink() ) ); ?>"
               class="bl-shop-filter-btn <?php echo $active_filter === $label ? 'active' : ''; ?>">
                <?php echo esc_html( $label ); ?> (<?php echo $count; ?>)
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── PRODUCT GRID ── -->
        <?php if ( empty( $filtered ) ) : ?>
        <div style="text-align:center;padding:64px 0;color:var(--gray-1);">
            <p>No products in this category.</p>
            <a href="<?php echo esc_url( get_permalink() ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm" style="margin-top:16px;display:inline-flex;">View All</a>
        </div>
        <?php else : ?>
        <div class="bl-shop-grid">
            <?php foreach ( $filtered as $item ) :
                $product_url  = bl_sprd_product_url( $shop_url, $item );
                $type_label   = $product_types[ $item['product_type'] ] ?? '';
            ?>
            <a href="<?php echo esc_url( $product_url ); ?>" target="_blank" rel="noopener" class="bl-shop-card">
                <div class="bl-shop-card__img-wrap">
                    <?php if ( $item['img'] ) : ?>
                    <img src="<?php echo esc_url( $item['img'] ); ?>"
                         alt="<?php echo esc_attr( $item['name'] ); ?>"
                         loading="lazy">
                    <?php else : ?>
                    <div class="bl-shop-card__placeholder">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--surface-4)"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    </div>
                    <?php endif; ?>
                    <div class="bl-shop-card__overlay">
                        <span class="bl-btn bl-btn--primary bl-btn--sm" style="pointer-events:none;">Shop Now ↗</span>
                    </div>
                </div>
                <div class="bl-shop-card__body">
                    <?php if ( $type_label ) : ?>
                    <div class="bl-shop-card__type"><?php echo esc_html( $type_label ); ?></div>
                    <?php endif; ?>
                    <h3 class="bl-shop-card__name"><?php echo esc_html( $item['name'] ); ?></h3>
                    <div class="bl-shop-card__price">
                        <?php echo $item['price'] ? '$' . number_format( $item['price'], 2 ) : '—'; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="margin-top:56px;text-align:center;border-top:1px solid var(--surface-2);padding-top:40px;">
            <p style="color:var(--gray-1);font-size:13px;">
                All orders are fulfilled and shipped by Spreadshirt. You'll be taken to their secure checkout to complete your purchase.
            </p>
            <a href="<?php echo esc_url( $shop_url ); ?>"
               target="_blank" rel="noopener"
               class="bl-btn bl-btn--ghost bl-btn--sm"
               style="display:inline-flex;margin-top:16px;">
                View Full Store on Spreadshirt ↗
            </a>
        </div>

        <?php endif; ?>

    </div>
</div>

<style>
.bl-shop-filter-btn {
    display:inline-flex;align-items:center;padding:8px 16px;border-radius:100px;
    font-family:var(--font-display);font-size:12px;font-weight:700;text-transform:uppercase;
    letter-spacing:.06em;border:1px solid var(--surface-4);color:var(--gray-2);
    background:var(--surface-1);text-decoration:none;white-space:nowrap;
    transition:border-color .15s,color .15s,background .15s;
}
.bl-shop-filter-btn:hover { border-color:var(--gray-2);color:var(--white);text-decoration:none; }
.bl-shop-filter-btn.active { background:var(--red);border-color:var(--red);color:var(--white); }

.bl-shop-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:20px; }
@media(max-width:1024px){.bl-shop-grid{grid-template-columns:repeat(3,1fr);}}
@media(max-width:720px) {.bl-shop-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:420px) {.bl-shop-grid{grid-template-columns:1fr;}}

.bl-shop-card {
    background:var(--surface-1);border:1px solid var(--surface-3);border-radius:var(--radius-lg);
    overflow:hidden;text-decoration:none;display:flex;flex-direction:column;
    transition:border-color .2s,transform .2s var(--ease-out);
}
.bl-shop-card:hover { border-color:var(--red-dim);transform:translateY(-4px);text-decoration:none; }
.bl-shop-card__img-wrap { position:relative;aspect-ratio:1;background:var(--surface-2);overflow:hidden; }
.bl-shop-card__img-wrap img { width:100%;height:100%;object-fit:contain;padding:8px;transition:transform .3s var(--ease-out); }
.bl-shop-card:hover .bl-shop-card__img-wrap img { transform:scale(1.05); }
.bl-shop-card__placeholder { width:100%;height:100%;display:flex;align-items:center;justify-content:center; }
.bl-shop-card__overlay { position:absolute;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s; }
.bl-shop-card:hover .bl-shop-card__overlay { opacity:1; }
.bl-shop-card__body { padding:14px 16px 18px;flex:1;display:flex;flex-direction:column;gap:4px; }
.bl-shop-card__type { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--gray-1); }
.bl-shop-card__name { font-family:var(--font-display);font-size:15px;font-weight:700;text-transform:uppercase;color:var(--white);line-height:1.2;margin-top:2px; }
.bl-shop-card__price { font-family:var(--font-display);font-size:18px;font-weight:700;color:var(--red);margin-top:6px; }
</style>

<?php get_footer(); ?>
