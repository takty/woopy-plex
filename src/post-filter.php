<?php
/**
 * Post Filter
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-05
 */

namespace wpinc\plex\post_filter;

require_once __DIR__ . '/pseudo-front.php';

/**
 * Register taxonomy used for filtering.
 *
 * @param string  $tax The taxonomy used for filtering.
 * @param string  $label The label of the taxonomy.
 * @param ?string $var (Optional) The query variable name related to the taxonomy.
 */
function add_filter_taxonomy( string $tax, string $label, ?string $var = null ) {
	$var  = $var ?? $tax;
	$args = array(
		'label'             => $label,
		'show_in_nav_menus' => false,
		'show_admin_column' => true,
		'hierarchical'      => true,
		'query_var'         => false,
		'rewrite'           => false,
	);
	register_taxonomy( $tax, null, $args );
	$inst = _get_instance();
	foreach ( $inst->post_types as $pt ) {
		register_taxonomy_for_object_type( $tax, $pt );
	}
	$inst->var_to_taxonomy[ $var ] = $tax;
}

/**
 * Add filtered post types.
 *
 * @param array|string $post_type_s Post types or a post type.
 */
function add_filtered_post_type( $post_type_s ) {
	if ( ! is_array( $post_type_s ) ) {
		$post_type_s = array( $post_type_s );
	}
	$inst = _get_instance();
	foreach ( $post_type_s as $pt ) {
		foreach ( $inst->var_to_taxonomy as $tax ) {
			register_taxonomy_for_object_type( $tax, $pt );
		}
	}
	$inst->post_types = array_merge( $inst->post_types, $post_type_s );
}

/**
 * Add filtered taxonomies.
 *
 * @param array|string $tax_s Taxonomies or a taxonomy.
 */
function add_filtered_taxonomy( $tax_s ) {
	$inst = _get_instance();
	if ( ! is_array( $tax_s ) ) {
		$tax_s = array( $tax_s );
	}
	$inst->filtered_taxonomies = array_merge( $inst->filtered_taxonomies, $tax_s );
}

/**
 * Initialize the post filter.
 */
function initialize() {
	if ( is_admin() ) {
		add_action( 'edited_term_taxonomy', '\wpinc\plex\post_filter\_cb_edited_term_taxonomy', 10, 2 );
	} else {
		add_filter( 'get_next_post_join', '\wpinc\plex\post_filter\_cb_get_adjacent_post_join', 10, 5 );
		add_filter( 'get_previous_post_join', '\wpinc\plex\post_filter\_cb_get_adjacent_post_join', 10, 5 );
		add_filter( 'get_next_post_where', '\wpinc\plex\post_filter\_cb_get_adjacent_post_where', 10, 5 );
		add_filter( 'get_previous_post_where', '\wpinc\plex\post_filter\_cb_get_adjacent_post_where', 10, 5 );

		add_filter( 'getarchives_join', '\wpinc\plex\post_filter\_cb_getarchives_join', 10, 2 );
		add_filter( 'getarchives_where', '\wpinc\plex\post_filter\_cb_getarchives_where', 10, 2 );

		add_action( 'posts_join', '\wpinc\plex\post_filter\_cb_posts_join', 10, 2 );
		add_action( 'posts_where', '\wpinc\plex\post_filter\_cb_posts_where', 10, 2 );
		add_action( 'posts_groupby', '\wpinc\plex\post_filter\_cb_posts_groupby', 10, 2 );

		\wpinc\plex\custom_rewrite\add_post_link_filter( '\wpinc\plex\post_filter\_cb_filter_by_taxonomy' );
	}
}


// -----------------------------------------------------------------------------


/**
 * Build term relationships for JOIN clause.
 *
 * @internal
 * @global $wpdb;
 *
 * @param int    $count The number of joined tables.
 * @param string $type The operation type.
 * @return string The JOIN clause.
 */
function _build_join_term_relationships( int $count, string $type = 'INNER' ): string {
	global $wpdb;
	$q = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$q[] = " $type JOIN {$wpdb->term_relationships} AS tr$i ON (p.ID = tr$i.object_id)";
	}
	return implode( '', $q );
}

/**
 * Build term relationships for WHERE clause.
 *
 * @internal
 * @global $wpdb;
 *
 * @param array $term_taxonomies The array of term taxonomy ids.
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

/**
 * Retrieve term taxonomy ids.
 *
 * @internal
 *
 * @return array The ids.
 */
