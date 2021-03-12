<?php
/**
 * Utilities
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-12
 */

namespace wpinc\plex;

/**
 * Generate combinations of given strings.
 *
 * @param array $arrays An array of string arrays.
 * @return array The array of combinations.
 */
function generate_combination( array $arrays ): array {
	$counts = array_map( 'count', $arrays );
	$total  = array_product( $counts );
	$res    = array();
	$cycles = array();

	$c = $total;
	foreach ( $arrays as $k => $vs ) {
		$c = $c / $counts[ $k ];

		$cycles[ $k ] = $c;
	}

	for ( $i = 0; $i < $total; ++$i ) {
		foreach ( $arrays as $k => $vs ) {
			$res[ $i ][ $k ] = $vs[ ( $i / $cycles[ $k ] ) % $counts[ $k ] ];
		}
	}
	return $res;
}
