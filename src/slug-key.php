<?php
/**
 * Utilities for Slug-Key Operations
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-24
 */

namespace wpinc\plex;

require_once __DIR__ . '/custom-rewrite.php';

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
		$temp       = implode( '_', custom_rewrite\get_structures( 'default_slug', $vars ) );
		$ret[ $sk ] = str_replace( '-', '_', $temp );
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
		$temp = implode(
			'_',
			array_map(
				function ( $v ) {
					return custom_rewrite\get_query_var( $v );
				},
				custom_rewrite\get_structures( 'var', $vars )
			)
		);

		$ret[ $sk ] = str_replace( '-', '_', $temp );
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
	if ( is_array( $args ) && ! empty( $args ) ) {
		$key = implode(
			'_',
			array_map(
				function ( $var ) use ( $args, $vars ) {
					return $args[ $var ] ?? custom_rewrite\get_query_var( $var );
				},
				custom_rewrite\get_structures( 'var', $vars )
			)
		);
		$key = str_replace( '-', '_', $key );
	} elseif ( is_string( $args ) && ! empty( $args ) ) {
		$key = str_replace( '-', '_', $args );
	} else {
		$key = get_query_key( $vars );
	}
	return $key;
}


// -----------------------------------------------------------------------------


/**
 * Generate an array of slug key to slug combinations.
 *
 * @param ?array $vars               (Optional) Variable names for filtering.
 * @param bool   $is_default_omitted (Optional) Whether the default key is omitted.
 * @return array The array of slug key to slug combinations.
 */
function get_slug_key_to_combination( ?array $vars = null, bool $is_default_omitted = false ): array {
	$dy  = get_default_key( $vars );
	$scs = get_slug_combination( $vars );
	$ret = array();

	foreach ( $scs as $sc ) {
		$key = str_replace( '-', '_', implode( '_', $sc ) );
		if ( $is_default_omitted && $key === $dy ) {
			continue;
		}
		$ret[ $key ] = $sc;
	}
	return $ret;
}

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


// -----------------------------------------------------------------------------


/**
 * Retrieve the label of current query variables.
 *
 * @access private
 *
 * @param string[] $slugs         The slug combination.
 * @param array    $slug_to_label The array of slug to label.
 * @param ?string  $filter        The label format.
 * @return string The label string.
 */
function get_admin_label( array $slugs, array $slug_to_label, ?string $filter = null ): string {
	$ls = array_map(
		function ( $s ) use ( $slug_to_label ) {
			return $slug_to_label[ $s ] ?? $s;
		},
		$slugs
	);
	if ( ! empty( $filter ) ) {
		return sprintf( $filter, ...$ls );
	}
	return implode( ' ', $ls );
}
