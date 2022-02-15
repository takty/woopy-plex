<?php
/**
 * Post Filter
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2022-02-15
 */

namespace wpinc\plex\filter;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/slug-key.php';

/**
 * Registers taxonomy used for filter.
 *
 * @param string $var  The query variable name related to the taxonomy.
 * @param array  $args {
 *     Configuration arguments.
 *
 *     @type string 'taxonomy'        The taxonomy used for filter. Default the same as $var.
 *     @type bool   'do_insert_terms' Whether terms are inserted. Default true.
 *     @type array  'slug_to_label'   An array of slug to label.
 * }
 */
function add_filter_taxonomy( string $var, array $args = array() ): void {
	$args += array(
		'taxonomy'          => $var,
		'do_insert_terms'   => true,
		'slug_to_label'     => array(),

		'label'             => _x( 'Filter', 'filter', 'wpinc_plex' ),
		'show_in_nav_menus' => false,
		'show_admin_column' => true,
		'show_in_rest'      => true,  // For Gutenberg.
		'hierarchical'      => true,
		'query_var'         => false,
		'rewrite'           => false,
	);

	$tx              = $args['taxonomy'];
	$do_insert_terms = $args['do_insert_terms'];
	$slug_to_label   = $args['slug_to_label'];
	unset( $args['taxonomy'] );
	unset( $args['do_insert_terms'] );
	unset( $args['slug_to_label'] );

	register_taxonomy( $tx, null, $args );

	if ( $do_insert_terms ) {
		$slugs = \wpinc\plex\custom_rewrite\get_structures( 'slugs', array( $var ) )[0];
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, $tx );
			if ( false === $term ) {
				$lab = $slug_to_label[ $slug ] ?? ucfirst( $slug );
				wp_insert_term( $lab, $tx, array( 'slug' => $slug ) );
			}
		}
	}
	$inst = _get_instance();
	foreach ( $inst->post_types as $pt ) {
		register_taxonomy_for_object_type( $tx, $pt );
	}
	$inst->vars[]            = $var;
	$inst->var_to_tx[ $var ] = $tx;
}

/**
 * Adds filtered post types.
 *
 * @param string|string[] $post_type_s A post type or an array of post types.
 */
function add_filtered_post_type( $post_type_s ): void {
	$pts  = is_array( $post_type_s ) ? $post_type_s : array( $post_type_s );
	$inst = _get_instance();
	foreach ( $pts as $pt ) {
		foreach ( $inst->var_to_tx as $tx ) {
			register_taxonomy_for_object_type( $tx, $pt );
		}
	}
	$inst->post_types = array_merge( $inst->post_types, $pts );
}

/**
 * Adds counted taxonomies.
 *
 * @param string|string[] $taxonomy_s A taxonomy or an array of taxonomies.
 */
function add_counted_taxonomy( $taxonomy_s ): void {
	$txs  = is_array( $taxonomy_s ) ? $taxonomy_s : array( $taxonomy_s );
	$inst = _get_instance();

	$inst->txs_counted = array_merge( $inst->txs_counted, $txs );
}

/**
 * Activates the post filter.
 *
 * @param array $args {
 *     (Optional) Configuration arguments.
 *
 *     @type string 'count_key_prefix' Key prefix of term count. Default '_count_'.
 * }
 */
function activate( array $args = array() ): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'count_key_prefix' => '_count_',
	);

	$inst->key_pre_count = $args['count_key_prefix'];

	if ( is_admin() ) {
		add_action( 'edited_term_taxonomy', '\wpinc\plex\filter\_cb_edited_term_taxonomy', 10, 2 );
		\wpinc\plex\custom_rewrite\add_post_link_filter( '\wpinc\plex\filter\_post_link_filter' );
	} else {
		add_filter( 'get_next_post_join', '\wpinc\plex\filter\_cb_get_adjacent_post_join', 10, 5 );
		add_filter( 'get_previous_post_join', '\wpinc\plex\filter\_cb_get_adjacent_post_join', 10, 5 );
		add_filter( 'get_next_post_where', '\wpinc\plex\filter\_cb_get_adjacent_post_where', 10, 5 );
		add_filter( 'get_previous_post_where', '\wpinc\plex\filter\_cb_get_adjacent_post_where', 10, 5 );

		add_filter( 'getarchives_join', '\wpinc\plex\filter\_cb_getarchives_join', 10, 2 );
		add_filter( 'getarchives_where', '\wpinc\plex\filter\_cb_getarchives_where', 10, 2 );

		add_action( 'posts_join', '\wpinc\plex\filter\_cb_posts_join', 10, 2 );
		add_action( 'posts_where', '\wpinc\plex\filter\_cb_posts_where', 10, 2 );
		add_action( 'posts_groupby', '\wpinc\plex\filter\_cb_posts_groupby', 10, 2 );
	}
}


