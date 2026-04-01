<?php
/**
 * Blusiast_Nav_Walker
 * Custom Walker for the primary navigation.
 * Outputs clean, semantic HTML without WP's
 * default classes bloat.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Blusiast_Nav_Walker extends Walker_Nav_Menu {

    /**
     * Start the <ul> element.
     */
    public function start_lvl( &$output, $depth = 0, $args = null ) {
        $output .= '<ul class="nav__dropdown">';
    }

    /**
     * End the <ul> element.
     */
    public function end_lvl( &$output, $depth = 0, $args = null ) {
        $output .= '</ul>';
    }

    /**
     * Start each menu item <li>.
     */
    public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
        $classes     = empty( $item->classes ) ? [] : (array) $item->classes;
        $has_children = in_array( 'menu-item-has-children', $classes );
        $is_active    = in_array( 'current-menu-item', $classes ) || in_array( 'current-menu-ancestor', $classes );

        $li_class  = 'nav__item';
        $li_class .= $has_children ? ' nav__item--has-children' : '';
        $li_class .= $is_active    ? ' nav__item--active'       : '';
        $li_class .= $depth > 0   ? ' nav__item--sub'          : '';

        $output .= '<li class="' . esc_attr( $li_class ) . '">';

        // Build the link
        $atts             = [];
        $atts['href']     = ! empty( $item->url ) ? $item->url : '#';
        $atts['target']   = ! empty( $item->target ) ? $item->target : '';
        $atts['rel']      = ! empty( $item->xfn ) ? $item->xfn : '';
        $atts['title']    = ! empty( $item->attr_title ) ? $item->attr_title : '';
        $atts['class']    = 'nav__link' . ( $is_active ? ' nav__link--active' : '' );
        $atts['aria-current'] = $is_active ? 'page' : '';

        $link = '<a';
        foreach ( $atts as $attr => $val ) {
            if ( ! empty( $val ) ) {
                $link .= ' ' . $attr . '="' . esc_attr( $val ) . '"';
            }
        }
        $link .= '>';
        $link .= esc_html( $item->title );

        if ( $has_children && $depth === 0 ) {
            $link .= '<span class="nav__chevron" aria-hidden="true"></span>';
        }

        $link .= '</a>';

        $output .= $link;
    }

    /**
     * End each menu item </li>.
     */
    public function end_el( &$output, $item, $depth = 0, $args = null ) {
        $output .= '</li>';
    }
}
