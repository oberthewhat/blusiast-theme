<?php
/**
 * index.php — Fallback template.
 * WordPress requires this file to exist.
 * Actual page templates live in /page-templates/.
 */

get_header();
?>

<div class="container" style="padding: 80px 24px; text-align:center;">
    <p style="color: var(--br-gray-2);">
        <?php esc_html_e( 'No template found for this content.', 'blusiast' ); ?>
    </p>
</div>

<?php
get_footer();
