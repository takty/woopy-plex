<?php
namespace st;
/**
 *
 * Post Filter
 *
 * @author Takuto Yanagida @ Space-Time Inc.
 * @version 2020-12-05
 *
 */


require_once __DIR__ . '/pseudo-front.php';


class PostFilter {

	static private $_instance = null;
	static public function instance() {
		if ( self::$_instance === null ) self::$_instance = new self();
		return self::$_instance;
	}

	private $_var_to_taxonomy = [];
	private $_post_types = [];
	private $_filtered_taxonomies = [];
	private $_suppress_get_terms_filter = false;

	private function __construct() {}

	public function add_filter_taxonomy( string $tax, string $label, string $var = null ) {
		if ( empty( $var ) ) $var = $tax;
		register_taxonomy( $tax, null, [
			'label'             => $label,
			'show_in_nav_menus' => false,
			'show_admin_column' => true,
			'hierarchical'      => true,
			'query_var'         => false,
			'rewrite'           => false,
		] );
		foreach ( $this->_post_types as $pt ) register_taxonomy_for_object_type( $tax, $pt );
		$this->_var_to_taxonomy[ $var ] = $tax;
	}

	public function add_filtered_post_type( $post_type_s ) {
		if ( ! is_array( $post_type_s ) ) $post_type_s = [ $post_type_s ];
		foreach ( $post_type_s as $pt ) {
			foreach ( $this->_var_to_taxonomy as $tax ) register_taxonomy_for_object_type( $tax, $pt );
		}
		$this->_post_types = array_merge( $this->_post_types, $post_type_s );
	}

	public function add_filtered_taxonomy( $tax_s ) {
		if ( ! is_array( $tax_s ) ) $tax_s = [ $tax_s ];
		$this->_filtered_taxonomies = array_merge( $this->_filtered_taxonomies, $tax_s );
	}

	public function initialize() {
		if ( is_admin() ) {
			add_action( 'edited_term_taxonomy', [ $this, '_cb_edited_term_taxonomy' ], 10, 2 );
		} else {
			add_filter( 'get_next_post_join',      [ $this, '_cb_get_adjacent_post_join' ], 10, 5 );
			add_filter( 'get_previous_post_join',  [ $this, '_cb_get_adjacent_post_join' ], 10, 5 );
			add_filter( 'get_next_post_where',     [ $this, '_cb_get_adjacent_post_where' ], 10, 5 );
			add_filter( 'get_previous_post_where', [ $this, '_cb_get_adjacent_post_where' ], 10, 5 );

			add_filter( 'getarchives_join',        [ $this, '_cb_getarchives_join' ],  10, 2 );
			add_filter( 'getarchives_where',       [ $this, '_cb_getarchives_where' ], 10, 2 );

			add_action( 'posts_join',              [ $this, '_cb_posts_join' ],  10, 2 );
			add_action( 'posts_where',             [ $this, '_cb_posts_where' ], 10, 2 );
			add_action( 'posts_groupby',           [ $this, '_cb_posts_groupby' ], 10, 2 );

			\st\custom_rewrite\add_post_link_filter( [ $this, '_cb_filter_by_taxonomy' ] );
		}
	}


	// -------------------------------------------------------------------------


	static private function _make_join_term_relationships( $count, $type = 'INNER' ) {
		global $wpdb;
		$q = [];
		for ( $i = 0; $i < $count; $i += 1 ) {
			$q[] = " $type JOIN {$wpdb->term_relationships} AS tr$i ON (p.ID = tr$i.object_id)";
		}
		return implode( '', $q );
	}

	static private function _make_where_term_relationships( $term_taxonomies ) {
		global $wpdb;
		$q = [];
		foreach ( $term_taxonomies as $i => $tt ) {
			$q[] = $wpdb->prepare( "tr$i.term_taxonomy_id = %d", $tt );
		}
		return implode( ' AND ', $q );
	}

