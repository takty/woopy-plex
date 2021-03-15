<?php
/**
 * Utilities
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-14
 */

namespace wpinc\plex;

/**
 * Retrieve the key of default query variables.
 *
 * @param ?array $vars (Optional) Variable names for filtering.
 * @return string The key string.
 */
function get_default_key( ?array $vars = null ): string {
	static $ret = array();

	$sk = wp_json_encode( $vars );
	if ( ! isset( $ret[ $sk ] ) ) {
		$ret[ $sk ] = implode(
			'_',
			custom_rewrite\get_structures( 'default_slug', $vars )
		);
	}
	return $ret[ $sk ];
}

/**
 * Retrieve the key of current query variables.
 *
 * @param ?array $vars (Optional) Variable names for filtering.
 * @return string The key string.
 */
function get_query_key( ?array $vars = null ): string {
	static $ret = array();

	$sk = wp_json_encode( $vars );
	if ( ! isset( $ret[ $sk ] ) ) {
		$ret[ $sk ] = implode(
			'_',
			array_map(
				function ( $v ) {
					return custom_rewrite\get_query_var( $v );
				},
				custom_rewrite\get_structures( 'var', $vars )
			)
		);
	}
	return $ret[ $sk ];
}

/**
 * Retrieve the key of argument variables.
 *
 * @param mixed  $args An array of variable name to slugs.
 * @param ?array $vars (Optional) Variable names for filtering.
 * @return string The key string.
 */
function get_argument_key( $args, ?array $vars = null ): string {
	$inst = _get_instance();
	if ( is_array( $args ) && ! empty( $args ) ) {
		$key = implode(
			'_',
			array_map(
				function ( $var ) use ( $args ) {
					return $args[ $var ] ?? custom_rewrite\get_query_var( $var );
				},
				custom_rewrite\get_structures( 'var', $inst->vars )
			)
		);
	} elseif ( is_string( $args ) && ! empty( $args ) ) {
		$key = $args;
	} else {
		$key = get_query_key( $inst->vars );
	}
	return $key;
}


// -----------------------------------------------------------------------------


/**
 * Generate slug combinations.
 *
 * @param ?array $vars (Optional) Variable names for filtering.
 * @return array The array of slug combinations.
 */
function get_slug_combination( ?array $vars = null ): array {
	static $ret = array();

	$sk = wp_json_encode( $vars );
	if ( ! isset( $ret[ $sk ] ) ) {
		$ret[ $sk ] = _generate_combination(
			custom_rewrite\get_structures( 'slugs', $vars )
		);
	}
	return $ret[ $sk ];
}


// -----------------------------------------------------------------------------


/**
 * Generate combinations of given strings.
 *
 * @access private
 *
 * @param array $arrays An array of string arrays.
 * @return array The array of combinations.
 */
function _generate_combination( array $arrays ): array {
	$counts = array_map( 'count', $arrays );
	$total  = array_product( $counts );
	$cycles = array();

	$c = $total;
	foreach ( $arrays as $k => $vs ) {
		$c /= $counts[ $k ];

		$cycles[ $k ] = $c;
	}
	$res = array();
	for ( $i = 0; $i < $total; ++$i ) {
		$temp = array();
		foreach ( $arrays as $k => $vs ) {
			$temp[ $k ] = $vs[ ( $i / $cycles[ $k ] ) % $counts[ $k ] ];
		}
		$res[] = $temp;
	}
	return $res;
}
