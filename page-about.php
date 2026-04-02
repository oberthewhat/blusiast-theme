<?php
/**
 * Template Name: About Us
 * page-about.php — About Us page for Blusiast
 *
 * Sections:
 *  1. Page Hero
 *  2. Origin Story (2-col: image + text)
 *  3. Stat Strip
 *  4. Core Values
 *  5. Leadership Team (photo + bio cards — primary focus)
 *  6. Awards & Accomplishments
 *  7. CTA — Join the Community
 */

get_header();

// ── ACF fields (graceful fallback if ACF not active) ──────────────────────────
$hero_bg      = function_exists('get_field') ? get_field('about_hero_bg')       : null;
$story_body   = function_exists('get_field') ? get_field('about_story_body')    : '';
$story_img    = function_exists('get_field') ? get_field('about_story_image')   : null;
$founded_year = function_exists('get_field') ? get_field('about_founded_year')  : '2022';
$members      = function_exists('get_field') ? get_field('about_member_count')  : '100+';
$parks        = function_exists('get_field') ? get_field('about_parks_visited') : '50+';
$countries    = function_exists('get_field') ? get_field('about_countries')     : '10+';
$awards       = function_exists('get_field') ? get_field('about_awards')        : [];
// Repeater fields: member_name, member_title, member_bio, member_photo, member_instagram, member_facebook
$team         = function_exists('get_field') ? get_field('about_team')          : [];
?>


<!-- ════════════════════════════════════════════
     1. PAGE HERO
     ════════════════════════════════════════════ -->

<?php
$hero_bg_url = '';
if ( $hero_bg ) {
    if ( is_array($hero_bg) && ! empty($hero_bg['url']) ) {
        $hero_bg_url = $hero_bg['url'];
    } elseif ( is_numeric($hero_bg) ) {
        $hero_bg_url = wp_get_attachment_image_url( $hero_bg, 'full' );
    } elseif ( is_string($hero_bg) ) {
        $hero_bg_url = $hero_bg;
    }
}
?>
<div class="page-hero about-hero<?php echo $hero_bg_url ? ' about-hero--has-bg' : ''; ?>"<?php echo $hero_bg_url ? ' style="background-image:url(\'' . esc_url($hero_bg_url) . '\')"' : ''; ?>>
    <?php if ( $hero_bg_url ) : ?>
        <div class="about-hero__overlay" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container" style="position:relative;z-index:2;">
        <p class="bl-label"><?php esc_html_e( 'Our Story', 'blusiast' ); ?></p>
        <h1 class="bl-display-lg about-hero__heading">
            <?php esc_html_e( 'Black Enthusiasts.', 'blusiast' ); ?><br>
            <span class="bl-text-red"><?php esc_html_e( 'Born from Passion.', 'blusiast' ); ?></span><br>
            <span style="color:var(--gray-1);font-style:italic;"><?php esc_html_e( 'Built for Everyone.', 'blusiast' ); ?></span>
        </h1>
        <p class="bl-body-lg about-hero__sub">
            <?php esc_html_e( 'Blusiast began with a simple idea — that everyone deserves to feel welcomed, represented, and thrilled. We\'re a global family of diverse theme park and roller coaster enthusiasts.', 'blusiast' ); ?>
        </p>
    </div>
</div>


<!-- ════════════════════════════════════════════
     2. ORIGIN STORY
     ════════════════════════════════════════════ -->

