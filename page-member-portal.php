<?php
/**
 * Template Name: Member Portal
 * page-member-portal.php
 *
 * Create a WP page with slug "member-portal" and assign this template.
 * All member account functionality lives here.
 */
get_header();

$is_logged_in = is_user_logged_in();
$member       = $is_logged_in ? blusiast_get_current_member() : null;
$current_user = $is_logged_in ? wp_get_current_user() : null;
$active_tab   = sanitize_key( $_GET['tab'] ?? ( $is_logged_in ? 'dashboard' : 'login' ) );
?>

<div class="portal-wrap">
    <div class="container">

        <?php if ( ! $is_logged_in ) : ?>
        <!-- ═══════════════════════════════════════
             GATE — Login / Register
        ═══════════════════════════════════════ -->
        <div class="portal-gate">
            <p class="bl-label" style="text-align:center;margin-bottom:8px;">Member Portal</p>
            <h1 class="bl-display-md" style="text-align:center;margin-bottom:32px;">
                <?php echo $active_tab === 'register' ? 'Join the Crew' : 'Welcome Back'; ?>
            </h1>

            <div class="portal-gate__tabs">
                <div class="portal-gate__tab <?php echo $active_tab !== 'register' ? 'active' : ''; ?>" data-tab="login">Sign In</div>
                <div class="portal-gate__tab <?php echo $active_tab === 'register' ? 'active' : ''; ?>" data-tab="register">Join Now</div>
            </div>

            <!-- LOGIN -->
            <div id="gate-login" class="portal-gate__pane" style="<?php echo $active_tab === 'register' ? 'display:none;' : ''; ?>">
                <form id="portal-login-form" class="portal-form">
                    <div class="portal-field">
                        <label class="portal-label" for="login-email">Email Address</label>
                        <input class="portal-input" type="email" id="login-email" name="email" autocomplete="email" required placeholder="you@email.com">
                    </div>
                    <div class="portal-field">
                        <label class="portal-label" for="login-password">Password</label>
                        <input class="portal-input" type="password" id="login-password" name="password" autocomplete="current-password" required placeholder="••••••••">
                    </div>
                    <div class="portal-msg"></div>
                    <button type="submit" class="bl-btn bl-btn--primary bl-btn--lg" style="width:100%;justify-content:center;margin-top:4px;">
                        Sign In <?php blusiast_icon('arrow-right'); ?>
                    </button>
                    <p style="text-align:center;font-size:13px;color:var(--gray-1);margin-top:8px;">
                        <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" style="color:var(--red);">Forgot your password?</a>
                    </p>
                </form>
            </div>

            <!-- REGISTER -->
            <div id="gate-register" class="portal-gate__pane" style="<?php echo $active_tab !== 'register' ? 'display:none;' : ''; ?>">
                <form id="portal-register-form" class="portal-form">
                    <div class="portal-form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="portal-field">
                            <label class="portal-label" for="reg-first">First Name <span style="color:var(--red);">*</span></label>
                            <input class="portal-input" type="text" id="reg-first" name="first_name" autocomplete="given-name" required>
                        </div>
                        <div class="portal-field">
                            <label class="portal-label" for="reg-last">Last Name <span style="color:var(--red);">*</span></label>
                            <input class="portal-input" type="text" id="reg-last" name="last_name" autocomplete="family-name" required>
                        </div>
                    </div>
                    <div class="portal-field">
                        <label class="portal-label" for="reg-email">Email Address <span style="color:var(--red);">*</span></label>
                        <input class="portal-input" type="email" id="reg-email" name="email" autocomplete="email" required>
                    </div>
                    <div class="portal-form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="portal-field">
                            <label class="portal-label" for="reg-phone">Phone <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                            <input class="portal-input" type="tel" id="reg-phone" name="phone" autocomplete="tel">
                        </div>
                        <div class="portal-field">
                            <label class="portal-label" for="reg-zip">Zip Code <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                            <input class="portal-input" type="text" id="reg-zip" name="zip" autocomplete="postal-code" maxlength="10">
                        </div>
                    </div>
                    <div class="portal-field">
                        <label class="portal-label" for="reg-password">Password <span style="color:var(--red);">*</span></label>
                        <input class="portal-input" type="password" id="reg-password" name="password" autocomplete="new-password" required minlength="8" placeholder="Min 8 characters">
                    </div>
                    <div class="portal-field">
                        <label class="portal-label" for="reg-confirm">Confirm Password <span style="color:var(--red);">*</span></label>
                        <input class="portal-input" type="password" id="reg-confirm" name="confirm_password" autocomplete="new-password" required>
                    </div>
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                        <input type="checkbox" name="consent" value="1" required style="margin-top:3px;accent-color:var(--red);flex-shrink:0;">
                        <span style="font-size:13px;color:var(--gray-2);line-height:1.5;">
                            I agree to receive communications from Blusiast. We never spam — just event updates and crew news.
                        </span>
                    </label>
                    <div class="portal-msg"></div>
                    <button type="submit" class="bl-btn bl-btn--primary bl-btn--lg" style="width:100%;justify-content:center;margin-top:4px;">
                        Create My Account <?php blusiast_icon('arrow-right'); ?>
                    </button>
                    <p style="text-align:center;font-size:12px;color:var(--gray-1);margin-top:8px;">
                        Already have an account? <span style="color:var(--red);cursor:pointer;" onclick="document.querySelector('[data-tab=login]').click()">Sign in →</span>
                    </p>
                </form>
            </div>
        </div>

        <?php else : ?>
        <!-- ═══════════════════════════════════════
             PORTAL — Logged in member view
        ═══════════════════════════════════════ -->
        <?php
        // Fetch member data
        global $wpdb;
        $rtable = $wpdb->prefix . 'bl_event_registrations';
        $mtable = $wpdb->prefix . 'bl_members';

        // Member's event registrations
        $registrations = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, p.post_title as event_name, p.ID as event_post_id
             FROM $rtable r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             WHERE r.email = %s
             ORDER BY r.created_at DESC",
            $current_user->user_email
        ) );

        // Upcoming vs past
        $today    = date( 'Y-m-d' );
        $upcoming = [];
        $past     = [];
        foreach ( $registrations as $reg ) {
            $event_date = function_exists('get_field') ? get_field( 'event_date', $reg->event_post_id ) : '';
            $reg->event_date_raw = $event_date;
            if ( $event_date && $event_date >= $today ) {
                $upcoming[] = $reg;
            } else {
                $past[] = $reg;
            }
        }

        // Member directory (non-hidden)
        $dir_members = $wpdb->get_results(
            "SELECT first_name, last_name, handle, dir_name_pref, home_park, fave_coaster, avatar_url, instagram
             FROM $mtable
             WHERE hide_from_dir = 0 AND account_status != 'banned'
             ORDER BY first_name ASC"
        );

        $initials = strtoupper(
            substr( $member ? $member->first_name : $current_user->first_name, 0, 1 ) .
            substr( $member ? $member->last_name  : $current_user->last_name,  0, 1 )
        );
        ?>

        <div class="portal-body">

            <!-- ── SIDEBAR ── -->
            <aside class="portal-sidebar">
                <div class="portal-sidebar__member">
                    <div style="position:relative;display:inline-block;">
                        <div class="portal-avatar" id="sidebar-avatar">
                            <?php if ( $member && $member->avatar_url ) : ?>
                                <img src="<?php echo esc_url( $member->avatar_url ); ?>" alt="" id="avatar-preview-img">
                            <?php else : ?>
                                <span id="avatar-initials"><?php echo esc_html( $initials ?: '?' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <label for="avatar-file-input" title="Change photo" style="position:absolute;bottom:0;right:0;width:24px;height:24px;background:var(--red);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid var(--surface-1);">
                            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M11 2l3 3-9 9H2v-3L11 2z" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        </label>
                        <input type="file" id="avatar-file-input" accept="image/*" style="display:none;">
                    </div>
                    <div class="portal-sidebar__name">
                        <?php echo esc_html( ( $member ? $member->first_name : $current_user->first_name ) . ' ' . ( $member ? $member->last_name : $current_user->last_name ) ); ?>
                    </div>
                    <?php if ( $member && ! empty( $member->handle ) ) : ?>
                    <div style="font-size:12px;color:var(--gray-1);">@<?php echo esc_html( $member->handle ); ?></div>
                    <?php endif; ?>
                    <div class="portal-sidebar__email"><?php echo esc_html( $current_user->user_email ); ?></div>
                    <?php if ( $member && ! empty( $member->zip ) ) : ?>
                    <div style="font-size:11px;color:var(--gray-1);" data-zip-lookup="<?php echo esc_attr($member->zip); ?>">📍 <?php echo esc_html($member->zip); ?></div>
                    <?php endif; ?>
                    <?php if ( $member ) : ?>
                    <div class="portal-sidebar__status portal-sidebar__status--<?php echo esc_attr( $member->account_status ); ?>">
                        <?php echo esc_html( ucfirst( $member->account_status ) ); ?> Member
                    </div>
                    <?php endif; ?>
                </div>
                <nav class="portal-nav">
                    <a class="portal-nav__item <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" data-panel="dashboard" href="?tab=dashboard">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><rect x="1" y="1" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="1" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="1" y="9" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
                        Dashboard
                    </a>
                    <a class="portal-nav__item <?php echo $active_tab === 'events' ? 'active' : ''; ?>" data-panel="events" href="?tab=events">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M1 7h14M5 1v4M11 1v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        My Events
                    </a>
                    <a class="portal-nav__item <?php echo $active_tab === 'directory' ? 'active' : ''; ?>" data-panel="directory" href="?tab=directory">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><circle cx="6" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M1 14c0-2.761 2.239-5 5-5s5 2.239 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="13" cy="5" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M13 9c1.657 0 3 1.567 3 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        Member Directory
                    </a>
                    <a class="portal-nav__item <?php echo $active_tab === 'photos' ? 'active' : ''; ?>" data-panel="photos" href="?tab=photos">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="11" rx="2" stroke="currentColor" stroke-width="1.5"/><circle cx="8" cy="8.5" r="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 3l1-2h4l1 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Submit Photo
                    </a>
                    <a class="portal-nav__item <?php echo $active_tab === 'account' ? 'active' : ''; ?>" data-panel="account" href="?tab=account">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2 14c0-3.314 2.686-6 6-6s6 2.686 6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        My Account
                    </a>
                    <a class="portal-nav__item <?php echo $active_tab === 'reviews' ? 'active' : ''; ?>" data-panel="reviews" href="?tab=reviews">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><path d="M8 2l1.5 3 3.5.5-2.5 2.5.5 3.5L8 10l-3 1.5.5-3.5L3 5.5l3.5-.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        Coaster Reviews
                    </a>
                    <a class="portal-nav__item <?php echo $active_tab === 'help' ? 'active' : ''; ?>" data-panel="help" href="?tab=help">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6 6.5C6 5.672 6.895 5 8 5s2 .672 2 1.5c0 .623-.448 1.162-1.1 1.39L8 8.1V9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="11.5" r=".75" fill="currentColor"/></svg>
                        Help & Contact
                    </a>
                    <a class="portal-nav__item" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" style="margin-top:8px;border-top:1px solid var(--surface-3);color:var(--gray-1);">
                        <svg class="portal-nav__icon" viewBox="0 0 16 16" fill="none"><path d="M6 2H3a1 1 0 00-1 1v10a1 1 0 001 1h3M10 11l3-3-3-3M13 8H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Log Out
                    </a>
                </nav>
            </aside>

            <!-- ── MAIN PANELS ── -->
            <div class="portal-main">

                <!-- DASHBOARD -->
                <div id="panel-dashboard" class="portal-panel <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">
                    <div class="portal-stats">
                        <div class="portal-stat">
                            <div class="portal-stat__num"><?php echo count( $registrations ); ?></div>
                            <div class="portal-stat__label">Events Joined</div>
                        </div>
                        <div class="portal-stat">
                            <div class="portal-stat__num"><?php echo count( $upcoming ); ?></div>
                            <div class="portal-stat__label">Upcoming</div>
                        </div>
                        <div class="portal-stat">
                            <div class="portal-stat__num"><?php echo count( $past ); ?></div>
                            <div class="portal-stat__label">Parks Visited</div>
                        </div>
                    </div>

                    <?php if ( $upcoming ) : ?>
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Upcoming Events</div>
                        <div class="portal-event-list">
                            <?php foreach ( array_slice( $upcoming, 0, 3 ) as $reg ) :
                                $fmt = blusiast_format_event_date( $reg->event_date_raw );
                                $loc = function_exists('get_field') ? get_field( 'event_location', $reg->event_post_id ) : '';
                            ?>
                            <div class="portal-event-item">
                                <div class="portal-event-item__date">
                                    <span class="portal-event-item__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                                    <span class="portal-event-item__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                                </div>
                                <div class="portal-event-item__info">
                                    <div class="portal-event-item__name"><a href="<?php echo esc_url( get_permalink( $reg->event_post_id ) ); ?>" style="color:inherit;"><?php echo esc_html( $reg->event_name ); ?></a></div>
                                    <?php if ( $loc ) : ?><div class="portal-event-item__meta"><?php echo esc_html( $loc ); ?></div><?php endif; ?>
                                </div>
                                <span class="portal-event-badge portal-event-badge--<?php echo esc_attr( $reg->status ); ?>"><?php echo esc_html( ucfirst( $reg->status ) ); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Quick Links</div>
                        <div style="display:flex;flex-wrap:wrap;gap:10px;">
                            <a href="<?php echo esc_url( get_post_type_archive_link('bl_event') ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">Browse Events <?php blusiast_icon('arrow-right'); ?></a>
                            <a href="?tab=photos" class="bl-btn bl-btn--ghost bl-btn--sm" data-panel="photos">Submit a Photo</a>
                            <a href="?tab=help" class="bl-btn bl-btn--ghost bl-btn--sm" data-panel="help">Contact Us</a>
                        </div>
                    </div>
                </div>


                <!-- MY EVENTS -->
                <div id="panel-events" class="portal-panel <?php echo $active_tab === 'events' ? 'active' : ''; ?>">
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Upcoming Events</div>
                        <?php if ( $upcoming ) : ?>
                        <div class="portal-event-list">
                            <?php foreach ( $upcoming as $reg ) :
                                $fmt = blusiast_format_event_date( $reg->event_date_raw );
                                $loc  = function_exists('get_field') ? get_field( 'event_location', $reg->event_post_id ) : '';
                                $time = function_exists('get_field') ? get_field( 'event_time',     $reg->event_post_id ) : '';
                            ?>
                            <div class="portal-event-item">
                                <div class="portal-event-item__date">
                                    <span class="portal-event-item__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                                    <span class="portal-event-item__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                                </div>
                                <div class="portal-event-item__info">
                                    <div class="portal-event-item__name"><a href="<?php echo esc_url( get_permalink( $reg->event_post_id ) ); ?>" style="color:inherit;"><?php echo esc_html( $reg->event_name ); ?></a></div>
                                    <div class="portal-event-item__meta">
                                        <?php if($loc)  echo esc_html($loc); ?>
                                        <?php if($loc && $time) echo ' · '; ?>
                                        <?php if($time) echo esc_html($time); ?>
                                        <?php if($reg->guest_count > 1) echo ' · ' . (int)$reg->guest_count . ' guests'; ?>
                                    </div>
                                </div>
                                <span class="portal-event-badge portal-event-badge--<?php echo esc_attr( $reg->status ); ?>"><?php echo esc_html( ucfirst( $reg->status ) ); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else : ?>
                            <p class="bl-body-md">No upcoming events. <a href="<?php echo esc_url( get_post_type_archive_link('bl_event') ); ?>" style="color:var(--red);">Browse events →</a></p>
                        <?php endif; ?>
                    </div>

                    <?php if ( $past ) : ?>
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Past Events</div>
                        <div class="portal-event-list">
                            <?php foreach ( $past as $reg ) :
                                $fmt = blusiast_format_event_date( $reg->event_date_raw );
                                $loc = function_exists('get_field') ? get_field( 'event_location', $reg->event_post_id ) : '';
                            ?>
                            <div class="portal-event-item" style="opacity:.6;">
                                <div class="portal-event-item__date" style="background:var(--surface-3);">
                                    <span class="portal-event-item__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                                    <span class="portal-event-item__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                                </div>
                                <div class="portal-event-item__info">
                                    <div class="portal-event-item__name"><?php echo esc_html( $reg->event_name ); ?></div>
                                    <?php if($loc) : ?><div class="portal-event-item__meta"><?php echo esc_html($loc); ?></div><?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>


                <!-- MEMBER DIRECTORY -->
                <div id="panel-directory" class="portal-panel <?php echo $active_tab === 'directory' ? 'active' : ''; ?>">
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Member Directory</div>
                        <p class="bl-body-sm" style="margin-bottom:20px;color:var(--gray-1);">
                            <?php echo count( $dir_members ); ?> members · You can hide yourself from this list in Account Settings.
                        </p>
                        <?php
                        // Re-fetch with wp_user_id for profile links
                        $dir_members_full = $wpdb->get_results(
                            "SELECT first_name, last_name, handle, dir_name_pref, home_park, fave_coaster, avatar_url, instagram, wp_user_id
                             FROM $mtable WHERE hide_from_dir = 0 AND account_status != 'banned' ORDER BY first_name ASC"
                        );
                        $profile_page = get_page_by_path('member-profile');
                        $profile_base = $profile_page ? get_permalink($profile_page->ID) : home_url('/member-profile/');
                        ?>
                        <?php if ( $dir_members_full ) : ?>
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <?php foreach ( $dir_members_full as $dm ) :
                                $use_handle  = ( ! empty( $dm->handle ) && ( $dm->dir_name_pref ?? 'real' ) === 'handle' );
                                $dir_display = $use_handle ? $dm->handle : $dm->first_name . ' ' . $dm->last_name;
                                $di          = $use_handle
                                    ? strtoupper( substr( $dm->handle, 0, 2 ) )
                                    : strtoupper( substr( $dm->first_name, 0, 1 ) . substr( $dm->last_name, 0, 1 ) );
                                $profile_url = $dm->wp_user_id ? add_query_arg('uid', $dm->wp_user_id, $profile_base) : '';
                            ?>
                            <a href="<?php echo esc_url($profile_url ?: '#'); ?>" style="display:flex;align-items:center;gap:14px;padding:12px 16px;background:var(--surface-2);border:1px solid var(--surface-3);border-radius:var(--radius-md);text-decoration:none;transition:border-color .15s,transform .15s;" onmouseover="this.style.borderColor='var(--red-dim)'" onmouseout="this.style.borderColor='var(--surface-3)'">
                                <div class="portal-member-card__avatar" style="width:44px;height:44px;font-size:16px;flex-shrink:0;">
                                    <?php if ( $dm->avatar_url ) : ?>
                                        <img src="<?php echo esc_url($dm->avatar_url); ?>" alt="">
                                    <?php else : ?>
                                        <?php echo esc_html($di); ?>
                                    <?php endif; ?>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-family:var(--font-display);font-size:16px;font-weight:700;text-transform:uppercase;color:var(--white);"><?php echo esc_html($dir_display); ?></div>
                                    <div style="font-size:12px;color:var(--gray-1);display:flex;gap:12px;flex-wrap:wrap;margin-top:2px;">
                                        <?php if ($dm->home_park) : ?><span>🏠 <?php echo esc_html($dm->home_park); ?></span><?php endif; ?>
                                        <?php if ($dm->fave_coaster) : ?><span>🎢 <?php echo esc_html($dm->fave_coaster); ?></span><?php endif; ?>
                                        <?php if ($dm->instagram) : ?><span style="color:var(--red);">@<?php echo esc_html($dm->instagram); ?></span><?php endif; ?>
                                        <?php if ( ! empty($dm->zip) ) : ?><span data-zip-lookup="<?php echo esc_attr($dm->zip); ?>">📍 <?php echo esc_html($dm->zip); ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="color:var(--gray-1);flex-shrink:0;"><path d="M3 13L13 3M13 3H6M13 3v7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php else : ?>
                            <p class="bl-body-md">No members visible yet.</p>
                        <?php endif; ?>
                    </div>
                </div>


                <!-- SUBMIT PHOTO -->
                <div id="panel-photos" class="portal-panel <?php echo $active_tab === 'photos' ? 'active' : ''; ?>">
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Submit a Photo</div>
                        <p class="bl-body-sm" style="margin-bottom:24px;color:var(--gray-1);">
                            Share your best coaster or meetup shots with the crew. Photos are reviewed before being added to the gallery.
                        </p>
                        <form id="portal-photo-form" class="portal-form">
                            <div class="portal-upload-zone">
                                <input type="file" id="portal-photo-input" name="photo" accept="image/*" style="display:none;">
                                <div class="portal-upload-zone__icon">📷</div>
                                <div class="portal-upload-zone__label">Drop photo here or click to browse</div>
                                <div class="portal-upload-zone__hint">JPG, PNG — max 10MB</div>
                            </div>
                            <img id="portal-photo-preview" class="portal-upload-preview" src="" alt="Preview">
                            <div class="portal-field">
                                <label class="portal-label" for="portal-caption">Caption <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                                <input class="portal-input" type="text" id="portal-caption" name="caption" placeholder="e.g. Steel Vengeance at Cedar Point — June 2024">
                            </div>
                            <div class="portal-msg"></div>
                            <button type="submit" class="bl-btn bl-btn--primary" style="align-self:flex-start;">
                                Submit Photo <?php blusiast_icon('arrow-right'); ?>
                            </button>
                        </form>
                    </div>
                </div>


                <!-- MY ACCOUNT -->
                <div id="panel-account" class="portal-panel <?php echo $active_tab === 'account' ? 'active' : ''; ?>">

                    <!-- Profile -->
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Profile Information</div>
                        <form id="portal-profile-form" class="portal-form">
                            <div class="portal-form-row">
                                <div class="portal-field">
                                    <label class="portal-label" for="pf-first">First Name</label>
                                    <input class="portal-input" type="text" id="pf-first" name="first_name"
                                           value="<?php echo esc_attr( $member ? $member->first_name : $current_user->first_name ); ?>" required>
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label" for="pf-last">Last Name</label>
                                    <input class="portal-input" type="text" id="pf-last" name="last_name"
                                           value="<?php echo esc_attr( $member ? $member->last_name : $current_user->last_name ); ?>" required>
                                </div>
                            </div>
                            <div class="portal-form-row">
                                <div class="portal-field">
                                    <label class="portal-label" for="pf-phone">Phone</label>
                                    <input class="portal-input" type="tel" id="pf-phone" name="phone"
                                           value="<?php echo esc_attr( $member ? $member->phone : '' ); ?>">
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label" for="pf-zip">Zip Code</label>
                                    <input class="portal-input" type="text" id="pf-zip" name="zip" maxlength="10"
                                           value="<?php echo esc_attr( $member ? $member->zip : '' ); ?>">
                                </div>
                            </div>
                            <div class="portal-form-row">
                                <div class="portal-field">
                                    <label class="portal-label" for="pf-homepark">Home Park</label>
                                    <input class="portal-input" type="text" id="pf-homepark" name="home_park"
                                           placeholder="e.g. Cedar Point"
                                           value="<?php echo esc_attr( $member ? $member->home_park : '' ); ?>">
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label" for="pf-coaster">Favorite Coaster</label>
                                    <input class="portal-input" type="text" id="pf-coaster" name="fave_coaster"
                                           placeholder="e.g. Steel Vengeance"
                                           value="<?php echo esc_attr( $member ? $member->fave_coaster : '' ); ?>">
                                </div>
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="pf-instagram">Instagram Handle <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                                <input class="portal-input" type="text" id="pf-instagram" name="instagram"
                                       placeholder="yourhandle"
                                       value="<?php echo esc_attr( $member ? $member->instagram : '' ); ?>">
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="pf-handle">Community Handle <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional — shown instead of your name)</span></label>
                                <input class="portal-input" type="text" id="pf-handle" name="handle"
                                       placeholder="e.g. CoasterKing"
                                       value="<?php echo esc_attr( $member && isset( $member->handle ) ? $member->handle : '' ); ?>">
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="pf-bio">Bio <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                                <textarea class="portal-input portal-textarea" id="pf-bio" name="bio"
                                          placeholder="Tell the crew a little about yourself..."><?php echo esc_textarea( $member ? $member->bio : '' ); ?></textarea>
                            </div>
                            <!-- Directory privacy -->
                            <div style="background:var(--surface-2);border:1px solid var(--surface-4);border-radius:var(--radius-md);padding:16px;">
                                <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray-2);margin-bottom:12px;">Directory Privacy</div>

                                <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px;">
                                    <?php
                                    $pref = $member && isset( $member->dir_name_pref ) ? $member->dir_name_pref : 'real';
                                    ?>
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                        <input type="radio" name="dir_name_pref" value="real"
                                               <?php checked( $pref, 'real' ); ?>
                                               style="accent-color:var(--red);">
                                        <span style="font-size:13px;color:var(--gray-2);">
                                            <strong style="color:var(--white);">Show my real name</strong>
                                            — <?php echo esc_html( ( $member ? $member->first_name : $current_user->first_name ) . ' ' . ( $member ? $member->last_name : $current_user->last_name ) ); ?>
                                        </span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;" id="pf-handle-option">
                                        <input type="radio" name="dir_name_pref" value="handle"
                                               <?php checked( $pref, 'handle' ); ?>
                                               style="accent-color:var(--red);">
                                        <span style="font-size:13px;color:var(--gray-2);">
                                            <strong style="color:var(--white);">Show my handle only</strong>
                                            — requires a handle set above
                                        </span>
                                    </label>
                                </div>

                                <label class="portal-toggle" style="background:transparent;border:none;padding:0;">
                                    <input type="checkbox" name="hide_from_dir" value="1"
                                           <?php checked( $member && $member->hide_from_dir, 1 ); ?>
                                           style="accent-color:var(--red);">
                                    <div class="portal-toggle-text">
                                        <strong>Hide me from the directory entirely</strong>
                                        Other members won't see you at all.
                                    </div>
                                </label>
                            </div>
                            <div class="portal-msg"></div>
                            <button type="submit" class="bl-btn bl-btn--primary" style="align-self:flex-start;">
                                Save Profile <?php blusiast_icon('arrow-right'); ?>
                            </button>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Change Password</div>
                        <form id="portal-password-form" class="portal-form" style="max-width:400px;">
                            <div class="portal-field">
                                <label class="portal-label" for="pw-current">Current Password</label>
                                <input class="portal-input" type="password" id="pw-current" name="current_password" required>
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="pw-new">New Password</label>
                                <input class="portal-input" type="password" id="pw-new" name="new_password" required minlength="8" placeholder="Min 8 characters">
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="pw-confirm">Confirm New Password</label>
                                <input class="portal-input" type="password" id="pw-confirm" name="confirm_password" required>
                            </div>
                            <div class="portal-msg"></div>
                            <button type="submit" class="bl-btn bl-btn--ghost" style="align-self:flex-start;">
                                Update Password
                            </button>
                        </form>
                    </div>
                </div>


                <!-- COASTER REVIEWS -->
                <div id="panel-reviews" class="portal-panel <?php echo $active_tab === 'reviews' ? 'active' : ''; ?>">
                    <?php
                    global $wpdb;
                    $revtable = $wpdb->prefix . 'bl_coaster_reviews';
                    $aggtable = $wpdb->prefix . 'bl_coasters_agg';
                    $my_reviews = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM $revtable WHERE wp_user_id = %d ORDER BY created_at DESC",
                        get_current_user_id()
                    ) );
                    $all_reviews = $wpdb->get_results(
                        "SELECT r.*, m.first_name, m.last_name, m.handle, m.dir_name_pref
                         FROM $revtable r LEFT JOIN {$wpdb->prefix}bl_members m ON m.id = r.member_id
                         ORDER BY r.created_at DESC LIMIT 50"
                    );
                    $by_coaster = $wpdb->get_results( "SELECT * FROM $aggtable ORDER BY avg_rating DESC, review_count DESC" );
                    $thrill_opts = [ 'mild' => '🟢 Mild', 'moderate' => '🟡 Moderate', 'intense' => '🟠 Intense', 'extreme' => '🔴 Extreme' ];
                    ?>

                    <!-- Submit review form -->
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Write a Review</div>
                        <form id="portal-review-form" class="portal-form">
                            <div class="portal-form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="portal-field">
                                    <label class="portal-label">Coaster Name <span style="color:var(--red);">*</span></label>
                                    <input class="portal-input" type="text" name="coaster_name" required placeholder="e.g. Steel Vengeance">
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label">Park <span style="color:var(--red);">*</span></label>
                                    <input class="portal-input" type="text" name="park_name" required placeholder="e.g. Cedar Point">
                                </div>
                            </div>
                            <div class="portal-form-row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                                <div class="portal-field">
                                    <label class="portal-label">Rating (1–10) <span style="color:var(--red);">*</span></label>
                                    <input class="portal-input" type="number" name="rating" min="1" max="10" value="8" required>
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label">Thrill Level</label>
                                    <select class="portal-input" name="thrill_level">
                                        <option value="">Select…</option>
                                        <?php foreach ($thrill_opts as $val => $label) : ?>
                                            <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label">Type</label>
                                    <select class="portal-input" name="coaster_type">
                                        <option value="">Select…</option>
                                        <option value="Steel">Steel</option>
                                        <option value="Wood">Wood</option>
                                        <option value="Hybrid">Hybrid</option>
                                        <option value="Launched">Launched</option>
                                        <option value="Inverted">Inverted</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="portal-field">
                                <label class="portal-label">Date Ridden</label>
                                <input class="portal-input" type="date" name="ride_date">
                            </div>
                            <div class="portal-field">
                                <label class="portal-label">Your Review <span style="color:var(--red);">*</span></label>
                                <textarea class="portal-input portal-textarea" name="review_text" required rows="5" placeholder="Tell the crew what you thought…"></textarea>
                            </div>
                            <div class="portal-msg"></div>
                            <button type="submit" class="bl-btn bl-btn--primary" style="align-self:flex-start;">
                                Submit Review <?php blusiast_icon('arrow-right'); ?>
                            </button>
                        </form>
                    </div>

                    <!-- Review browser tabs -->
                    <div class="portal-card">
                        <div style="display:flex;gap:0;border-bottom:1px solid var(--surface-3);margin-bottom:20px;">
                            <?php foreach (['all' => 'All Reviews', 'mine' => 'My Reviews', 'bycoaster' => 'By Coaster'] as $rtab => $rlabel) : ?>
                            <button class="review-tab <?php echo $rtab === 'all' ? 'active' : ''; ?>" data-rtab="<?php echo $rtab; ?>"
                                    style="padding:10px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--gray-1);font-family:var(--font-display);font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;margin-bottom:-1px;transition:color .15s,border-color .15s;">
                                <?php echo $rlabel; ?>
                            </button>
                            <?php endforeach; ?>
                            <div style="flex:1;"></div>
                            <input type="search" id="review-search" class="portal-input" placeholder="Search coaster or park…" style="max-width:200px;padding:6px 12px;font-size:13px;">
                        </div>

                        <!-- All reviews -->
                        <div id="rtab-all" class="rtab-pane" style="display:block;">
                            <?php if ($all_reviews) : ?>
                            <div style="display:flex;flex-direction:column;gap:12px;" id="all-reviews-list">
                                <?php foreach ($all_reviews as $rv) :
                                    $ruse_h = !empty($rv->handle) && ($rv->dir_name_pref??'real')==='handle';
                                    $rauthor = $ruse_h ? '@'.$rv->handle : ($rv->first_name ? $rv->first_name.' '.substr($rv->last_name,0,1).'.' : 'Member');
                                    $tmap = ['mild'=>'🟢','moderate'=>'🟡','intense'=>'🟠','extreme'=>'🔴'];
                                ?>
                                <div class="review-row" data-coaster="<?php echo esc_attr(strtolower($rv->coaster_name)); ?>" data-park="<?php echo esc_attr(strtolower($rv->park_name)); ?>">
                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
                                        <div>
                                            <span style="font-family:var(--font-display);font-size:16px;font-weight:700;text-transform:uppercase;color:var(--white);"><?php echo esc_html($rv->coaster_name); ?></span>
                                            <span style="font-size:12px;color:var(--gray-1);margin-left:8px;"><?php echo esc_html($rv->park_name); ?></span>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <span style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--red);"><?php echo (int)$rv->rating; ?><small style="font-size:11px;color:var(--gray-1);">/10</small></span>
                                            <?php if($rv->thrill_level): ?><span style="font-size:11px;color:var(--gray-2);"><?php echo ($tmap[$rv->thrill_level]??'').' '.ucfirst($rv->thrill_level); ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <p style="font-size:13px;color:var(--gray-2);line-height:1.6;margin:8px 0 4px;"><?php echo esc_html($rv->review_text); ?></p>
                                    <div style="font-size:11px;color:var(--gray-1);">— <?php echo esc_html($rauthor); ?> · <?php echo esc_html(date('M j, Y', strtotime($rv->created_at))); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else : ?><p class="bl-body-md" style="color:var(--gray-1);">No reviews yet. Be the first!</p><?php endif; ?>
                        </div>

                        <!-- My reviews -->
                        <div id="rtab-mine" class="rtab-pane" style="display:none;">
                            <?php if ($my_reviews) : ?>
                            <div style="display:flex;flex-direction:column;gap:12px;">
                                <?php foreach ($my_reviews as $rv) : $tmap = ['mild'=>'🟢','moderate'=>'🟡','intense'=>'🟠','extreme'=>'🔴']; ?>
                                <div style="padding:14px;background:var(--surface-2);border:1px solid var(--surface-3);border-radius:var(--radius-md);">
                                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                        <div>
                                            <span style="font-family:var(--font-display);font-size:16px;font-weight:700;text-transform:uppercase;color:var(--white);"><?php echo esc_html($rv->coaster_name); ?></span>
                                            <span style="font-size:12px;color:var(--gray-1);margin-left:8px;"><?php echo esc_html($rv->park_name); ?></span>
                                        </div>
                                        <span style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--red);"><?php echo (int)$rv->rating; ?>/10</span>
                                    </div>
                                    <p style="font-size:13px;color:var(--gray-2);margin-top:8px;"><?php echo esc_html($rv->review_text); ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else : ?><p class="bl-body-md" style="color:var(--gray-1);">You haven't written any reviews yet.</p><?php endif; ?>
                        </div>

                        <!-- By coaster aggregate -->
                        <div id="rtab-bycoaster" class="rtab-pane" style="display:none;">
                            <?php if ($by_coaster) : ?>
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <?php foreach ($by_coaster as $ca) : ?>
                                <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--surface-2);border:1px solid var(--surface-3);border-radius:var(--radius-md);">
                                    <div style="flex:1;">
                                        <div style="font-family:var(--font-display);font-size:15px;font-weight:700;text-transform:uppercase;color:var(--white);"><?php echo esc_html($ca->coaster_name); ?></div>
                                        <div style="font-size:11px;color:var(--gray-1);"><?php echo esc_html($ca->park_name); ?> · <?php echo (int)$ca->review_count; ?> review<?php echo $ca->review_count != 1 ? 's' : ''; ?></div>
                                    </div>
                                    <div style="font-family:var(--font-display);font-size:24px;font-weight:800;color:var(--red);"><?php echo number_format($ca->avg_rating,1); ?><small style="font-size:12px;color:var(--gray-1);">/10</small></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else : ?><p class="bl-body-md" style="color:var(--gray-1);">No coaster data yet.</p><?php endif; ?>
                        </div>
                    </div>
                </div>


                <!-- HELP & CONTACT -->
                <div id="panel-help" class="portal-panel <?php echo $active_tab === 'help' ? 'active' : ''; ?>">
                    <div class="portal-card">
                        <div class="portal-card__title"><span class="portal-card__title-dot"></span> Contact the Help Team</div>
                        <p class="bl-body-sm" style="margin-bottom:24px;color:var(--gray-1);">
                            Have a question, issue, or feedback? Send us a message and our team will get back to you.
                        </p>
                        <form id="portal-help-form" class="portal-form" style="max-width:500px;">
                            <div class="portal-field">
                                <label class="portal-label" for="help-subject">Subject</label>
                                <input class="portal-input" type="text" id="help-subject" name="subject" required placeholder="What's this about?">
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="help-message">Message</label>
                                <textarea class="portal-input portal-textarea" id="help-message" name="message" required rows="6" placeholder="Tell us what's going on..."></textarea>
                            </div>
                            <div class="portal-msg"></div>
                            <button type="submit" class="bl-btn bl-btn--primary" style="align-self:flex-start;">
                                Send Message <?php blusiast_icon('arrow-right'); ?>
                            </button>
                        </form>
                    </div>

                    <div class="portal-card" style="background:var(--surface-2);">
                        <div class="portal-card__title" style="font-size:16px;"><span class="portal-card__title-dot"></span> Other ways to reach us</div>
                        <div style="display:flex;flex-direction:column;gap:8px;font-size:14px;color:var(--gray-2);">
                            <div>📧 Email: <a href="mailto:<?php echo esc_attr( get_option('admin_email') ); ?>" style="color:var(--red);"><?php echo esc_html( get_option('admin_email') ); ?></a></div>
                            <div>📱 Follow us on social for quick updates</div>
                        </div>
                    </div>
                </div>

            </div><!-- /.portal-main -->
        </div><!-- /.portal-body -->
        <?php endif; ?>

    </div><!-- /.container -->
</div><!-- /.portal-wrap -->

<?php get_footer(); ?>
