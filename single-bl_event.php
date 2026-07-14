<?php
/**
 * single-bl_event.php — Single Event Page
 */
get_header();
the_post();

$event_id     = get_the_ID();
$date         = function_exists('get_field') ? get_field('event_date',         $event_id) : '';
$end_date     = function_exists('get_field') ? get_field('event_end_date',     $event_id) : '';
$time         = function_exists('get_field') ? get_field('event_time',         $event_id) : '';
$location     = function_exists('get_field') ? get_field('event_location',     $event_id) : '';
$price_cents  = (int) get_post_meta( $event_id, 'ticket_price_cents',          true );
$ph_cents_top = (int) get_post_meta( $event_id, 'passholder_price_cents',      true );
$nph_cents_top= (int) get_post_meta( $event_id, 'nonpassholder_price_cents',   true );
$capacity     = function_exists('get_field') ? get_field('event_capacity',     $event_id) : '';
$members_only = function_exists('get_field') ? get_field('event_members_only', $event_id) : false;
$sold_out     = function_exists('get_field') ? get_field('event_sold_out',     $event_id) : false;
$fmt          = blusiast_format_event_date( $date );
$has_tiers    = $ph_cents_top > 0 && $nph_cents_top > 0;
$is_free      = $price_cents <= 0 && ! $has_tiers;

$already_registered = false;
$current_member     = null;

if ( is_user_logged_in() ) {
    $current_member = blusiast_get_current_member();
    if ( $current_member ) {
        global $wpdb;

        // If member hit back or cancelled from Stripe, clean up the orphaned pending row
        if ( isset( $_GET['bl_ticket'] ) && $_GET['bl_ticket'] === 'cancelled' ) {
            $wpdb->delete(
                $wpdb->prefix . 'bl_event_registrations',
                [ 'event_id' => $event_id, 'wp_user_id' => get_current_user_id(), 'status' => 'pending' ],
                [ '%d', '%d', '%s' ]
            );
        }

        $already_registered = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bl_event_registrations
             WHERE event_id = %d AND wp_user_id = %d AND status = 'confirmed'
             LIMIT 1",
            $event_id, get_current_user_id()
        ) );
    }
}

// ── DEV PREVIEW — remove before launch ──
// Visit any event page with ?bl_preview_registered=1 as a logged-in admin
// to preview the "already registered" UI without a real ticket.
if ( ! $already_registered && isset( $_GET['bl_preview_registered'] ) && current_user_can( 'manage_options' ) ) {
    $already_registered = true;
    if ( ! $current_member ) {
        $current_member = blusiast_get_current_member();
    }
}

$portal_url = function_exists('blusiast_portal_url') ? blusiast_portal_url() : home_url('/member-portal');
$buy_url    = rest_url('blusiast/v1/buy-ticket');
$nonce      = wp_create_nonce('wp_rest');
$btn_label  = $is_free ? 'Agree & Register Free' : 'Agree & Continue to Payment';
?>

