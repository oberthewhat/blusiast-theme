</main><!-- /#main-content -->

<footer class="site-footer" role="contentinfo">


    <div class="site-footer__main">
        <div class="container">
            <div class="footer-grid">

                <!-- Brand column -->
                <div class="footer-brand">
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="footer-brand__logo">
                        <img src="https://blusiast.org/wp-content/uploads/2026/04/cropped-Untitled-design-1.png" alt="Blusiast" style="max-height:70px;width:auto;">
                    </a>

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

                            <a href="https://discord.com/invite/n4tu63A2AS" target="_blank" class="social-link"
                                aria-label="Discord">
                                <svg viewBox="0 0 24 24">
                                    <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.031.056a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
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
                        <li>
                            <?php
                            $portal_url   = function_exists( 'blusiast_portal_url' ) ? blusiast_portal_url() : home_url( '/member-portal/' );
                            $register_url = add_query_arg( 'tab', 'register', $portal_url );
                            ?>
                            <a href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e('Join / Membership', 'blusiast'); ?></a>
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
                                href="<?php echo esc_url(home_url('/coasters')); ?>"><?php esc_html_e('Best Coasters', 'blusiast'); ?></a>
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

                <a href="https://thrillnerds.com" target="_blank" rel="noopener"
                   class="site-footer__credit"
                   style="text-decoration:none;transition:color .2s;"
                   onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''">
                    Site design by ThrillNerds
                </a>

                <a href="<?php echo esc_url( home_url('/privacy-policy') ); ?>"
                   style="font-size:12px;color:var(--gray-3);text-decoration:none;transition:color .2s;"
                   onmouseover="this.style.color='#fff'" onmouseout="this.style.color=''">
                    Privacy Policy
                </a>
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