<?php
/**
 * Event Sign-Up Modal
 * Included from single-bl_event.php when registration is open.
 * JS in main.js opens it via #bl-signup-trigger.
 *
 * @var int    $event_id   Post ID of the event.
 * @var string $event_name Event title (escaped before passing in).
 * @var string $event_date Formatted date string for display.
 * @var string $price      Price string (may be empty).
 */
?>
<div id="bl-signup-modal"
     class="bl-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="bl-modal-title"
     hidden>

    <div class="bl-modal__backdrop" data-modal-close></div>

    <div class="bl-modal__panel">

        <!-- Header -->
        <div class="bl-modal__header">
            <div>
                <p class="bl-label"><?php esc_html_e( 'Event Registration', 'blusiast' ); ?></p>
                <h2 class="bl-modal__title" id="bl-modal-title">
                    <?php echo esc_html( $event_name ); ?>
                </h2>
                <?php if ( $event_date ) : ?>
                    <p class="bl-modal__subtitle"><?php echo esc_html( $event_date ); ?></p>
                <?php endif; ?>
            </div>
            <button class="bl-modal__close" aria-label="<?php esc_attr_e( 'Close', 'blusiast' ); ?>" data-modal-close>
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M4 4l12 12M16 4L4 16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </button>
        </div>

        <!-- Success state (hidden until AJAX succeeds) -->
        <div id="bl-signup-success" class="bl-modal__success" hidden>
            <div class="bl-modal__success-icon">
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                    <circle cx="20" cy="20" r="19" stroke="var(--red)" stroke-width="1.5"/>
                    <path d="M11 20.5l6 6L29 14" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h3 class="bl-modal__success-title"><?php esc_html_e( "You're In!", 'blusiast' ); ?></h3>
            <p class="bl-modal__success-body">
                <?php esc_html_e( "We've got you on the list. Check your email for confirmation details.", 'blusiast' ); ?>
            </p>
            <?php if ( $price && strtolower( $price ) !== 'free' ) : ?>
                <p class="bl-modal__success-note">
                    <?php esc_html_e( 'Payment instructions will be sent to your email.', 'blusiast' ); ?>
                </p>
            <?php endif; ?>
            <button class="bl-btn bl-btn--ghost bl-btn--sm" data-modal-close style="margin-top:16px;">
                <?php esc_html_e( 'Close', 'blusiast' ); ?>
            </button>
        </div>

        <!-- Form -->
        <form id="bl-signup-form"
              class="bl-modal__form"
              novalidate
              data-event-id="<?php echo esc_attr( $event_id ); ?>">

            <?php wp_nonce_field( 'blusiast_event_signup', 'bl_nonce' ); ?>

            <div class="bl-form-row bl-form-row--two">
                <div class="bl-form-field">
                    <label class="bl-form-label" for="bl-first-name">
                        <?php esc_html_e( 'First Name', 'blusiast' ); ?>
                        <span aria-hidden="true">*</span>
                    </label>
                    <input class="bl-form-input"
                           type="text"
                           id="bl-first-name"
                           name="first_name"
                           autocomplete="given-name"
                           required>
                </div>
                <div class="bl-form-field">
                    <label class="bl-form-label" for="bl-last-name">
                        <?php esc_html_e( 'Last Name', 'blusiast' ); ?>
                        <span aria-hidden="true">*</span>
                    </label>
                    <input class="bl-form-input"
                           type="text"
                           id="bl-last-name"
                           name="last_name"
                           autocomplete="family-name"
                           required>
                </div>
            </div>

            <div class="bl-form-field">
                <label class="bl-form-label" for="bl-email">
                    <?php esc_html_e( 'Email Address', 'blusiast' ); ?>
                    <span aria-hidden="true">*</span>
                </label>
                <input class="bl-form-input"
                       type="email"
                       id="bl-email"
                       name="email"
                       autocomplete="email"
                       required>
            </div>

            <div class="bl-form-field">
                <label class="bl-form-label" for="bl-phone">
                    <?php esc_html_e( 'Phone Number', 'blusiast' ); ?>
                    <span class="bl-form-optional"><?php esc_html_e( '(optional)', 'blusiast' ); ?></span>
                </label>
                <input class="bl-form-input"
                       type="tel"
                       id="bl-phone"
                       name="phone"
                       autocomplete="tel">
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

            <?php if ( $price && strtolower( $price ) !== 'free' ) : ?>
            <div class="bl-form-note">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.2"/>
                    <path d="M8 7v4M8 5.5v.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
                <?php
                printf(
                    esc_html__( 'Cost: %s per person. Payment instructions will follow by email after registration.', 'blusiast' ),
                    '<strong>' . esc_html( $price ) . '</strong>'
                );
                ?>
            </div>
            <?php endif; ?>

            <div class="bl-form-field bl-form-field--check">
                <input type="checkbox"
                       id="bl-consent"
                       name="consent"
                       class="bl-form-checkbox"
                       required>
                <label for="bl-consent" class="bl-form-check-label">
                    <?php esc_html_e( 'I agree to receive event communications from Blusiast. We never spam.', 'blusiast' ); ?>
                </label>
            </div>

            <!-- Error message -->
            <div id="bl-signup-error" class="bl-form-error" hidden role="alert"></div>

            <div class="bl-modal__footer">
                <button type="submit" class="bl-btn bl-btn--primary bl-btn--lg" id="bl-signup-submit">
                    <span class="bl-btn__label">
                        <?php echo $price && strtolower( $price ) !== 'free'
                            ? esc_html__( 'Reserve My Spot', 'blusiast' )
                            : esc_html__( 'Sign Me Up — Free', 'blusiast' );
                        ?>
                    </span>
                    <span class="bl-btn__spinner" hidden aria-hidden="true"></span>
                    <?php blusiast_icon( 'arrow-right' ); ?>
                </button>
                <p class="bl-modal__fine-print">
                    <?php esc_html_e( 'Your info is only used for event coordination.', 'blusiast' ); ?>
                </p>
            </div>

        </form>

    </div><!-- /.bl-modal__panel -->
</div><!-- /#bl-signup-modal -->
