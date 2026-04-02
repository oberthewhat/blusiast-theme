<?php
/**
 * single-bl_event.php — Single Event Page
 * Layout: hero → event meta info → sign-up form → back link
 */
get_header();
the_post();

$event_id  = get_the_ID();
$date      = function_exists('get_field') ? get_field('event_date',         $event_id) : '';
$end_date  = function_exists('get_field') ? get_field('event_end_date',     $event_id) : '';
$time      = function_exists('get_field') ? get_field('event_time',         $event_id) : '';
$location  = function_exists('get_field') ? get_field('event_location',     $event_id) : '';
$price     = function_exists('get_field') ? get_field('event_price',        $event_id) : '';
$reg_url   = function_exists('get_field') ? get_field('event_reg_url',      $event_id) : '';
$capacity  = function_exists('get_field') ? get_field('event_capacity',     $event_id) : '';
$members   = function_exists('get_field') ? get_field('event_members_only', $event_id) : false;
$sold_out  = function_exists('get_field') ? get_field('event_sold_out',     $event_id) : false;
$fmt       = blusiast_format_event_date( $date );
$has_price = ! empty( $price ) && strtolower( trim( $price ) ) !== 'free';
?>

<!-- ── PAGE HERO ──────────────────────────── -->
<div class="page-hero">
    <div class="container">
        <p class="bl-label"><?php esc_html_e( 'Event', 'blusiast' ); ?></p>
        <h1 class="bl-display-lg" style="margin-bottom:20px;"><?php the_title(); ?></h1>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php if ( $members ) : ?>
                <span class="bl-badge bl-badge--red bl-badge--dot"><?php esc_html_e( 'Members Only', 'blusiast' ); ?></span>
            <?php else : ?>
                <span class="bl-badge bl-badge--white bl-badge--dot"><?php esc_html_e( 'Open to All', 'blusiast' ); ?></span>
            <?php endif; ?>
            <?php if ( $sold_out ) : ?>
                <span class="bl-badge bl-badge--white"><?php esc_html_e( 'Sold Out', 'blusiast' ); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="page-content">
    <div class="container">

        <!-- ── EVENT INFO SECTION ─────────────── -->
        <div class="event-single-layout">

            <!-- Featured image -->
            <?php if ( has_post_thumbnail() ) : ?>
                <div class="event-single__image">
                    <?php the_post_thumbnail( 'blusiast-hero', [ 'style' => 'width:100%;border-radius:12px;', 'alt' => '' ] ); ?>
                </div>
            <?php endif; ?>

            <!-- Quick-facts strip -->
            <div class="event-single__meta-strip">

                <?php if ( ! empty( $fmt['full'] ) ) : ?>
                <div class="event-meta-fact">
                    <div class="event-card__date" style="min-width:52px;min-height:52px;">
                        <span class="event-card__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                        <span class="event-card__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                    </div>
                    <div>
                        <div class="event-meta-fact__label"><?php esc_html_e( 'Date', 'blusiast' ); ?></div>
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
                        <div class="event-meta-fact__label"><?php esc_html_e( 'Time', 'blusiast' ); ?></div>
                        <div class="event-meta-fact__value"><?php echo esc_html( $time ); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $location ) : ?>
                <div class="event-meta-fact">
                    <?php blusiast_icon( 'location', 'event-meta-fact__icon' ); ?>
                    <div>
                        <div class="event-meta-fact__label"><?php esc_html_e( 'Location', 'blusiast' ); ?></div>
                        <div class="event-meta-fact__value"><?php echo esc_html( $location ); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $price ) : ?>
                <div class="event-meta-fact">
                    <div style="font-family:var(--font-display);font-size:28px;font-weight:800;color:var(--red);line-height:1;min-width:52px;text-align:center;">
                        <?php echo esc_html( $price ); ?>
                    </div>
                    <div>
                        <div class="event-meta-fact__label"><?php esc_html_e( 'Price', 'blusiast' ); ?></div>
                        <?php if ( $capacity ) : ?>
                            <div class="event-meta-fact__value" style="font-size:13px;color:var(--gray-1);">
                                <?php echo esc_html( $capacity ); ?> <?php esc_html_e( 'spots total', 'blusiast' ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /.event-single__meta-strip -->

            <!-- Event body content -->
            <?php if ( get_the_content() ) : ?>
            <div class="event-single__body">
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.event-single-layout -->


        <!-- ── SIGN-UP SECTION ────────────────── -->
        <div class="event-signup-section">

            <?php if ( $sold_out ) : ?>

                <div class="event-signup-section__sold-out">
                    <p class="bl-display-sm" style="color:var(--gray-1);"><?php esc_html_e( 'This event is sold out.', 'blusiast' ); ?></p>
                    <p class="bl-body-md" style="margin-top:8px;"><?php esc_html_e( 'Keep an eye on our social channels for future events.', 'blusiast' ); ?></p>
                </div>

            <?php else : ?>

                <div class="event-signup-box">

                    <div class="event-signup-box__header">
                        <p class="bl-label"><?php esc_html_e( 'Reserve Your Spot', 'blusiast' ); ?></p>
                        <h2 class="bl-display-md"><?php esc_html_e( 'Sign Up for This Event', 'blusiast' ); ?></h2>
                        <?php if ( $has_price ) : ?>
                            <p class="bl-body-md" style="margin-top:12px;">
                                <?php printf(
                                    esc_html__( 'Cost is %s per person. Payment instructions will be sent to your email after you register.', 'blusiast' ),
                                    '<strong style="color:var(--white);">' . esc_html( $price ) . '</strong>'
                                ); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Success state -->
                    <div id="bl-signup-success" class="bl-signup-success" hidden>
                        <div class="bl-signup-success__icon">
                            <svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                                <circle cx="24" cy="24" r="23" stroke="var(--red)" stroke-width="1.5"/>
                                <path d="M13 24.5l8 8L35 16" stroke="var(--red)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h3 class="bl-display-sm" style="color:var(--white);margin:16px 0 8px;"><?php esc_html_e( "You're In!", 'blusiast' ); ?></h3>
                        <p class="bl-body-md"><?php esc_html_e( "We've got you on the list. Check your email for confirmation details.", 'blusiast' ); ?></p>
                        <?php if ( $has_price ) : ?>
                            <p class="bl-body-sm" style="margin-top:8px;color:var(--gray-1);"><?php esc_html_e( 'Payment instructions are on their way to your inbox.', 'blusiast' ); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Form -->
                    <form id="bl-signup-form"
                          class="bl-signup-form"
                          novalidate
                          data-event-id="<?php echo esc_attr( $event_id ); ?>">

                        <?php wp_nonce_field( 'blusiast_event_signup', 'bl_nonce' ); ?>

                        <div class="bl-form-row bl-form-row--two">
                            <div class="bl-form-field">
                                <label class="bl-form-label" for="bl-first-name">
                                    <?php esc_html_e( 'First Name', 'blusiast' ); ?>
                                    <span aria-hidden="true" style="color:var(--red);margin-left:3px;">*</span>
                                </label>
                                <input class="bl-form-input" type="text" id="bl-first-name" name="first_name" autocomplete="given-name" required>
                            </div>
                            <div class="bl-form-field">
                                <label class="bl-form-label" for="bl-last-name">
                                    <?php esc_html_e( 'Last Name', 'blusiast' ); ?>
                                    <span aria-hidden="true" style="color:var(--red);margin-left:3px;">*</span>
                                </label>
                                <input class="bl-form-input" type="text" id="bl-last-name" name="last_name" autocomplete="family-name" required>
                            </div>
                        </div>

                        <div class="bl-form-field">
                            <label class="bl-form-label" for="bl-email">
                                <?php esc_html_e( 'Email Address', 'blusiast' ); ?>
                                <span aria-hidden="true" style="color:var(--red);margin-left:3px;">*</span>
                            </label>
                            <input class="bl-form-input" type="email" id="bl-email" name="email" autocomplete="email" required>
                        </div>

                        <div class="bl-form-row bl-form-row--two">
                            <div class="bl-form-field">
                                <label class="bl-form-label" for="bl-phone">
                                    <?php esc_html_e( 'Phone Number', 'blusiast' ); ?>
                                    <span style="font-weight:400;color:var(--gray-1);text-transform:none;letter-spacing:0;margin-left:4px;"><?php esc_html_e( '(optional)', 'blusiast' ); ?></span>
                                </label>
                                <input class="bl-form-input" type="tel" id="bl-phone" name="phone" autocomplete="tel">
                            </div>
                            <div class="bl-form-field">
                                <label class="bl-form-label" for="bl-zip">
                                    <?php esc_html_e( 'Zip Code', 'blusiast' ); ?>
                                    <span style="font-weight:400;color:var(--gray-1);text-transform:none;letter-spacing:0;margin-left:4px;"><?php esc_html_e( '(optional)', 'blusiast' ); ?></span>
                                </label>
                                <input class="bl-form-input" type="text" id="bl-zip" name="zip" autocomplete="postal-code" maxlength="10">
                            </div>
                        </div>

                        <div class="bl-form-field">
                            <label class="bl-form-label" for="bl-guest-count">
                                <?php esc_html_e( 'Number of Guests (including yourself)', 'blusiast' ); ?>
                            </label>
                            <select class="bl-form-input bl-form-select" id="bl-guest-count" name="guest_count">
                                <?php for ( $i = 1; $i <= 8; $i++ ) : ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="bl-form-field" style="flex-direction:row;align-items:flex-start;gap:10px;margin-top:4px;">
                            <input type="checkbox"
                                   id="bl-consent"
                                   name="consent"
                                   class="bl-form-checkbox"
                                   required
                                   style="width:18px;height:18px;flex-shrink:0;margin-top:3px;accent-color:var(--red);cursor:pointer;">
                            <label for="bl-consent" style="font-size:13px;color:var(--gray-2);line-height:1.5;cursor:pointer;">
                                <?php esc_html_e( 'I agree to receive event communications from Blusiast. We never spam.', 'blusiast' ); ?>
                            </label>
                        </div>

                        <!-- Error message -->
                        <div id="bl-signup-error" class="bl-form-error" hidden role="alert"></div>

                        <div style="margin-top:8px;">
                            <button type="submit"
                                    id="bl-signup-submit"
                                    class="bl-btn bl-btn--primary bl-btn--lg"
                                    style="width:100%;justify-content:center;">
                                <span class="bl-btn__label">
                                    <?php echo $has_price
                                        ? esc_html__( 'Reserve My Spot', 'blusiast' )
                                        : esc_html__( 'Sign Me Up — Free', 'blusiast' );
                                    ?>
                                </span>
                                <span class="bl-btn__spinner" hidden aria-hidden="true"></span>
                                <?php blusiast_icon( 'arrow-right' ); ?>
                            </button>
                            <p style="font-size:11px;color:var(--gray-1);text-align:center;margin-top:10px;">
                                <?php esc_html_e( 'Your info is only used for event coordination.', 'blusiast' ); ?>
                            </p>
                            <?php if ( $members ) : ?>
                                <p style="font-size:11px;color:var(--gray-1);text-align:center;margin-top:4px;display:flex;align-items:center;justify-content:center;gap:4px;">
                                    <?php blusiast_icon( 'lock' ); ?>
                                    <?php esc_html_e( 'Members only event', 'blusiast' ); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                    </form><!-- /#bl-signup-form -->

                </div><!-- /.event-signup-box -->

            <?php endif; ?>

        </div><!-- /.event-signup-section -->


        <!-- ── BACK LINK ───────────────────────── -->
        <div style="margin-top:48px;padding-top:24px;border-top:1px solid var(--surface-3);">
            <a href="<?php echo esc_url( get_post_type_archive_link( 'bl_event' ) ); ?>"
               class="bl-btn bl-btn--ghost bl-btn--sm">
                ← <?php esc_html_e( 'All Events', 'blusiast' ); ?>
            </a>
        </div>

    </div>
</div>

<?php get_footer(); ?>
