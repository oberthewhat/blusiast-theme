<?php // template-parts/sections/mission.php ?>
<section class="mission section">
    <div class="container">
        <div class="mission__grid">
            <div class="mission__content">
                <p class="bl-label"><?php esc_html_e('Our Mission', 'blusiast'); ?></p>
                <h2 class="mission__quote">
                    <?php esc_html_e('Building a ', 'blusiast'); ?>
                    <em><?php esc_html_e('family culture', 'blusiast'); ?></em>
                    <?php esc_html_e(' of diversity — one ride at a time.', 'blusiast'); ?>
                </h2>
                <p class="bl-body-lg">
                    <?php esc_html_e('We exist to provide awareness and inclusion for all park goers, regardless of race. Sharing our joy and passion with every enthusiast, everywhere.', 'blusiast'); ?>
                </p>

                <ul class="value-list" role="list">
                    <li class="value-list__item">
                        <span class="value-list__icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
                                class="size-6">
                                <path fill-rule="evenodd"
                                    d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z"
                                    clip-rule="evenodd" />
                            </svg>
s
                        </span>
                        <div class="value-list__text">
                            <strong><?php esc_html_e('Integrity', 'blusiast'); ?></strong>
                            <span><?php esc_html_e('We show up, do what we say, and lead with honesty.', 'blusiast'); ?></span>
                        </div>
                    </li>
                    <li class="value-list__item">
                        <span class="value-list__icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" class="size-6">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="m6.115 5.19.319 1.913A6 6 0 0 0 8.11 10.36L9.75 12l-.387.775c-.217.433-.132.956.21 1.298l1.348 1.348c.21.21.329.497.329.795v1.089c0 .426.24.815.622 1.006l.153.076c.433.217.956.132 1.298-.21l.723-.723a8.7 8.7 0 0 0 2.288-4.042 1.087 1.087 0 0 0-.358-1.099l-1.33-1.108c-.251-.21-.582-.299-.905-.245l-1.17.195a1.125 1.125 0 0 1-.98-.314l-.295-.295a1.125 1.125 0 0 1 0-1.591l.13-.132a1.125 1.125 0 0 1 1.3-.21l.603.302a.809.809 0 0 0 1.086-1.086L14.25 7.5l1.256-.837a4.5 4.5 0 0 0 1.528-1.732l.146-.292M6.115 5.19A9 9 0 1 0 17.18 4.64M6.115 5.19A8.965 8.965 0 0 1 12 3c1.929 0 3.716.607 5.18 1.64" />
                            </svg>

                        </span>
                        <div class="value-list__text">
                            <strong><?php esc_html_e('Diversity & Inclusion', 'blusiast'); ?></strong>
                            <span><?php esc_html_e('All races, ages, and backgrounds belong here.', 'blusiast'); ?></span>
                        </div>
                    </li>
                    <li class="value-list__item">
                        <span class="value-list__icon" aria-hidden="true">
                            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"
                                    fill="currentColor" />
                            </svg>
                        </span>
                        <div class="value-list__text">
                            <strong><?php esc_html_e('Trust & Community', 'blusiast'); ?></strong>
                            <span><?php esc_html_e('A safe, welcoming space built on genuine connection.', 'blusiast'); ?></span>
                        </div>
                    </li>
                </ul>

                <a href="<?php echo esc_url(get_permalink(get_page_by_path('about'))); ?>"
                    class="bl-btn bl-btn--primary" style="margin-top:2rem;">
                    <?php esc_html_e('Our Story', 'blusiast'); ?>
                </a>
            </div>

            <div class="mission__visual">
                <?php $mission_image = function_exists('get_field') ? get_field('mission_image') : null; ?>
                <?php if ($mission_image): ?>
                    <img class="mission__img" src="<?php echo esc_url($mission_image['url']); ?>"
                        alt="<?php echo esc_attr($mission_image['alt']); ?>">
                <?php else: ?>
                    <div class="mission__img-placeholder" aria-hidden="true"></div>
                <?php endif; ?>
                <div class="mission__float-card" aria-hidden="true">
                    <span class="mission__float-num">100+</span>
                    <span class="mission__float-label"><?php esc_html_e('Members & Growing', 'blusiast'); ?></span>
                </div>
            </div>
        </div>
    </div>
</section>