<!-- ── PAGE HERO ── -->
<div class="page-hero" data-event-id="<?php echo esc_attr( $event_id ); ?>" data-event-title="<?php echo esc_attr( get_the_title() ); ?>">
    <div class="container">
        <p class="bl-label"><?php esc_html_e( 'Event', 'blusiast' ); ?></p>
        <h1 class="bl-display-lg" style="margin-bottom:20px;"><?php the_title(); ?></h1>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php if ( $members_only ) : ?>
                <span class="bl-badge bl-badge--red bl-badge--dot">Members Only</span>
            <?php else : ?>
                <span class="bl-badge bl-badge--white bl-badge--dot">Open to All</span>
            <?php endif; ?>
            <?php if ( $sold_out ) : ?>
                <span class="bl-badge bl-badge--white">Sold Out</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="page-content">
    <div class="container">

        <!-- ── EVENT INFO ── -->
        <div class="event-single-layout">
            <?php if ( has_post_thumbnail() ) : ?>
                <div class="event-single__image">
                    <?php the_post_thumbnail( 'blusiast-hero', [ 'style' => 'width:100%;border-radius:12px;', 'alt' => '' ] ); ?>
                </div>
            <?php endif; ?>

            <div class="event-single__meta-strip">
                <?php if ( ! empty( $fmt['full'] ) ) : ?>
                <div class="event-meta-fact">
                    <div class="event-card__date" style="min-width:52px;min-height:52px;">
                        <span class="event-card__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                        <span class="event-card__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                    </div>
                    <div>
                        <div class="event-meta-fact__label">Date</div>
                        <div class="event-meta-fact__value">
                            <?php echo esc_html( $fmt['full'] ); ?>
                            <?php if ( $end_date && $end_date !== $date ) :
                                $end = blusiast_format_event_date( $end_date );
                            ?>
                                <span style="color:var(--gray-1);"> – <?php echo esc_html( $end['full'] ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $time ) : ?>
                <div class="event-meta-fact">
                    <?php blusiast_icon( 'calendar', 'event-meta-fact__icon' ); ?>
                    <div>
                        <div class="event-meta-fact__label">Time</div>
                        <div class="event-meta-fact__value"><?php echo esc_html( $time ); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $location ) : ?>
                <div class="event-meta-fact">
                    <?php blusiast_icon( 'location', 'event-meta-fact__icon' ); ?>
                    <div>
                        <div class="event-meta-fact__label">Location</div>
                        <div class="event-meta-fact__value"><?php echo esc_html( $location ); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $has_tiers ) : ?>
                <div class="event-meta-fact">
                    <?php blusiast_icon( 'ticket', 'event-meta-fact__icon' ); ?>
                    <div>
                        <div class="event-meta-fact__label">Pricing</div>
                        <div class="event-meta-fact__value">
                            <div style="display:flex;justify-content:space-between;gap:24px;margin-bottom:4px;">
                                <span style="color:var(--gray-1);font-size:13px;">With season pass</span>
                                <span style="font-weight:700;color:var(--white);">$<?php echo number_format( $ph_cents_top / 100, 0 ); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;gap:24px;">
                                <span style="color:var(--gray-1);font-size:13px;">Without season pass</span>
                                <span style="font-weight:700;color:var(--white);">$<?php echo number_format( $nph_cents_top / 100, 0 ); ?></span>
                            </div>
                            <?php if ( $capacity ) : ?>
                            <div style="margin-top:8px;font-size:12px;color:var(--gray-1);"><?php echo esc_html( $capacity ); ?> spots total</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php elseif ( $price_cents > 0 ) : ?>
                <div class="event-meta-fact">
                    <div style="font-family:var(--font-display);font-size:28px;font-weight:800;color:var(--red);line-height:1;min-width:52px;text-align:center;">
                        $<?php echo number_format( $price_cents / 100, 0 ); ?>
                    </div>
                    <div>
                        <div class="event-meta-fact__label">Price</div>
                        <?php if ( $capacity ) : ?>
                            <div class="event-meta-fact__value" style="font-size:13px;color:var(--gray-1);">
                                <?php echo esc_html( $capacity ); ?> spots total
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( get_the_content() ) : ?>
            <div class="event-single__body">
                <div class="entry-content"><?php the_content(); ?></div>
            </div>
            <?php endif; ?>
        </div>


        <!-- ══════════════════════════════════════
             TWO-COLUMN: TICKET LEFT, CONDUCT RIGHT
             ══════════════════════════════════════ -->
        <div class="event-signup-section">
        <div class="bl-event-grid">

            <!-- ── LEFT: ticket states ── -->
            <div class="bl-ticket-col">

                <?php if ( $sold_out ) : ?>
                <div class="event-signup-box" style="text-align:center;padding:48px 32px;">
                    <div style="font-size:48px;margin-bottom:16px;">🎟️</div>
                    <h2 class="bl-display-sm" style="color:var(--gray-1);margin-bottom:8px;">This event is sold out.</h2>
                    <p class="bl-body-md">Keep an eye on our social channels for future events.</p>
                </div>

                <?php elseif ( ! is_user_logged_in() ) : ?>
                <div class="event-signup-box">
                    <div class="event-signup-box__header">
                        <p class="bl-label">Members Only Event</p>
                        <h2 class="bl-display-md">Get Your Ticket</h2>
                        <p class="bl-body-md" style="margin-top:12px;color:var(--gray-1);">
                            Tickets are available to Blusiast members. Already a member? Sign in. Not yet? Join free.
                        </p>
                    </div>
                    <div class="portal-gate__tabs" style="margin-bottom:24px;">
                        <div class="portal-gate__tab active" data-tab="login">Sign In</div>
                        <div class="portal-gate__tab" data-tab="register">Not a Member? Join Free</div>
                    </div>
                    <div id="gate-login" class="portal-gate__pane">
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
                            <p style="text-align:center;font-size:13px;color:var(--gray-1);margin-top:10px;">
                                <a href="<?php echo esc_url( wp_lostpassword_url( get_permalink() ) ); ?>" style="color:var(--red);">Forgot your password?</a>
                            </p>
                        </form>
                    </div>
                    <div id="gate-register" class="portal-gate__pane" style="display:none;">
                        <form id="portal-register-form" class="portal-form">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="portal-field">
                                    <label class="portal-label" for="reg-first">First Name *</label>
                                    <input class="portal-input" type="text" id="reg-first" name="first_name" autocomplete="given-name" required>
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label" for="reg-last">Last Name *</label>
                                    <input class="portal-input" type="text" id="reg-last" name="last_name" autocomplete="family-name" required>
                                </div>
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="reg-email">Email Address *</label>
                                <input class="portal-input" type="email" id="reg-email" name="email" autocomplete="email" required>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="portal-field">
                                    <label class="portal-label" for="reg-phone">Phone (optional)</label>
                                    <input class="portal-input" type="tel" id="reg-phone" name="phone" autocomplete="tel">
                                </div>
                                <div class="portal-field">
                                    <label class="portal-label" for="reg-zip">Zip Code (optional)</label>
                                    <input class="portal-input" type="text" id="reg-zip" name="zip" autocomplete="postal-code" maxlength="10">
                                </div>
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="reg-password">Password *</label>
                                <input class="portal-input" type="password" id="reg-password" name="password" autocomplete="new-password" required minlength="8" placeholder="Min 8 characters">
                            </div>
                            <div class="portal-field">
                                <label class="portal-label" for="reg-confirm">Confirm Password *</label>
                                <input class="portal-input" type="password" id="reg-confirm" name="confirm_password" autocomplete="new-password" required>
                            </div>
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                                <input type="checkbox" name="consent" value="1" required style="margin-top:3px;accent-color:var(--red);flex-shrink:0;">
                                <span style="font-size:13px;color:var(--gray-2);line-height:1.5;">
                                    I agree to receive communications from Blusiast. We never spam.
                                </span>
                            </label>
                            <div class="portal-msg"></div>
                            <button type="submit" class="bl-btn bl-btn--primary bl-btn--lg" style="width:100%;justify-content:center;margin-top:4px;">
                                Create My Account <?php blusiast_icon('arrow-right'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <?php elseif ( $already_registered ) : ?>
                <?php
                    // Get guest count for this registration
                    $reg_row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT guest_count FROM {$wpdb->prefix}bl_event_registrations
                         WHERE event_id = %d AND wp_user_id = %d AND status = 'confirmed'
                         LIMIT 1",
                        $event_id, get_current_user_id()
                    ) );
                    $current_guest_count = $reg_row ? (int) $reg_row->guest_count : 1;

                    // Tiered pricing for add-tickets flow
                    $ph_cents_reg  = (int) get_post_meta( $event_id, 'passholder_price_cents',    true );
                    $nph_cents_reg = (int) get_post_meta( $event_id, 'nonpassholder_price_cents',  true );
                    $has_tiers_reg = $ph_cents_reg > 0 && $nph_cents_reg > 0;
                    if ( ! $has_tiers_reg ) {
                        $ph_cents_reg  = $price_cents;
                        $nph_cents_reg = $price_cents;
                    }
                    $fmt_ph_reg  = $ph_cents_reg  ? '$' . number_format( $ph_cents_reg  / 100, 0 ) : 'Free';
                    $fmt_nph_reg = $nph_cents_reg ? '$' . number_format( $nph_cents_reg / 100, 0 ) : 'Free';
                ?>
                <div class="event-signup-box" style="padding:32px;">

                    <!-- Confirmed header -->
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--surface-3);">
                        <div style="flex-shrink:0;">
                            <svg width="48" height="48" viewBox="0 0 56 56" fill="none">
                                <circle cx="28" cy="28" r="27" stroke="var(--red)" stroke-width="1.5"/>
                                <path d="M16 28.5l9 9L40 20" stroke="var(--red)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div style="text-align:left;">
                            <p class="bl-label" style="margin-bottom:4px;">You're on the list</p>
                            <h2 class="bl-display-sm" style="margin-bottom:4px;">You're Registered!</h2>
                            <p style="font-size:13px;color:var(--gray-1);margin:0;">Your ticket is confirmed. Bring your member QR code to the door.</p>
                        </div>
                    </div>

                    <?php if ( $current_member ) :
                        $member_num = blusiast_get_member_number( $current_member->id );
                    ?>
                    <div style="background:var(--surface-2);border:1px solid var(--surface-3);border-radius:10px;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
                        <div>
                            <div style="font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:var(--gray-1);margin-bottom:4px;">Your Member Number</div>
                            <div style="font-family:'Courier New',monospace;font-size:20px;font-weight:700;color:var(--white);letter-spacing:.1em;"><?php echo esc_html( $member_num ); ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:var(--gray-1);margin-bottom:4px;">Tickets on Account</div>
                            <div style="font-size:20px;font-weight:800;color:var(--white);">
                                <?php echo $current_guest_count; ?> ticket<?php echo $current_guest_count !== 1 ? 's' : ''; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <a href="<?php echo esc_url( $portal_url ); ?>" class="bl-btn bl-btn--outline" style="width:100%;justify-content:center;margin-bottom:28px;">
                        View My Member ID Card <?php blusiast_icon('arrow-right'); ?>
                    </a>

                    <!-- Bring someone along -->
                    <div style="margin-bottom:20px;">
                        <p style="font-size:17px;font-weight:800;color:var(--white);line-height:1.3;margin-bottom:6px;">
                            Bringing someone along? The more, the merrier! 🙌
                        </p>
                        <p style="font-size:14px;color:var(--gray-1);line-height:1.6;margin:0;">
                            We love seeing the crew come through. Choose how you want to make it happen:
                        </p>
                    </div>

                    <!-- Option 1: Add tickets -->
                    <div class="bl-already-option" id="bl-opt-add" style="background:var(--surface-2);border:1px solid var(--surface-3);border-radius:12px;margin-bottom:12px;overflow:hidden;">
                        <div style="display:flex;align-items:flex-start;gap:14px;padding:20px;cursor:pointer;" onclick="blToggleOption('add')">
                            <div style="font-size:22px;flex-shrink:0;margin-top:2px;">🎟️</div>
                            <div style="flex:1;">
                                <div style="font-weight:800;font-size:15px;color:var(--white);margin-bottom:3px;">Add tickets to my account</div>
                                <div style="font-size:13px;color:var(--gray-1);line-height:1.5;">Pick up extra tickets and handle the whole crew in one shot. Everyone checks in under your QR code.</div>
                            </div>
                            <div id="bl-opt-add-chevron" style="color:var(--gray-1);font-size:18px;flex-shrink:0;transition:transform .2s;">▾</div>
                        </div>
                        <div id="bl-opt-add-body" style="display:none;padding:0 20px 20px;">
                            <?php if ( $has_tiers_reg ) : ?>
                            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;">
                                <!-- Pass Holder tier -->
                                <div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface-3);border-radius:8px;padding:12px 16px;gap:12px;flex-wrap:wrap;">
                                    <div>
                                        <div style="font-weight:700;font-size:14px;color:var(--white);">Pass Holder</div>
                                        <div style="font-size:12px;color:var(--gray-1);"><?php echo esc_html( $fmt_ph_reg ); ?> + $3 fee / ticket</div>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <button class="bl-qty-btn bl-qty-btn--add" data-tier="passholder" data-dir="-1" style="width:32px;height:32px;border-radius:50%;background:var(--surface-2);border:1px solid var(--surface-3);color:var(--white);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:.3;">−</button>
                                        <span class="bl-qty-display" data-tier="passholder" style="font-weight:800;font-size:16px;color:var(--white);min-width:20px;text-align:center;">0</span>
                                        <button class="bl-qty-btn bl-qty-btn--add" data-tier="passholder" data-dir="1" style="width:32px;height:32px;border-radius:50%;background:var(--red);border:none;color:var(--white);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
                                        <span class="bl-tier-subtotal" data-tier="passholder" data-price="<?php echo esc_attr( $ph_cents_reg + 300 ); ?>" style="font-size:13px;color:var(--gray-1);min-width:44px;text-align:right;"></span>
                                    </div>
                                </div>

                            </div>
                            <?php else : ?>
                            <!-- Single price fallback -->
                            <div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface-3);border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                                <div>
                                    <div style="font-weight:700;font-size:14px;color:var(--white);">Additional Ticket</div>
                                    <div style="font-size:12px;color:var(--gray-1);"><?php echo esc_html( $fmt_ph_reg ); ?> / ticket</div>
                                </div>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <button class="bl-qty-btn bl-qty-btn--add" data-tier="passholder" data-dir="-1" style="width:32px;height:32px;border-radius:50%;background:var(--surface-2);border:1px solid var(--surface-3);color:var(--white);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:.3;">−</button>
                                    <span class="bl-qty-display" data-tier="passholder" style="font-weight:800;font-size:16px;color:var(--white);min-width:20px;text-align:center;">0</span>
                                    <button class="bl-qty-btn bl-qty-btn--add" data-tier="passholder" data-dir="1" style="width:32px;height:32px;border-radius:50%;background:var(--red);border:none;color:var(--white);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Add-tickets cart summary -->
                            <div id="bl-add-cart-summary" style="display:none;background:var(--surface-3);border-radius:8px;padding:14px 16px;margin-bottom:14px;">
                                <div id="bl-add-cart-lines" style="font-size:13px;color:var(--gray-2);margin-bottom:8px;"></div>
                                <div style="display:flex;justify-content:space-between;font-weight:800;font-size:15px;color:var(--white);">
                                    <span>Total</span>
                                    <span id="bl-add-cart-total"></span>
                                </div>
                            </div>

                            <div id="bl-add-ticket-error" style="display:none;color:#ff6b6b;font-size:13px;margin-bottom:10px;"></div>

                            <button id="bl-add-tickets-btn" class="bl-btn bl-btn--primary" style="width:100%;justify-content:center;opacity:.4;cursor:not-allowed;" disabled>
                                <span id="bl-add-btn-label">Add Tickets</span>
                                <?php blusiast_icon('arrow-right'); ?>
                            </button>
                            <p style="font-size:12px;color:var(--gray-1);margin-top:8px;text-align:center;">You'll agree to the Code of Conduct again before checkout.</p>
                        </div>
                    </div>

                    <!-- Option 2: Send the link -->
                    <div class="bl-already-option" id="bl-opt-share" style="background:var(--surface-2);border:1px solid var(--surface-3);border-radius:12px;overflow:hidden;">
                        <div style="display:flex;align-items:flex-start;gap:14px;padding:20px;cursor:pointer;" onclick="blToggleOption('share')">
                            <div style="font-size:22px;flex-shrink:0;margin-top:2px;">🔗</div>
                            <div style="flex:1;">
                                <div style="font-weight:800;font-size:15px;color:var(--white);margin-bottom:3px;">Send them the link</div>
                                <div style="font-size:13px;color:var(--gray-1);line-height:1.5;">Let your crew grab their own tickets. Share the event link or invite them to join Blusiast first.</div>
                            </div>
                            <div id="bl-opt-share-chevron" style="color:var(--gray-1);font-size:18px;flex-shrink:0;transition:transform .2s;">▾</div>
                        </div>
                        <div id="bl-opt-share-body" style="display:none;padding:0 20px 20px;">
                            <!-- Copyable link -->
                            <div style="margin-bottom:16px;">
                                <div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--gray-1);margin-bottom:8px;">Event Link</div>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input id="bl-share-url" type="text" value="<?php echo esc_attr( get_permalink() ); ?>" readonly
                                        style="flex:1;background:var(--surface-3);border:1px solid var(--surface-3);border-radius:8px;padding:10px 14px;color:var(--white);font-size:13px;outline:none;">
                                    <button onclick="blCopyEventUrl()" class="bl-btn bl-btn--outline" style="flex-shrink:0;padding:10px 16px;font-size:13px;">
                                        <span id="bl-copy-label">Copy</span>
                                    </button>
                                </div>
                            </div>
                            <!-- Share buttons -->
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">
                                <?php
                                    $share_url   = rawurlencode( get_permalink() );
                                    $share_title = rawurlencode( 'Join me at ' . get_the_title() . ' — a Blusiast event!' );
                                ?>
                                <a href="sms:?body=<?php echo $share_title; ?>%20<?php echo $share_url; ?>"
                                   class="bl-btn bl-btn--outline" style="font-size:13px;padding:9px 16px;">📱 Text</a>
                                <a href="https://wa.me/?text=<?php echo $share_title; ?>%20<?php echo $share_url; ?>"
                                   target="_blank" rel="noopener"
                                   class="bl-btn bl-btn--outline" style="font-size:13px;padding:9px 16px;">💬 WhatsApp</a>
                                <a href="mailto:?subject=<?php echo rawurlencode( 'Join me at ' . get_the_title() ); ?>&body=<?php echo $share_title; ?>%20<?php echo $share_url; ?>"
                                   class="bl-btn bl-btn--outline" style="font-size:13px;padding:9px 16px;">✉️ Email</a>
                            </div>
                            <!-- Why join Blusiast -->
                            <div style="background:var(--surface-3);border-radius:10px;padding:16px 18px;">
                                <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--red);font-weight:700;margin-bottom:10px;">Why they should join Blusiast</div>
                                <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px;">
                                    <li style="display:flex;gap:10px;font-size:13px;color:var(--gray-1);"><span style="color:var(--red);font-weight:700;">✓</span> Free to join — no cost, no commitment</li>
                                    <li style="display:flex;gap:10px;font-size:13px;color:var(--gray-1);"><span style="color:var(--red);font-weight:700;">✓</span> Personalized member ID card with QR code</li>
                                    <li style="display:flex;gap:10px;font-size:13px;color:var(--gray-1);"><span style="color:var(--red);font-weight:700;">✓</span> Access to exclusive Blusiast events</li>
                                    <li style="display:flex;gap:10px;font-size:13px;color:var(--gray-1);"><span style="color:var(--red);font-weight:700;">✓</span> A global community of coaster enthusiasts</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Add-tickets conduct modal (reuses existing modal markup style) -->
                <div id="bl-add-conduct-modal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;">
                    <div style="position:absolute;inset:0;background:rgba(0,0,0,.75);" onclick="document.getElementById('bl-add-conduct-modal').style.display='none';document.body.style.overflow='';"></div>
                    <div style="position:relative;background:var(--surface-1);border:1px solid var(--surface-3);border-radius:16px;padding:36px 32px;max-width:500px;width:90%;max-height:85vh;overflow-y:auto;z-index:1;">
                        <button onclick="document.getElementById('bl-add-conduct-modal').style.display='none';document.body.style.overflow='';"
                            style="position:absolute;top:16px;right:16px;background:none;border:none;color:var(--gray-1);font-size:22px;cursor:pointer;line-height:1;">×</button>
                        <p class="bl-label" style="margin-bottom:6px;">Before You Continue</p>
                        <h3 class="bl-display-sm" style="margin-bottom:16px;">Code of Conduct</h3>
                        <div style="font-size:13px;color:var(--gray-2);line-height:1.7;margin-bottom:20px;">
                            <?php
                                $conduct_page = get_page_by_path('code-of-conduct');
                                if ( $conduct_page ) {
                                    echo wp_kses_post( apply_filters( 'the_content', $conduct_page->post_content ) );
                                } else {
                                    echo '<p>By purchasing additional tickets you agree to treat all attendees with respect and to uphold the Blusiast community values of diversity, inclusion, and togetherness.</p>';
                                }
                            ?>
                        </div>
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:20px;">
                            <input type="checkbox" id="bl-add-conduct-check" style="margin-top:3px;accent-color:var(--red);flex-shrink:0;">
                            <span style="font-size:13px;color:var(--gray-2);line-height:1.5;">I have read and agree to the Blusiast Code of Conduct.</span>
                        </label>
                        <div id="bl-add-modal-error" style="display:none;color:#ff6b6b;font-size:13px;margin-bottom:12px;"></div>
                        <button id="bl-add-conduct-proceed" class="bl-btn bl-btn--primary" style="width:100%;justify-content:center;opacity:.4;cursor:not-allowed;" disabled>
                            <span id="bl-add-proceed-label">Continue to Payment</span>
                            <?php blusiast_icon('arrow-right'); ?>
                        </button>
                    </div>
                </div>

                <?php else : ?>
                <?php
                // ── Tiered pricing values ──
                $ph_cents  = (int) get_post_meta( $event_id, 'passholder_price_cents',   true );
                $nph_cents = (int) get_post_meta( $event_id, 'nonpassholder_price_cents', true );
                $has_tiers = $ph_cents > 0 && $nph_cents > 0;

                // Legacy fallback
                if ( ! $has_tiers ) {
                    $ph_cents  = $price_cents;
                    $nph_cents = $price_cents;
                }

                $fmt_ph  = $ph_cents  ? '$' . number_format( $ph_cents  / 100, 0 ) : 'Free';
                $fmt_nph = $nph_cents ? '$' . number_format( $nph_cents / 100, 0 ) : 'Free';
                ?>
                <div class="event-signup-box" style="padding:32px;">
                    <p class="bl-label" style="margin-bottom:8px;">Reserve Your Spot</p>
                    <h2 class="bl-display-md" style="margin-bottom:20px;">Get Your Ticket</h2>

                    <?php if ( $current_member ) : ?>
                    <div style="background:var(--surface-2);border:1px solid var(--surface-3);border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:24px;">
                        <div>
                            <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--gray-1);margin-bottom:2px;">Booking as</div>
                            <div style="font-weight:700;color:var(--white);"><?php echo esc_html( $current_member->first_name . ' ' . $current_member->last_name ); ?></div>
                            <div style="font-size:12px;color:var(--gray-1);"><?php echo esc_html( $current_member->email ); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ( $has_tiers ) : ?>
                    <!-- ── TICKET TIERS WITH INDIVIDUAL QUANTITIES ── -->
                    <div style="margin-bottom:20px;">
                        <p style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--gray-1);margin-bottom:12px;">Select Tickets</p>

                        <!-- Pass Holder row -->
                        <div class="bl-tier-row" style="background:var(--surface-2);border:1px solid var(--surface-3);border-radius:10px;padding:18px;margin-bottom:10px;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;">
                                <div>
                                    <div style="font-weight:800;font-size:15px;color:var(--white);margin-bottom:4px;">Member Access — Pass Holder</div>
                                    <div style="font-size:13px;color:var(--gray-1);line-height:1.55;">Season pass or valid Six Flags park ticket required. Park admission <strong style="color:var(--white);">not included</strong>.</div>
                                </div>
                                <div style="font-family:var(--font-display);font-size:22px;font-weight:900;color:var(--red);white-space:nowrap;flex-shrink:0;"><?php echo esc_html($fmt_ph); ?></div>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="font-size:12px;color:var(--gray-1);">Qty:</span>
                                <div style="display:flex;align-items:center;background:var(--surface-1);border:1px solid var(--surface-3);border-radius:8px;overflow:hidden;">
                                    <button type="button" class="bl-qty-btn" data-tier="passholder" data-dir="-1"
                                        style="width:40px;height:40px;background:transparent;border:none;color:var(--white);font-size:22px;font-weight:900;cursor:pointer;">−</button>
                                    <span class="bl-qty-num" data-tier="passholder"
                                        style="min-width:36px;text-align:center;font-size:18px;font-weight:900;color:var(--white);font-family:var(--font-display);">0</span>
                                    <button type="button" class="bl-qty-btn" data-tier="passholder" data-dir="1"
                                        style="width:40px;height:40px;background:transparent;border:none;color:var(--white);font-size:22px;font-weight:900;cursor:pointer;">+</button>
                                </div>
                                <span class="bl-tier-subtotal" data-tier="passholder" data-price="<?php echo (int)$ph_cents; ?>"
                                    style="font-size:13px;font-weight:700;color:var(--gray-1);"></span>
                            </div>
                        </div>



                        <!-- Cart summary -->
                        <div id="bl-cart-summary" style="display:none;margin-top:14px;background:var(--surface-1);border:1px solid var(--surface-2);border-radius:8px;padding:14px 18px;">
                            <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--gray-1);margin-bottom:10px;">Order Summary</div>
                            <div id="bl-cart-lines" style="font-size:13px;color:var(--gray-2);line-height:2;"></div>
                            <div style="border-top:1px solid var(--surface-2);margin-top:10px;padding-top:10px;display:flex;justify-content:space-between;">
                                <span style="font-size:13px;font-weight:700;color:var(--white);">Total</span>
                                <span id="bl-cart-total" style="font-size:16px;font-weight:900;color:var(--red);"></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div id="bl-ticket-error" style="display:none;background:rgba(204,0,0,.1);border:1px solid rgba(204,0,0,.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px;color:#ff6666;text-align:left;"></div>

                    <button id="bl-buy-ticket-btn"
                            class="bl-btn bl-btn--primary bl-btn--lg"
                            data-event-id="<?php echo esc_attr( $event_id ); ?>"
                            data-event-title="<?php echo esc_attr( get_the_title() ); ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>"
                            data-buy-url="<?php echo esc_attr( $buy_url ); ?>"
                            style="width:100%;justify-content:center;">
                        <span class="bl-btn__label"><?php echo $is_free ? 'Register — Free' : 'Buy Ticket'; ?></span>
                        <?php blusiast_icon('arrow-right'); ?>
                    </button>
                    <?php if ( ! $is_free ) : ?>
                    <p style="font-size:12px;color:var(--gray-1);margin-top:12px;display:flex;align-items:center;justify-content:center;gap:5px;">
                        <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><rect x=".5" y="4.5" width="12" height="8" rx="1" stroke="currentColor" stroke-width="1.1"/><path d="M3.5 4.5V3a3 3 0 0 1 6 0v1.5" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/></svg>
                        Secure checkout powered by Stripe
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div><!-- /.bl-ticket-col -->


            <!-- ── RIGHT: code of conduct tagline panel ── -->
            <div class="bl-conduct-panel">
                <p class="bl-label" style="margin-bottom:14px;">Before You Come</p>
                <h2 class="bl-display-sm" style="margin-bottom:16px;">Code of Conduct</h2>
                <p style="font-size:14px;color:var(--gray-1);margin-bottom:24px;line-height:1.6;">
                    Blusiast events are for everyone. We keep it simple.
                </p>
                <div class="bl-conduct-tags">
                    <div class="bl-conduct-tag">🤝&nbsp; Respect Everyone</div>
                    <div class="bl-conduct-tag">📱&nbsp; No Phones on Rides</div>
                    <div class="bl-conduct-tag">⏰&nbsp; Be On Time</div>
                    <div class="bl-conduct-tag">🗣️&nbsp; Represent Well</div>
                    <div class="bl-conduct-tag">👥&nbsp; Your Guests, Your Responsibility</div>
                    <div class="bl-conduct-tag">🎢&nbsp; Have Fun!</div>
                </div>
                <button id="bl-read-conduct-btn" class="bl-conduct-read-more">
                    Read the full Code of Conduct →
                </button>
            </div><!-- /.bl-conduct-panel -->

        </div><!-- /.bl-event-grid -->
        </div><!-- /.event-signup-section -->


        <!-- ── BACK LINK ── -->
        <div style="margin-top:48px;padding-top:24px;border-top:1px solid var(--surface-3);">
            <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_event' ) ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">
                ← All Events
            </a>
        </div>

    </div>