<section class="about-story section" id="mission">
    <div class="container">
        <div class="about-story__grid">

            <!-- Image -->
            <div class="about-story__visual bl-animate">
                <?php if ( $story_img ) : ?>
                    <img
                        src="<?php echo esc_url( is_array($story_img) ? $story_img['url'] : $story_img ); ?>"
                        alt="<?php echo esc_attr( is_array($story_img) ? ($story_img['alt'] ?? '') : '' ); ?>"
                        class="about-story__img"
                        loading="lazy">
                <?php else : ?>
                    <div class="about-story__img-placeholder" aria-hidden="true"></div>
                <?php endif; ?>

                <div class="about-story__float" aria-hidden="true">
                    <span class="about-story__float-year"><?php echo esc_html( $founded_year ); ?></span>
                    <span class="about-story__float-label"><?php esc_html_e( 'Founded', 'blusiast' ); ?></span>
                </div>
            </div>

            <!-- Text -->
            <div class="about-story__content bl-animate" style="animation-delay:.1s">
                <p class="bl-label"><?php esc_html_e( 'How It Started', 'blusiast' ); ?></p>
                <h2 class="bl-display-md" style="margin-bottom:24px;">
                    <?php esc_html_e( 'The Culture', 'blusiast' ); ?>
                    <span class="bl-text-red"><?php esc_html_e( ' Rides', 'blusiast' ); ?></span>
                    <?php esc_html_e( ' With Us', 'blusiast' ); ?>
                </h2>

                <?php if ( $story_body ) : ?>
                    <div class="entry-content">
                        <?php echo wp_kses_post( $story_body ); ?>
                    </div>
                <?php else : ?>
                    <p class="bl-body-lg" style="margin-bottom:20px;">
                        <?php esc_html_e( 'Founded in 2022, Blusiast — short for Black Enthusiasts — started as a gathering of passionate roller coaster and theme park fans who wanted to see themselves represented in enthusiast spaces.', 'blusiast' ); ?>
                    </p>
                    <p class="bl-body-md" style="margin-bottom:20px;">
                        <?php esc_html_e( 'What began as a small group chat quickly grew into a global community spanning families, adults, and teens across multiple countries — all united by a love of the ride and a commitment to inclusion.', 'blusiast' ); ?>
                    </p>
                    <p class="bl-body-md">
                        <?php esc_html_e( 'Our goal is simple: educate, inspire, and welcome every enthusiast regardless of background. Because the thrill of a great coaster belongs to everyone.', 'blusiast' ); ?>
                    </p>
                <?php endif; ?>

                <div class="about-story__divider" aria-hidden="true"><span></span></div>

                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:28px;">
                    <a href="<?php echo esc_url( get_permalink( get_page_by_path('membership') ) ); ?>" class="bl-btn bl-btn--primary">
                        <?php esc_html_e( 'Join the Family', 'blusiast' ); ?>
                        <?php blusiast_icon('arrow-right'); ?>
                    </a>
                    <a href="<?php echo esc_url( get_post_type_archive_link('bl_event') ); ?>" class="bl-btn bl-btn--ghost">
                        <?php esc_html_e( 'See Our Events', 'blusiast' ); ?>
                    </a>
                </div>
            </div>

        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════
     3. STAT STRIP
     ════════════════════════════════════════════ -->

<div class="stat-strip" style="display:none;">
    <div class="container">
        <div class="stat-strip__inner">
            <div class="stat-strip__item">
                <span class="stat-strip__num"><?php echo esc_html( $members ); ?></span>
                <span class="stat-strip__label"><?php esc_html_e( 'Members & Growing', 'blusiast' ); ?></span>
            </div>
            <div class="stat-strip__item">
                <span class="stat-strip__num"><?php echo esc_html( $parks ); ?></span>
                <span class="stat-strip__label"><?php esc_html_e( 'Parks Visited', 'blusiast' ); ?></span>
            </div>
            <div class="stat-strip__item">
                <span class="stat-strip__num"><?php echo esc_html( $countries ); ?></span>
                <span class="stat-strip__label"><?php esc_html_e( 'Countries Represented', 'blusiast' ); ?></span>
            </div>
            <div class="stat-strip__item">
                <span class="stat-strip__num"><?php echo esc_html( $founded_year ); ?></span>
                <span class="stat-strip__label"><?php esc_html_e( 'Year Founded', 'blusiast' ); ?></span>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════
     4. CORE VALUES
     ════════════════════════════════════════════ -->

