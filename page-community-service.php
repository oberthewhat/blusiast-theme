<?php
/**
 * Template Name: Community Service
 * page-community-service.php — Blusiast Community Service & Giving Back page
 *
 * Sections:
 *  1. Hero
 *  2. Mission statement / intro with image slot
 *  3. Why it matters (values cards)
 *  4. Ways we're looking to give back (aspirational opportunities)
 *  5. Contact / interest form
 */

get_header();

// ── Form handling
$sent  = false;
$error = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['bl_cs_nonce'] ) ) {
    if ( ! wp_verify_nonce( $_POST['bl_cs_nonce'], 'bl_community_service_form' ) ) {
        $error = 'Security check failed. Please try again.';
    } elseif ( ! blusiast_recaptcha_verify_post_form( 'community_service' ) ) {
        $error = 'reCAPTCHA verification failed. Please try again.';
    } else {
        $name         = sanitize_text_field( $_POST['cs_name']         ?? '' );
        $email        = sanitize_email(      $_POST['cs_email']        ?? '' );
        $org          = sanitize_text_field( $_POST['cs_org']          ?? '' );
        $opportunity  = sanitize_text_field( $_POST['cs_opportunity']  ?? '' );
        $message      = sanitize_textarea_field( $_POST['cs_message']  ?? '' );

        if ( ! $name || ! $email || ! $message ) {
            $error = 'Please fill in all required fields.';
        } elseif ( ! is_email( $email ) ) {
            $error = 'Please enter a valid email address.';
        } else {
            $to           = get_option( 'admin_email' );
            $subject_line = 'Blusiast Community Service Inquiry — ' . $name;
            $body         = "Name:         {$name}\nEmail:        {$email}\nOrganization: {$org}\nOpportunity:  {$opportunity}\n\nMessage:\n{$message}";
            $headers      = [
                'Content-Type: text/plain; charset=UTF-8',
                'Reply-To: ' . $name . ' <' . $email . '>',
            ];
            wp_mail( $to, $subject_line, $body, $headers );
            $sent = true;
        }
    }
}

// ── Pull the featured image set on this page (if any)
$page_thumb = get_the_post_thumbnail_url( get_the_ID(), 'large' );

?>

<style>

/* ══════════════════════════════════════
   COMMUNITY SERVICE — STYLES
   ══════════════════════════════════════ */

/* ── HERO ── */
.cs-hero {
    background: var(--black);
    padding: 88px 0 72px;
    position: relative;
    overflow: hidden;
}
.cs-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 30% 60%, rgba(204,0,0,.07) 0%, transparent 65%);
    pointer-events: none;
}
.cs-hero__inner {
    position: relative;
    z-index: 2;
    max-width: 680px;
}
.cs-hero__eyebrow {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}
.cs-hero__line {
    display: block;
    width: 32px;
    height: 2px;
    background: var(--red);
}
.cs-hero__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: var(--red);
}
.cs-hero__title {
    font-size: clamp(2.25rem, 6vw, 3.75rem);
    font-weight: 900;
    line-height: 1.1;
    color: var(--white);
    margin: 0 0 20px;
}
.cs-hero__desc {
    font-size: 1.0625rem;
    line-height: 1.75;
    color: var(--gray-2);
    max-width: 580px;
    margin: 0;
}

/* ── INTRO (text + image) ── */
.cs-intro {
    padding: 80px 0;
    border-bottom: 1px solid var(--surface-2);
}
.cs-intro__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 64px;
    align-items: center;
}
.cs-intro__text {}
.cs-intro__overline {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 16px;
}
.cs-intro__heading {
    font-size: clamp(1.625rem, 3vw, 2.25rem);
    font-weight: 800;
    line-height: 1.2;
    color: var(--white);
    margin: 0 0 20px;
}
.cs-intro__body {
    font-size: 1rem;
    line-height: 1.8;
    color: var(--gray-2);
    margin: 0 0 16px;
}
.cs-intro__body:last-child { margin-bottom: 0; }

/* ── IMAGE SLOT ── */
.cs-intro__image-wrap {
    position: relative;
}
.cs-intro__image {
    width: 100%;
    aspect-ratio: 4/3;
    object-fit: cover;
    border-radius: 12px;
    display: block;
}
.cs-intro__image-placeholder {
    width: 100%;
    aspect-ratio: 4/3;
    background: var(--surface-1);
    border: 2px dashed var(--surface-2);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: var(--gray-3);
    font-size: .875rem;
    text-align: center;
    padding: 24px;
}
.cs-intro__image-placeholder .cs-img-icon {
    font-size: 2.5rem;
}
.cs-intro__image-placeholder p {
    margin: 0;
    line-height: 1.5;
    color: var(--gray-3);
    font-size: .8125rem;
}
.cs-intro__image-placeholder strong {
    display: block;
    color: var(--gray-2);
    font-size: .9375rem;
    margin-bottom: 4px;
}

