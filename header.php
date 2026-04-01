<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
            <a href="<?php echo esc_url( get_permalink( get_page_by_path( 'membership' ) ) ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">
                Sign In
            </a>
            <a href="<?php echo esc_url( get_permalink( get_page_by_path( 'membership' ) ) ); ?>" class="bl-btn bl-btn--primary bl-btn--sm">
                Join Now
            </a>
        </div>

        <!-- Mobile Menu Toggle -->
        <button class="nav-toggle" id="nav-toggle" aria-controls="site-nav" aria-expanded="false" aria-label="<?php esc_attr_e( 'Toggle navigation', 'blusiast' ); ?>">
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
        </button>

    </div><!-- /.site-header__inner -->
</header>

<main id="main-content" class="site-main" role="main">
<?php

/**
 * Fallback menu if no menu is assigned.
 */
function blusiast_fallback_menu() {
    echo '<ul class="nav__list">';
    echo '<li class="nav__item"><a class="nav__link" href="' . esc_url( home_url('/') ) . '">Home</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="#">About</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="#">Events</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="#">Gallery</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="#">Blog</a></li>';
    echo '<li class="nav__item"><a class="nav__link" href="#">Shop</a></li>';
    echo '</ul>';
}
