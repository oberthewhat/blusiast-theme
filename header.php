<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- PWA manifest for Add to Home Screen -->
    <link rel="manifest" href="<?php echo esc_url( home_url('/blusiast-manifest.json') ); ?>">
    <meta name="theme-color" content="#0d0d0d">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Blusiast">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main-content"><?php esc_html_e( 'Skip to content', 'blusiast' ); ?></a>

<header class="site-header" id="site-header" role="banner">
    <div class="site-header__inner container">

        <!-- Logo -->
        <a class="site-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" aria-label="<?php bloginfo( 'name' ); ?> — Home">
            <?php
            if ( has_custom_logo() ) :
                the_custom_logo();
            else :
            ?>
                <span class="site-header__logo-text">BLUSIAST</span>
            <?php endif; ?>
        </a>

        <!-- Primary Nav -->
        <nav class="site-nav" id="site-nav" role="navigation" aria-label="<?php esc_attr_e( 'Primary Navigation', 'blusiast' ); ?>">
            <?php
            wp_nav_menu( [
                'theme_location' => 'primary',
                'container'      => false,
                'menu_class'     => 'nav__list',
                'fallback_cb'    => 'blusiast_fallback_menu',
                'walker'         => new Blusiast_Nav_Walker(),
            ] );
            ?>
        </nav>

        <!-- CTA Buttons -->
        <div class="site-header__actions">
            <?php blusiast_header_account_buttons(); ?>
        </div>

        <!-- Mobile Menu Toggle -->
        <button class="nav-toggle" id="nav-toggle" aria-controls="site-nav" aria-expanded="false" aria-label="<?php esc_attr_e( 'Toggle navigation', 'blusiast' ); ?>">
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
        </button>

    </div><!-- /.site-header__inner -->
</header>

<?php if ( is_user_logged_in() ) : ?>
<!-- ── Mobile Bottom Bar (logged-in members only) ── -->
<?php
// Inject admin check-in tab CSS override when admin is logged in
if ( current_user_can( 'manage_options' ) ) :
?>
<style>
.mobile-member-bar { grid-template-columns: repeat(5, 1fr) !important; }
.mobile-member-bar__item--checkin {
    color: #cc0000 !important;
    position: relative;
}
.mobile-member-bar__item--checkin span {
    font-weight: 800;
}
</style>
<?php endif; ?>
<div class="mobile-member-bar" id="mobile-member-bar" role="navigation" aria-label="Member quick actions">
    <a href="<?php echo esc_url( blusiast_portal_url() ); ?>" class="mobile-member-bar__item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        <span>Portal</span>
    </a>
    <a href="<?php echo esc_url( blusiast_portal_url( 'id-card' ) ); ?>" class="mobile-member-bar__item mobile-member-bar__item--card">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>
        </svg>
        <span>My Card</span>
    </a>
    <a href="<?php echo esc_url( blusiast_portal_url( 'events' ) ); ?>" class="mobile-member-bar__item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        <span>Events</span>
    </a>
    <?php if ( current_user_can( 'manage_options' ) ) : ?>
    <a href="<?php echo esc_url( home_url( '/event-checkin' ) ); ?>" class="mobile-member-bar__item mobile-member-bar__item--checkin">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        <span>Check In</span>
    </a>
    <?php endif; ?>
    <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="mobile-member-bar__item">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <span>Log Out</span>
    </a>
</div>
<?php endif; ?>

<main id="main-content" class="site-main" role="main">
<?php

/**
 * Fallback menu if no menu is assigned.
 */
function blusiast_fallback_menu() {
    echo '<ul class="nav__list">';
    echo '<li class="nav__item"><a class="nav__link" href="' . esc_url( home_url('/') ) . '">Home</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="' . esc_url( home_url('/about') ) . '">About</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="' . esc_url( home_url('/events') ) . '">Events</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="' . esc_url( home_url('/gallery') ) . '">Gallery</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="' . esc_url( home_url('/blog') ) . '">Blog</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="' . esc_url( home_url('/shop') ) . '">Shop</a></li>';
    if ( is_user_logged_in() ) {
        echo '<li class="nav__item nav__item--portal"><a class="nav__link nav__link--portal" href="' . esc_url( blusiast_portal_url() ) . '">My Account</a></li>';
    }
    echo '</ul>';
}
