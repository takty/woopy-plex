<?php
/**
 * Multi-Home-Language
 *
 * @package Sample
 * @author Takuto Yanagida
 * @version 2022-06-28
 */

namespace sample;

require_once __DIR__ . '/plex/custom-rewrite.php';
require_once __DIR__ . '/plex/pseudo-front.php';
require_once __DIR__ . '/plex/filter.php';
require_once __DIR__ . '/plex/term-field.php';
require_once __DIR__ . '/plex/option-field.php';

const QUERY_VAR_SITE_HOME = 'site_home';
const QUERY_VAR_SITE_LANG = 'site_lang';
const TAXONOMY_POST_HOME  = 'post_home';
const TAXONOMY_POST_LANG  = 'post_lang';

/**
 * Initialize multi-language features.
 *
 * @param array $args {
 *     Configuration arguments.
 *
 *     @type array  $site_langs
 *     @type string $default_lang
 *     @type array  $site_homes
 *     @type string $default_home
 *     @type bool   $default_home_omittable
 *     @type array  $admin_labels
 *     @type array  $translated_taxonomies
 *     @type array  $filter_term_labels
 *     @type array  $filtered_post_types
 * }
 */
function initialize_multi_home_lang( array $args ) {
	$args += array(
		'site_langs'                  => array(),
		'default_lang'                => '',
		'site_homes'                  => array(),
		'default_home'                => '',
		'default_home_omittable'      => false,
		'admin_labels'                => array(),
		'translated_taxonomies'       => array(),
		'filter_term_labels'          => array(),
		'filtered_post_types'         => array(),
		'do_set_page_on_front_option' => true,
	);
	$inst  = &_get_multi_home_lang_instance();
	$inst += $args;

	/*
	 * For enabling custom rewrite.
	 */
	\wpinc\plex\custom_rewrite\add_structure(
		array(
			'var'          => QUERY_VAR_SITE_HOME,
			'slugs'        => $args['site_homes'],
			'default_slug' => $args['default_home'],
			'omittable'    => $args['default_home_omittable'],
			'global'       => true,
		)
	);
	\wpinc\plex\custom_rewrite\add_structure(
		array(
			'var'          => QUERY_VAR_SITE_LANG,
			'slugs'        => $args['site_langs'],
			'default_slug' => $args['default_lang'],
			'omittable'    => true,
			'global'       => true,
		)
	);
	\wpinc\plex\custom_rewrite\activate();

	/*
	 * For enabling pseudo front pages.
	 */
	\wpinc\plex\pseudo_front\activate(
		array(
			'has_default_front_bloginfo'  => false,
			'do_set_page_on_front_option' => $args['do_set_page_on_front_option'],
		)
	);
	\wpinc\plex\pseudo_front\add_admin_labels( $args['admin_labels'] );

	/*
	 * For adding fields of the name translation.
	 */
	foreach ( $args['translated_taxonomies'] as $tx ) {
		\wpinc\plex\term_field\add_taxonomy( $tx );
	}
	\wpinc\plex\term_field\add_admin_labels( $args['admin_labels'] );
	\wpinc\plex\term_field\activate( array( 'vars' => array( QUERY_VAR_SITE_LANG ) ) );

	/*
	 * For adding fields of the date and time format of each locale to the option screen.
	 */
	\wpinc\plex\option_field\add_admin_labels( $args['admin_labels'] );
	\wpinc\plex\option_field\activate( array( 'vars' => array( QUERY_VAR_SITE_LANG ) ) );

	/*
	 * Switch the locale in this timing!
	 */
	add_action( 'after_setup_theme', '\st\_cb_after_setup_theme_multi_home_lang' );

	/*
	 * For adding filter taxonomy and its terms.
	 */
	\wpinc\plex\filter\activate();
	add_action( 'init', '\st\_cb_init_multi_home_lang' );  // Do here because locale is used.
}

/**
 * Get instance.
 *
 * @access private
 *
 * @return object Instance.
 */
function &_get_multi_home_lang_instance(): array {
	static $values = array();
	return $values;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'after_setup_theme' action.
 *
 * @access private
 */
function _cb_after_setup_theme_multi_home_lang() {
	static $sl_locale = array( 'en' => 'en_US' );
	static $locale_sl = array( 'en_US' => 'en' );

	if ( is_admin() ) {
		$locale = get_user_locale();
		$sl     = $locale_sl[ $locale ] ?? $locale;
		\wpinc\plex\custom_rewrite\set_query_var( QUERY_VAR_SITE_LANG, $sl );  // This is needed for admin screens!
	} else {
		$sl     = \wpinc\plex\custom_rewrite\get_query_var( QUERY_VAR_SITE_LANG );
		$locale = $sl_locale[ $sl ] ?? $sl;
		switch_to_locale( $locale );
	}
	load_theme_textdomain( 'theme', get_template_directory() . '/languages' );
	load_theme_textdomain( 'wpinc_plex', __DIR__ . '/plex/languages' );
}

/**
 * Callback function for 'init' action.
 *
 * @access private
 */
function _cb_init_multi_home_lang() {
	$inst = &_get_multi_home_lang_instance();
	\wpinc\plex\filter\add_filter_taxonomy(
		QUERY_VAR_SITE_HOME,
		array(
			'taxonomy'      => TAXONOMY_POST_HOME,
			'slug_to_label' => $inst['filter_term_labels'],
			'label'         => __( 'Homes', 'theme' ),
		)
	);
	\wpinc\plex\filter\add_filter_taxonomy(
		QUERY_VAR_SITE_LANG,
		array(
			'taxonomy'      => TAXONOMY_POST_LANG,
			'slug_to_label' => $inst['filter_term_labels'],
			'label'         => __( 'Languages', 'theme' ),
		)
	);
	\wpinc\plex\filter\add_filtered_post_type( $inst['filtered_post_types'] );
	\wpinc\plex\filter\add_counted_taxonomy( $inst['translated_taxonomies'] );
}


// -----------------------------------------------------------------------------


/**
 * Retrieve current site home.
 */
function get_site_home() {
	return \wpinc\plex\custom_rewrite\get_query_var( QUERY_VAR_SITE_HOME );
}

/**
 * Retrieve current site language.
 */
function get_site_lang() {
	return explode( '_', is_admin() ? get_user_locale() : get_locale() )[0];
}

/**
 * Retrieve home url based on the current site language.
 *
 * @param string   $path Path.
 * @param string[] $vars (Optional) An array of variable name to slug.
 * @return string The home url.
 */
function home_url( string $path = '', array $vars = array() ): string {
	return \wpinc\plex\pseudo_front\home_url( $path, null, $vars );
}

/**
 * Retrieves invalid pagename.
 *
 * @return array|null Invalid pagename.
 */
function get_invalid_pagename(): ?array {
	return \wpinc\plex\custom_rewrite\get_invalid_pagename();
}
