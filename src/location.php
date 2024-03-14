<?php
/**
 * Locations
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2024-03-14
 */

declare(strict_types=1);

namespace wpinc\plex\location;

require_once __DIR__ . '/slug-key.php';

/**
 * Adds an array of slug to label.
 *
 * @param array<string, string> $slug_to_label An array of slug to label.
 * @param string|null           $format        A format to assign.
 */
function add_admin_labels( array $slug_to_label, ?string $format = null ): void {
	$inst = _get_instance();

	$inst->slug_to_label = array_merge( $inst->slug_to_label, $slug_to_label );  // @phpstan-ignore-line
	if ( is_string( $format ) ) {
		$inst->label_format = $format;  // @phpstan-ignore-line
	}
}

/**
 * Activates the post content.
 *
 * @param array<string, mixed> $args {
 *     (Optional) Configuration arguments.
 *
 *     @type array  'vars'               Query variable names.
 *     @type string 'editor_type'        Editor type to be activated: 'block' or 'classic'. Default 'block'.
 *     @type string 'title_key_prefix'   Key prefix of post metadata for custom title. Default '_post_title_'.
 *     @type string 'content_key_prefix' Key prefix of post metadata for custom content. Default '_post_content_'.
 * }
 */
function activate( array $args = array() ): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$args += array(
		'do_multiplex_nav_menu' => false,
		'do_multiplex_sidebar'  => false,
	);

	if ( $args['do_multiplex_nav_menu'] ) {
		add_action( 'after_setup_theme', '\wpinc\plex\location\_cb_after_setup_theme', 99, 0 );
		if ( ! is_admin() ) {
			add_filter( 'has_nav_menu', '\wpinc\plex\location\_cb_has_nav_menu', 10, 2 );
			add_filter( 'wp_nav_menu_args', '\wpinc\plex\location\_cb_wp_nav_menu_args', 10, 1 );
		}
	}
	if ( $args['do_multiplex_sidebar'] ) {
		add_action( 'widgets_init', '\wpinc\plex\location\_cb_widgets_init', 99, 0 );
		if ( ! is_admin() ) {
			add_filter( 'sidebars_widgets', '\wpinc\plex\location\_cb_sidebars_widgets', 10, 1 );
		}
	}
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'after_setup_theme' action.
 *
 * @access private
 */
function _cb_after_setup_theme(): void {
	$inst = _get_instance();

	$nms    = get_registered_nav_menus();
	$nms_sl = array();

	$def_key = \wpinc\plex\get_default_key();

	foreach ( \wpinc\plex\get_slug_key_to_combination() as $key => $slugs ) {
		if ( $key === $def_key ) {
			continue;
		}
		$lab = esc_html( \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format ) );
		foreach ( $nms as $loc => $desc ) {
			$id            = "{$loc}-" . str_replace( '_', '-', $key );
			$nms_sl[ $id ] = trim( "$lab $desc" );
		}
	}
	register_nav_menus( $nms_sl );
}

/**
 * Callback function for 'has_nav_menu' filter.
 *
 * @access private
 *
 * @param bool   $has_nav_menu Whether there is a menu assigned to a location.
 * @param string $location     Menu location.
 * @return bool Whether there is a menu assigned to a location.
 */
function _cb_has_nav_menu( $has_nav_menu, $location ) {
	$key = \wpinc\plex\get_query_key();

	if ( \wpinc\plex\get_default_key() !== $key ) {
		$nms = get_registered_nav_menus();
		$id  = "{$location}-" . str_replace( '_', '-', $key );
		if ( isset( $nms[ $id ] ) ) {
			$locations    = get_nav_menu_locations();
			$has_nav_menu = isset( $locations[ $id ] );
		}
	}
	return $has_nav_menu;
}

/**
 * Callback function for 'wp_nav_menu_args' filter.
 *
 * @access private
 *
 * @param array<string, mixed> $args Array of wp_nav_menu() arguments.
 * @return array<string, mixed> Filtered array.
 */
function _cb_wp_nav_menu_args( array $args ): array {
	$key = \wpinc\plex\get_query_key();

	if ( \wpinc\plex\get_default_key() !== $key ) {
		if (
			isset( $args['theme_location'] ) &&
			is_string( $args['theme_location'] ) && '' !== $args['theme_location']  // Check for non-empty-string.
		) {
			$loc = $args['theme_location'];
			$id  = "{$loc}-" . str_replace( '_', '-', $key );

			$args['theme_location'] = $id;
		}
	}
	return $args;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'widgets_init' action.
 *
 * @access private
 */
function _cb_widgets_init(): void {
	global $wp_registered_sidebars;
	$ss = $wp_registered_sidebars;

	$inst    = _get_instance();
	$def_key = \wpinc\plex\get_default_key();

	foreach ( \wpinc\plex\get_slug_key_to_combination() as $key => $slugs ) {
		if ( $key === $def_key ) {
			continue;
		}
		foreach ( $ss as $sidebar ) {
			$inst->sidebar_ids[] = $sidebar['id'];  // @phpstan-ignore-line

			$lab    = esc_html( \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format ) );
			$id_new = "{$sidebar['id']}-" . str_replace( '_', '-', $key );

			$sidebar['id']   = $id_new;
			$sidebar['name'] = trim( "$lab {$sidebar['name']}" );

			register_sidebar( $sidebar );
		}
	}
}

/**
 * Callback function for 'sidebars_widgets' filter.
 *
 * @access private
 *
 * @param array<string, mixed> $sidebars_widgets An associative array of sidebars and their widgets.
 * @return array<string, mixed> Filtered array.
 */
function _cb_sidebars_widgets( array $sidebars_widgets ): array {
	$inst = _get_instance();
	$key  = \wpinc\plex\get_query_key();

	if ( \wpinc\plex\get_default_key() !== $key && ! is_admin() && ! wp_doing_ajax() ) {
		foreach ( $inst->sidebar_ids as $id ) {
			$id_new = "$id-" . str_replace( '_', '-', $key );

			$sidebars_widgets[ $id ] = $sidebars_widgets[ $id_new ];
		}
	}
	return $sidebars_widgets;
}


// -----------------------------------------------------------------------------


/**
 * Gets instance.
 *
 * @access private
 *
 * @return object{
 *     slug_to_label: array<string, string>,
 *     label_format : string,
 *     sidebar_ids  : string[],
 * } Instance.
 */
function _get_instance(): object {
	static $values = null;
	if ( $values ) {
		return $values;
	}
	$values = new class() {
		/**
		 * The array of slug to label.
		 *
		 * @var array<string, string>
		 */
		public $slug_to_label = array();

		/**
		 * The label format.
		 *
		 * @var string
		 */
		public $label_format = '';

		/**
		 * Sidebar IDs.
		 *
		 * @var string[]
		 */
		public $sidebar_ids = array();
	};
	return $values;
}
