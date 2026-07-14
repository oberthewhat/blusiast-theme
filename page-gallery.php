<?php
/**
 * page-gallery.php
 * Gallery page template — Blusiast theme.
 * WordPress loads this when the page slug is "gallery".
 *
 * Data model (no ACF required):
 *   Admin creates posts of post_type = 'bl_gallery' via WP block editor.
 *   Each post title is the gallery name ("General", "Kings Dominion June 2025", etc.)
 *   The post body contains a standard WordPress Gallery block.
 *   Image IDs are parsed directly from the block's HTML — no meta, no ACF.
 *
 *   One special post titled exactly "General" populates the top masonry grid.
 *   All others become event galleries with filter pills.
 *
 *   Member-submitted photos (pending approval) are shown in an upload form at the bottom.
 *
 * Comments are stored as standard WordPress comments attached to THIS page,
 * with a custom meta key `_gallery_image_id` that holds "att-{attachment_id}".
 */

get_header();

/* ── HELPER: extract attachment IDs from a native Gallery block ─────────
   Gallery block HTML looks like:
   <!-- wp:gallery {"ids":[123,456,789]} -->
   We pull from the block attributes first; fall back to parsing <img> srcs.
   ----------------------------------------------------------------------- */
function blusiast_parse_gallery_ids( $post_content ) {
    $ids = [];

    // Method 1: block editor — wp:gallery block comment with ids attribute
    // e.g. <!-- wp:gallery {"ids":[123,456]} -->
    if ( preg_match_all( '/<!--\s*wp:gallery\s+({.+?})/is', $post_content, $m ) ) {
        foreach ( $m[1] as $json_str ) {
            $attrs = json_decode( $json_str, true );
            if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
                foreach ( $attrs['ids'] as $id ) {
                    $ids[] = (int) $id;
                }
            }
        }
    }

    // Method 2: classic editor legacy shortcode — [gallery ids="1,2,3"]
    // This is what "Add Media → Create Gallery" produces
    if ( empty( $ids ) && preg_match_all( '/\[gallery[^\]]*ids=["\']{0,1}([\d,\s]+)/i', $post_content, $m2 ) ) {
        foreach ( $m2[1] as $id_string ) {
            foreach ( explode( ',', $id_string ) as $id ) {
                $clean = (int) trim( $id );
                if ( $clean ) $ids[] = $clean;
            }
        }
    }

    // Method 3: block editor newer format — wp:image blocks inside a wp:gallery wrapper
    // e.g. <!-- wp:image {"id":123} -->
    if ( empty( $ids ) && preg_match_all( '/<!--\s*wp:image\s+({.+?})/is', $post_content, $m3 ) ) {
        foreach ( $m3[1] as $json_str ) {
            $attrs = json_decode( $json_str, true );
            if ( ! empty( $attrs['id'] ) ) {
                $ids[] = (int) $attrs['id'];
            }
        }
    }

    // Method 4: last resort — wp-image-{id} class on any <img> tag
    if ( empty( $ids ) && preg_match_all( '/class="[^"]*wp-image-(\d+)/i', $post_content, $m4 ) ) {
        foreach ( $m4[1] as $id ) {
            $ids[] = (int) $id;
        }
    }

    return array_unique( array_filter( $ids ) );
}

/* ── FETCH ALL GALLERY POSTS ──────────────────────────────────────────── */
$gallery_posts = get_posts( [
    'post_type'      => 'bl_gallery',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'menu_order date',
    'order'          => 'ASC',
] );

/* Split into General vs event galleries */
$general_post    = null;
$event_galleries = [];   // [ [ 'title' => '', 'date_label' => '', 'ids' => [] ], … ]

foreach ( $gallery_posts as $gpost ) {
    $ids = blusiast_parse_gallery_ids( $gpost->post_content );
    if ( empty( $ids ) ) continue;

    // A post titled "General" (case-insensitive) feeds the top masonry grid
    if ( strtolower( trim( $gpost->post_title ) ) === 'general' ) {
        $general_post = [ 'post' => $gpost, 'ids' => $ids ];
    } else {
        // Use the post excerpt as an optional date label (e.g. "June 2025")
        $date_label = has_excerpt( $gpost ) ? get_the_excerpt( $gpost ) : date( 'M Y', strtotime( $gpost->post_date ) );
        $event_galleries[] = [
            'post'       => $gpost,
            'title'      => $gpost->post_title,
            'date_label' => $date_label,
            'ids'        => $ids,
        ];
    }
}

