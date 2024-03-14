<?php
/**
 * Option Fields
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2024-03-14
 */

declare(strict_types=1);

namespace wpinc\plex\option_field;

require_once __DIR__ . '/custom-rewrite.php';
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
 * Activates the option fields.
 *
 * @param array<string, mixed> $args {
 *     (Optional) Configuration arguments.
 *
 *     @type array 'vars' Query variable names.
 * }
 */
function activate( array $args = array() ): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'vars' => array(),
	);

	$inst->vars = $args['vars'];  // @phpstan-ignore-line

	if ( is_admin() ) {
		add_action( 'admin_head', '\wpinc\plex\option_field\_cb_admin_head', 10, 0 );
		add_action( 'admin_init', '\wpinc\plex\option_field\_cb_admin_init', 10, 0 );
	} else {
		add_filter( 'option_date_format', '\wpinc\plex\option_field\_cb_option_date_format' );
		add_filter( 'option_time_format', '\wpinc\plex\option_field\_cb_option_time_format' );
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
	$inst = _get_instance();
	$ret  = get_option( 'date_format_' . \wpinc\plex\get_query_key( $inst->vars ) );
	if ( ! is_string( $ret ) || '' === $ret ) {  // Check for non-empty-string.
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
	$inst = _get_instance();
	$ret  = get_option( 'time_format_' . \wpinc\plex\get_query_key( $inst->vars ) );
	if ( ! is_string( $ret ) || '' === $ret ) {  // Check for non-empty-string.
		return $value;
	}
	return $ret;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'admin_head' action.
 *
 * @access private
 */
function _cb_admin_head(): void {
	?>
<style>
	.wpinc-plex-option-field th { padding-top: 10px; padding-bottom: 10px; }
</style>
	<?php
}

/**
 * Callback function for 'admin_init' action.
 *
 * @access private
 */
function _cb_admin_init(): void {
	$inst = _get_instance();

	add_settings_section( 'option-field-section', _x( 'Option Fields', 'option field', 'wpinc_plex' ), function () {}, 'general' );

	foreach ( \wpinc\plex\get_slug_key_to_combination( $inst->vars, true ) as $key => $slugs ) {
		$key_date = "date_format_$key";
		$key_time = "time_format_$key";
		register_setting( 'general', $key_date );
		register_setting( 'general', $key_time );

		$lab = esc_html( \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format ) );
		add_settings_field(
			$key_date,
			__( 'Date Format' ) . "<br>$lab",
			function () use ( $key_date ) {
				\wpinc\plex\option_field\_cb_field_input( $key_date );
			},
			'general',
			'option-field-section',
			array(
				'label_for' => $key_date,
				'class'     => 'wpinc-plex-option-field',
			)
		);
		add_settings_field(
			$key_time,
			__( 'Time Format' ) . "<br>$lab",
			function () use ( $key_time ) {
				\wpinc\plex\option_field\_cb_field_input( $key_time );
			},
			'general',
			'option-field-section',
			array(
				'label_for' => $key_time,
				'class'     => 'wpinc-plex-option-field',
			)
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
function _cb_field_input( string $key ): void {
	$val = get_option( $key );
	$val = is_string( $val ) ? $val : '';
	printf(
		'<input id="%1$s" name="%1$s" type="text" value="%2$s" class="regular-text">',
		esc_attr( $key ),
		esc_attr( $val )
	);
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
 *     vars         : string[],
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
		 * The array of variable names.
		 *
		 * @var string[]
		 */
		public $vars = array();
	};
	return $values;
}