/* ── VALUES / WHY IT MATTERS ── */
.cs-values {
    padding: 80px 0;
    border-bottom: 1px solid var(--surface-2);
}
.cs-values__header {
    text-align: center;
    margin-bottom: 48px;
}
.cs-values__header .bl-label { margin-bottom: 12px; display: block; }
.cs-values__heading {
    font-size: clamp(1.625rem, 3vw, 2.25rem);
    font-weight: 800;
    color: var(--white);
    margin: 0;
}
.cs-values__grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}
.cs-value-card {
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-radius: 10px;
    padding: 32px 28px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    transition: border-color .2s;
}
.cs-value-card:hover { border-color: var(--red); }
.cs-value-card__icon {
    width: 48px;
    height: 48px;
    background: rgba(204,0,0,.1);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}
.cs-value-card__title {
    font-size: 1.0625rem;
    font-weight: 700;
    color: var(--white);
}
.cs-value-card__body {
    font-size: .9375rem;
    line-height: 1.7;
    color: var(--gray-2);
    flex: 1;
}

/* ── OPPORTUNITIES ── */
.cs-opps {
    padding: 80px 0;
    border-bottom: 1px solid var(--surface-2);
}
.cs-opps__header {
    margin-bottom: 48px;
}
.cs-opps__overline {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 12px;
}
.cs-opps__heading {
    font-size: clamp(1.625rem, 3vw, 2.25rem);
    font-weight: 800;
    color: var(--white);
    margin: 0 0 12px;
}
.cs-opps__subhead {
    font-size: 1rem;
    color: var(--gray-2);
    line-height: 1.7;
    max-width: 580px;
}
.cs-opps__list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}
.cs-opp-item {
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-left: 3px solid var(--red);
    border-radius: 0 10px 10px 0;
    padding: 24px 28px;
    display: flex;
    gap: 20px;
    align-items: flex-start;
}
.cs-opp-item__icon {
    font-size: 1.75rem;
    flex-shrink: 0;
    line-height: 1;
    margin-top: 2px;
}
.cs-opp-item__title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--white);
    margin-bottom: 6px;
}
.cs-opp-item__body {
    font-size: .875rem;
    line-height: 1.65;
    color: var(--gray-2);
    margin: 0;
}
.cs-opp-badge {
    display: inline-block;
    margin-top: 8px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 3px;
    background: rgba(255,255,255,.06);
    color: var(--gray-3);
}

/* ── CONTACT FORM ── */
.cs-form-section {
    padding: 80px 0 96px;
}
.cs-form-section__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 64px;
    align-items: start;
}
.cs-form-info {}
.cs-form-info__overline {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 12px;
}
.cs-form-info__heading {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--white);
    margin: 0 0 16px;
}
.cs-form-info__body {
    font-size: 1rem;
    line-height: 1.75;
    color: var(--gray-2);
    margin: 0 0 32px;
}
.cs-form-bullets {
    display: flex;
    flex-direction: column;
    gap: 14px;
    list-style: none;
    padding: 0;
    margin: 0;
}
.cs-form-bullets li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: .9375rem;
    color: var(--gray-2);
    line-height: 1.5;
}
.cs-form-bullets__icon {
    color: var(--red);
    flex-shrink: 0;
    margin-top: 1px;
}

/* Form card */
.cs-form-card {
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-radius: 12px;
    padding: 40px;
}
.cs-form-card__title {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--white);
    margin: 0 0 6px;
}
.cs-form-card__sub {
    font-size: .875rem;
    color: var(--gray-3);
    margin: 0 0 32px;
}

/* Reuse contact form styles via shared classes */
.cs-field { margin-bottom: 20px; }
.cs-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--gray-2);
    margin-bottom: 7px;
    letter-spacing: .03em;
}
.cs-required { color: var(--red); margin-left: 2px; }
.cs-input {
    width: 100%;
    padding: 12px 14px;
    background: var(--black);
    border: 1px solid var(--surface-2);
    border-radius: 6px;
    color: var(--white);
    font-size: .9375rem;
    font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}
