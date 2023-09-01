<?php
/**
 * Multi-Term Filter
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2023-09-01
 */

namespace wpinc\plex\multi_term_filter;

/**
 * Adds function to retrieve terms.
 *
 * @param callable    $fn        Function for obtaining terms.
 * @param string|null $post_type A post type.
 */
function add_function_to_retrieve_terms( callable $fn, ?string $post_type = null ): void {
	$inst = _get_instance();
	if ( null === $post_type ) {  // For use when is_search() is true.
		$inst->pt_fn[0] = $fn;
	} else {
		$inst->pt_fn[ $post_type ] = $fn;
	}
}

/**
 * Activates the multi-term filter.
 */
function activate(): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	add_filter( 'get_next_post_join', '\wpinc\plex\multi_term_filter\_cb_get_adjacent_post_join', 10, 5 );
	add_filter( 'get_previous_post_join', '\wpinc\plex\multi_term_filter\_cb_get_adjacent_post_join', 10, 5 );
	add_filter( 'get_next_post_where', '\wpinc\plex\multi_term_filter\_cb_get_adjacent_post_where', 10, 5 );
	add_filter( 'get_previous_post_where', '\wpinc\plex\multi_term_filter\_cb_get_adjacent_post_where', 10, 5 );

	add_filter( 'getarchives_join', '\wpinc\plex\multi_term_filter\_cb_getarchives_join', 10, 2 );
	add_filter( 'getarchives_where', '\wpinc\plex\multi_term_filter\_cb_getarchives_where', 10, 2 );

	add_filter( 'posts_join', '\wpinc\plex\multi_term_filter\_cb_posts_join', 10, 2 );
	add_filter( 'posts_where', '\wpinc\plex\multi_term_filter\_cb_posts_where', 10, 2 );
	add_filter( 'posts_groupby', '\wpinc\plex\multi_term_filter\_cb_posts_groupby', 10, 2 );
}

/**
 * Counts posts with terms.
 *
 * @global $wpdb;
 *
 * @param string|string[] $post_type_s     A post type or an array of post types.
 * @param int[]           $term_taxonomies The array of term taxonomy ids.
 * @return int Count of posts.
 */
function count_posts_with_terms( $post_type_s, array $term_taxonomies ): int {
	if ( empty( $term_taxonomies ) ) {
		return 0;
	}
	$inst = _get_instance();
	$pts  = (array) $post_type_s;
	$pts  = "('" . implode( "', '", esc_sql( $pts ) ) . "')";

	global $wpdb;
	$q  = "SELECT COUNT(*) FROM $wpdb->posts AS p";
	$q .= _build_join_term_relationships( count( $term_taxonomies ), 'p' );
	$q .= " WHERE 1=1 AND p.post_status = 'publish' AND p.post_type IN $pts";
	$q .= ' AND ' . _build_where_term_relationships( $term_taxonomies );

	return (int) $wpdb->get_var( $q );  // phpcs:ignore
}


// -----------------------------------------------------------------------------


/**
 * Retrieves term-taxonomy ids.
 *
 * @access private
 *
 * @param string|null $post_type Post type.
 * @return int[]|null The ids.
 */
function _get_term_taxonomy_ids( ?string $post_type = null ): ?array {
	static $ret = null;
	if ( $ret ) {
		return $ret;
	}
	$inst = _get_instance();
	if ( is_string( $post_type ) ) {
		if ( ! isset( $inst->pt_fn[ $post_type ] ) ) {
			return null;
		}
	} else {  // When is_search() is true.
		if ( ! isset( $inst->pt_fn[0] ) ) {
			return null;
		}
	}
	$ts = $inst->pt_fn[ $post_type ?? 0 ]();
	return empty( $ts ) ? null : array_column( $ts, 'term_taxonomy_id' );
}

/**
 * Gets post type from query vars array.
 *
 * @access private
 *
 * @param array<string, mixed> $query_vars Array of query vars.
 * @return string Post type.
 */
function _get_post_type_from_query_vars( array $query_vars ): string {
	$pt = '';  // Must be initialized with '' to distinguish it from null.
	if ( ! empty( $query_vars['post_type'] ) ) {
		if ( is_array( $query_vars['post_type'] ) ) {
			$pt = $query_vars['post_type'][0];
		} else {
			$pt = $query_vars['post_type'];
		}
	}
	return $pt;
}

/**
 * Builds term relationships for JOIN clause.
 *
 * @access private
 * @global $wpdb;
 *
 * @param int    $count    The number of joined tables.
 * @param string $wp_posts Table name of wp_posts.
 * @param string $type     Operation type.
 * @return string The JOIN clause.
 */
function _build_join_term_relationships( int $count, string $wp_posts, string $type = 'INNER' ): string {
	global $wpdb;
	$q = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$q[] = " $type JOIN {$wpdb->term_relationships} AS tr$i ON ($wp_posts.ID = tr$i.object_id)";
	}
	return implode( '', $q );
}

/**
 * Builds term relationships for WHERE clause.
 *
 * @access private
 * @global $wpdb;
 *
 * @param int[] $term_taxonomies The array of term taxonomy ids.
 * @return string The WHERE clause.
 */
