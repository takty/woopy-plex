<?php
/**
 * Pseudo-Front
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-10
 */

namespace wpinc\plex\pseudo_front;

require_once __DIR__ . '/custom-rewrite.php';

const ADMIN_QUERY_VAR = 'pseudo_front';

/**
 * Register an array of slug to label.
 *
 * @param array $slug_to_label An array of slug to label.
 */
function register_admin_labels( array $slug_to_label ) {
	$inst = _get_instance();
	foreach ( $slug_to_label as $slug => $label ) {
		$inst->slug_to_label[ $slug ] = $label;
	}
}

/**
 * Assign a format for displaying admin labels.
 *
 * @param string $format A format to assign.
 */
function set_admin_label_format( string $format ) {
	_get_instance()->label_format = $format;
}

/**
 * Set whether the default front bloginfo is enabled.
 *
 * @param bool $flag Whether the default front bloginfo is enabled.
 */
function set_default_front_bloginfo_enabled( bool $flag ) {
	_get_instance()->is_default_front_bloginfo_enabled = $flag;
	if ( false === $flag ) {
		$key = _get_default_key();
		delete_option( "blogname_$key" );
		delete_option( "blogdescription_$key" );
	}
}

/**
 * Initialize the pseudo-front.
 */
function initialize() {
	if ( is_admin() ) {
		add_action( 'admin_init', '\wpinc\plex\pseudo_front\_cb_admin_init' );

		add_filter( 'query_vars', '\wpinc\plex\pseudo_front\_cb_query_vars' );
		add_action( 'admin_menu', '\wpinc\plex\pseudo_front\_cb_admin_menu' );
		add_action( 'parse_query', '\wpinc\plex\pseudo_front\_cb_parse_query' );

		add_filter( 'display_post_states', '\wpinc\plex\pseudo_front\_cb_display_post_states', 10, 2 );
	} else {
		add_filter( 'option_page_on_front', '\wpinc\plex\pseudo_front\_cb_option_page_on_front' );
		add_filter( 'redirect_canonical', '\wpinc\plex\pseudo_front\_cb_redirect_canonical', 1, 2 );

		add_filter( 'option_blogname', '\wpinc\plex\pseudo_front\_cb_option_blogname' );
		add_filter( 'option_blogdescription', '\wpinc\plex\pseudo_front\_cb_option_blogdescription' );

		add_filter( 'body_class', '\wpinc\plex\pseudo_front\_cb_body_class' );
	}
	if ( is_admin_bar_showing() ) {
		add_action( 'admin_bar_menu', '\wpinc\plex\pseudo_front\_cb_admin_bar_menu' );
	}
}

/**
 * Retrieves the URL for the current site where the front end is accessible.
 *
 * @param string      $path   (Optional) Path relative to the home URL.
 *                            Default is ''.
 * @param string|null $scheme (Optional) Scheme to give the home URL context.
 *                            Accepts 'http', 'https', 'relative', 'rest', or null.
 * @param array       $vars   (Optional) An array of variable name to slug.
 * @return string Home URL link with optional path appended.
 */
function home_url( string $path = '', ?string $scheme = null, array $vars = array() ): string {
	$fp = \wpinc\plex\custom_rewrite\build_norm_path( $vars );
	if ( ! empty( $fp ) ) {
		$fp = "/$fp";
	}
	if ( ! empty( $path ) ) {
		$path = '/' . ltrim( $path, '/' );
	}
	return \home_url( $fp . $path, $scheme );
}


// -----------------------------------------------------------------------------


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

/**
 * Generate slug combinations.
 *
 * @return array The array of slug combinations.
 */
function get_slug_combination(): array {
	static $ret = null;
	if ( null === $ret ) {
		$ret = generate_combination( \wpinc\plex\custom_rewrite\get_structures( 'slugs' ) );
	}
	return $ret;
}

/**
 * Retrieve the key of current query variables.
 *
 * @access private
 *
 * @return string The key string.
 */
