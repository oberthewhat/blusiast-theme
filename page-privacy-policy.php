<?php
/**
 * Template Name: Privacy Policy
 * page-privacy-policy.php — Blusiast Privacy Policy page
 */

get_header();
$updated = 'April 29, 2026';
?>

<style>
.privacy-hero {
    background: var(--black);
    padding: 72px 0 56px;
    border-bottom: 1px solid var(--surface-2);
}
.privacy-hero__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 14px;
    display: block;
}
.privacy-hero__title {
    font-size: clamp(2rem, 5vw, 3rem);
    font-weight: 900;
    color: var(--white);
    margin: 0 0 12px;
}
.privacy-hero__meta {
    font-size: 13px;
    color: var(--gray-3);
}

.privacy-body {
    max-width: 760px;
    margin: 0 auto;
    padding: 64px 32px 96px;
}

.privacy-toc {
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-left: 3px solid var(--red);
    border-radius: 0 8px 8px 0;
    padding: 24px 28px;
    margin-bottom: 56px;
}
.privacy-toc__title {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--red);
    margin: 0 0 14px;
}
.privacy-toc__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.privacy-toc__list li a {
    font-size: 14px;
    color: var(--gray-2);
    text-decoration: none;
    transition: color .2s;
}
.privacy-toc__list li a:hover { color: var(--white); }
.privacy-toc__list li::before {
    content: '—';
    color: var(--surface-3, #333);
    margin-right: 10px;
    font-size: 12px;
}

.privacy-section {
    margin-bottom: 52px;
    scroll-margin-top: 80px;
}
.privacy-section__title {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--white);
    margin: 0 0 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--surface-2);
    display: flex;
    align-items: center;
    gap: 12px;
}
.privacy-section__title::before {
    content: '';
    display: block;
    width: 4px;
    height: 1.1em;
    background: var(--red);
    border-radius: 2px;
    flex-shrink: 0;
}
.privacy-section p {
    font-size: 1rem;
    line-height: 1.8;
    color: var(--gray-2);
    margin: 0 0 16px;
}
.privacy-section p:last-child { margin-bottom: 0; }
.privacy-section ul {
    padding-left: 0;
    margin: 0 0 16px;
    list-style: none;
}
.privacy-section ul li {
    font-size: 1rem;
    line-height: 1.75;
    color: var(--gray-2);
    padding: 4px 0 4px 20px;
    position: relative;
}
.privacy-section ul li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 14px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--red);
    flex-shrink: 0;
}
.privacy-section a {
    color: var(--red);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.privacy-section strong {
    color: var(--white);
    font-weight: 700;
}

.privacy-contact-box {
    background: var(--surface-1);
    border: 1px solid var(--surface-2);
    border-radius: 10px;
    padding: 32px;
    text-align: center;
    margin-top: 56px;
}
.privacy-contact-box h3 {
    font-size: 1.125rem;
    font-weight: 800;
    color: var(--white);
    margin: 0 0 10px;
}
.privacy-contact-box p {
    font-size: .9375rem;
    color: var(--gray-2);
    margin: 0 0 20px;
    line-height: 1.7;
}

@media (max-width: 600px) {
    .privacy-body { padding: 40px 20px 64px; }
    .privacy-hero { padding: 56px 0 40px; }
}
</style>


<!-- ── HERO ── -->
<section class="privacy-hero">
    <div class="container">
        <span class="privacy-hero__label">Legal</span>
        <h1 class="privacy-hero__title">Privacy Policy</h1>
        <p class="privacy-hero__meta">Last updated: <?php echo esc_html( $updated ); ?></p>
    </div>
</section>


<!-- ── BODY ── -->
<div class="privacy-body">

    <!-- Table of Contents -->
    <div class="privacy-toc">
        <p class="privacy-toc__title">Contents</p>
        <ol class="privacy-toc__list">
            <li><a href="#who-we-are">Who We Are</a></li>
            <li><a href="#information-we-collect">Information We Collect</a></li>
            <li><a href="#how-we-use-it">How We Use Your Information</a></li>
            <li><a href="#payment-processing">Payment Processing</a></li>
            <li><a href="#third-parties">Third-Party Services</a></li>
            <li><a href="#cookies">Cookies</a></li>
            <li><a href="#your-rights">Your Rights</a></li>
            <li><a href="#data-retention">Data Retention</a></li>
            <li><a href="#childrens-privacy">Children's Privacy</a></li>
            <li><a href="#changes">Changes to This Policy</a></li>
            <li><a href="#contact">Contact Us</a></li>
        </ol>
    </div>

    <!-- 1 -->
    <div class="privacy-section" id="who-we-are">
        <h2 class="privacy-section__title">Who We Are</h2>
        <p>Blusiast ("we," "us," or "our") is a community organization for Black roller coaster and theme park enthusiasts and their allies. We operate the website located at <strong>blusiast.org</strong>, including the member portal, event ticketing system, coaster reviews, gallery, and related features.</p>
        <p>This Privacy Policy explains how we collect, use, and protect your personal information when you use our website and services. By creating an account or using our site, you agree to the practices described here.</p>
    </div>

    <!-- 2 -->
    <div class="privacy-section" id="information-we-collect">
        <h2 class="privacy-section__title">Information We Collect</h2>
        <p><strong>Information you provide directly:</strong></p>
        <ul>
            <li>Name and email address when you register for an account</li>
            <li>Phone number, zip code, and state when completing your member profile</li>
            <li>Profile photo, bio, home park, favorite coaster, and social media handles if you choose to add them</li>
            <li>Coaster reviews and ratings you submit</li>
            <li>Photos you submit to our gallery</li>
            <li>Messages you send through our contact or help forms</li>
            <li>Event registration details including name, email, and phone number</li>
        </ul>
        <p><strong>Information collected automatically:</strong></p>
        <ul>
            <li>Login activity and session data</li>
            <li>Pages you visit and features you use on our site</li>
            <li>Browser type and device information</li>
            <li>IP address</li>
            <li>Cookies and similar tracking technologies (see <a href="#cookies">Cookies</a> section)</li>
        </ul>
        <p><strong>Information from third-party sign-in:</strong> If you sign in using Google, Facebook, or Apple, we receive your name, email address, and profile photo from that provider, subject to their privacy policies and the permissions you grant.</p>
    </div>

    <!-- 3 -->
    <div class="privacy-section" id="how-we-use-it">
        <h2 class="privacy-section__title">How We Use Your Information</h2>
        <p>We use the information we collect to:</p>
        <ul>
            <li>Create and manage your member account</li>
            <li>Generate your member ID card and QR code for event check-in</li>
            <li>Process event ticket purchases and confirm registrations</li>
            <li>Send transactional emails including ticket confirmations, account notices, and event reminders</li>
            <li>Display your profile and reviews to other members (subject to your privacy settings)</li>
            <li>Moderate gallery submissions and coaster reviews</li>
            <li>Respond to your support requests and messages</li>
            <li>Improve our website and services</li>
            <li>Communicate with you about Blusiast news, events, and community updates</li>
        </ul>
        <p>We do not sell your personal information to third parties. We do not use your data for advertising purposes.</p>
    </div>

    <!-- 4 -->
    <div class="privacy-section" id="payment-processing">
        <h2 class="privacy-section__title">Payment Processing</h2>
        <p>All event ticket payments are processed by <strong>Stripe</strong>, a third-party payment processor. When you purchase a ticket, you are redirected to Stripe's hosted checkout page. <strong>Blusiast never sees, stores, or has access to your full credit card number, CVV, or banking information.</strong></p>
        <p>Stripe collects and processes your payment information in accordance with their own Privacy Policy, available at <a href="https://stripe.com/privacy" target="_blank" rel="noopener">stripe.com/privacy</a>. Stripe is PCI-DSS compliant.</p>
        <p>We do retain a record of your transaction for our own records, including the amount paid, the event purchased, and a reference ID from Stripe. This is used for check-in, refund processing, and accounting purposes.</p>
    </div>

    <!-- 5 -->
    <div class="privacy-section" id="third-parties">
        <h2 class="privacy-section__title">Third-Party Services</h2>
        <p>We use the following third-party services to operate Blusiast. Each has its own privacy policy governing how they handle data:</p>
        <ul>
            <li><strong>Stripe</strong> — Payment processing. <a href="https://stripe.com/privacy" target="_blank" rel="noopener">Privacy Policy</a></li>
            <li><strong>Brevo (formerly Sendinblue)</strong> — Transactional email delivery. <a href="https://www.brevo.com/legal/privacypolicy/" target="_blank" rel="noopener">Privacy Policy</a></li>
            <li><strong>Google</strong> — Optional sign-in via Google OAuth. <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Privacy Policy</a></li>
            <li><strong>Facebook</strong> — Optional sign-in via Facebook Login. <a href="https://www.facebook.com/privacy/policy/" target="_blank" rel="noopener">Privacy Policy</a></li>
            <li><strong>Apple</strong> — Optional sign-in via Sign in with Apple. <a href="https://www.apple.com/legal/privacy/" target="_blank" rel="noopener">Privacy Policy</a></li>
            <li><strong>GoDaddy / Cloudflare</strong> — Web hosting and CDN infrastructure.</li>
        </ul>
        <p>We do not share your personal information with these services beyond what is necessary to provide their function.</p>
    </div>

    <!-- 6 -->
    <div class="privacy-section" id="cookies">
        <h2 class="privacy-section__title">Cookies</h2>
        <p>Our website uses cookies — small text files stored on your device — to keep you logged in and remember your preferences. Specifically we use:</p>
        <ul>
            <li><strong>Session cookies</strong> — Required for you to stay logged in as a member. These expire when you close your browser or log out.</li>
            <li><strong>Authentication cookies</strong> — Set by WordPress to maintain your login session across visits.</li>
            <li><strong>Security cookies</strong> — Used to protect forms from cross-site request forgery (CSRF).</li>
        </ul>
        <p>We do not use advertising cookies, tracking pixels, or third-party analytics cookies. You can disable cookies in your browser settings, but doing so will prevent you from logging into your member account.</p>
    </div>

    <!-- 7 -->
    <div class="privacy-section" id="your-rights">
        <h2 class="privacy-section__title">Your Rights</h2>
        <p>You have the right to:</p>
        <ul>
            <li><strong>Access</strong> — Request a copy of the personal information we hold about you</li>
            <li><strong>Correction</strong> — Update or correct your information through your member portal profile at any time</li>
            <li><strong>Deletion</strong> — Request that we delete your account and associated personal data</li>
            <li><strong>Opt out</strong> — Unsubscribe from non-transactional communications at any time</li>
            <li><strong>Portability</strong> — Request your data in a portable format</li>
        </ul>
        <p>To exercise any of these rights, contact us at <a href="mailto:info@blusiast.org">info@blusiast.org</a>. We will respond within 30 days. Note that some data may be retained for legal or accounting purposes even after account deletion.</p>
    </div>

    <!-- 8 -->
    <div class="privacy-section" id="data-retention">
        <h2 class="privacy-section__title">Data Retention</h2>
        <p>We retain your personal information for as long as your account is active or as needed to provide our services. Specifically:</p>
        <ul>
            <li>Member profile data is retained until you request account deletion</li>
            <li>Event registration and payment records are retained for a minimum of 3 years for accounting and legal compliance</li>
            <li>Gallery submissions and coaster reviews are retained until you request their removal or your account is deleted</li>
            <li>Contact form submissions are retained for up to 12 months</li>
        </ul>
    </div>

    <!-- 9 -->
    <div class="privacy-section" id="childrens-privacy">
        <h2 class="privacy-section__title">Children's Privacy</h2>
        <p>Blusiast is open to families and enthusiasts of all ages. However, children under the age of 13 may not create an account or provide personal information without verifiable parental consent. If you believe a child under 13 has provided us with personal information without consent, please contact us at <a href="mailto:info@blusiast.org">info@blusiast.org</a> and we will promptly delete that information.</p>
    </div>

    <!-- 10 -->
    <div class="privacy-section" id="changes">
        <h2 class="privacy-section__title">Changes to This Policy</h2>
        <p>We may update this Privacy Policy from time to time. When we do, we will update the "Last updated" date at the top of this page. For significant changes, we will notify members via email or a notice in the member portal. Your continued use of Blusiast after any changes constitutes your acceptance of the updated policy.</p>
    </div>

    <!-- 11 -->
    <div class="privacy-section" id="contact">
        <h2 class="privacy-section__title">Contact Us</h2>
        <p>If you have any questions, concerns, or requests regarding this Privacy Policy or how we handle your data, please reach out:</p>
        <ul>
            <li><strong>Email:</strong> <a href="mailto:info@blusiast.org">info@blusiast.org</a></li>
            <li><strong>Website:</strong> <a href="<?php echo esc_url( home_url('/contact') ); ?>">blusiast.org/contact</a></li>
        </ul>
    </div>

    <!-- Contact CTA -->
    <div class="privacy-contact-box">
        <h3>Questions about your data?</h3>
        <p>We're a real community with real people behind it. If you have any privacy concerns, reach out directly and we'll get back to you.</p>
        <a href="<?php echo esc_url( home_url('/contact') ); ?>" class="bl-btn bl-btn--primary">Contact Us</a>
    </div>

</div>

<?php get_footer(); ?>