// -----------------------------------------------------------------------------


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
 * Retrieves term taxonomy ids.
 *
 * @access private
 *
 * @return array The ids.
 */
function _get_term_taxonomy_ids(): array {
	static $ret = null;
	if ( $ret ) {
		return $ret;
	}
	$ret  = array();
	$inst = _get_instance();
	$vars = \wpinc\plex\custom_rewrite\get_structures( 'var', $inst->vars );

	foreach ( $vars as $var ) {
		$tx = $inst->var_to_tx[ $var ];
		$t  = get_term_by( 'slug', \wpinc\plex\custom_rewrite\get_query_var( $var ), $tx );
		if ( $t ) {
			$ret[] = $t->term_taxonomy_id;
		}
	}
	return $ret;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'get_{$adjacent}_post_join' filter.
 *
 * @access private
 *
 * @param string       $join           The JOIN clause in the SQL.
 * @param bool         $in_same_term   Whether post should be in a same taxonomy term.
 * @param array|string $excluded_terms Array of excluded term IDs.
 * @param string       $taxonomy       Used to identify the term used when $in_same_term is true.
 * @param \WP_Post     $post           WP_Post object.
 * @return string The filtered clause.
 */
function _cb_get_adjacent_post_join( string $join, bool $in_same_term, $excluded_terms, string $taxonomy, \WP_Post $post ): string {
	$inst = _get_instance();
	if ( ! in_array( $post->post_type, $inst->post_types, true ) ) {
		return $join;
	}
	$tts = _get_term_taxonomy_ids();
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
 * @param array|string $excluded_terms Array of excluded term IDs.
 * @param string       $taxonomy       Used to identify the term used when $in_same_term is true.
 * @param \WP_Post     $post           WP_Post object.
 * @return string The filtered clause.
 */
function _cb_get_adjacent_post_where( string $where, bool $in_same_term, $excluded_terms, string $taxonomy, \WP_Post $post ): string {
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
 * @access private
 * @global $wpdb
 *
 * @param string $sql_join    Portion of SQL query containing the JOIN clause.
 * @param array  $parsed_args An array of default arguments.
 * @return string The filtered clause.
 */
function _cb_getarchives_join( string $sql_join, array $parsed_args ): string {
	$inst = _get_instance();
	if ( ! in_array( $parsed_args['post_type'], $inst->post_types, true ) ) {
		return $sql_join;
	}
	$tts = _get_term_taxonomy_ids();
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
 * @param string $sql_where   Portion of SQL query containing the WHERE clause.
 * @param array  $parsed_args An array of default arguments.
 * @return string The filtered clause.
 */
function _cb_getarchives_where( string $sql_where, array $parsed_args ): string {
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
 * @access private
 * @global $wpdb
 *
 * @param string    $join  The JOIN BY clause of the query.
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 * @return string The filtered clause.
 */
function _cb_posts_join( string $join, \WP_Query $query ): string {
	$inst = _get_instance();
	if ( empty( $inst->post_types ) ) {
		return $join;
	}
	$tts = _get_term_taxonomy_ids();
	if ( empty( $tts ) ) {
		return $join;
	}
	global $wpdb;
	if ( in_array( $query->query_vars['post_type'], $inst->post_types, true ) ) {
		$join .= _build_join_term_relationships( count( $tts ), $wpdb->posts );
	} elseif ( $query->is_search() ) {
		$join .= _build_join_term_relationships( count( $tts ), $wpdb->posts, 'LEFT' );
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
	$inst = _get_instance();
	if ( empty( $inst->post_types ) ) {
		return $where;
	}
	$tts = _get_term_taxonomy_ids();
	if ( empty( $tts ) ) {
		return $where;
	}
	global $wpdb;
	if ( in_array( $query->query_vars['post_type'], $inst->post_types, true ) ) {
		$where .= ' AND ' . _build_where_term_relationships( $tts );
	} elseif ( $query->is_search() ) {
		$pts = "('" . implode( "', '", $inst->post_types ) . "')";

		$where .= " AND ({$wpdb->posts}.post_type NOT IN $pts";
		$where .= ' OR (' . _build_where_term_relationships( $tts ) . '))';
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


// -----------------------------------------------------------------------------


/**
 * Callback function for post_link filter of the custom rewrite.
 *
 * @access private
 *
 * @param array         $query_vars The query vars.
 * @param \WP_Post|null $post       The post in question.
 * @return array The filtered vars.
 */
function _post_link_filter( array $query_vars, ?\WP_Post $post = null ): array {
	if ( ! is_admin() || ! is_a( $post, 'WP_Post' ) ) {
		return $query_vars;
	}
	$inst = _get_instance();
	if ( ! in_array( $post->post_type, $inst->post_types, true ) ) {
		return $query_vars;
	}
	$vars = \wpinc\plex\custom_rewrite\get_structures( 'var', $inst->vars );

	foreach ( $vars as $var ) {
		$terms = get_the_terms( $post->ID, $inst->var_to_tx[ $var ] );
		if ( ! is_array( $terms ) ) {
			return $query_vars;
		}
		$term_slugs = array_column( $terms, 'slug' );
		if ( ! in_array( $query_vars[ $var ], $term_slugs, true ) ) {
			$query_vars[ $var ] = $term_slugs[0];
		}
	}
	return $query_vars;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'edited_term_taxonomy' action.
 *
 * @access private
 * @global $wpdb
 *
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy slug.
 */
function _cb_edited_term_taxonomy( int $tt_id, string $taxonomy ): void {
	$inst = _get_instance();
	if ( empty( $inst->txs_counted ) ) {
		return;
	}
	$txs = array_map(
		function ( $var ) use ( $inst ) {
			return $inst->var_to_tx[ $var ];
		},
		\wpinc\plex\custom_rewrite\get_structures( 'var', $inst->vars )
	);

	$is_filtered = in_array( $taxonomy, $inst->txs_counted, true );
	if ( ! $is_filtered && ! in_array( $taxonomy, $txs, true ) ) {
		return;
	}
	if ( $is_filtered ) {
		$t    = get_term_by( 'term_taxonomy_id', $tt_id );
		$tars = $t ? array( $t ) : array();
	} else {
		$tars = get_terms(
			array(
				'taxonomy'   => $inst->txs_counted,
				'hide_empty' => false,
			)
		);
	}
	$pts = "('" . implode( "', '", array_map( 'esc_sql', $inst->post_types ) ) . "')";
	$skc = \wpinc\plex\get_slug_key_to_combination( $inst->vars );

	global $wpdb;
	foreach ( $tars as $tar ) {
		$tt_id   = $tar->term_taxonomy_id;
		$term_id = $tar->term_id;

		foreach ( $skc as $key => $slugs ) {
			$tts = array();
			foreach ( $txs as $idx => $tx ) {
				$t = get_term_by( 'slug', $slugs[ $idx ], $tx );
				if ( $t ) {
					$tts[] = $t->term_taxonomy_id;
				}
			}
			$count = 0;
			if ( ! empty( $tts ) ) {
				$q  = 'SELECT COUNT(*) FROM wp_posts AS p';
				$q .= " INNER JOIN $wpdb->term_relationships AS tr ON (p.ID = tr.object_id)";
				$q .= _build_join_term_relationships( count( $tts ), 'p' );
				// phpcs:disable
				$q .= $wpdb->prepare( " WHERE 1=1 AND p.post_status = 'publish' AND p.post_type IN $pts AND tr.term_taxonomy_id = %d", $tt_id );
				$q .= ' AND ' . _build_where_term_relationships( $tts );
				$count = (int) $wpdb->get_var( $q );
				// phpcs:enable
			}
			update_term_meta( $term_id, $inst->key_pre_count . $key, $count );
		}
	}
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
		 * The array of variable names.
		 *
		 * @var array
		 */
		public $vars = array();

		/**
		 * The array of variable name to taxonomy.
		 *
		 * @var array
		 */
		public $var_to_tx = array();

		/**
		 * The filtered post types.
		 *
		 * @var array
		 */
		public $post_types = array();

		/**
		 * The counted taxonomies.
		 *
		 * @var array
		 */
		public $txs_counted = array();

		/**
		 * The key prefix of term count
		 *
		 * @var string
		 */
		public $key_pre_count = '';

		/**
		 * Whether the get_terms filter is suppressed.
		 *
		 * @var bool
		 */
		public $suppress_get_terms_filter = false;
	};
	return $values;
}
