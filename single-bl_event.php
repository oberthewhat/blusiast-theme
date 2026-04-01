<?php // single-bl_event.php — Single event page
get_header();
the_post();

$date      = function_exists('get_field') ? get_field('event_date')         : '';
$end_date  = function_exists('get_field') ? get_field('event_end_date')     : '';
$time      = function_exists('get_field') ? get_field('event_time')         : '';
$location  = function_exists('get_field') ? get_field('event_location')     : '';
$price     = function_exists('get_field') ? get_field('event_price')        : '';
$reg_url   = function_exists('get_field') ? get_field('event_reg_url')      : '';
$capacity  = function_exists('get_field') ? get_field('event_capacity')     : '';
$members   = function_exists('get_field') ? get_field('event_members_only') : false;
$sold_out  = function_exists('get_field') ? get_field('event_sold_out')     : false;
$fmt       = blusiast_format_event_date( $date );
?>

<div class="page-hero">
    <div class="container">
        <p class="bl-label"><?php esc_html_e( 'Event', 'blusiast' ); ?></p>
        <h1 class="bl-display-lg" style="margin-bottom:20px;"><?php the_title(); ?></h1>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php if ( $members ) : ?>
                <span class="bl-badge bl-badge--red bl-badge--dot"><?php esc_html_e('Members Only','blusiast'); ?></span>
            <?php else : ?>
                <span class="bl-badge bl-badge--white bl-badge--dot"><?php esc_html_e('Open to All','blusiast'); ?></span>
            <?php endif; ?>
            <?php if ( $sold_out ) : ?>
                <span class="bl-badge bl-badge--white"><?php esc_html_e('Sold Out','blusiast'); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="page-content">
    <div class="container">
        <div class="event-detail-grid">

            <!-- Main content -->
            <div class="event-detail__main">
                <?php if ( has_post_thumbnail() ) : ?>
                    <?php the_post_thumbnail( 'blusiast-hero', [ 'style' => 'width:100%;border-radius:12px;margin-bottom:32px;', 'alt' => '' ] ); ?>
                <?php endif; ?>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
            </div>

            <!-- Sidebar -->
            <aside class="event-detail__sidebar">
                <div class="event-sidebar-card">

                    <!-- Date display -->
                    <div class="event-sidebar-date">
                        <div class="event-card__date" style="min-width:64px;">
                            <span class="event-card__month"><?php echo esc_html( $fmt['month'] ); ?></span>
                            <span class="event-card__day"><?php echo esc_html( $fmt['day'] ); ?></span>
                        </div>
                        <div>
                            <div style="font-family:var(--font-display);font-size:16px;font-weight:700;text-transform:uppercase;color:var(--white);">
                                <?php echo esc_html( $fmt['full'] ); ?>
                            </div>
                            <?php if ( $end_date && $end_date !== $date ) :
                                $end = blusiast_format_event_date( $end_date );
                            ?>
                                <div style="font-size:13px;color:var(--gray-1);">– <?php echo esc_html( $end['full'] ); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr style="border:none;border-top:1px solid var(--surface-3);margin:16px 0;">

                    <!-- Details list -->
                    <div class="event-sidebar-details">
                        <?php if ( $time ) : ?>
                        <div class="event-sidebar-row">
                            <?php blusiast_icon('calendar'); ?>
                            <span><?php echo esc_html( $time ); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ( $location ) : ?>
                        <div class="event-sidebar-row">
                            <?php blusiast_icon('location'); ?>
                            <span><?php echo esc_html( $location ); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ( $price ) : ?>
                        <div class="event-sidebar-row">
                            <span style="font-family:var(--font-display);font-size:22px;font-weight:800;color:var(--red);">
                                <?php echo esc_html( $price ); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if ( $capacity ) : ?>
                        <div class="event-sidebar-row" style="font-size:12px;color:var(--gray-1);">
                            <?php echo esc_html( $capacity ); ?> <?php esc_html_e('spots total','blusiast'); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- CTA -->
                    <div style="margin-top:20px;">
                        <?php if ( $sold_out ) : ?>
                            <div class="bl-btn bl-btn--ghost" style="width:100%;justify-content:center;opacity:.5;pointer-events:none;">
                                <?php esc_html_e('Sold Out','blusiast'); ?>
                            </div>
                        <?php elseif ( $reg_url ) : ?>
                            <a href="<?php echo esc_url( $reg_url ); ?>"
                               class="bl-btn bl-btn--primary"
                               style="width:100%;justify-content:center;">
                                <?php esc_html_e('Register & Pay','blusiast'); ?>
                                <?php blusiast_icon('arrow-right'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ( $members ) : ?>
                            <p style="font-size:11px;color:var(--gray-1);text-align:center;margin-top:10px;display:flex;align-items:center;justify-content:center;gap:4px;">
                                <?php blusiast_icon('lock'); ?>
                                <?php esc_html_e('Members only event','blusiast'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </aside>

        </div><!-- /.event-detail-grid -->

        <div style="margin-top:48px;padding-top:24px;border-top:1px solid var(--surface-3);">
            <a href="<?php echo esc_url( get_post_type_archive_link('bl_event') ); ?>" class="bl-btn bl-btn--ghost bl-btn--sm">
                ← <?php esc_html_e('All Events','blusiast'); ?>
            </a>
        </div>
    </div>
</div>

<?php get_footer();