</div>


<!-- ══════════════════════════════════════
     CONDUCT AGREEMENT MODAL
     ══════════════════════════════════════ -->
<div id="bl-conduct-modal" role="dialog" aria-modal="true" aria-labelledby="bl-conduct-modal-title" style="display:none;position:fixed;inset:0;z-index:9100;align-items:center;justify-content:center;padding:80px 16px 16px;">
    <div id="bl-conduct-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(4px);cursor:pointer;"></div>
    <div style="position:relative;z-index:1;background:var(--surface-1);border:1px solid var(--surface-4);border-radius:var(--radius-xl);width:100%;max-width:580px;max-height:90vh;overflow-y:auto;padding:36px;animation:bl-modal-in .25s ease both;">

        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;">
            <div>
                <p class="bl-label" style="margin-bottom:6px;">Before You Register</p>
                <div id="bl-conduct-modal-title" style="font-family:var(--font-display);font-size:24px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Code of Conduct</div>
                <p style="font-size:13px;color:var(--gray-1);">Please read and agree before continuing to payment.</p>
            </div>
            <button id="bl-conduct-close" aria-label="Close" style="background:var(--surface-2);border:1px solid var(--surface-4);border-radius:6px;width:36px;height:36px;cursor:pointer;color:var(--gray-1);font-size:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-left:16px;line-height:1;">×</button>
        </div>

        <!-- Rules -->
        <div style="display:flex;flex-direction:column;gap:0;margin-bottom:24px;">

            <div style="display:flex;gap:14px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--surface-3);">
                <div style="font-size:20px;flex-shrink:0;width:28px;text-align:center;margin-top:2px;">🤝</div>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--white);margin-bottom:4px;">Respect Everyone</div>
                    <div style="font-size:13px;color:var(--gray-1);line-height:1.65;">Respect park staff, other guests, and fellow Blusiast members at all times. No harassment, no gossip, and no behavior that makes others feel unwelcome — in person or online.</div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--surface-3);">
                <div style="font-size:20px;flex-shrink:0;width:28px;text-align:center;margin-top:2px;">📱</div>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--white);margin-bottom:4px;">No Phones on Rides</div>
                    <div style="font-size:13px;color:var(--gray-1);line-height:1.65;">Keep your phone pocketed on rides. Loose articles are a safety hazard. If a park allows on-ride recording, use proper equipment. Don't photograph your ride photos — buy them if you want them.</div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--surface-3);">
                <div style="font-size:20px;flex-shrink:0;width:28px;text-align:center;margin-top:2px;">⏰</div>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--white);margin-bottom:4px;">Be On Time</div>
                    <div style="font-size:13px;color:var(--gray-1);line-height:1.65;">ERT, exclusive tours, and special access are privileges we work hard to arrange. Late arrivals to paid events will not receive refunds. Accurate headcounts matter for our park relationships.</div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--surface-3);">
                <div style="font-size:20px;flex-shrink:0;width:28px;text-align:center;margin-top:2px;">🗣️</div>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--white);margin-bottom:4px;">Represent Well</div>
                    <div style="font-size:13px;color:var(--gray-1);line-height:1.65;">Speak positively of parks and events. No negativity, leaks, or gossip while in Blusiast gear or at Blusiast events. What you post reflects on all of us.</div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--surface-3);">
                <div style="font-size:20px;flex-shrink:0;width:28px;text-align:center;margin-top:2px;">👥</div>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--white);margin-bottom:4px;">Your Guests, Your Responsibility</div>
                    <div style="font-size:13px;color:var(--gray-1);line-height:1.65;">Guests must follow this code. You're accountable for their behavior. Members banned from Blusiast may not attend future events as a guest of another member.</div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start;padding:16px 0;">
                <div style="font-size:20px;flex-shrink:0;width:28px;text-align:center;margin-top:2px;">🎢</div>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--white);margin-bottom:4px;">Have Fun!</div>
                    <div style="font-size:13px;color:var(--gray-1);line-height:1.65;">We're all here for the rides and the community. Be yourself, look out for each other, and make it a great time.</div>
                </div>
            </div>

        </div>

        <p style="font-size:11px;color:var(--gray-1);margin-bottom:20px;padding:12px 14px;background:var(--surface-2);border-radius:6px;line-height:1.6;">
            Failure to abide by the Blusiast Code of Conduct may result in removal from the event and/or the network.
        </p>

        <!-- Agreement checkbox -->
        <label style="display:flex;align-items:flex-start;gap:12px;background:var(--surface-2);border:1px solid var(--surface-3);border-radius:8px;padding:16px;margin-bottom:16px;cursor:pointer;">
            <input type="checkbox" id="bl-conduct-checkbox" style="width:20px;height:20px;flex-shrink:0;margin-top:2px;accent-color:var(--red);cursor:pointer;">
            <div>
                <div style="font-size:14px;font-weight:600;color:var(--white);line-height:1.4;">I have read and agree to the Blusiast Code of Conduct</div>
                <div style="font-size:12px;color:var(--gray-1);margin-top:3px;">This agreement will be recorded on your registration for this event.</div>
            </div>
        </label>

        <!-- Error -->
        <div id="bl-conduct-error" style="display:none;color:#ff6666;font-size:13px;margin-bottom:12px;"></div>

        <!-- CTA button -->
        <button id="bl-conduct-proceed-btn" class="bl-btn bl-btn--primary bl-btn--lg" style="width:100%;justify-content:center;opacity:.4;cursor:not-allowed;" disabled>
            <span id="bl-conduct-btn-label"><?php echo esc_html( $btn_label ); ?></span>
        </button>

    </div>