/* Fall back to demo placeholders only if no General gallery post exists yet */
$general_images = [];
if ( $general_post ) {
    foreach ( $general_post['ids'] as $att_id ) {
        $src  = wp_get_attachment_image_url( $att_id, 'blusiast-gallery' ) ?: wp_get_attachment_url( $att_id );
        $meta = wp_get_attachment_metadata( $att_id );
        $alt  = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
        $cap  = wp_get_attachment_caption( $att_id );
        if ( $src ) {
            $general_images[] = [
                'att_id'  => $att_id,
                'src'     => $src,
                'alt'     => $alt ?: '',
                'caption' => $cap ?: '',
            ];
        }
    }
} else {
    // Demo mode — remove once you publish a "General" gallery post
    $general_images = array_map( fn( $i ) => [
        'att_id'  => 0,
        'src'     => "https://picsum.photos/seed/blu{$i}/800/600",
        'alt'     => "Gallery photo {$i}",
        'caption' => "A great day at the park — shot {$i}",
    ], range( 1, 12 ) );
}

/* ── COMMENTS ─────────────────────────────────────────────────────────── */
$page_id      = get_the_ID();
$all_comments = get_comments( [ 'post_id' => $page_id, 'status' => 'approve', 'number' => 1000 ] );
$comments_by_id = [];
foreach ( $all_comments as $c ) {
    $img_key = get_comment_meta( $c->comment_ID, '_gallery_image_id', true );
    if ( $img_key !== '' ) {
        $comments_by_id[ $img_key ][] = $c;
    }
}

$gallery_nonce  = wp_create_nonce( 'blusiast_gallery_comment' );
$upload_nonce   = wp_create_nonce( 'blusiast_gallery_upload' );

/* ── PAST EVENTS (for the upload dropdown + member photo galleries) ───── */
$past_events    = function_exists( 'blusiast_past_events_list' ) ? blusiast_past_events_list( 50 ) : [];
$preselect_evt  = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
if ( $preselect_evt && get_post_type( $preselect_evt ) !== 'bl_event' ) $preselect_evt = 0;

/* Member-submitted approved photos, grouped by event, appended to the
   event gallery blocks below so they live alongside the admin galleries. */
$member_event_galleries = [];
foreach ( $past_events as $pe ) {
    $photos = function_exists( 'blusiast_get_event_photos' ) ? blusiast_get_event_photos( $pe['id'] ) : [];
    if ( ! $photos ) continue;

    $ids = [];
    foreach ( $photos as $ph ) {
        if ( $ph->attachment_id ) $ids[] = (int) $ph->attachment_id;
    }
    if ( ! $ids ) continue;

    $member_event_galleries[] = [
        'post'       => get_post( $pe['id'] ),
        'title'      => $pe['title'],
        'date_label' => $pe['date'] ? date( 'M Y', strtotime( $pe['date'] ) ) : '',
        'ids'        => $ids,
        'event_id'   => $pe['id'],
    ];
}

/* Merge member galleries into the existing event galleries.
   If an admin gallery post already carries the same title, fold the
   member photos into it rather than creating a duplicate block. */
foreach ( $member_event_galleries as $mg ) {
    $merged = false;
    foreach ( $event_galleries as &$eg ) {
        if ( strcasecmp( trim( $eg['title'] ), trim( $mg['title'] ) ) === 0 ) {
            $eg['ids']      = array_unique( array_merge( $eg['ids'], $mg['ids'] ) );
            $eg['event_id'] = $mg['event_id'];
            $merged = true;
            break;
        }
    }
    unset( $eg );
    if ( ! $merged ) $event_galleries[] = $mg;
}
?>

