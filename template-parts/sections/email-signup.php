<?php // template-parts/sections/email-signup.php ?>
<section class="email-signup">
    <div class="container">
        <div class="email-signup__inner">
            <div class="email-signup__text">
                <p class="bl-label"><?php esc_html_e( 'Stay Connected', 'blusiast' ); ?></p>
                <h2 class="bl-display-sm"><?php esc_html_e( 'Never Miss a Trip or Rope Drop', 'blusiast' ); ?></h2>
                <p class="bl-body-md"><?php esc_html_e( 'Trip announcements, park news, member spotlights — straight to your inbox.', 'blusiast' ); ?></p>
            </div>
            <form class="email-signup__form" action="#" method="post" aria-label="<?php esc_attr_e( 'Email signup', 'blusiast' ); ?>">
                <?php wp_nonce_field( 'blusiast_email_signup', 'email_signup_nonce' ); ?>
                <div class="email-signup__field-wrap">
                    <label for="signup-email" class="screen-reader-text">
                        <?php esc_html_e( 'Email address', 'blusiast' ); ?>
                    </label>
                    <input
                        type="email"
                        id="signup-email"
                        name="email"
                        class="email-signup__input"
                        placeholder="<?php esc_attr_e( 'Your email address', 'blusiast' ); ?>"
                        required
                        autocomplete="email"
                    >
                    <button type="submit" class="email-signup__submit bl-btn bl-btn--primary">
                        <?php esc_html_e( 'Subscribe', 'blusiast' ); ?>
                    </button>
                </div>
                <p class="email-signup__disclaimer">
                    <?php esc_html_e( 'No spam. Unsubscribe anytime.', 'blusiast' ); ?>
                </p>
            </form>
        </div>
    </div>
</section>
