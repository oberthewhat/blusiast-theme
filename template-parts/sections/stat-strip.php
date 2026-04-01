<?php // template-parts/sections/stat-strip.php
$s1n = function_exists('get_field') ? get_field('hp_stat_1_num')   ?: '2022'   : '2022';
$s1l = function_exists('get_field') ? get_field('hp_stat_1_label') ?: 'Founded': 'Founded';
$s2n = function_exists('get_field') ? get_field('hp_stat_2_num')   ?: 'Global' : 'Global';
$s2l = function_exists('get_field') ? get_field('hp_stat_2_label') ?: 'Reach'  : 'Reach';
$s3n = function_exists('get_field') ? get_field('hp_stat_3_num')   ?: '100+'   : '100+';
$s3l = function_exists('get_field') ? get_field('hp_stat_3_label') ?: 'Members': 'Members';
?>
<div class="stat-strip" aria-hidden="true">
    <div class="container">
        <div class="stat-strip__inner">
            <div class="stat-strip__item">
                <span class="stat-strip__num"><?php echo esc_html( $s1n ); ?></span>
                <span class="stat-strip__label"><?php echo esc_html( $s1l ); ?></span>
            </div>
            <div class="stat-strip__item">
                <span class="stat-strip__num"><?php echo esc_html( $s2n ); ?></span>
                <span class="stat-strip__label"><?php echo esc_html( $s2l ); ?></span>
            </div>
            <div class="stat-strip__item">
                <span class="stat-strip__num"><?php echo esc_html( $s3n ); ?></span>
                <span class="stat-strip__label"><?php echo esc_html( $s3l ); ?></span>
            </div>
            <div class="stat-strip__item">
                <span class="stat-strip__num">All Ages</span>
                <span class="stat-strip__label"><?php esc_html_e( 'Welcome', 'blusiast' ); ?></span>
            </div>
            <div class="stat-strip__item">
                <span class="stat-strip__num">&infin;</span>
                <span class="stat-strip__label"><?php esc_html_e( 'Good Times', 'blusiast' ); ?></span>
            </div>
        </div>
    </div>
</div>