<?php /* ── PAGE HERO ──────────────────────────────────────────────── */ ?>
<section class="gallery-hero">
    <div class="container">
        <span class="bl-label">The Crew in Action</span>
        <h1 class="bl-display-lg gallery-hero__heading">Our Gallery</h1>
        <p class="bl-body-lg gallery-hero__sub">
            Every ride. Every laugh. Every memory — captured by the community, for the community.
        </p>
    </div>
    <div class="gallery-hero__rule" aria-hidden="true">
        <span></span><span></span><span></span>
    </div>
</section>

<?php /* ── GENERAL GALLERY ─────────────────────────────────────────── */ ?>
<section class="section gallery-general-section" id="gallery-general">
    <div class="container">

        <div class="section-header gallery-section-header">
            <div>
                <span class="bl-label">Community Shots</span>
                <h2 class="bl-display-md">General Gallery</h2>
            </div>
            <p class="bl-body-md gallery-section-header__desc">
                Snapshots from the community — tap any photo to view full size and drop a comment.
            </p>
        </div>

        <div class="gallery-masonry" id="general-grid">
            <?php foreach ( $general_images as $idx => $img ) :
                // Use attachment ID as the stable image key so comments survive re-ordering
                $img_id  = $img['att_id'] ? 'att-' . $img['att_id'] : 'gen-' . $idx;
                $count   = isset( $comments_by_id[ $img_id ] ) ? count( $comments_by_id[ $img_id ] ) : 0;
            ?>
            <div class="gallery-item"
                 data-img-id="<?php echo esc_attr( $img_id ); ?>"
                 data-src="<?php echo esc_url( $img['src'] ); ?>"
                 data-caption="<?php echo esc_attr( $img['caption'] ); ?>">
                <div class="gallery-item__inner">
                    <img src="<?php echo esc_url( $img['src'] ); ?>"
                         alt="<?php echo esc_attr( $img['alt'] ); ?>"
                         loading="lazy">
                    <div class="gallery-item__overlay">
                        <span class="gallery-item__zoom" aria-hidden="true">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </span>
                        <?php if ( $img['caption'] ) : ?>
                        <p class="gallery-item__caption"><?php echo esc_html( $img['caption'] ); ?></p>
                        <?php endif; ?>
                        <button class="gallery-item__comment-btn" aria-label="View comments">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <span class="comment-count"><?php echo $count; ?></span>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<?php /* ── EVENT GALLERY ───────────────────────────────────────────── */ ?>