<section class="about-values section" style="background:var(--surface-0);">
    <div class="container">

        <div class="section-header" style="text-align:center;max-width:600px;margin:0 auto 52px;">
            <p class="bl-label"><?php esc_html_e( 'What We Stand For', 'blusiast' ); ?></p>
            <h2 class="bl-display-md"><?php esc_html_e( 'Our Core Values', 'blusiast' ); ?></h2>
        </div>

        <div class="about-values__grid">

            <div class="about-value-card bl-animate">
                <div class="about-value-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/></svg>
                </div>
                <h3 class="about-value-card__title"><?php esc_html_e( 'Integrity', 'blusiast' ); ?></h3>
                <p class="about-value-card__body"><?php esc_html_e( 'We show up, do what we say, and lead with honesty in everything we do — on the road and in the community.', 'blusiast' ); ?></p>
            </div>

            <div class="about-value-card bl-animate" style="animation-delay:.08s">
                <div class="about-value-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m6.115 5.19.319 1.913A6 6 0 0 0 8.11 10.36L9.75 12l-.387.775c-.217.433-.132.956.21 1.298l1.348 1.348c.21.21.329.497.329.795v1.089c0 .426.24.815.622 1.006l.153.076c.433.217.956.132 1.298-.21l.723-.723a8.7 8.7 0 0 0 2.288-4.042 1.087 1.087 0 0 0-.358-1.099l-1.33-1.108c-.251-.21-.582-.299-.905-.245l-1.17.195a1.125 1.125 0 0 1-.98-.314l-.295-.295a1.125 1.125 0 0 1 0-1.591l.13-.132a1.125 1.125 0 0 1 1.3-.21l.603.302a.809.809 0 0 0 1.086-1.086L14.25 7.5l1.256-.837a4.5 4.5 0 0 0 1.528-1.732l.146-.292M6.115 5.19A9 9 0 1 0 17.18 4.64M6.115 5.19A8.965 8.965 0 0 1 12 3c1.929 0 3.716.607 5.18 1.64"/></svg>
                </div>
                <h3 class="about-value-card__title"><?php esc_html_e( 'Diversity & Inclusion', 'blusiast' ); ?></h3>
                <p class="about-value-card__body"><?php esc_html_e( 'All races, ages, and backgrounds belong here. Representation matters — in the park, in the photos, and in the conversation.', 'blusiast' ); ?></p>
            </div>

            <div class="about-value-card bl-animate" style="animation-delay:.16s">
                <div class="about-value-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"/></svg>
                </div>
                <h3 class="about-value-card__title"><?php esc_html_e( 'Trust & Community', 'blusiast' ); ?></h3>
                <p class="about-value-card__body"><?php esc_html_e( 'A safe, welcoming space built on genuine connection. We look out for each other — every member, every trip, every time.', 'blusiast' ); ?></p>
            </div>

            <div class="about-value-card bl-animate" style="animation-delay:.24s">
                <div class="about-value-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 0 1-6.364 0M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Z"/></svg>
                </div>
                <h3 class="about-value-card__title"><?php esc_html_e( 'Joy & Passion', 'blusiast' ); ?></h3>
                <p class="about-value-card__body"><?php esc_html_e( 'We are unapologetically enthusiastic. The thrill of a great ride, shared with great people, is what this is all about.', 'blusiast' ); ?></p>
            </div>

        </div>
    </div>
</section>


<!-- ════════════════════════════════════════════
     5. LEADERSHIP TEAM
     ════════════════════════════════════════════ -->

