<?php
/**
 * Template Name: Member Profile
 * page-member-profile.php
 *
 * Create a WP page with slug "member-profile".
 * URL: /member-profile/?uid=123  (wp_user_id)
 */
get_header();

global $wpdb;
$mtable = $wpdb->prefix . 'bl_members';
$rtable = $wpdb->prefix . 'bl_event_registrations';
$revtable = $wpdb->prefix . 'bl_coaster_reviews';

$uid    = absint( $_GET['uid'] ?? 0 );
$member = $uid ? $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM $mtable WHERE wp_user_id = %d AND account_status != 'banned' LIMIT 1", $uid
) ) : null;

// If hidden from directory and not viewing own profile, 404
if ( ! $member || ( $member->hide_from_dir && ( ! is_user_logged_in() || get_current_user_id() != $uid ) ) ) {
    ?>
    <div class="page-hero"><div class="container"><h1 class="bl-display-lg">Member Not Found</h1></div></div>
    <div class="page-content"><div class="container"><p class="bl-body-lg">This profile is private or does not exist.</p></div></div>
    <?php
    get_footer(); return;
}

$use_handle  = ( ! empty( $member->handle ) && ( $member->dir_name_pref ?? 'real' ) === 'handle' );
$display     = $use_handle ? $member->handle : $member->first_name . ' ' . $member->last_name;
$initials    = $use_handle
    ? strtoupper( substr( $member->handle, 0, 2 ) )
    : strtoupper( substr( $member->first_name, 0, 1 ) . substr( $member->last_name, 0, 1 ) );

// Event history (confirmed only — public view)
$events = $wpdb->get_results( $wpdb->prepare(
    "SELECT r.*, p.post_title as event_name, p.ID as event_post_id
     FROM $rtable r LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
     WHERE r.email = %s AND r.status = 'confirmed'
     ORDER BY r.created_at DESC LIMIT 10",
    $member->email
) );

// Reviews
$reviews = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM $revtable WHERE member_id = %d ORDER BY created_at DESC",
    $member->id
) );

// Approved photos
$ptable  = $wpdb->prefix . 'bl_photo_submissions';
$photos  = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM $ptable WHERE member_id = %d AND status = 'approved' ORDER BY submitted_at DESC",
    $member->id
) );
?>