<?php if ( ! empty( $event_galleries ) ) : ?>
<section class="section gallery-events-section" id="gallery-events">
    <div class="container">

        <div class="section-header gallery-section-header">
            <div>
                <span class="bl-label">By the Moment</span>
                <h2 class="bl-display-md">Event Gallery</h2>
            </div>
            <p class="bl-body-md gallery-section-header__desc">
                Relive each trip. Filter by event to find your favorite memories.
            </p>
        </div>

        <?php /* Filter Pills */ ?>
        <div class="archive-filters gallery-event-filters" role="tablist" aria-label="Filter by event">
            <button class="archive-filter archive-filter--active gallery-event-filter"
                    data-event="all" role="tab" aria-selected="true">All Events</button>
            <?php foreach ( $event_galleries as $eidx => $ev ) : ?>
            <button class="archive-filter gallery-event-filter"
                    data-event="<?php echo esc_attr( 'event-' . $eidx ); ?>"
                    role="tab" aria-selected="false">
                <?php echo esc_html( $ev['title'] ); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ( $event_galleries as $eidx => $ev ) :
            $event_key = 'event-' . $eidx;
        ?>
        <div class="gallery-event-block" data-event="<?php echo esc_attr( $event_key ); ?>">

            <div class="gallery-event-block__header">
                <div class="gallery-event-block__meta">
                    <span class="gallery-event-block__date bl-label"><?php echo esc_html( $ev['date_label'] ); ?></span>
                    <h3 class="bl-display-sm gallery-event-block__name"><?php echo esc_html( $ev['title'] ); ?></h3>
                </div>
                <div class="gallery-event-block__aside">
                    <span class="gallery-event-block__count"><?php echo count( $ev['ids'] ); ?> photos</span>
                    <?php if ( ! empty( $ev['event_id'] ) && is_user_logged_in() ) : ?>
                        <a href="#gallery-upload"
                           class="bl-btn bl-btn--ghost bl-btn--sm gallery-event-block__add"
                           data-event-id="<?php echo (int) $ev['event_id']; ?>">Add Your Photos</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gallery-event-grid">
                <?php foreach ( $ev['ids'] as $pidx => $att_id ) :
                    $src     = wp_get_attachment_image_url( $att_id, 'blusiast-gallery' ) ?: wp_get_attachment_url( $att_id );
                    $alt     = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
                    $caption = wp_get_attachment_caption( $att_id );
                    if ( ! $src ) continue;
                    $img_id  = 'att-' . $att_id;
                    $count   = isset( $comments_by_id[ $img_id ] ) ? count( $comments_by_id[ $img_id ] ) : 0;
                ?>
                <div class="gallery-item gallery-item--event"
                     data-img-id="<?php echo esc_attr( $img_id ); ?>"
                     data-src="<?php echo esc_url( $src ); ?>"
                     data-caption="<?php echo esc_attr( $caption ?: '' ); ?>"
                     data-event-name="<?php echo esc_attr( $ev['title'] ); ?>">
                    <div class="gallery-item__inner">
                        <img src="<?php echo esc_url( $src ); ?>"
                             alt="<?php echo esc_attr( $alt ?: '' ); ?>"
                             loading="lazy">
                        <div class="gallery-item__overlay">
                            <span class="gallery-item__zoom" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                            </span>
                            <?php if ( $caption ) : ?>
                            <p class="gallery-item__caption"><?php echo esc_html( $caption ); ?></p>
                            <?php endif; ?>
                            <button class="gallery-item__comment-btn" aria-label="View comments">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                <span class="comment-count"><?php echo $count; ?></span>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php endforeach; ?>

    </div>
</section>
<?php endif; ?>


<?php /* ── MEMBER PHOTO UPLOAD ─────────────────────────────────────── */ ?>
<section class="section gallery-upload-section" id="gallery-upload">
    <div class="container">
        <div class="section-header gallery-section-header">
            <div>
                <span class="bl-label">Share Your Shots</span>
                <h2 class="bl-display-md">Submit a Photo</h2>
            </div>
            <p class="bl-body-md gallery-section-header__desc">
                Got a great shot from a trip or event? Submit it here.
                All photos are reviewed by an admin before going live.
            </p>
        </div>

        <?php if ( is_user_logged_in() ) : ?>

        <div class="gallery-upload-wrap">
            <form id="gallery-upload-form" enctype="multipart/form-data">
                <input type="hidden" id="gallery-upload-nonce" value="<?php echo esc_attr( $upload_nonce ); ?>">

                <div class="gallery-upload-dropzone" id="gallery-upload-dropzone">
                    <input type="file" id="gallery-upload-input" name="photo"
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           style="position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;">
                    <div class="gallery-upload-dropzone__icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </div>
                    <p class="gallery-upload-dropzone__label">Click or drag a photo here</p>
                    <p class="gallery-upload-dropzone__hint">JPG, PNG, GIF or WEBP · Max 10 MB</p>
                </div>

                <img id="gallery-upload-preview" src="" alt="" style="display:none;max-width:320px;border-radius:var(--radius-md);margin:16px 0;border:1px solid var(--surface-3);">

                <?php if ( $past_events ) : ?>
                <div class="portal-field" style="max-width:520px;margin-top:16px;">
                    <label class="portal-label" for="gallery-upload-event">Which event? <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-1);">(optional)</span></label>
                    <select class="portal-input" id="gallery-upload-event" name="event_id">
                        <option value="0">General gallery — not from an event</option>
                        <?php foreach ( $past_events as $pe ) : ?>
                            <option value="<?php echo (int) $pe['id']; ?>" <?php selected( $preselect_evt, $pe['id'] ); ?>>
                                <?php echo esc_html( $pe['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="portal-field" style="max-width:520px;margin-top:16px;">
                    <label class="portal-label" for="gallery-upload-caption">Caption <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-1);">(optional)</span></label>
                    <input class="portal-input" type="text" id="gallery-upload-caption" name="caption"
                           placeholder="Where was this? What's happening?" maxlength="200">
                </div>

                <div id="gallery-upload-msg" class="portal-msg" style="max-width:520px;"></div>

                <button type="submit" class="bl-btn bl-btn--primary" style="margin-top:12px;" id="gallery-upload-btn">
                    Submit Photo
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:6px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </form>
        </div>

        <?php else : ?>

        <div class="gallery-upload-login">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--gray-1);margin-bottom:12px;"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <p class="bl-body-md" style="margin-bottom:16px;">Members can submit photos to the gallery.</p>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'register', blusiast_portal_url() ) ); ?>"
               class="bl-btn bl-btn--primary">Join to Submit</a>
            <a href="<?php echo esc_url( blusiast_portal_url() ); ?>"
               class="bl-btn bl-btn--ghost" style="margin-left:10px;">Sign In</a>
        </div>

        <?php endif; ?>

    </div>
