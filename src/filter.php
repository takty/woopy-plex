<?php
/**
 * Post Filter
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2023-10-19
 */

declare(strict_types=1);

namespace wpinc\plex\filter;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/multi-term-filter.php';
require_once __DIR__ . '/slug-key.php';

/** phpcs:ignore
 * Registers taxonomy used for filter.
 *
 * @param string $var_name The query variable name related to the taxonomy.
 * phpcs:ignore
 * @param array{
 *     taxonomy?         : string,
 *     do_insert_terms?  : bool,
 *     slug_to_label?    : array,
 *     label?            : string,
 *     show_in_nav_menus?: bool,
 *     show_admin_column?: bool,
 *     show_in_rest?     : bool,
 *     hierarchical?     : bool,
 *     query_var?        : bool,
 *     rewrite?          : bool,
 * } $args (Optional) Configuration arguments.
 *
 * $args {
 *     (Optional) Configuration arguments.
 *
 *     @type string 'taxonomy'        The taxonomy used for filter. Default the same as $var_name.
 *     @type bool   'do_insert_terms' Whether terms are inserted. Default true.
 *     @type array  'slug_to_label'   An array of slug to label.
 * }
 */
// phpcs:ignore
function add_filter_taxonomy( string $var_name, array $args = array() ): void {  // @phpstan-ignore-line
	$args += array(
		'taxonomy'          => $var_name,
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
	unset( $args['taxonomy'], $args['do_insert_terms'], $args['slug_to_label'] );

	register_taxonomy( $tx, '', $args );

	if ( $do_insert_terms ) {
		_insert_terms( $var_name, $tx, $slug_to_label );
	}
	$inst = _get_instance();
	foreach ( $inst->post_types as $pt ) {
		register_taxonomy_for_object_type( $tx, $pt );
	}
	$inst->vars[]                 = $var_name;  // @phpstan-ignore-line
	$inst->var_to_tx[ $var_name ] = $tx;  // @phpstan-ignore-line
}

/**
 * Inserts terms.
 *
 * @access private
 *
 * @param string                $var_name      The query variable name related to the taxonomy.
 * @param string                $tx            Taxonomy.
 * @param array<string, string> $slug_to_label Array of slugs to label.
 */
function _insert_terms( string $var_name, string $tx, array $slug_to_label ): void {
	$temp = \wpinc\plex\custom_rewrite\get_structure_slugs( array( $var_name ) );
	if ( empty( $temp ) ) {
		return;
	}
	$slugs = $temp[0];
	foreach ( $slugs as $slug ) {
		$t = get_term_by( 'slug', $slug, $tx );
		if ( false === $t ) {
			$l = $slug_to_label[ $slug ] ?? ucfirst( $slug );
			wp_insert_term( $l, $tx, array( 'slug' => $slug ) );
		}
	}
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
			\wpinc\plex\multi_term_filter\add_function_to_retrieve_terms( 'wpinc\plex\filter\_get_query_terms', $pt );
		}
	}
	$inst->post_types = array_merge( $inst->post_types, $pts );  // @phpstan-ignore-line
}

/**
 * Adds counted taxonomies.
 *
 * @param string|string[] $taxonomy_s A taxonomy or an array of taxonomies.
 */
function add_counted_taxonomy( $taxonomy_s ): void {
	$txs  = is_array( $taxonomy_s ) ? $taxonomy_s : array( $taxonomy_s );
	$inst = _get_instance();

	$inst->txs_counted = array_merge( $inst->txs_counted, $txs );  // @phpstan-ignore-line
}

/**
 * Activates the post filter.
 *
 * @param array<string, mixed> $args {
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

	$inst->key_pre_count = $args['count_key_prefix'];  // @phpstan-ignore-line

	if ( is_admin() ) {
		add_action( 'edited_term_taxonomy', '\wpinc\plex\filter\_cb_edited_term_taxonomy', 10, 2 );
	} else {
		\wpinc\plex\multi_term_filter\add_function_to_retrieve_terms( 'wpinc\plex\filter\_get_query_terms' );  // For search.
		\wpinc\plex\multi_term_filter\activate();
	}
	\wpinc\plex\custom_rewrite\add_post_link_filter( '\wpinc\plex\filter\_cb_post_link_filter' );
}


// -----------------------------------------------------------------------------


/**
 * Retrieves queried terms.
 *
 * @access private
 *
 * @return \WP_Term[] The terms.
 */