.cs-input::placeholder { color: var(--gray-4, #555); }
.cs-input:focus {
    outline: none;
    border-color: var(--red);
    box-shadow: 0 0 0 3px rgba(204,0,0,.15);
}
.cs-textarea { min-height: 130px; resize: vertical; }
.cs-select { appearance: none; cursor: pointer; }

.cs-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.cs-success {
    text-align: center;
    padding: 32px 24px;
}
.cs-success__icon {
    width: 56px;
    height: 56px;
    background: rgba(204,0,0,.12);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    color: var(--red);
}
.cs-success__title {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--white);
    margin: 0 0 10px;
}
.cs-success__body {
    font-size: .9375rem;
    color: var(--gray-2);
    line-height: 1.65;
    margin: 0 0 24px;
}
.cs-error {
    background: rgba(204,0,0,.1);
    border: 1px solid rgba(204,0,0,.3);
    border-radius: 6px;
    color: #ff6b6b;
    font-size: .875rem;
    padding: 12px 16px;
    margin-bottom: 20px;
}

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
    .cs-intro__grid        { grid-template-columns: 1fr; gap: 40px; }
    .cs-intro__image-wrap  { order: -1; }
    .cs-values__grid       { grid-template-columns: repeat(2, 1fr); }
    .cs-opps__list         { grid-template-columns: 1fr; }
    .cs-form-section__grid { grid-template-columns: 1fr; gap: 40px; }
}
@media (max-width: 600px) {
    .cs-values__grid { grid-template-columns: 1fr; }
    .cs-row          { grid-template-columns: 1fr; }
    .cs-form-card    { padding: 28px 24px; }
    .cs-hero         { padding: 64px 0 56px; }
}
</style>


<!-- ══════════════════════════════════════
     1. HERO
     ══════════════════════════════════════ -->
<section class="cs-hero">
    <div class="container">
        <div class="cs-hero__inner">
            <div class="cs-hero__eyebrow">
                <span class="cs-hero__line" aria-hidden="true"></span>
                <span class="cs-hero__label">Giving Back</span>
            </div>
            <h1 class="cs-hero__title">
                Community <span style="color:var(--red);">Service</span><br>
                &amp; Giving Back
            </h1>
            <p class="cs-hero__desc">
                Blusiast was built on togetherness — and togetherness doesn't stop at the park gates. We're actively exploring opportunities to give back to the communities that make our culture what it is.
            </p>
        </div>
    </div>
</section>


<!-- ══════════════════════════════════════
     2. INTRO — text + image
     ══════════════════════════════════════ -->
<section class="cs-intro section">
    <div class="container">
        <div class="cs-intro__grid">

            <!-- Text -->
            <div class="cs-intro__text">
                <p class="cs-intro__overline">Where We Stand</p>
                <h2 class="cs-intro__heading">We're Just Getting Started</h2>
                <p class="cs-intro__body">
                    Blusiast is a young organization with a big heart. We haven't launched formal community service programs yet — but it's something we feel deeply called to do. Our roots are in diversity, inclusion, and building a culture of togetherness, and we know that means showing up beyond the coaster community.
                </p>
                <p class="cs-intro__body">
                    We're in the discovery phase — exploring partnerships, causes, and opportunities that align with who we are. If you have an idea or a connection that could help us get started, we'd love to hear from you.
                </p>
                <p class="cs-intro__body" style="color:var(--gray-1);">
                    <strong style="color:var(--white);">This is an open invitation.</strong> If your organization is looking for engaged community partners, or if you're a Blusiast member with a cause close to your heart — reach out below.
                </p>
            </div>

            <!-- Image slot — set a featured image on this page in WordPress, or leave blank for placeholder -->
            <div class="cs-intro__image-wrap">
                <?php if ( $page_thumb ) : ?>
                    <img src="<?php echo esc_url( $page_thumb ); ?>"
                         alt="Blusiast Community Service"
                         class="cs-intro__image">
                <?php else : ?>
                    <div class="cs-intro__image-placeholder">
                        <span class="cs-img-icon">🤝</span>
                        <div>
                            <strong>Add Your Photo Here</strong>
                            <p>Set a Featured Image on this page in WordPress to display a community photo in this space.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>


<!-- ══════════════════════════════════════
     3. WHY IT MATTERS — values cards
     ══════════════════════════════════════ -->