</section>


<?php /* ── LIGHTBOX + COMMENT DRAWER ─────────────────────────────── */ ?>
<div class="gl-lightbox" id="gl-lightbox" role="dialog" aria-modal="true" aria-label="Photo viewer" hidden>
    <button class="gl-lightbox__close" id="gl-lb-close" aria-label="Close photo viewer" title="Close (Esc)">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>

    <div class="gl-lightbox__stage">
        <button class="gl-lightbox__arrow gl-lightbox__arrow--prev" id="gl-lb-prev" aria-label="Previous photo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <div class="gl-lightbox__img-wrap">
            <img src="" alt="" id="gl-lb-img" class="gl-lightbox__img">
        </div>
        <button class="gl-lightbox__arrow gl-lightbox__arrow--next" id="gl-lb-next" aria-label="Next photo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>

    <div class="gl-lightbox__sidebar">
        <div class="gl-lightbox__sidebar-top">
            <p class="gl-lightbox__caption" id="gl-lb-caption"></p>
            <span class="gl-lightbox__event-tag" id="gl-lb-event-tag"></span>
        </div>

        <div class="gl-lightbox__comments" id="gl-lb-comments">
            <h4 class="gl-lightbox__comments-heading">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Comments
            </h4>
            <div class="gl-lightbox__comment-list" id="gl-lb-comment-list">
                <p class="gl-lightbox__no-comments">No comments yet — be the first!</p>
            </div>
        </div>

        <?php if ( is_user_logged_in() ) :
            $current_user = wp_get_current_user();
        ?>
        <div class="gl-lightbox__comment-form">
            <input type="hidden" id="gl-comment-img-id" value="">
            <input type="hidden" id="gl-comment-nonce" value="<?php echo esc_attr( $gallery_nonce ); ?>">
            <input type="hidden" id="gl-comment-page-id" value="<?php echo esc_attr( $page_id ); ?>">
            <div class="gl-lightbox__comment-author">
                <?php echo get_avatar( $current_user->user_email, 32, '', '', [ 'class' => 'gl-avatar' ] ); ?>
                <span><?php echo esc_html( $current_user->display_name ); ?></span>
            </div>
            <textarea id="gl-comment-text" class="gl-lightbox__comment-textarea"
                      placeholder="Share your thoughts…" rows="3" maxlength="500"></textarea>
            <button class="btn btn--red btn--sm gl-lightbox__comment-submit" id="gl-comment-submit">
                Post Comment
            </button>
            <p class="gl-lightbox__comment-status" id="gl-comment-status" aria-live="polite"></p>
        </div>
        <?php else : ?>
        <div class="gl-lightbox__comment-login">
            <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="btn btn--outline btn--sm">
                Log in to comment
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<div class="gl-lightbox__backdrop" id="gl-lb-backdrop" hidden></div>