	private function _get_term_taxonomy_ids() {
		static $ret = null;
		if ( $ret ) return $ret;
		$ret = [];

		$vars = \st\custom_rewrite\get_structures( 'var' );
		foreach ( $vars as $var ) {
			if ( ! isset( $this->_var_to_taxonomy[ $var ] ) ) continue;
			$tax = $this->_var_to_taxonomy[ $var ];
			$term = get_term_by( 'slug', get_query_var( $tax ), $tax );
			if ( $term === false ) continue;
			$ret[] = $term->term_taxonomy_id;
		}
		return $ret;
	}


	// -------------------------------------------------------------------------


	public function _cb_get_adjacent_post_join( string $join, bool $in_same_term, $excluded_terms, string $tax, \WP_Post $post ) {  // Private
		if ( ! in_array( $post->post_type, $this->_post_types, true ) ) return $join;

		$tts = $this->_get_term_taxonomy_ids();
		if ( ! empty( $tts ) ) $join .= self::_make_join_term_relationships( count( $tts ) );
		return $join;

	}

	public function _cb_get_adjacent_post_where( string $where, bool $in_same_term, $excluded_terms, string $tax, \WP_Post $post ) {  // Private
		if ( ! in_array( $post->post_type, $this->_post_types, true ) ) return $where;

		$tts = $this->_get_term_taxonomy_ids();
		if ( ! empty( $tts ) ) $where .= ' AND ' . self::_make_where_term_relationships( $tts );
		return $where;
	}


	// -------------------------------------------------------------------------


	public function _cb_getarchives_join( string $join, array $r ) {  // Private
		if ( ! is_main_query() ) return $join;
		if ( ! in_array( $r['post_type'], $this->_post_types, true ) ) return $join;

		$tts = $this->_get_term_taxonomy_ids();
		if ( ! empty( $tts ) ) $join .= self::_make_join_term_relationships( count( $tts ) );
		return $join;
	}

	public function _cb_getarchives_where( string $where, array $r ) {  // Private
		if ( ! is_main_query() ) return $where;
		if ( ! in_array( $r['post_type'], $this->_post_types, true ) ) return $where;

		$tts = $this->_get_term_taxonomy_ids();
		if ( ! empty( $tts ) ) $where .= ' AND ' . self::_make_where_term_relationships( $tts );
		return $where;
	}


	// -------------------------------------------------------------------------


	public function _cb_posts_join( string $join, \WP_Query $query ) {  // Private
		if ( ! is_main_query() || empty( $this->_post_types ) ) return $join;

		$tts = $this->_get_term_taxonomy_ids();
		if ( empty( $tts ) ) return $join;

		global $wpdb;
		if ( in_array( $query->query_vars['post_type'], $this->_post_types, true ) ) {
			$join .= self::_make_join_term_relationships( count( $tts ) );
		} else if ( is_search() ) {
			$join .= self::_make_join_term_relationships( count( $tts ), 'LEFT' );
		}
		return $join;
	}

	public function _cb_posts_where( string $where, \WP_Query $query ) {  // Private
		if ( ! is_main_query() || empty( $this->_post_types ) ) return $where;

		$tts = $this->_get_term_taxonomy_ids();
		if ( empty( $tts ) ) return $where;

		global $wpdb;
		if ( in_array( $query->query_vars['post_type'], $this->_post_types, true ) ) {
			$where .= ' AND ' . self::_make_where_term_relationships( $tts );
		} else if ( is_search() ) {
			$pts = "('" . implode( "', '", $this->_post_types ) . "')";
			$where .= " AND ({$wpdb->posts}.post_type NOT IN $pts";
			$where .= ' OR (' . self::_make_where_term_relationships( $tts ) . '))';
		}
		return $where;
	}

	public function _cb_posts_groupby( string $groupby, \WP_Query $query ) {  // Private
		if ( ! is_main_query() ) return $groupby;

		if ( is_search() ) {
			global $wpdb;
			$g = "{$wpdb->posts}.ID";

			if ( preg_match( "/$g/", $groupby ) ) return $groupby;
			if ( empty( trim( $groupby ) ) ) return $g;
			$groupby .= ", $g";
		}
		return $groupby;
	}


