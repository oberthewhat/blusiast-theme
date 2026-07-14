<?php
/**
 * Template Name: Contact Us
 * page-contact.php — Contact page for Blusiast
 */

get_header();
?>

<!-- ════════════════════════════════════════════
     CONTACT HERO
     ════════════════════════════════════════════ -->

<section class="contact-hero">
    <div class="contact-hero__overlay" aria-hidden="true"></div>
    <div class="container" style="position:relative;z-index:2;">
        <p class="bl-label">Get In Touch</p>
        <h1 class="bl-display-lg contact-hero__heading">
            We'd Love to<br>
            <span class="bl-text-red">Hear From You</span>
        </h1>
        <p class="bl-body-lg contact-hero__sub">
            Questions, partnerships, event ideas, or just want to connect with the crew — we're here.
        </p>
    </div>
</section>


<!-- ════════════════════════════════════════════
     MAIN CONTENT — Form + Info Cards
     ════════════════════════════════════════════ -->

<section class="section contact-section">
    <div class="container">
        <div class="contact-grid">

            <!-- ── LEFT: Contact Form ── -->
            <div class="contact-form-wrap">
                <div class="contact-form-card">
                    <div class="contact-form-card__header">
                        <h2 class="contact-form-card__title">Send Us a Message</h2>
                        <p class="contact-form-card__sub">We typically respond within 24–48 hours.</p>
                    </div>

                    <?php
                    // Show success/error message after submit
                    $sent    = false;
                    $error   = '';

                    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['bl_contact_nonce'] ) ) {
                        if ( ! wp_verify_nonce( $_POST['bl_contact_nonce'], 'bl_contact_form' ) ) {
                            $error = 'Security check failed. Please try again.';
                        } elseif ( ! blusiast_recaptcha_verify_post_form( 'contact' ) ) {
                            $error = 'reCAPTCHA verification failed. Please try again.';
                        } else {
                            $name    = sanitize_text_field( $_POST['contact_name']    ?? '' );
                            $email   = sanitize_email(      $_POST['contact_email']   ?? '' );
                            $subject = sanitize_text_field( $_POST['contact_subject'] ?? '' );
                            $message = sanitize_textarea_field( $_POST['contact_message'] ?? '' );

                            if ( ! $name || ! $email || ! $message ) {
                                $error = 'Please fill in all required fields.';
                            } elseif ( ! is_email( $email ) ) {
                                $error = 'Please enter a valid email address.';
                            } else {
                                $to      = get_option( 'admin_email' );
                                $subject_line = 'Blusiast Contact: ' . ( $subject ?: 'General Inquiry' ) . ' — ' . $name;
                                $body    = "Name:    {$name}\nEmail:   {$email}\nSubject: {$subject}\n\nMessage:\n{$message}";
                                $headers = [
                                    'Content-Type: text/plain; charset=UTF-8',
                                    'Reply-To: ' . $name . ' <' . $email . '>',
                                ];

                                // Save to DB regardless of email success
                                global $wpdb;
                                $wpdb->insert(
                                    $wpdb->prefix . 'bl_contact_submissions',
                                    [
                                        'name'       => $name,
                                        'email'      => $email,
                                        'subject'    => $subject,
                                        'message'    => $message,
                                        'created_at' => blusiast_eastern_now(),
                                    ],
                                    [ '%s', '%s', '%s', '%s', '%s' ]
                                );

                                wp_mail( $to, $subject_line, $body, $headers );
                                $sent = true;
                            }
                        }
                    }
                    ?>

                    <?php if ( $sent ) : ?>
                        <div class="contact-success">
                            <div class="contact-success__icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <h3 class="contact-success__title">Message Sent!</h3>
                            <p class="contact-success__body">Thanks for reaching out. We'll get back to you within 24–48 hours. Ride on! 🎢</p>
                            <a href="<?php echo esc_url( get_permalink() ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm" style="margin-top:8px;">Send Another</a>
                        </div>
                    <?php else : ?>

                        <?php if ( $error ) : ?>
                            <div class="contact-form__error"><?php echo esc_html( $error ); ?></div>
                        <?php endif; ?>

                        <form class="contact-form" method="post" action="<?php echo esc_url( get_permalink() ); ?>#contact-form" id="contact-form" data-recaptcha-action="contact">
                            <?php wp_nonce_field( 'bl_contact_form', 'bl_contact_nonce' ); ?>

                            <div class="contact-form__row">
                                <div class="contact-field">
                                    <label class="contact-label" for="contact_name">Full Name <span class="contact-required">*</span></label>
                                    <input class="contact-input" type="text" id="contact_name" name="contact_name"
                                           value="<?php echo esc_attr( $_POST['contact_name'] ?? '' ); ?>"
                                           placeholder="Your name" required>
                                </div>
                                <div class="contact-field">
                                    <label class="contact-label" for="contact_email">Email Address <span class="contact-required">*</span></label>
                                    <input class="contact-input" type="email" id="contact_email" name="contact_email"
                                           value="<?php echo esc_attr( $_POST['contact_email'] ?? '' ); ?>"
                                           placeholder="your@email.com" required>
                                </div>
                            </div>

                            <div class="contact-field">
                                <label class="contact-label" for="contact_subject">Subject</label>
                                <select class="contact-input contact-select" id="contact_subject" name="contact_subject">
                                    <option value="">— Select a topic —</option>
                                    <option value="General Question"     <?php selected( $_POST['contact_subject'] ?? '', 'General Question'     ); ?>>General Question</option>
                                    <option value="Event Inquiry"        <?php selected( $_POST['contact_subject'] ?? '', 'Event Inquiry'        ); ?>>Event Inquiry</option>
                                    <option value="Membership"           <?php selected( $_POST['contact_subject'] ?? '', 'Membership'           ); ?>>Membership</option>
                                    <option value="Partnership / Sponsorship" <?php selected( $_POST['contact_subject'] ?? '', 'Partnership / Sponsorship' ); ?>>Partnership / Sponsorship</option>
                                    <option value="Media / Press"        <?php selected( $_POST['contact_subject'] ?? '', 'Media / Press'        ); ?>>Media / Press</option>
                                    <option value="Other"                <?php selected( $_POST['contact_subject'] ?? '', 'Other'                ); ?>>Other</option>
                                </select>
                            </div>

                            <div class="contact-field">
                                <label class="contact-label" for="contact_message">Message <span class="contact-required">*</span></label>
                                <textarea class="contact-input contact-textarea" id="contact_message" name="contact_message"
                                          placeholder="Tell us what's on your mind…" rows="6" required><?php echo esc_textarea( $_POST['contact_message'] ?? '' ); ?></textarea>
                            </div>

                            <button type="submit" class="bl-btn bl-btn--primary contact-submit">
                                Send Message
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            </button>
                        </form>

                    <?php endif; ?>
                </div>
            </div>

            <!-- ── RIGHT: Info Cards ── -->
            <div class="contact-info">

                <div class="contact-info-card">
                    <div class="contact-info-card__icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div>
                        <div class="contact-info-card__label">Email Us</div>
                        <a href="mailto:info@blusiast.org" class="contact-info-card__value">info@blusiast.org</a>
                        <div class="contact-info-card__note">We respond within 24–48 hours</div>
                    </div>
                </div>

                <div class="contact-info-card">
                    <div class="contact-info-card__icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2H3v16h5l4 4 4-4h5V2z"/></svg>
                    </div>
                    <div>
                        <div class="contact-info-card__label">Social Media</div>
                        <div class="contact-info-card__value">@Blusiast</div>
                        <div class="contact-info-card__note">DM us on Instagram or Facebook</div>
                    </div>
                </div>

                <div class="contact-info-card">
                    <div class="contact-info-card__icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="contact-info-card__label">Response Time</div>
                        <div class="contact-info-card__value">24–48 Hours</div>
                        <div class="contact-info-card__note">Monday – Sunday</div>
                    </div>
                </div>

                <!-- Already a member? -->
                <div class="contact-member-box">
                    <div class="contact-member-box__icon">🎢</div>
                    <div class="contact-member-box__title">Already a Member?</div>
                    <p class="contact-member-box__body">Use the Help section in your member portal for faster support from the team.</p>
                    <a href="<?php echo esc_url( home_url( '/member-portal' ) ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">Go to Portal →</a>
                </div>

            </div>
        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════
     SOCIAL STRIP
     ════════════════════════════════════════════ -->

<section class="contact-social section" style="padding-top:0;">
    <div class="container">
        <div class="contact-social__inner">
            <div class="contact-social__text">
                <p class="bl-label">Follow the Crew</p>
                <h2 class="bl-display-sm">Join the Conversation</h2>
            </div>
            <div class="contact-social__links">
                <a href="https://instagram.com/blusiast" target="_blank" rel="noopener" class="contact-social-link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                    Instagram
                </a>
                <a href="https://facebook.com/blusiast" target="_blank" rel="noopener" class="contact-social-link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    Facebook
                </a>
                <a href="https://youtube.com/@blusiast" target="_blank" rel="noopener" class="contact-social-link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58A2.78 2.78 0 0 0 3.41 19.54C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02"/></svg>
                    YouTube
                </a>
                <a href="https://tiktok.com/@blusiast" target="_blank" rel="noopener" class="contact-social-link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.27 6.27 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.75a4.85 4.85 0 0 1-1.01-.06z"/></svg>
                    TikTok
                </a>
            </div>
        </div>
    </div>
</section>

<?php get_footer(); ?>