</div>


<!-- ── SUCCESS / CANCELLED NOTICES ── -->
<?php $ticket_result = sanitize_key( $_GET['bl_ticket'] ?? '' ); ?>
<?php if ( $ticket_result === 'success' ) : ?>
<div id="bl-ticket-notice" style="position:fixed;bottom:24px;right:24px;z-index:9999;background:#1a1a1a;border:1px solid var(--red);border-radius:10px;padding:18px 24px 18px 20px;max-width:320px;box-shadow:0 8px 32px rgba(0,0,0,.6);animation:bl-notice-in .3s ease;">
    <button onclick="this.parentNode.remove()" style="position:absolute;top:10px;right:12px;background:none;border:none;color:var(--gray-1);font-size:20px;cursor:pointer;line-height:1;">×</button>
    <div style="font-weight:700;color:var(--white);margin-bottom:4px;">🎟️ Ticket Confirmed!</div>
    <div style="font-size:13px;color:var(--gray-1);line-height:1.5;">Your ticket is locked in. Check your email and bring your member QR code to the door.</div>
</div>
<?php endif; ?>
<?php if ( $ticket_result === 'cancelled' ) : ?>
<div id="bl-ticket-notice" style="position:fixed;bottom:24px;right:24px;z-index:9999;background:#1a1a1a;border:1px solid #444;border-radius:10px;padding:18px 24px 18px 20px;max-width:300px;box-shadow:0 8px 32px rgba(0,0,0,.6);">
    <button onclick="this.parentNode.remove()" style="position:absolute;top:10px;right:12px;background:none;border:none;color:var(--gray-1);font-size:20px;cursor:pointer;line-height:1;">×</button>
    <div style="font-weight:700;color:var(--white);margin-bottom:4px;">Payment Cancelled</div>
    <div style="font-size:13px;color:var(--gray-1);">No charge was made. You can try again whenever you're ready.</div>