<section class="about-team section" id="team" style="background:var(--black);">
    <div class="container">

        <div class="section-header" style="text-align:center;max-width:600px;margin:0 auto 56px;">
            <p class="bl-label"><?php esc_html_e( 'The People Behind It', 'blusiast' ); ?></p>
            <h2 class="bl-display-md"><?php esc_html_e( 'Meet Our Leadership', 'blusiast' ); ?></h2>
            <p class="bl-body-lg" style="margin-top:16px;">
                <?php esc_html_e( 'Blusiast is run by enthusiasts, for enthusiasts. Every person on our team is here because they believe the park is better together.', 'blusiast' ); ?>
            </p>
        </div>

        <?php

        // Use ACF data if available, otherwise show placeholder cards
        $render_team = ! empty($team) ? $team : [
            [ 'member_name' => 'Founder Name',  'member_title' => 'Founder & President',  'member_bio' => 'Add this bio via the About page team repeater in your WordPress dashboard.', 'member_photo' => null, 'member_instagram' => '', 'member_facebook' => '' ],
            [ 'member_name' => 'Team Member',    'member_title' => 'Vice President',        'member_bio' => 'Add this bio via the About page team repeater in your WordPress dashboard.', 'member_photo' => null, 'member_instagram' => '', 'member_facebook' => '' ],
            [ 'member_name' => 'Team Member',    'member_title' => 'Events Coordinator',    'member_bio' => 'Add this bio via the About page team repeater in your WordPress dashboard.', 'member_photo' => null, 'member_instagram' => '', 'member_facebook' => '' ],
            [ 'member_name' => 'Team Member',    'member_title' => 'Community Manager',     'member_bio' => 'Add this bio via the About page team repeater in your WordPress dashboard.', 'member_photo' => null, 'member_instagram' => '', 'member_facebook' => '' ],
            [ 'member_name' => 'Team Member',    'member_title' => 'Secretary',             'member_bio' => 'Add this bio via the About page team repeater in your WordPress dashboard.', 'member_photo' => null, 'member_instagram' => '', 'member_facebook' => '' ],
        ];

        ?>

        <div class="about-team__list">
            <?php foreach ( $render_team as $i => $member ) :
                $photo     = $member['member_photo']     ?? null;
                $photo_url = is_array($photo) ? ($photo['url'] ?? '') : $photo;
                $photo_alt = is_array($photo) ? ($photo['alt'] ?? '') : '';
                $name      = $member['member_name']      ?? '';
                $title     = $member['member_title']     ?? '';
                $bio       = $member['member_bio']       ?? '';
                $instagram = $member['member_instagram'] ?? '';
                $facebook  = $member['member_facebook']  ?? '';
            ?>
                <div class="about-team-row bl-animate" style="animation-delay:<?php echo esc_attr( $i * 0.08 ); ?>s">

                    <!-- Photo -->
                    <div class="about-team-row__img-wrap">
                        <?php if ( $photo_url ) : ?>
                            <img
                                src="<?php echo esc_url($photo_url); ?>"
                                alt="<?php echo esc_attr($photo_alt ?: $name); ?>"
                                class="about-team-row__img"
                                loading="lazy">
                        <?php else : ?>
                            <div class="about-team-row__img-placeholder" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Bio content -->
                    <div class="about-team-row__body">
                        <div class="about-team-row__header">
                            <strong class="about-team-row__name"><?php echo esc_html($name); ?></strong>
                            <?php if ($title) : ?>
                                <span class="about-team-row__title"><?php echo esc_html($title); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($bio) : ?>
                            <p class="about-team-row__bio"><?php echo esc_html($bio); ?></p>
                        <?php endif; ?>

                        <?php if ($instagram || $facebook) : ?>
                            <div class="about-team-row__social">
                                <?php if ($instagram) : ?>
                                    <a href="<?php echo esc_url($instagram); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       class="social-link"
                                       aria-label="<?php echo esc_attr($name); ?> on Instagram">
                                        <svg viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M7 2C4.2 2 2 4.2 2 7v10c0 2.8 2.2 5 5 5h10c2.8 0 5-2.2 5-5V7c0-2.8-2.2-5-5-5H7zm5 5a5 5 0 110 10 5 5 0 010-10zm6.5-.8a1.2 1.2 0 110 2.4 1.2 1.2 0 010-2.4z"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($facebook) : ?>
                                    <a href="<?php echo esc_url($facebook); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       class="social-link"
                                       aria-label="<?php echo esc_attr($name); ?> on Facebook">
                                        <svg viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M13 3h4V0h-4c-3.3 0-6 2.7-6 6v3H4v4h3v11h4V13h3l1-4h-4V6c0-.6.4-1 1-1z"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>
        </div><!-- /.about-team__list -->

    </div>
