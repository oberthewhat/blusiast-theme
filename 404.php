<?php // 404.php
get_header(); ?>
<div class="page-hero" style="text-align:center;padding:120px 0;">
    <div class="container">
        <p class="bl-label"><?php esc_html_e('404 — Not Found','blusiast'); ?></p>
        <h1 class="bl-display-xl" style="color:var(--surface-3);margin-bottom:24px;">404</h1>
        <p class="bl-body-lg" style="margin-bottom:32px;"><?php esc_html_e("This page doesn't exist. Maybe it's on a coaster somewhere.",'blusiast'); ?></p>
        <a href="<?php echo esc_url( home_url('/') ); ?>" class="bl-btn bl-btn--primary">
            <?php esc_html_e('Back to Home','blusiast'); ?>
            <?php blusiast_icon('arrow-right'); ?>
        </a>
    </div>
</div>
<?php get_footer();