function _get_query_terms(): array {
	$ret  = array();
	$inst = _get_instance();
	$vars = \wpinc\plex\custom_rewrite\get_structure_vars( $inst->vars );

	foreach ( $vars as $var ) {
		$tx = $inst->var_to_tx[ $var ];
		$t  = get_term_by( 'slug', \wpinc\plex\custom_rewrite\get_query_var( $var ), $tx );
		if ( $t instanceof \WP_Term ) {
			$ret[] = $t;
		}
	}
	return $ret;
}

/**
 * Callback function for post_link filter of the custom rewrite.
 *
 * @access private
 *
 * @param array<string, mixed> $query_vars The query vars.
 * @param \WP_Post|null        $post       The post in question.
 * @return array<string, mixed> The filtered vars.
 */
function _cb_post_link_filter( array $query_vars, ?\WP_Post $post = null ): array {
	$inst = _get_instance();
	if ( null === $post || ! in_array( $post->post_type, $inst->post_types, true ) ) {
		return $query_vars;
	}
	$vars = \wpinc\plex\custom_rewrite\get_structure_vars( $inst->vars );

	foreach ( $vars as $var ) {
		$terms = get_the_terms( $post->ID, $inst->var_to_tx[ $var ] );
		if ( ! is_array( $terms ) ) {
			continue;
		}
		$term_slugs = array_column( $terms, 'slug' );
		if ( ! isset( $query_vars[ $var ] ) || ! in_array( $query_vars[ $var ], $term_slugs, true ) ) {
			$query_vars[ $var ] = $term_slugs[0];
		}
	}
	return $query_vars;
}

/**
 * Callback function for 'edited_term_taxonomy' action.
 *
 * @access private
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
		function ( string $var_name ) use ( $inst ) {
			return $inst->var_to_tx[ $var_name ];
		},
		\wpinc\plex\custom_rewrite\get_structure_vars( $inst->vars )
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
		if ( ! is_array( $tars ) ) {
			return;
		}
	}
	$skc = \wpinc\plex\get_slug_key_to_combination( $inst->vars );

	foreach ( $tars as $tar ) {
		if ( ! ( $tar instanceof \WP_Term ) ) {
			continue;
		}
		$tt_id   = $tar->term_taxonomy_id;
		$term_id = $tar->term_id;

		foreach ( $skc as $key => $slugs ) {
			$tts = array( $tt_id );
			foreach ( $txs as $idx => $tx ) {
				$t = get_term_by( 'slug', $slugs[ $idx ], $tx );
				if ( $t instanceof \WP_Term && $tt_id !== $t->term_taxonomy_id ) {
					$tts[] = $t->term_taxonomy_id;
				}
			}
			$count = \wpinc\plex\multi_term_filter\count_posts_with_terms( $inst->post_types, $tts );
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
 * @return object{
 *     vars         : string[],
 *     var_to_tx    : array<string, string>,
 *     post_types   : string[],
 *     txs_counted  : string[],
 *     key_pre_count: string,
 * } Instance.
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
		 * @var string[]
		 */
		public $vars = array();

		/**
		 * The array of variable name to taxonomy.
		 *
		 * @var array<string, string>
		 */
		public $var_to_tx = array();

		/**
		 * The filtered post types.
		 *
		 * @var string[]
		 */
		public $post_types = array();

		/**
		 * The counted taxonomies.
		 *
		 * @var string[]
		 */
		public $txs_counted = array();

		/**
		 * The key prefix of term count
		 *
		 * @var string
		 */
		public $key_pre_count = '';
	};
	return $values;
}
