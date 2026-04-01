<?php
/**
 * Section: Events Preview
 * Shows next 3 upcoming events on homepage.
 * Layout: featured image left, details right.
 */

$events_query = blusiast_get_upcoming_events(3);
if (!$events_query->have_posts())
    return;
?>

<section class="events-preview section">
    <div class="container">

        <div class="section-header section-header--inline">
            <div>
                <p class="bl-label"><?php esc_html_e('Upcoming Events', 'blusiast'); ?></p>
                <h2 class="bl-display-md"><?php esc_html_e('Trips & Meetups', 'blusiast'); ?></h2>
            </div>
            <a href="<?php echo esc_url(get_post_type_archive_link('bl_event')); ?>"
                class="bl-btn bl-btn--ghost bl-btn--sm">
                <?php esc_html_e('View All Events', 'blusiast'); ?>
                <?php blusiast_icon('arrow-right'); ?>
            </a>
        </div>

        <div class="events-list">
            <?php while ($events_query->have_posts()):
                $events_query->the_post(); ?>
                <?php
                $date = function_exists('get_field') ? get_field('event_date') : '';
                $location = function_exists('get_field') ? get_field('event_location') : '';
                $time = function_exists('get_field') ? get_field('event_time') : '';
                $price = function_exists('get_field') ? get_field('event_price') : '';
                $reg_url = function_exists('get_field') ? get_field('event_reg_url') : get_permalink();
                $members_only = function_exists('get_field') ? get_field('event_members_only') : false;
                $sold_out = function_exists('get_field') ? get_field('event_sold_out') : false;
                $formatted = blusiast_format_event_date($date);
                ?>

                <article class="event-card" aria-label="<?php the_title_attribute(); ?>">

                    <!-- Left: Featured image with date badge overlaid -->
                    <!-- Left: Featured image with date badge overlaid -->
                    <a href="<?php the_permalink(); ?>" class="event-card__img-wrap" tabindex="-1" aria-hidden="true">

                        <?php if (has_post_thumbnail()): ?>
                            <?php the_post_thumbnail('blusiast-card', [
                                'class' => 'event-card__img',
                                'alt' => '',
                            ]); ?>
                        <?php else: ?>
                            <div class="event-card__img-placeholder"></div>
                        <?php endif; ?>

                        <?php if ($sold_out): ?>
                            <div class="event-card__sold-out-banner" aria-hidden="true">
                                <span>Sold Out</span>
                            </div>
                        <?php endif; ?>

                        <div class="event-card__date" aria-label="<?php echo esc_attr($formatted['full']); ?>">
                            <span class="event-card__month"><?php echo esc_html($formatted['month']); ?></span>
                            <span class="event-card__day"><?php echo esc_html($formatted['day']); ?></span>
                        </div>

                    </a>

                    <!-- Right: All event details -->
                    <div class="event-card__info">

                        <div class="event-card__badges">
                            <?php if ($members_only): ?>
                                <span class="bl-badge bl-badge--red bl-badge--dot">
                                    <?php esc_html_e('Members', 'blusiast'); ?>
                                </span>
                            <?php else: ?>
                                <span class="bl-badge bl-badge--white bl-badge--dot">
                                    <?php esc_html_e('Open', 'blusiast'); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($sold_out): ?>
                                <span class="bl-badge bl-badge--white">
                                    <?php esc_html_e('Sold Out', 'blusiast'); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <h3 class="event-card__title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h3>

                        <div class="event-card__meta">
                            <?php if ($location): ?>
                                <span class="event-card__meta-item">
                                    <?php blusiast_icon('location'); ?>
                                    <?php echo esc_html($location); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($time): ?>
                                <span class="event-card__meta-sep" aria-hidden="true"></span>
                                <span class="event-card__meta-item">
                                    <?php blusiast_icon('calendar'); ?>
                                    <?php echo esc_html($time); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($price): ?>
                                <span class="event-card__meta-sep" aria-hidden="true"></span>
                                <span class="event-card__meta-item event-card__meta-item--price">
                                    <?php echo esc_html($price); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php $excerpt = get_the_excerpt();
                        if ($excerpt): ?>
                            <p class="event-card__excerpt">
                                <?php echo esc_html(wp_trim_words($excerpt, 20)); ?>
                            </p>
                        <?php endif; ?>

                        <div class="event-card__actions">
                            <?php if ($sold_out): ?>
                                <span class="bl-btn bl-btn--ghost bl-btn--sm" aria-disabled="true">
                                    <?php esc_html_e('Sold Out', 'blusiast'); ?>
                                </span>
                            <?php elseif ($reg_url): ?>
                                <a href="<?php echo esc_url($reg_url); ?>" class="bl-btn bl-btn--primary bl-btn--sm">
                                    <?php esc_html_e('Register & Pay', 'blusiast'); ?>
                                    <?php blusiast_icon('arrow-right'); ?>
                                </a>
                            <?php endif; ?>
                            <a href="<?php the_permalink(); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">
                                <?php esc_html_e('Details', 'blusiast'); ?>
                            </a>
                        </div>

                    </div><!-- /.event-card__info -->

                </article>

            <?php endwhile;
            wp_reset_postdata(); ?>
        </div><!-- /.events-list -->

    </div>
</section>