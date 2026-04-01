<?php
/**
 * front-page.php
 * Homepage template. WordPress loads this automatically
 * when a static front page is set in Settings → Reading.
 */

get_header();

// Pull ACF homepage fields (fall back to defaults if ACF not active)
$hero_line1  = function_exists('get_field') ? get_field('hp_hero_headline') : 'The Culture';
$hero_line2  = function_exists('get_field') ? get_field('hp_hero_line2')    : 'Rides';
$hero_line3  = function_exists('get_field') ? get_field('hp_hero_line3')    : 'With Us';
$hero_body   = function_exists('get_field') ? get_field('hp_hero_body')     : 'A global family of diverse theme park and roller coaster enthusiasts — celebrating joy, inclusion, and the thrill of the ride together.';

$stat_1_num   = function_exists('get_field') ? get_field('hp_stat_1_num')   : '2022';
$stat_1_label = function_exists('get_field') ? get_field('hp_stat_1_label') : 'Founded';
$stat_2_num   = function_exists('get_field') ? get_field('hp_stat_2_num')   : 'Global';
$stat_2_label = function_exists('get_field') ? get_field('hp_stat_2_label') : 'Reach';
$stat_3_num   = function_exists('get_field') ? get_field('hp_stat_3_num')   : '100+';
$stat_3_label = function_exists('get_field') ? get_field('hp_stat_3_label') : 'Members';
?>

<?php get_template_part( 'template-parts/sections/hero' ); ?>

<?php get_template_part( 'template-parts/sections/stat-strip' ); ?>

<?php get_template_part( 'template-parts/sections/mission' ); ?>

<?php get_template_part( 'template-parts/sections/events-preview' ); ?>

<?php get_template_part( 'template-parts/sections/photo-strip' ); ?>

<?php get_template_part( 'template-parts/sections/spotlight' ); ?>

<!-- <?php get_template_part( 'template-parts/sections/merch-preview' ); ?> -->

<?php get_template_part( 'template-parts/sections/email-signup' ); ?>

<?php
get_footer();