function _get_key(): string {
	$vars = \wpinc\plex\custom_rewrite\get_structures( 'var' );
	$vals = array_map(
		function ( $e ) {
			return \wpinc\plex\custom_rewrite\get_query_var( $e );
		},
		$vars
	);
	return implode( '_', $vals );
}

/**
 * Retrieve the key of default query variables.
 *
 * @access private
 *
 * @return string The key string.
 */
function _get_default_key(): string {
	$slugs = \wpinc\plex\custom_rewrite\get_structures( 'default_slug' );
	return implode( '_', $slugs );
}

/**
 * Retrieve the label of current query variables.
 *
 * @access private
 *
 * @param string[] $slugs The slug combination.
 * @return string The label string.
 */
function _get_front_label( array $slugs ): string {
	$inst = _get_instance();

	$ls = array();
	foreach ( $slugs as $s ) {
		$ls[] = $inst->slug_to_label[ $s ] ?? $s;
	}
	if ( $inst->label_format ) {
		return sprintf( $inst->label_format, ...$ls );
	}
	return implode( ' ', $ls );
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'option_{$option}' filter.
 *
 * @access private
 * @global \WP_Post $post
 *
 * @param string $value Value of the option.
 * @return string The filtered string.
 */
function _cb_option_page_on_front( string $value ) {
	$inst = _get_instance();

	$fp = get_page_by_path( \wpinc\plex\custom_rewrite\build_full_path() );
	global $post;
	if ( $fp && $post && $fp->ID === $post->ID ) {
		$value = $fp->ID;

		$inst->suppress_redirect = true;
	}
	return $value;
}

/**
 * Callback function for 'redirect_canonical' filter.
 *
 * @access private
 *
 * @param string $redirect_url  The redirect URL.
 * @param string $requested_url The requested URL.
 * @return string The filtered string.
 */
function _cb_redirect_canonical( string $redirect_url, string $requested_url ) {
	if ( _get_instance()->suppress_redirect ) {
		return false;
	}
	return $redirect_url;
}

/**
 * Callback function for 'option_{$option}' filter.
 *
 * @access private
 *
 * @param string $value Value of the option.
 * @return string The filtered string.
 */
function _cb_option_blogname( string $value ): string {
	$ret = get_option( 'blogname_' . _get_key() );
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
function _cb_option_blogdescription( string $value ): string {
	$ret = get_option( 'blogdescription_' . _get_key() );
	if ( false === $ret ) {
		return $value;
	}
	return $ret;
}

/**
 * Callback function for 'body_class' filter.
 *
 * @access private
 *
 * @param string[] $classes An array of body class names.
 * @return string[] The filtered array.
 */
function _cb_body_class( array $classes ): array {
	$cs = array_map(
		function ( $var ) {
			$val = \wpinc\plex\custom_rewrite\get_query_var( $var );
			return str_replace( '_', '-', "$var-$val" );
		},
		\wpinc\plex\custom_rewrite\get_structures( 'var' )
	);
	return array_merge( $classes, $cs );
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'admin_init' action.
 *
 * @access private
 */
function _cb_admin_init() {
	$skip_key = _get_instance()->is_default_front_bloginfo_enabled ? '' : _get_default_key();

	add_settings_section( 'pseudo-front-section', __( 'Sites' ), function () {}, 'general' );

	foreach ( get_slug_combination() as $slugs ) {
		$key = implode( '_', $slugs );
		if ( $key === $skip_key ) {
			continue;
		}
		$title = esc_html( _get_front_label( $slugs ) );

		$key_bn = "blogname_$key";
		$key_bd = "blogdescription_$key";
		register_setting( 'general', $key_bn );
		register_setting( 'general', $key_bd );

		$title_bn = __( 'Site Title' ) . "<br>$title";
		$title_bd = __( 'Tagline' ) . "<br>$title";
		add_settings_field(
			$key_bn,
			$title_bn,
			function () use ( $key_bn ) {
				\wpinc\plex\pseudo_front\_cb_field_input( $key_bn );
			},
			'general',
			'pseudo-front-section'
		);
		add_settings_field(
			$key_bd,
			$title_bd,
			function () use ( $key_bd ) {
				\wpinc\plex\pseudo_front\_cb_field_input( $key_bd );
			},
			'general',
			'pseudo-front-section'
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

/**
 * Callback function for 'query_vars' filter.
 *
 * @access private
 *
 * @param string[] $public_query_vars The array of allowed query variable names.
 * @return string[] The filtered array.
 */
function _cb_query_vars( array $public_query_vars ): array {
	$public_query_vars[] = ADMIN_QUERY_VAR;
	return $public_query_vars;
}

/**
 * Callback function for 'admin_menu' action.
 *
 * @access private
 */
function _cb_admin_menu() {
	foreach ( get_slug_combination() as $slugs ) {
		$page = get_page_by_path( implode( '/', $slugs ) );
		if ( null === $page ) {
			continue;
		}
		$key   = implode( '_', $slugs );
		$title = __( 'All Pages', 'default' ) . '<br>' . esc_html( _get_front_label( $slugs ) );

		$slug = add_query_arg( ADMIN_QUERY_VAR, $page->ID, 'edit.php?post_type=page' );
		add_pages_page( '', $title, 'edit_pages', $slug );
	}
}

/**
 * Callback function for 'parse_query' action.
 *
 * @access private
 * @global string $pagenow

 * @param \WP_Query $query The WP_Query instance (passed by reference).
 */
function _cb_parse_query( \WP_Query $query ) {
	global $pagenow;
	if ( 'edit.php' !== $pagenow ) {
		return;
	}
	$post_type = get_query_var( 'post_type' );
	if ( 'page' !== $post_type ) {
		return;
	}
	$page_id = get_query_var( ADMIN_QUERY_VAR );
	if ( empty( $page_id ) ) {
		return;
	}
	$page_id = intval( $page_id );
	// Must contains the posts with (parent_id === 0)
	// because of the algorithm of WP_Posts_List_Table->_display_rows_hierarchical().
	$ids   = array_reverse( get_post_ancestors( $page_id ) );
	$ids[] = $page_id;

	$args = array(
		'child_of'    => $page_id,
		'sort_column' => 'menu_order',
		'post_status' => 'publish,future,draft,pending,private',
	);

	$ps = get_pages( $args );
	foreach ( $ps as $p ) {
		$ids[] = $p->ID;
	}
	$query->set( 'post__in', $ids );
	$query->set( 'orderby', 'post__in' );
}

/**
 * Callback function for 'display_post_states' filter.
 *
 * @access private
 *
 * @param string[] $post_states An array of post display states.
 * @param \WP_Post $post        The current post object.
 * @return string[] The filtered states.
 */
function _cb_display_post_states( array $post_states, \WP_Post $post ): array {
	unset( $post_states['page_on_front'] );

	foreach ( get_slug_combination() as $slugs ) {
		$page = get_page_by_path( implode( '/', $slugs ) );
		if ( $page && $page->ID === $post->ID ) {
			$post_states['page_on_front'] = _x( 'Front Page', 'page label' );
		}
	}
	return $post_states;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'admin_bar_menu' action.
 *
 * @access private
 *
 * @param \WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance, passed by reference.
 */
function _cb_admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ) {
	foreach ( get_slug_combination() as $slugs ) {
		$path = implode( '/', $slugs );
		$page = get_page_by_path( $path );
		if ( $page ) {
			$node = array(
				'id'     => 'view-site-' . implode( '-', $slugs ),
				'parent' => 'site-name',
				'title'  => esc_html( _get_front_label( $slugs ) ),
				'href'   => \home_url( $path ),
			);
			$wp_admin_bar->add_menu( $node );
		}
	}
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
		 * Whether the default front bloginfo is enabled.
		 *
		 * @var bool
		 */
		public $is_default_front_bloginfo_enabled = true;

		/**
		 * Whether redirect is suppressed.
		 *
		 * @var bool
		 */
		public $suppress_redirect = false;
	};
	return $values;
}