	// -------------------------------------------------------------------------


	public function _cb_filter_by_taxonomy( array $vars, \WP_Post $post = null ) {  // Private
		if ( ! is_admin() || ! is_a( $post, 'WP_Post' ) ) return $vars;
		if ( ! in_array( $post->post_type, $this->_post_types, true ) ) return $vars;

		$vars = \st\custom_rewrite\get_structures( 'var' );
		foreach ( $vars as $var ) {
			if ( ! isset( $this->_var_to_taxonomy[ $var ] ) ) continue;
			$tax = $this->_var_to_taxonomy[ $var ];
			$ts = get_the_terms( $post->ID, $tax );
			if ( ! is_array( $ts ) ) return $vars;
			$term_slug = get_query_var( $tax );
			$slugs = array_map( function ( $t ) { return $t->slug; }, $ts );
			if ( in_array( $term_slug, $slugs, true ) ) {
				$vars[ $tax ] = $term_slug;
			}
		}
		return $vars;
	}


	// -------------------------------------------------------------------------


	public function _cb_edited_term_taxonomy( $term, $taxonomy ) {
		if ( empty( $this->_filtered_taxonomies ) ) return;
		$taxes = [];
		$slugs_array = [];
		foreach ( \st\custom_rewrite\get_structures() as $st ) {
			if ( ! isset( $this->_var_to_taxonomy[ $st['var'] ] ) ) continue;
			$taxes[] = $this->_var_to_taxonomy[ $st['var'] ];
			$slugs_array[] = $st['slugs'];
		}
		$is_filtered = in_array( $taxonomy, $this->_filtered_taxonomies, true );
		if ( ! $is_filtered && ! in_array( $taxonomy, $taxes, true ) ) return;

		if ( $is_filtered ) {
			$tars = [ $term ];
		} else {
			$tars = get_terms( $this->_filtered_taxonomies, [ 'hide_empty' => false ] );
		}
		global $wpdb;
		$pts = "('" . implode( "', '", $this->_post_types ) . "')";
		$slug_comb = \st\pseudo_front\generate_combination( $slugs_array );

		foreach ( $tars as $tar ) {
			foreach ( $slug_comb as $slugs ) {
				$tts = [];
				foreach ( $taxes as $idx => $tax ) {
					$t = get_term_by( 'slug', $slugs[ $idx ], $tax );
					if ( $t === false ) continue;
					$tts[] = $t->term_taxonomy_id;
				}
				$count = 0;
				if ( ! empty( $tts ) ) {
					$q = "SELECT COUNT(*) FROM wp_posts AS p";
					$q .= $wpdb->prepare( " INNER JOIN {$wpdb->term_relationships} AS tr ON (p.ID = tr.object_id)" );
					$q .= self::_make_join_term_relationships( count( $tts ) );
					$q .= $wpdb->prepare( " WHERE 1=1 AND p.post_status = 'publish' AND p.post_type IN {$pts} AND tr.term_taxonomy_id = {$tar->term_taxonomy_id}" );
					$q .= ' AND ' . self::_make_where_term_relationships( $tts );
					$count = (int) $wpdb->get_var( $q );
				}
				$tmk = 'count_' . str_replace( '-', '_', implode( '_', $slugs ) );
				update_term_meta( $tar->term_id, $tmk, $count );
			}
		}
	}

}


// -----------------------------------------------------------------------------


namespace st\post_filter;

function add_filter_taxonomy( $tax, $label ) { \st\PostFilter::instance()->add_filter_taxonomy( $tax, $label ); }
function add_filtered_post_type( $post_type_s ) { \st\PostFilter::instance()->add_filtered_post_type( $post_type_s ); }
function add_filtered_taxonomy( $tax_s ) { \st\PostFilter::instance()->add_filtered_taxonomy( $tax_s ); }
function initialize() { \st\PostFilter::instance()->initialize(); }
