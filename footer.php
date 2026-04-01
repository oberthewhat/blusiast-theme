</main><!-- /#main-content -->

<footer class="site-footer" role="contentinfo">


    <div class="site-footer__main">
        <div class="container">
            <div class="footer-grid">

                <!-- Brand column -->
                <div class="footer-brand">
                    <?php
                    $footer_logo = get_theme_mod('blusiast_footer_logo', '');
                    if ($footer_logo):
                        ?>
                        <a href="<?php echo esc_url(home_url('/')); ?>" class="footer-brand__logo">
                            <img src="<?php echo esc_url($footer_logo); ?>" alt="<?php bloginfo('name'); ?>">
                        </a>
                    <?php endif; ?>

                    <span class="bl-label footer-brand__title">
                        <?php esc_html_e('Black Enthusiasts', 'blusiast'); ?>
                    </span>
                    <p><?php esc_html_e('Passionate roller coaster and theme park enthusiasts building a family culture of diversity. Global community. All ages. All welcome.', 'blusiast'); ?>
                    </p>

                    <!-- Social Links -->
                    <?php if (has_nav_menu('social')): ?>
                        <nav class="social-links" aria-label="<?php esc_attr_e('Social media links', 'blusiast'); ?>">
                            <?php
                            wp_nav_menu([
                                'theme_location' => 'social',
                                'container' => false,
                                'menu_class' => 'social-links__list',
                                'link_before' => '<span class="screen-reader-text">',
                                'link_after' => '</span>',
                                'depth' => 1,
                            ]);
                            ?>
                        </nav>
                    <?php else: ?>
                        <div class="social-links">
                            <a href="https://www.instagram.com/theblusiast/" target="_blank" class="social-link"
                                aria-label="Instagram">
                                <svg viewBox="0 0 24 24">
                                    <path
                                        d="M7 2C4.2 2 2 4.2 2 7v10c0 2.8 2.2 5 5 5h10c2.8 0 5-2.2 5-5V7c0-2.8-2.2-5-5-5H7zm5 5a5 5 0 110 10 5 5 0 010-10zm6.5-.8a1.2 1.2 0 110 2.4 1.2 1.2 0 010-2.4z" />
                                </svg>
                            </a>

                            <a href="https://www.facebook.com/groups/theblusiast/" target="_blank" class="social-link"
                                aria-label="Facebook">
                                <svg viewBox="0 0 24 24">
                                    <path d="M13 3h4V0h-4c-3.3 0-6 2.7-6 6v3H4v4h3v11h4V13h3l1-4h-4V6c0-.6.4-1 1-1z" />
                                </svg>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Explore column -->
                <div class="footer-col">
                    <h3 class="footer-col__title"><?php esc_html_e('Explore', 'blusiast'); ?></h3>
                    <ul class="footer-col__links">
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('about'))); ?>"><?php esc_html_e('About Us', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('about'))); ?>#mission"><?php esc_html_e('Our Mission', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(get_post_type_archive_link('bl_event')); ?>"><?php esc_html_e('Events', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('gallery'))); ?>"><?php esc_html_e('Gallery', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(home_url('/blog')); ?>"><?php esc_html_e('Blog & News', 'blusiast'); ?></a>
                        </li>
                    </ul>
                </div>

                <!-- Community column -->
                <div class="footer-col">
                    <h3 class="footer-col__title"><?php esc_html_e('Community', 'blusiast'); ?></h3>
                    <ul class="footer-col__links">
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('membership'))); ?>"><?php esc_html_e('Join / Membership', 'blusiast'); ?></a>
                        </li>
                        <?php if (function_exists('wc_get_page_permalink')): ?>
                            <li><a
                                    href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Shop Merch', 'blusiast'); ?></a>
                            </li>
                        <?php endif; ?>
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('community-service'))); ?>"><?php esc_html_e('Community Service', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('about'))); ?>#awards"><?php esc_html_e('Awards', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(get_post_type_archive_link('bl_coaster')); ?>"><?php esc_html_e('Best Coasters', 'blusiast'); ?></a>
                        </li>
                    </ul>
                </div>

                <!-- Contact column -->
                <div class="footer-col">
                    <h3 class="footer-col__title"><?php esc_html_e('Contact', 'blusiast'); ?></h3>
                    <ul class="footer-col__links">
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('contact'))); ?>"><?php esc_html_e('Contact Us', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('contact'))); ?>#press"><?php esc_html_e('Press & Media', 'blusiast'); ?></a>
                        </li>
                        <li><a
                                href="<?php echo esc_url(get_permalink(get_page_by_path('contact'))); ?>#partner"><?php esc_html_e('Partnerships', 'blusiast'); ?></a>
                        </li>
                    </ul>
                </div>

            </div><!-- /.footer-grid -->
        </div>
    </div><!-- /.site-footer__main -->

    <div class="site-footer__bottom">
        <div class="container site-footer__bottom-inner">

            <div class="site-footer__bottom-left">
                <span class="site-footer__copy">
                    &copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?>.
                    <?php esc_html_e('All Rights Reserved.', 'blusiast'); ?>
                </span>

                <span class="site-footer__credit">
                    Site design by ThrillNerds
                </span>
            </div>

            <span class="site-footer__tagline">
                <?php esc_html_e('Building a family culture of diversity.', 'blusiast'); ?>
            </span>

        </div>
    </div>

</footer>

<?php wp_footer(); ?>
</body>

</html>