<?php /* ── PRE-SERIALISED COMMENT DATA for JS ──────────────────────── */ ?>
<script id="gallery-comment-data" type="application/json">
<?php
$js_comments = [];
foreach ( $comments_by_id as $img_id => $comments ) {
    $js_comments[ $img_id ] = array_map( function( $c ) {
        return [
            'id'      => (int) $c->comment_ID,
            'author'  => esc_html( $c->comment_author ),
            'avatar'  => get_avatar_url( $c->comment_author_email, [ 'size' => 32 ] ),
            'date'    => esc_html( human_time_diff( strtotime( $c->comment_date_gmt ), current_time( 'timestamp', true ) ) ) . ' ago',
            'content' => esc_html( $c->comment_content ),
        ];
    }, $comments );
}
echo wp_json_encode( $js_comments );
?>
</script>

<?php /* ── UPLOAD FORM JS ───────────────────────────────────────────── */ ?>
<?php if ( is_user_logged_in() ) : ?>
<script>
(function(){
    var form      = document.getElementById('gallery-upload-form');
    var input     = document.getElementById('gallery-upload-input');
    var preview   = document.getElementById('gallery-upload-preview');
    var dropzone  = document.getElementById('gallery-upload-dropzone');
    var btn       = document.getElementById('gallery-upload-btn');
    var msgEl     = document.getElementById('gallery-upload-msg');
    var nonceEl   = document.getElementById('gallery-upload-nonce');
    var captionEl = document.getElementById('gallery-upload-caption');
    var eventEl   = document.getElementById('gallery-upload-event');

    if (!form) return;

    // Remember the chosen event so a reset after submit keeps it selected —
    // members usually upload several photos from the same trip in a row.
    var lockedEvent = eventEl ? eventEl.value : '0';
    if (eventEl) {
        eventEl.addEventListener('change', function(){ lockedEvent = this.value; });
    }

    // Preview on file select
    input.addEventListener('change', function(){
        var file = this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(e){
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
        dropzone.classList.add('has-file');
        dropzone.querySelector('.gallery-upload-dropzone__label').textContent = file.name;
    });

    // Drag-over highlight
    dropzone.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('is-dragover'); });
    dropzone.addEventListener('dragleave', function(){ this.classList.remove('is-dragover'); });
    dropzone.addEventListener('drop', function(e){
        e.preventDefault();
        this.classList.remove('is-dragover');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            input.dispatchEvent(new Event('change'));
        }
    });

    // Submit
    form.addEventListener('submit', function(e){
        e.preventDefault();
        if (!input.files[0]) {
            showMsg('Please select a photo first.', 'error'); return;
        }
        btn.disabled = true;
        btn.textContent = 'Submitting…';
        showMsg('', '');

        var data = new FormData();
        data.append('action',  'blusiast_gallery_upload');
        data.append('nonce',   nonceEl.value);
        data.append('photo',   input.files[0]);
        data.append('caption', captionEl ? captionEl.value : '');
        data.append('event_id', eventEl ? eventEl.value : '0');

        fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', {
            method: 'POST', body: data
        })
        .then(function(r){ return r.json(); })
        .then(function(json){
            btn.disabled = false;
            btn.textContent = 'Submit Photo';
            if (json.success) {
                showMsg(json.data.message, 'success');
                form.reset();
                if (eventEl) eventEl.value = lockedEvent;
                preview.style.display = 'none';
                preview.src = '';
                dropzone.classList.remove('has-file');
                dropzone.querySelector('.gallery-upload-dropzone__label').textContent = 'Click or drag a photo here';
            } else {
                showMsg((json.data && json.data.message) || 'Something went wrong.', 'error');
            }
        })
        .catch(function(){
            btn.disabled = false;
            btn.textContent = 'Submit Photo';
            showMsg('Network error — please try again.', 'error');
        });
    });

    // "Add Your Photos" buttons inside each event block jump to the form
    // and preselect that event.
    document.querySelectorAll('.gallery-event-block__add').forEach(function(a){
        a.addEventListener('click', function(){
            if (!eventEl) return;
            eventEl.value = String(this.dataset.eventId);
            lockedEvent   = eventEl.value;
            eventEl.dispatchEvent(new Event('change'));
        });
    });

    function showMsg(text, type) {
        msgEl.textContent = text;
        msgEl.className = 'portal-msg' + (type ? ' portal-msg--' + type : '');
        msgEl.style.display = text ? 'block' : 'none';
    }
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