</div>
<?php endif; ?>


<!-- ── STYLES ── -->
<style>
/* Two-column layout */
.bl-event-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 48px;
    align-items: start;
}
.bl-ticket-col .event-signup-box { max-width: 100%; }

/* Conduct tagline panel */
.bl-conduct-panel {
    background: var(--surface-1);
    border: 1px solid var(--surface-3);
    border-radius: var(--radius-lg);
    padding: 32px;
    position: sticky;
    top: 24px;
}
.bl-conduct-tags {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 24px;
}
.bl-conduct-tag {
    font-size: 14px;
    font-weight: 700;
    color: var(--white);
    padding: 10px 14px;
    background: var(--surface-2);
    border: 1px solid var(--surface-3);
    border-left: 3px solid var(--red);
    border-radius: 6px;
    letter-spacing: .02em;
}
.bl-conduct-read-more {
    background: none;
    border: none;
    color: var(--red);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
    text-underline-offset: 3px;
}
.bl-conduct-read-more:hover { opacity: .7; }

/* Modal animation */
@keyframes bl-modal-in { from { opacity:0; transform:translateY(16px) scale(.97); } to { opacity:1; transform:none; } }
@keyframes bl-notice-in { from { transform:translateY(20px);opacity:0; } to { transform:translateY(0);opacity:1; } }

