<?php
/**
 * Option Fields
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-21
 */

namespace wpinc\plex\option_field;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/slug-key.php';

/**
 * Add an array of slug to label.
 *
 * @param array  $slug_to_label An array of slug to label.
 * @param string $format        A format to assign.
 */
function add_admin_labels( array $slug_to_label, ?string $format = null ) {
	$inst = _get_instance();

	$inst->slug_to_label = array_merge( $inst->slug_to_label, $slug_to_label );
	if ( $format ) {
		$inst->label_format = $format;
	}
}

/**
 * Initialize the option fields.
 *
 * @param array $args {
 *     Configuration arguments.
 *
 *     @type array $vars Query variable names.
 * }
 */
function initialize( array $args = array() ) {
	static $initialized = 0;
	if ( $initialized++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'vars' => array(),
	);

	$inst->vars = $args['vars'];

	if ( is_admin() ) {
		add_action( 'admin_init', '\wpinc\plex\pseudo_front\_cb_admin_init' );
	} else {
		add_filter( 'option_date_format', '\wpinc\plex\pseudo_front\_cb_option_date_format' );
		add_filter( 'option_time_format', '\wpinc\plex\pseudo_front\_cb_option_time_format' );
	}
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'option_{$option}' filter.
 *
 * @access private
 *
 * @param string $value Value of the option.
 * @return string The filtered string.
 */
function _cb_option_date_format( string $value ): string {
	$ret = get_option( 'date_format_' . \wpinc\plex\get_query_key() );
	if ( false === $ret ) {
		return $value;
	}
	return $ret;
}

/**
 * Callback function for 'option_{$option}' filter.
 *
 * @access private
 *
 * @param string $value Value of the option.
 * @return string The filtered string.
 */
function _cb_option_time_format( string $value ): string {
	$ret = get_option( 'time_format_' . \wpinc\plex\get_query_key() );
	if ( false === $ret ) {
		return $value;
	}
	return $ret;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'admin_init' action.
 *
 * @access private
 */
function _cb_admin_init() {
	$inst    = _get_instance();
	$def_key = \wpinc\plex\get_default_key();

	add_settings_section( 'option-field-section', __( 'Sites' ), function () {}, 'general' );

	foreach ( \wpinc\plex\get_slug_key_to_combination() as $key => $slugs ) {
		if ( $key === $def_key ) {
			continue;
		}
		$key_date = "date_format_$key";
		$key_time = "date_format_$key";
		register_setting( 'general', $key_date );
		register_setting( 'general', $key_time );

		$lab = esc_html( \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format ) );
		add_settings_field(
			$key_date,
			__( 'Date Format' ) . "<br>$lab",
			function () use ( $key_date ) {
				\wpinc\plex\pseudo_front\_cb_field_input( $key_date );
			},
			'general',
			'option-field-section',
			array( 'label_for' => $key_date )
		);
		add_settings_field(
			$key_time,
			__( 'Time Format' ) . "<br>$lab",
			function () use ( $key_time ) {
				\wpinc\plex\pseudo_front\_cb_field_input( $key_time );
			},
			'general',
			'option-field-section',
			array( 'label_for' => $key_time )
		);
	}
}

/**
 * Function that fills the field with the desired form inputs.
 *
 * @access private
 *
 * @param string $key The key of the field.
 */
function _cb_field_input( string $key ) {
	$_key = esc_attr( $key );
	$_val = esc_attr( get_option( $key ) );
	// phpcs:disable
	echo "<input id=\"$_key\" name=\"$_key\" type=\"text\" value=\"$_val\" class=\"regular-text\">";
	// phpcs:enable
}


// -----------------------------------------------------------------------------


/**
 * Get instance.
 *
 * @access private
 *
 * @return object Instance.
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
		 * @var array
		 */
		public $slug_to_label = array();

		/**
		 * The label format.
		 *
		 * @var string
		 */
		public $label_format = '';

		/**
		 * The array of variable names.
		 *
		 * @var array
		 */
		public $vars = array();
	};
	return $values;
}