<section class="cs-values section">
    <div class="container">

        <div class="cs-values__header">
            <span class="bl-label">Our Commitment</span>
            <h2 class="cs-values__heading">Why This Matters to Blusiast</h2>
        </div>

        <div class="cs-values__grid">

            <div class="cs-value-card">
                <div class="cs-value-card__icon">❤️</div>
                <div>
                    <div class="cs-value-card__title">Community &amp; Togetherness</div>
                    <p class="cs-value-card__body">One of our core values. Real community means showing up — not just for the fun moments, but when it counts.</p>
                </div>
            </div>

            <div class="cs-value-card">
                <div class="cs-value-card__icon">✊</div>
                <div>
                    <div class="cs-value-card__title">Diversity &amp; Inclusion</div>
                    <p class="cs-value-card__body">We exist to make spaces more welcoming. Community service is one of the most powerful ways to live that mission beyond theme parks.</p>
                </div>
            </div>

            <div class="cs-value-card">
                <div class="cs-value-card__icon">🌍</div>
                <div>
                    <div class="cs-value-card__title">Global Impact, Local Roots</div>
                    <p class="cs-value-card__body">Blusiast is building a global brand — but real impact happens locally, in neighborhoods and communities across the country.</p>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ══════════════════════════════════════
     4. OPPORTUNITIES WE'RE EXPLORING
     ══════════════════════════════════════ -->
<section class="cs-opps section">
    <div class="container">

        <div class="cs-opps__header">
            <p class="cs-opps__overline">Looking Ahead</p>
            <h2 class="cs-opps__heading">Areas We're Exploring</h2>
            <p class="cs-opps__subhead">We don't have programs in place yet — but these are the kinds of opportunities that align with our heart and our values.</p>
        </div>

        <div class="cs-opps__list">

            <div class="cs-opp-item">
                <div class="cs-opp-item__icon">🎒</div>
                <div>
                    <div class="cs-opp-item__title">Youth &amp; Families</div>
                    <p class="cs-opp-item__body">Sponsoring or subsidizing park trips for underserved youth who may never have experienced a theme park. Joy is for everyone.</p>
                    <span class="cs-opp-badge">Exploring</span>
                </div>
            </div>

            <div class="cs-opp-item">
                <div class="cs-opp-item__icon">📚</div>
                <div>
                    <div class="cs-opp-item__title">Education &amp; Scholarships</div>
                    <p class="cs-opp-item__body">Supporting Black students pursuing careers in hospitality, engineering, entertainment, or related fields.</p>
                    <span class="cs-opp-badge">Exploring</span>
                </div>
            </div>

            <div class="cs-opp-item">
                <div class="cs-opp-item__icon">🤝</div>
                <div>
                    <div class="cs-opp-item__title">Community Partnerships</div>
                    <p class="cs-opp-item__body">Partnering with existing nonprofits and organizations whose values align with ours — amplifying what's already working.</p>
                    <span class="cs-opp-badge">Exploring</span>
                </div>
            </div>

            <div class="cs-opp-item">
                <div class="cs-opp-item__icon">🌱</div>
                <div>
                    <div class="cs-opp-item__title">Environmental &amp; Park Stewardship</div>
                    <p class="cs-opp-item__body">Giving back to the green spaces and parks our community loves — local park cleanups, conservation awareness, and more.</p>
                    <span class="cs-opp-badge">Exploring</span>
                </div>
            </div>

            <div class="cs-opp-item">
                <div class="cs-opp-item__icon">🏆</div>
                <div>
                    <div class="cs-opp-item__title">Recognition &amp; Awards</div>
                    <p class="cs-opp-item__body">Celebrating community members and organizations doing meaningful work — through spotlights, awards, and amplification.</p>
                    <span class="cs-opp-badge">Planned</span>
                </div>
            </div>

            <div class="cs-opp-item">
                <div class="cs-opp-item__icon">💬</div>
                <div>
                    <div class="cs-opp-item__title">Member-Led Initiatives</div>
                    <p class="cs-opp-item__body">The best ideas will come from our community. We want to empower members to lead the causes they care about under the Blusiast umbrella.</p>
                    <span class="cs-opp-badge">Coming Soon</span>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ══════════════════════════════════════
     5. CONTACT / INTEREST FORM
     ══════════════════════════════════════ -->