/* Responsive */
@media (max-width: 900px) {
    .bl-event-grid { grid-template-columns: 1fr; gap: 32px; }
    .bl-conduct-panel { position: static; }
}
</style>


<!-- ── JAVASCRIPT — placed AFTER all HTML ── -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    var buyBtn     = document.getElementById('bl-buy-ticket-btn');
    var readBtn    = document.getElementById('bl-read-conduct-btn');
    var modal      = document.getElementById('bl-conduct-modal');
    var backdrop   = document.getElementById('bl-conduct-backdrop');
    var closeBtn   = document.getElementById('bl-conduct-close');
    var checkbox   = document.getElementById('bl-conduct-checkbox');
    var proceedBtn = document.getElementById('bl-conduct-proceed-btn');
    var errBox     = document.getElementById('bl-conduct-error');
    var btnLabel   = document.getElementById('bl-conduct-btn-label');

    var BUY_URL    = <?php echo json_encode( $buy_url ); ?>;
    var NONCE      = <?php echo json_encode( $nonce ); ?>;
    var EVENT_ID   = <?php echo (int) $event_id; ?>;
    var BTN_LABEL  = <?php echo json_encode( $btn_label ); ?>;
    var PH_CENTS   = <?php echo (int) $ph_cents_top; ?>;
    var NPH_CENTS  = <?php echo (int) $nph_cents_top; ?>;

    // ── Per-tier quantities ──
    var tierQty = { passholder: 0, nonpassholder: 0 };

    function formatMoney(cents) {
        return '$' + (cents / 100).toFixed(0);
    }

    function updateCartUI() {
        var phQty  = tierQty.passholder;
        var nphQty = tierQty.nonpassholder;

        // Update displayed numbers
        document.querySelectorAll('.bl-qty-num').forEach(function(el) {
            el.textContent = tierQty[el.dataset.tier] || 0;
        });

        // Update minus button opacity
        document.querySelectorAll('.bl-qty-btn[data-dir="-1"]').forEach(function(btn) {
            btn.style.opacity = tierQty[btn.dataset.tier] <= 0 ? '.3' : '1';
        });

        // Update subtotals
        document.querySelectorAll('.bl-tier-subtotal').forEach(function(el) {
            var tier  = el.dataset.tier;
            var price = parseInt(el.dataset.price) || 0;
            var q     = tierQty[tier] || 0;
            el.textContent = q > 0 ? formatMoney(price * q) : '';
        });

        // Cart summary
        var total     = (phQty * PH_CENTS) + (nphQty * NPH_CENTS);
        var phFee     = phQty  * 300;   // $3 per pass holder ticket
        var nphFee    = nphQty * 400;   // $4 per full admission ticket
        var totalFees = phFee + nphFee;
        var grandTotal = total + totalFees;
        var summary   = document.getElementById('bl-cart-summary');
        var lines     = document.getElementById('bl-cart-lines');
        var totalEl   = document.getElementById('bl-cart-total');

        if (phQty === 0 && nphQty === 0) {
            if (summary) summary.style.display = 'none';
        } else {
            var html = '';
            if (phQty  > 0) html += '<div>Pass Holder ×' + phQty + ' <span style="float:right">' + formatMoney(PH_CENTS * phQty) + '</span></div>';
            if (nphQty > 0) html += '<div>Full Admission ×' + nphQty + ' <span style="float:right">' + formatMoney(NPH_CENTS * nphQty) + '</span></div>';
            if (totalFees > 0) html += '<div style="color:var(--gray-3);">Service fees <span style="float:right">' + formatMoney(totalFees) + '</span></div>';
            if (lines)  lines.innerHTML = html;
            if (totalEl) totalEl.textContent = formatMoney(grandTotal);
            if (summary) summary.style.display = 'block';
        }
    }

    // Qty button clicks
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.bl-qty-btn');
        if (!btn) return;
        var tier = btn.dataset.tier;
        var dir  = parseInt(btn.dataset.dir);
        if (!tier || !dir) return;
        tierQty[tier] = Math.max(0, (tierQty[tier] || 0) + dir);
        updateCartUI();
    });

    updateCartUI();

    function getSelectedTicketType() {
        // Legacy fallback — not used in multi-tier mode but kept for free events
        return 'passholder';
    }

    function openModal() {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (checkbox) checkbox.checked = false;
        if (proceedBtn) { proceedBtn.disabled = true; proceedBtn.style.opacity = '.4'; proceedBtn.style.cursor = 'not-allowed'; }
        if (errBox) errBox.style.display = 'none';
        if (btnLabel) btnLabel.textContent = BTN_LABEL;
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Open modal on Buy Ticket click — validate at least one ticket selected
    if (buyBtn) buyBtn.addEventListener('click', function() {
        var total = (tierQty.passholder || 0) + (tierQty.nonpassholder || 0);
        if (total === 0) {
            var errBox = document.getElementById('bl-ticket-error');
            if (errBox) { errBox.textContent = 'Please select at least one ticket before continuing.'; errBox.style.display = 'block'; }
            return;
        }
        var errBox = document.getElementById('bl-ticket-error');
        if (errBox) errBox.style.display = 'none';
        openModal();
    });

    // Open modal on Read Conduct click
    if (readBtn) readBtn.addEventListener('click', openModal);

    // Close modal
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
    });

    // Enable proceed button when checkbox is checked
    if (checkbox) {
        checkbox.addEventListener('change', function() {
            if (proceedBtn) {
                proceedBtn.disabled = !this.checked;
                proceedBtn.style.opacity = this.checked ? '1' : '.4';
                proceedBtn.style.cursor  = this.checked ? 'pointer' : 'not-allowed';
            }
        });
    }

    // Proceed to payment
    if (proceedBtn) {
        proceedBtn.addEventListener('click', async function() {
            if (!checkbox || !checkbox.checked) {
                if (errBox) { errBox.textContent = 'Please check the box to confirm you agree.'; errBox.style.display = 'block'; }
                return;
            }

            // For mixed carts, primary ticket_type is whichever has more; backend handles both
            var ticketType = (tierQty.nonpassholder || 0) >= (tierQty.passholder || 0) ? 'nonpassholder' : 'passholder';
            var qty = (tierQty.passholder || 0) + (tierQty.nonpassholder || 0);

            // Loading state
            proceedBtn.disabled      = true;
            proceedBtn.style.opacity = '.6';
            if (btnLabel) btnLabel.textContent = 'Loading…';
            if (errBox) errBox.style.display = 'none';

            try {
                var resp = await fetch(BUY_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body: JSON.stringify({
                        event_id:       EVENT_ID,
                        conduct_agreed: true,
                        ticket_type:    ticketType,
                        quantity:       qty,
                        ph_quantity:    tierQty.passholder    || 0,
                        nph_quantity:   tierQty.nonpassholder || 0,
                    }),
                });
                var data = await resp.json();

                if (resp.ok && data.url) {
                    window.location.href = data.url;
                    return;
                }
                if (resp.ok && data.status === 'confirmed') {
                    closeModal();
                    window.location.reload();
                    return;
                }

                if (errBox) { errBox.textContent = data.error || 'Something went wrong. Please try again.'; errBox.style.display = 'block'; }

            } catch(e) {
                if (errBox) { errBox.textContent = 'Network error. Please try again.'; errBox.style.display = 'block'; }
            }

            // Reset on error
            proceedBtn.disabled      = false;
            proceedBtn.style.opacity = '1';
            proceedBtn.style.cursor  = 'pointer';
            if (btnLabel) btnLabel.textContent = BTN_LABEL;
        });
    }

});
</script>