</section>


<!-- ════════════════════════════════════════════
     6. AWARDS & ACCOMPLISHMENTS
     ════════════════════════════════════════════ -->

<section class="about-awards section" id="awards" style="background:var(--surface-0);">
    <div class="container">

        <div class="section-header" style="text-align:center;max-width:560px;margin:0 auto 48px;">
            <p class="bl-label"><?php esc_html_e( 'Recognition', 'blusiast' ); ?></p>
            <h2 class="bl-display-md"><?php esc_html_e( 'Awards & Accomplishments', 'blusiast' ); ?></h2>
        </div>

        <?php if ( ! empty($awards) ) : ?>
            <div class="about-awards__grid">
                <?php foreach ( $awards as $i => $award ) :
                    $award_img = $award['award_img'] ?? null;
                    $img_url   = is_array($award_img) ? ($award_img['url'] ?? '') : $award_img;
                ?>
                    <div class="about-award-card bl-animate" style="animation-delay:<?php echo esc_attr( $i * 0.08 ); ?>s">
                        <?php if ( $img_url ) : ?>
                            <img src="<?php echo esc_url($img_url); ?>"
                                 alt="<?php echo esc_attr( $award['award_name'] ?? '' ); ?>"
                                 class="about-award-card__img" loading="lazy">
                        <?php else : ?>
                            <div class="about-award-card__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.004 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        <div class="about-award-card__body">
                            <strong class="about-award-card__name"><?php echo esc_html( $award['award_name'] ?? '' ); ?></strong>
                            <?php if ( ! empty($award['award_org']) ) : ?>
                                <span class="about-award-card__org"><?php echo esc_html( $award['award_org'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty($award['award_year']) ) : ?>
                                <span class="about-award-card__year"><?php echo esc_html( $award['award_year'] ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else : ?>
            <div class="about-awards__empty">
                <div class="about-awards__empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/>
                    </svg>
                </div>
                <p class="bl-body-md" style="text-align:center;">
                    <?php esc_html_e( 'Awards and accomplishments will appear here. Add them via the About page fields in the WordPress dashboard.', 'blusiast' ); ?>
                </p>
            </div>
        <?php endif; ?>

    </div>
</section>


<!-- ════════════════════════════════════════════
     7. CTA — JOIN THE COMMUNITY
     ════════════════════════════════════════════ -->

<section class="about-cta section" style="background:var(--surface-1);border-top:1px solid var(--surface-2);border-bottom:1px solid var(--surface-2);">
    <div class="container">
        <div class="about-cta__inner">
            <div class="about-cta__text bl-animate">
                <p class="bl-label"><?php esc_html_e( 'Ready to Ride With Us?', 'blusiast' ); ?></p>
                <h2 class="bl-display-md" style="margin-bottom:16px;">
                    <?php esc_html_e( 'Become Part of', 'blusiast' ); ?>
                    <span class="bl-text-red"><?php esc_html_e( ' the Family', 'blusiast' ); ?></span>
                </h2>
                <p class="bl-body-lg" style="max-width:520px;">
                    <?php esc_html_e( 'Join a global community of diverse theme park enthusiasts. Attend exclusive trips, access member perks, and help us build a culture of inclusion — one coaster at a time.', 'blusiast' ); ?>
                </p>
            </div>
            <div class="about-cta__actions bl-animate" style="animation-delay:.1s">
                <a href="<?php echo esc_url( get_permalink( get_page_by_path('membership') ) ); ?>" class="bl-btn bl-btn--primary bl-btn--lg">
                    <?php esc_html_e( 'Join Blusiast', 'blusiast' ); ?>
                    <?php blusiast_icon('arrow-right'); ?>
                </a>
                <a href="<?php echo esc_url( get_permalink( get_page_by_path('contact') ) ); ?>" class="bl-btn bl-btn--ghost bl-btn--lg">
                    <?php esc_html_e( 'Contact Us', 'blusiast' ); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<?php get_footer();