<div class="page-hero" style="padding-bottom:0;">
    <div class="container">
        <p class="bl-label">Member Profile</p>
        <div style="display:flex;align-items:center;gap:24px;padding-bottom:40px;flex-wrap:wrap;">
            <div class="portal-avatar" style="width:88px;height:88px;font-size:32px;border:3px solid var(--surface-3);">
                <?php if ( $member->avatar_url ) : ?>
                    <img src="<?php echo esc_url( $member->avatar_url ); ?>" alt="">
                <?php else : ?>
                    <?php echo esc_html( $initials ); ?>
                <?php endif; ?>
            </div>
            <div>
                <h1 class="bl-display-lg" style="margin-bottom:6px;"><?php echo esc_html( $display ); ?></h1>
                <?php if ( $use_handle && ! $member->hide_from_dir ) : ?>
                    <div style="font-size:14px;color:var(--gray-1);"><?php echo esc_html( $member->first_name ); ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px;">
                    <?php if ( $member->home_park ) : ?>
                        <span style="font-size:13px;color:var(--gray-2);">🏠 <?php echo esc_html( $member->home_park ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty( $member->zip ) ) : ?>
                        <span style="font-size:13px;color:var(--gray-2);" data-zip-lookup="<?php echo esc_attr($member->zip); ?>">📍 <?php echo esc_html($member->zip); ?></span>
                    <?php endif; ?>
                    <?php if ( $member->fave_coaster ) : ?>
                        <span style="font-size:13px;color:var(--gray-2);">🎢 <?php echo esc_html( $member->fave_coaster ); ?></span>
                    <?php endif; ?>
                    <?php if ( $member->instagram ) : ?>
                        <a href="https://instagram.com/<?php echo esc_attr( $member->instagram ); ?>" target="_blank" rel="noopener" style="font-size:13px;color:var(--red);">@<?php echo esc_html( $member->instagram ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-content">
    <div class="container">
        <div style="display:grid;grid-template-columns:1fr 340px;gap:32px;align-items:start;">

            <div>
                <?php if ( $member->bio ) : ?>
                <div class="portal-card" style="background:var(--surface-1);border:1px solid var(--surface-3);border-radius:var(--radius-lg);padding:24px;margin-bottom:20px;">
                    <div class="portal-card__title"><span class="portal-card__title-dot"></span> About</div>
                    <p class="bl-body-md"><?php echo nl2br( esc_html( $member->bio ) ); ?></p>
                </div>
                <?php endif; ?>

                <?php if ( $photos ) : ?>
                <div class="portal-card" style="background:var(--surface-1);border:1px solid var(--surface-3);border-radius:var(--radius-lg);padding:24px;margin-bottom:20px;">
                    <div class="portal-card__title"><span class="portal-card__title-dot"></span> Photos (<?php echo count($photos); ?>)</div>
                    <div class="member-gallery">
                        <?php foreach ( $photos as $ph ) :
                            $img_url = $ph->attachment_id ? wp_get_attachment_image_url( $ph->attachment_id, 'blusiast-gallery' ) : '';
                            if ( ! $img_url ) continue;
                        ?>
                        <div>
                            <div class="member-gallery__item">
                                <a href="<?php echo esc_url( wp_get_attachment_url( $ph->attachment_id ) ); ?>" target="_blank">
                                    <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $ph->caption ?: '' ); ?>" loading="lazy">
                                </a>
                            </div>
                            <?php if ( $ph->caption ) : ?>
                                <div class="member-gallery__caption"><?php echo esc_html( $ph->caption ); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $reviews ) : ?>
                <div class="portal-card" style="background:var(--surface-1);border:1px solid var(--surface-3);border-radius:var(--radius-lg);padding:24px;margin-bottom:20px;">
                    <div class="portal-card__title"><span class="portal-card__title-dot"></span> Coaster Reviews (<?php echo count($reviews); ?>)</div>
                    <div style="display:flex;flex-direction:column;gap:16px;">
                    <?php foreach ( $reviews as $rev ) :
                        $thrill_map = [ 'mild' => '🟢', 'moderate' => '🟡', 'intense' => '🟠', 'extreme' => '🔴' ];
                    ?>
                        <div style="padding:16px;background:var(--surface-2);border:1px solid var(--surface-3);border-radius:var(--radius-md);">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                                <div>
                                    <div style="font-family:var(--font-display);font-size:18px;font-weight:700;text-transform:uppercase;color:var(--white);"><?php echo esc_html($rev->coaster_name); ?></div>
                                    <div style="font-size:12px;color:var(--gray-1);"><?php echo esc_html($rev->park_name); ?></div>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="font-family:var(--font-display);font-size:24px;font-weight:800;color:var(--red);"><?php echo (int)$rev->rating; ?><small style="font-size:13px;color:var(--gray-1);">/10</small></span>
                                    <?php if ($rev->thrill_level) : ?><span style="font-size:12px;color:var(--gray-2);"><?php echo ($thrill_map[$rev->thrill_level] ?? '') . ' ' . ucfirst($rev->thrill_level); ?></span><?php endif; ?>
                                </div>
                            </div>
                            <p style="font-size:14px;color:var(--gray-2);line-height:1.6;"><?php echo esc_html($rev->review_text); ?></p>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <aside>
                <div class="portal-card" style="background:var(--surface-1);border:1px solid var(--surface-3);border-radius:var(--radius-lg);padding:24px;position:sticky;top:100px;">
                    <div class="portal-card__title" style="font-size:16px;margin-bottom:16px;"><span class="portal-card__title-dot"></span> Stats</div>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:1px solid var(--surface-3);">
                            <span style="font-size:13px;color:var(--gray-1);">Events Attended</span>
                            <span style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--red);"><?php echo count($events); ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:1px solid var(--surface-3);">
                            <span style="font-size:13px;color:var(--gray-1);">Reviews Written</span>
                            <span style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--white);"><?php echo count($reviews); ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:13px;color:var(--gray-1);">Member Since</span>
                            <span style="font-size:13px;color:var(--white);"><?php echo esc_html( date('M Y', strtotime($member->joined_at)) ); ?></span>
                        </div>
                    </div>

                    <?php if ( $events ) : ?>
                    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--surface-3);">
                        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray-1);margin-bottom:10px;">Events</div>
                        <?php foreach ( array_slice($events, 0, 5) as $ev ) : ?>
                            <div style="font-size:12px;color:var(--gray-2);padding:4px 0;border-bottom:1px solid var(--surface-3);">
                                <a href="<?php echo esc_url(get_permalink($ev->event_post_id)); ?>" style="color:var(--gray-2);"><?php echo esc_html($ev->event_name); ?></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </aside>

        </div>
    </div>
</div>

<script>
(function(){
    var cache = {};
    function lookup(zip, el) {
        var clean = zip.replace(/[^0-9]/g,'').substring(0,5);
        if(clean.length < 5) return;
        if(cache[clean]){ el.textContent = '📍 ' + cache[clean]; return; }
        fetch('https://api.zippopotam.us/us/' + clean)
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){
                if(d && d.places && d.places[0]){
                    var label = d.places[0]['place name'] + ', ' + d.places[0]['state abbreviation'];
                    cache[clean] = label;
                    el.textContent = '📍 ' + label;
                }
            }).catch(function(){});
    }
    document.querySelectorAll('[data-zip-lookup]').forEach(function(el, i){
        setTimeout(function(){ lookup(el.getAttribute('data-zip-lookup'), el); }, i * 100);
    });
})();
</script>
<?php get_footer(); ?>