<?php if ( $already_registered ) : ?>
<script>
(function() {

    // ── Option accordion toggles ──
    window.blToggleOption = function(which) {
        var bodies   = { add: document.getElementById('bl-opt-add-body'),   share: document.getElementById('bl-opt-share-body') };
        var chevrons = { add: document.getElementById('bl-opt-add-chevron'), share: document.getElementById('bl-opt-share-chevron') };
        Object.keys(bodies).forEach(function(k) {
            var open = k === which && bodies[k].style.display === 'none';
            bodies[k].style.display   = open ? 'block' : 'none';
            chevrons[k].style.transform = open ? 'rotate(180deg)' : 'rotate(0)';
        });
    };

    // ── Copy event URL ──
    window.blCopyEventUrl = function() {
        var input = document.getElementById('bl-share-url');
        var label = document.getElementById('bl-copy-label');
        if (!input) return;
        navigator.clipboard.writeText(input.value).then(function() {
            label.textContent = 'Copied!';
            setTimeout(function() { label.textContent = 'Copy'; }, 2000);
        }).catch(function() {
            input.select();
            document.execCommand('copy');
            label.textContent = 'Copied!';
            setTimeout(function() { label.textContent = 'Copy'; }, 2000);
        });
    };

    // ── Add-tickets quantity state ──
    var addQty = { passholder: 0, nonpassholder: 0 };
    var PH_CENTS_ADD  = <?php echo (int) ( $ph_cents_reg  + 300 ); ?>;
    var NPH_CENTS_ADD = <?php echo (int) ( $nph_cents_reg + 400 ); ?>;

    function formatMoney(cents) {
        return '$' + (cents / 100).toFixed(2).replace(/\.00$/, '');
    }

    function updateAddCartUI() {
        var phQty  = addQty.passholder    || 0;
        var nphQty = addQty.nonpassholder || 0;
        var total  = (phQty * PH_CENTS_ADD) + (nphQty * NPH_CENTS_ADD);
        var btn    = document.getElementById('bl-add-tickets-btn');
        var label  = document.getElementById('bl-add-btn-label');
        var summary = document.getElementById('bl-add-cart-summary');
        var lines   = document.getElementById('bl-add-cart-lines');
        var totalEl = document.getElementById('bl-add-cart-total');

        // Qty displays
        document.querySelectorAll('.bl-qty-btn--add').forEach(function(b) {
            var t = b.dataset.tier;
            var isPlus = parseInt(b.dataset.dir) > 0;
            if (!isPlus) b.style.opacity = (addQty[t] || 0) <= 0 ? '.3' : '1';
        });
        document.querySelectorAll('.bl-qty-display').forEach(function(el) {
            el.textContent = addQty[el.dataset.tier] || 0;
        });
        document.querySelectorAll('.bl-tier-subtotal').forEach(function(el) {
            var t = el.dataset.tier;
            var p = parseInt(el.dataset.price) || 0;
            var q = addQty[t] || 0;
            el.textContent = q > 0 ? formatMoney(p * q) : '';
        });

        // Cart summary
        var qty = phQty + nphQty;
        if (qty === 0) {
            if (summary) summary.style.display = 'none';
            if (btn) { btn.disabled = true; btn.style.opacity = '.4'; btn.style.cursor = 'not-allowed'; }
            if (label) label.textContent = 'Add Tickets';
        } else {
            var html = '';
            if (phQty  > 0) html += '<div>Pass Holder ×' + phQty  + ' <span style="float:right">' + formatMoney(PH_CENTS_ADD  * phQty)  + '</span></div>';
            if (nphQty > 0) html += '<div>Full Admission ×' + nphQty + ' <span style="float:right">' + formatMoney(NPH_CENTS_ADD * nphQty) + '</span></div>';
            if (lines)   lines.innerHTML = html;
            if (totalEl) totalEl.textContent = formatMoney(total);
            if (summary) summary.style.display = 'block';
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; }
            if (label) label.textContent = 'Add ' + qty + ' Ticket' + (qty !== 1 ? 's' : '');
        }
    }

    // Qty button clicks — scoped to add-tickets panel
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.bl-qty-btn--add');
        if (!btn) return;
        var tier = btn.dataset.tier;
        var dir  = parseInt(btn.dataset.dir);
        if (!tier || !dir) return;
        addQty[tier] = Math.max(0, (addQty[tier] || 0) + dir);
        updateAddCartUI();
    });

    updateAddCartUI();

    // ── Add-tickets conduct modal ──
    var addModal    = document.getElementById('bl-add-conduct-modal');
    var addCheck    = document.getElementById('bl-add-conduct-check');
    var addProceed  = document.getElementById('bl-add-conduct-proceed');
    var addProceedLabel = document.getElementById('bl-add-proceed-label');
    var addModalErr = document.getElementById('bl-add-modal-error');

    document.getElementById('bl-add-tickets-btn') && document.getElementById('bl-add-tickets-btn').addEventListener('click', function() {
        var qty = (addQty.passholder || 0) + (addQty.nonpassholder || 0);
        if (qty === 0) return;
        if (addModal) { addModal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
        if (addCheck)   addCheck.checked = false;
        if (addProceed) { addProceed.disabled = true; addProceed.style.opacity = '.4'; addProceed.style.cursor = 'not-allowed'; }
        if (addModalErr) addModalErr.style.display = 'none';
    });

    if (addCheck) {
        addCheck.addEventListener('change', function() {
            if (addProceed) {
                addProceed.disabled     = !this.checked;
                addProceed.style.opacity = this.checked ? '1' : '.4';
                addProceed.style.cursor  = this.checked ? 'pointer' : 'not-allowed';
            }
        });
    }

    if (addProceed) {
        addProceed.addEventListener('click', async function() {
            if (!addCheck || !addCheck.checked) return;

            addProceed.disabled      = true;
            addProceed.style.opacity = '.6';
            if (addProceedLabel) addProceedLabel.textContent = 'Loading…';
            if (addModalErr) addModalErr.style.display = 'none';

            var ticketType = (addQty.nonpassholder || 0) >= (addQty.passholder || 0) ? 'nonpassholder' : 'passholder';
            var qty = (addQty.passholder || 0) + (addQty.nonpassholder || 0);

            try {
                var resp = await fetch(<?php echo json_encode( rest_url('blusiast/v1/buy-ticket') ); ?>, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo json_encode( wp_create_nonce('wp_rest') ); ?> },
                    body: JSON.stringify({
                        event_id:          <?php echo (int) $event_id; ?>,
                        conduct_agreed:    true,
                        ticket_type:       ticketType,
                        quantity:          qty,
                        ph_quantity:       addQty.passholder    || 0,
                        nph_quantity:      addQty.nonpassholder || 0,
                        bl_add_to_existing: '1',
                    }),
                });
                var data = await resp.json();
                if (resp.ok && data.url) { window.location.href = data.url; return; }
                if (addModalErr) { addModalErr.textContent = (data && data.error) ? data.error : 'Something went wrong. Please try again.'; addModalErr.style.display = 'block'; }
            } catch(err) {
                if (addModalErr) { addModalErr.textContent = 'Network error. Please try again.'; addModalErr.style.display = 'block'; }
            }

            addProceed.disabled      = false;
            addProceed.style.opacity = '1';
            addProceed.style.cursor  = 'pointer';
            if (addProceedLabel) addProceedLabel.textContent = 'Continue to Payment';
        });
    }

})();
</script>
<?php endif; ?>

<?php if ( ! is_user_logged_in() ) : ?>
<script>
(function(){
    var thisUrl = <?php echo json_encode( get_permalink() ); ?>;
    // Set immediately — bluPortal is already defined by wp_localize_script in <head>
    if (window.bluPortal) {
        bluPortal.loginRedirect    = thisUrl;
        bluPortal.registerRedirect = thisUrl;
    }
    // Belt-and-suspenders fallback
    window.blEventRedirect = thisUrl;
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>