function _build_where_term_relationships( array $term_taxonomies ): string {
	global $wpdb;
	$q = array();
	foreach ( $term_taxonomies as $i => $tt ) {
		$q[] = $wpdb->prepare( 'tr%d.term_taxonomy_id = %d', $i, $tt );
	}
	return implode( ' AND ', $q );
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'get_{$adjacent}_post_join' filter.
 *
 * @access private
 *
 * @param string       $join           The JOIN clause in the SQL.
 * @param bool         $in_same_term   Whether post should be in a same taxonomy term.
 * @param int[]|string $excluded_terms Array of excluded term IDs.
 * @param string       $taxonomy       Used to identify the term used when $in_same_term is true.
 * @param \WP_Post     $post           WP_Post object.
 * @return string The filtered clause.
 */
function _cb_get_adjacent_post_join( string $join, bool $in_same_term, $excluded_terms, string $taxonomy, \WP_Post $post ): string {
	$tts = _get_term_taxonomy_ids( $post->post_type );
	if ( ! empty( $tts ) ) {
		$join .= _build_join_term_relationships( count( $tts ), 'p' );
	}
	return $join;
}

/**
 * Callback function for 'get_{$adjacent}_post_where' filter.
 *
 * @access private
 *
 * @param string       $where          The WHERE clause in the SQL.
 * @param bool         $in_same_term   Whether post should be in a same taxonomy term.
 * @param int[]|string $excluded_terms Array of excluded term IDs.
 * @param string       $taxonomy       Used to identify the term used when $in_same_term is true.
 * @param \WP_Post     $post           WP_Post object.
 * @return string The filtered clause.
 */
function _cb_get_adjacent_post_where( string $where, bool $in_same_term, $excluded_terms, string $taxonomy, \WP_Post $post ): string {
	$tts = _get_term_taxonomy_ids( $post->post_type );
	if ( ! empty( $tts ) ) {
		$where .= ' AND ' . _build_where_term_relationships( $tts );
	}
	return $where;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'getarchives_join' filter.
 *
 * @access private
 * @global $wpdb
 *
 * @param string               $sql_join    Portion of SQL query containing the JOIN clause.
 * @param array<string, mixed> $parsed_args An array of default arguments.
 * @return string The filtered clause.
 */
function _cb_getarchives_join( string $sql_join, array $parsed_args ): string {
	$tts = _get_term_taxonomy_ids( $parsed_args['post_type'] );
	if ( ! empty( $tts ) ) {
		global $wpdb;
		$sql_join .= _build_join_term_relationships( count( $tts ), $wpdb->posts );
	}
	return $sql_join;
}

/**
 * Callback function for 'getarchives_where' filter.
 *
 * @access private
 *
 * @param string               $sql_where   Portion of SQL query containing the WHERE clause.
 * @param array<string, mixed> $parsed_args An array of default arguments.
 * @return string The filtered clause.
 */
function _cb_getarchives_where( string $sql_where, array $parsed_args ): string {
	$tts = _get_term_taxonomy_ids( $parsed_args['post_type'] );
	if ( ! empty( $tts ) ) {
		$sql_where .= ' AND ' . _build_where_term_relationships( $tts );
	}
	return $sql_where;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'posts_join' filter.
 *
 * @access private
 * @global $wpdb
 *
 * @param string    $join  The JOIN BY clause of the query.
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 * @return string The filtered clause.
 */
function _cb_posts_join( string $join, \WP_Query $query ): string {
	if ( $query->is_search() ) {
		$tts = _get_term_taxonomy_ids();  // Call when is_search() is true.
	} else {
		$pt  = _get_post_type_from_query_vars( $query->query_vars );
		$tts = _get_term_taxonomy_ids( $pt );  // $pt is '' when the post type is unknown.
	}
	if ( empty( $tts ) ) {
		return $join;
	}
	global $wpdb;
	if ( $query->is_search() ) {
		$join .= _build_join_term_relationships( count( $tts ), $wpdb->posts, 'LEFT' );
	} else {
		$join .= _build_join_term_relationships( count( $tts ), $wpdb->posts );
	}
	return $join;
}

/**
 * Callback function for 'posts_where' filter.
 *
 * @access private
 * @global $wpdb
 *
 * @param string    $where The WHERE clause of the query.
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 * @return string The filtered clause.
 */
function _cb_posts_where( string $where, \WP_Query $query ): string {
	if ( $query->is_search() ) {
		$tts = _get_term_taxonomy_ids();  // Call when is_search() is true.
	} else {
		$pt  = _get_post_type_from_query_vars( $query->query_vars );
		$tts = _get_term_taxonomy_ids( $pt );  // $pt is '' when the post type is unknown.
	}
	if ( empty( $tts ) ) {
		return $where;
	}
	global $wpdb;
	if ( $query->is_search() ) {
		$inst = _get_instance();
		$pts  = "('" . implode( "', '", array_keys( $inst->pt_fn ) ) . "')";

		$where .= " AND ({$wpdb->posts}.post_type NOT IN $pts";
		$where .= ' OR (' . _build_where_term_relationships( $tts ) . '))';
	} else {
		$where .= ' AND ' . _build_where_term_relationships( $tts );
	}
	return $where;
}

/**
 * Callback function for 'posts_groupby' filter.
 *
 * @access private
 * @global $wpdb
 *
 * @param string    $groupby The GROUP BY clause of the query.
 * @param \WP_Query $query   The WP_Query instance (passed by reference).
 * @return string The filtered clause.
 */
function _cb_posts_groupby( string $groupby, \WP_Query $query ): string {
	if ( $query->is_search() ) {
		global $wpdb;
		$g = "{$wpdb->posts}.ID";

		if ( preg_match( "/$g/", $groupby ) ) {
			return $groupby;
		}
		if ( empty( trim( $groupby ) ) ) {
			return $g;
		}
		$groupby .= ", $g";
	}
	return $groupby;
}


// -------------------------------------------------------------------------


/**
 * Gets instance.
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
		 * The array of post type to functions for obtaining terms.
		 *
		 * @var array<string, callable>
		 */
		public $pt_fn = array();
	};
	return $values;
}