<section class="cs-form-section" id="connect">
    <div class="container">
        <div class="cs-form-section__grid">

            <!-- Left: info -->
            <div class="cs-form-info">
                <p class="cs-form-info__overline">Get Involved</p>
                <h2 class="cs-form-info__heading">Know an Opportunity? Let's Talk.</h2>
                <p class="cs-form-info__body">
                    Whether you're a nonprofit looking for partners, a Blusiast member with a passion project, or someone who simply wants to help point us in the right direction — we'd love to hear from you.
                </p>

                <ul class="cs-form-bullets">
                    <li>
                        <svg class="cs-form-bullets__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        Tell us about community organizations you'd love to see us work with
                    </li>
                    <li>
                        <svg class="cs-form-bullets__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        Share a cause or initiative you're personally involved in
                    </li>
                    <li>
                        <svg class="cs-form-bullets__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        Propose a partnership or collaboration
                    </li>
                    <li>
                        <svg class="cs-form-bullets__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        Simply share your ideas — no formal proposal needed
                    </li>
                </ul>
            </div>

            <!-- Right: form -->
            <div class="cs-form-card">

                <?php if ( $sent ) : ?>
                    <div class="cs-success">
                        <div class="cs-success__icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div class="cs-success__title">Message Received!</div>
                        <p class="cs-success__body">Thanks for reaching out about community service. We'll read every message carefully and follow up if there's a fit. Ride on! 🎢</p>
                        <a href="<?php echo esc_url( get_permalink() ); ?>#connect" class="bl-btn bl-btn--ghost bl-btn--sm">Send Another</a>
                    </div>

                <?php else : ?>

                    <h3 class="cs-form-card__title">Share Your Idea or Connection</h3>
                    <p class="cs-form-card__sub">We read every message. No pitch deck needed.</p>

                    <?php if ( $error ) : ?>
                        <div class="cs-error"><?php echo esc_html( $error ); ?></div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( get_permalink() ); ?>#connect" data-recaptcha-action="community_service">
                        <?php wp_nonce_field( 'bl_community_service_form', 'bl_cs_nonce' ); ?>

                        <div class="cs-row">
                            <div class="cs-field">
                                <label class="cs-label" for="cs_name">Your Name <span class="cs-required">*</span></label>
                                <input class="cs-input" type="text" id="cs_name" name="cs_name"
                                       value="<?php echo esc_attr( $_POST['cs_name'] ?? '' ); ?>"
                                       placeholder="Full name" required>
                            </div>
                            <div class="cs-field">
                                <label class="cs-label" for="cs_email">Email Address <span class="cs-required">*</span></label>
                                <input class="cs-input" type="email" id="cs_email" name="cs_email"
                                       value="<?php echo esc_attr( $_POST['cs_email'] ?? '' ); ?>"
                                       placeholder="your@email.com" required>
                            </div>
                        </div>

                        <div class="cs-field">
                            <label class="cs-label" for="cs_org">Organization / Affiliation</label>
                            <input class="cs-input" type="text" id="cs_org" name="cs_org"
                                   value="<?php echo esc_attr( $_POST['cs_org'] ?? '' ); ?>"
                                   placeholder="Nonprofit, company, or personal (optional)">
                        </div>

                        <div class="cs-field">
                            <label class="cs-label" for="cs_opportunity">Type of Opportunity</label>
                            <select class="cs-input cs-select" id="cs_opportunity" name="cs_opportunity">
                                <option value="">— What's on your mind? —</option>
                                <option value="Nonprofit Partnership"   <?php selected( $_POST['cs_opportunity'] ?? '', 'Nonprofit Partnership'   ); ?>>Nonprofit Partnership</option>
                                <option value="Youth / Families"        <?php selected( $_POST['cs_opportunity'] ?? '', 'Youth / Families'        ); ?>>Youth &amp; Families</option>
                                <option value="Education / Scholarship" <?php selected( $_POST['cs_opportunity'] ?? '', 'Education / Scholarship' ); ?>>Education / Scholarship</option>
                                <option value="Member Initiative"       <?php selected( $_POST['cs_opportunity'] ?? '', 'Member Initiative'       ); ?>>Member-Led Initiative</option>
                                <option value="Volunteering"            <?php selected( $_POST['cs_opportunity'] ?? '', 'Volunteering'            ); ?>>Volunteer Opportunity</option>
                                <option value="Other"                   <?php selected( $_POST['cs_opportunity'] ?? '', 'Other'                   ); ?>>Other / General Idea</option>
                            </select>
                        </div>

                        <div class="cs-field">
                            <label class="cs-label" for="cs_message">Tell Us More <span class="cs-required">*</span></label>
                            <textarea class="cs-input cs-textarea" id="cs_message" name="cs_message"
                                      placeholder="Share the cause, the idea, or how you think Blusiast could help or get involved…"
                                      required><?php echo esc_textarea( $_POST['cs_message'] ?? '' ); ?></textarea>
                        </div>

                        <button type="submit" class="bl-btn bl-btn--primary" style="width:100%;justify-content:center;">
                            Send Message
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        </button>
                    </form>

                <?php endif; ?>

            </div>
        </div>
    </div>
</section>

<?php get_footer(); ?>
