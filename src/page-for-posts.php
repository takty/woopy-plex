<?php
/**
 * Page for Posts
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2024-03-14
 */

declare(strict_types=1);

namespace wpinc\plex\page_for_posts;

require_once __DIR__ . '/pseudo-front.php';

/**
 * Activates the page for posts.
 */
function activate(): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	if ( ! is_admin() ) {
		add_filter( 'option_page_for_posts', '\wpinc\plex\page_for_posts\_cb_option_page_for_posts', 10, 1 );
	}
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'option_{$option}' filter (page_for_posts).
 *
 * @access private
 *
 * @param mixed $value Value of the option.
 * @return mixed The filtered string.
 */
function _cb_option_page_for_posts( $value ) {
	static $suppress = false;
	if ( $suppress ) {
		return $value;
	}
	if ( is_numeric( $value ) && 0 < (int) $value ) {
		$url     = get_page_link( (int) $value );
		$rep_url = str_replace( \home_url( '/' ), \wpinc\plex\pseudo_front\home_url( '/' ), $url );
		if ( $url !== $rep_url ) {
			$suppress = true;
			$value    = url_to_postid( $rep_url );
			$suppress = false;
		}
	}
	return $value;
}