function _get_term_taxonomy_ids(): array {
	static $ret = null;
	if ( $ret ) {
		return $ret;
	}
	$ret = array();

	$inst = _get_instance();
	$vars = \wpinc\plex\custom_rewrite\get_structures( 'var' );
	foreach ( $vars as $var ) {
		if ( ! isset( $inst->var_to_taxonomy[ $var ] ) ) {
			continue;
		}
		$tax  = $inst->var_to_taxonomy[ $var ];
		$term = get_term_by( 'slug', get_query_var( $tax ), $tax );
		if ( false === $term ) {
			continue;
		}
		$ret[] = $term->term_taxonomy_id;
	}
	return $ret;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'get_{$adjacent}_post_join' filter.
 *
 * @internal
 *
 * @param string   $join The JOIN clause in the SQL.
 * @param bool     $in_same_term Whether post should be in a same taxonomy term.
 * @param array    $excluded_terms Array of excluded term IDs.
 * @param string   $taxonomy Used to identify the term used when $in_same_term is true.
 * @param \WP_Post $post WP_Post object.
 * @return string The filtered clause.
 */
function _cb_get_adjacent_post_join( string $join, bool $in_same_term, $excluded_terms, string $taxonomy, \WP_Post $post ): string {
	$inst = _get_instance();
	if ( ! in_array( $post->post_type, $inst->post_types, true ) ) {
		return $join;
	}
	$tts = _get_term_taxonomy_ids();
	if ( ! empty( $tts ) ) {
		$join .= _build_join_term_relationships( count( $tts ) );
	}
	return $join;
}

/**
 * Callback function for 'get_{$adjacent}_post_where' filter.
 *
 * @internal
 *
 * @param string   $where The WHERE clause in the SQL.
 * @param bool     $in_same_term Whether post should be in a same taxonomy term.
 * @param array    $excluded_terms Array of excluded term IDs.
 * @param string   $taxonomy Used to identify the term used when $in_same_term is true.
 * @param \WP_Post $post WP_Post object.
 * @return string The filtered clause.
 */
function _cb_get_adjacent_post_where( string $where, bool $in_same_term, array $excluded_terms, string $taxonomy, \WP_Post $post ): string {
	$inst = _get_instance();
	if ( ! in_array( $post->post_type, $inst->post_types, true ) ) {
		return $where;
	}
	$tts = _get_term_taxonomy_ids();
	if ( ! empty( $tts ) ) {
		$where .= ' AND ' . _build_where_term_relationships( $tts );
	}
	return $where;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'getarchives_join' filter.
 *
 * @internal
 *
 * @param string $sql_join Portion of SQL query containing the JOIN clause.
 * @param array  $parsed_args An array of default arguments.
 * @return string The filtered clause.
 */
function _cb_getarchives_join( string $sql_join, array $parsed_args ): string {
	if ( ! is_main_query() ) {
		return $sql_join;
	}
	$inst = _get_instance();
	if ( ! in_array( $parsed_args['post_type'], $inst->post_types, true ) ) {
		return $sql_join;
	}
	$tts = _get_term_taxonomy_ids();
	if ( ! empty( $tts ) ) {
		$sql_join .= _build_join_term_relationships( count( $tts ) );
	}
	return $sql_join;
}

/**
 * Callback function for 'getarchives_where' filter.
 *
 * @internal
 *
 * @param string $sql_where Portion of SQL query containing the WHERE clause.
 * @param array  $parsed_args An array of default arguments.
 * @return string The filtered clause.
 */
function _cb_getarchives_where( string $sql_where, array $parsed_args ): string {
	if ( ! is_main_query() ) {
		return $sql_where;
	}
	$inst = _get_instance();
	if ( ! in_array( $parsed_args['post_type'], $inst->post_types, true ) ) {
		return $sql_where;
	}
	$tts = _get_term_taxonomy_ids();
	if ( ! empty( $tts ) ) {
		$sql_where .= ' AND ' . _build_where_term_relationships( $tts );
	}
	return $sql_where;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'posts_join' filter.
 *
 * @internal
 * @global $wpdb
 *
 * @param string    $join The JOIN BY clause of the query.
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 * @return string The filtered clause.
 */
function _cb_posts_join( string $join, \WP_Query $query ): string {
	$inst = _get_instance();
	if ( ! is_main_query() || empty( $inst->post_types ) ) {
		return $join;
	}
	$tts = _get_term_taxonomy_ids();
	if ( empty( $tts ) ) {
		return $join;
	}
	global $wpdb;
	if ( in_array( $query->query_vars['post_type'], $inst->post_types, true ) ) {
		$join .= _build_join_term_relationships( count( $tts ) );
	} elseif ( is_search() ) {
		$join .= _build_join_term_relationships( count( $tts ), 'LEFT' );
	}
	return $join;
}

/**
 * Callback function for 'posts_where' filter.
 *
 * @internal
 * @global $wpdb
 *
 * @param string    $where The WHERE clause of the query.
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 * @return string The filtered clause.
 */
function _cb_posts_where( string $where, \WP_Query $query ): string {
	$inst = _get_instance();
	if ( ! is_main_query() || empty( $inst->post_types ) ) {
		return $where;
	}
	$tts = _get_term_taxonomy_ids();
	if ( empty( $tts ) ) {
		return $where;
	}
	global $wpdb;
	if ( in_array( $query->query_vars['post_type'], $inst->post_types, true ) ) {
		$where .= ' AND ' . _build_where_term_relationships( $tts );
	} elseif ( is_search() ) {
		$pts = "('" . implode( "', '", $inst->post_types ) . "')";

		$where .= " AND ({$wpdb->posts}.post_type NOT IN $pts";
		$where .= ' OR (' . _build_where_term_relationships( $tts ) . '))';
	}
	return $where;
}

/**
 * Callback function for 'posts_groupby' filter.
 *
 * @internal
 * @global $wpdb
 *
 * @param string    $groupby The GROUP BY clause of the query.
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 * @return string The filtered clause.
 */
function _cb_posts_groupby( string $groupby, \WP_Query $query ): string {
	if ( ! is_main_query() ) {
		return $groupby;
	}
	if ( is_search() ) {
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


// -----------------------------------------------------------------------------


/**
 * Callback function for post_link filter of the custom rewrite.
 *
 * @internal
 *
 * @param array     $vars The query vars.
 * @param ?\WP_Post $post The post in question.
 * @return array The filtered vars.
 */
function _cb_filter_by_taxonomy( array $vars, ?\WP_Post $post = null ): array {
	if ( ! is_admin() || ! is_a( $post, 'WP_Post' ) ) {
		return $vars;
	}
	$inst = _get_instance();
	if ( ! in_array( $post->post_type, $inst->post_types, true ) ) {
		return $vars;
	}
	$vars = \wpinc\plex\custom_rewrite\get_structures( 'var' );
	foreach ( $vars as $var ) {
		if ( ! isset( $inst->var_to_taxonomy[ $var ] ) ) {
			continue;
		}
		$tax = $inst->var_to_taxonomy[ $var ];
		$ts  = get_the_terms( $post->ID, $tax );
		if ( ! is_array( $ts ) ) {
			return $vars;
		}
		$term_slug = get_query_var( $tax );
		$slugs     = array_map(
			function ( $t ) {
				return $t->slug;
			},
			$ts
		);
		if ( in_array( $term_slug, $slugs, true ) ) {
			$vars[ $tax ] = $term_slug;
		}
	}
	return $vars;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'edited_term_taxonomy' action.
 *
 * @internal
 * @global $wpdb
 *
 * @param int    $tt_id Term taxonomy ID.
 * @param string $taxonomy Taxonomy slug.
 */
function _cb_edited_term_taxonomy( int $tt_id, string $taxonomy ) {
	$inst = _get_instance();
	if ( empty( $inst->filtered_taxonomies ) ) {
		return;
	}
	$taxes       = array();
	$slugs_array = array();
	foreach ( \wpinc\plex\custom_rewrite\get_structures() as $st ) {
		if ( ! isset( $inst->var_to_taxonomy[ $st['var'] ] ) ) {
			continue;
		}
		$taxes[]       = $inst->var_to_taxonomy[ $st['var'] ];
		$slugs_array[] = $st['slugs'];
	}
	$is_filtered = in_array( $taxonomy, $inst->filtered_taxonomies, true );
	if ( ! $is_filtered && ! in_array( $taxonomy, $taxes, true ) ) {
		return;
	}
	if ( $is_filtered ) {
		$tars = array( $tt_id );
	} else {
		$tars = get_terms( $inst->filtered_taxonomies, array( 'hide_empty' => false ) );
	}
	$pts       = "('" . implode( "', '", $inst->post_types ) . "')";
	$slug_comb = \wpinc\plex\pseudo_front\generate_combination( $slugs_array );

	global $wpdb;
	foreach ( $tars as $tar ) {
		foreach ( $slug_comb as $slugs ) {
			$tts = array();
			foreach ( $taxes as $idx => $tax ) {
				$t = get_term_by( 'slug', $slugs[ $idx ], $tax );
				if ( false === $t ) {
					continue;
				}
				$tts[] = $t->term_taxonomy_id;
			}
			$count = 0;
			if ( ! empty( $tts ) ) {
				$q  = 'SELECT COUNT(*) FROM wp_posts AS p';
				$q .= " INNER JOIN $wpdb->term_relationships AS tr ON (p.ID = tr.object_id)";
				$q .= _build_join_term_relationships( count( $tts ) );
				$q .= $wpdb->prepare( " WHERE 1=1 AND p.post_status = 'publish' AND p.post_type IN %s AND tr.term_taxonomy_id = %d", $pts, $tar->term_taxonomy_id );
				$q .= ' AND ' . _build_where_term_relationships( $tts );
				// phpcs:disable
				$count = (int) $wpdb->get_var( $q );
				// phpcs:enable
			}
			$tmk = 'count_' . str_replace( '-', '_', implode( '_', $slugs ) );
			update_term_meta( $tar->term_id, $tmk, $count );
		}
	}
}


// -------------------------------------------------------------------------


/**
 * Get instance.
 *
 * @internal
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
		 * The array of variable name to taxonomy.
		 *
		 * @var array
		 */
		public $var_to_taxonomy = array();

		/**
		 * The post types.
		 *
		 * @var array
		 */
		public $post_types = array();

		/**
		 * The filtered taxonomies.
		 *
		 * @var array
		 */
		public $filtered_taxonomies = array();

		/**
		 * Whether the get_terms filter is suppressed.
		 *
		 * @var bool
		 */
		public $suppress_get_terms_filter = false;
	};
	return $values;
}
