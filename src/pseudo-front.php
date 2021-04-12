<?php
/**
 * Pseudo-Front
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-04-12
 */

namespace wpinc\plex\pseudo_front;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/slug-key.php';

const ADMIN_QUERY_VAR = 'pseudo_front';
const EDIT_PAGE_URL   = 'edit.php?post_type=page';

/**
 * Add an array of slug to label.
 *
 * @param array  $slug_to_label An array of slug to label.
 * @param string $format        A format to assign.
 */
function add_admin_labels( array $slug_to_label, ?string $format = null ) {
	$inst = _get_instance();

	$inst->slug_to_label = array_merge( $inst->slug_to_label, $slug_to_label );
	if ( $format ) {
		$inst->label_format = $format;
	}
}

/**
 * Initialize the pseudo-front.
 *
 * @param array $args {
 *     (Optional) Configuration arguments.
 *
 *     @type bool 'has_default_front_bloginfo' Whether the site has the default front bloginfo. Default true.
 * }
 */
function initialize( array $args = array() ) {
	static $initialized = 0;
	if ( $initialized++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'has_default_front_bloginfo' => true,
	);

	$inst->has_default_front_bloginfo = $args['has_default_front_bloginfo'];
	if ( ! $inst->has_default_front_bloginfo ) {
		$key = \wpinc\plex\get_default_key();
		delete_option( "blogname_$key" );
		delete_option( "blogdescription_$key" );
	}

	if ( is_admin() ) {
		add_action( 'admin_init', '\wpinc\plex\pseudo_front\_cb_admin_init' );

		add_filter( 'query_vars', '\wpinc\plex\pseudo_front\_cb_query_vars' );
		add_action( 'admin_menu', '\wpinc\plex\pseudo_front\_cb_admin_menu' );
		add_action( 'submenu_file', '\wpinc\plex\pseudo_front\_cb_submenu_file', 10, 2 );
		add_action( 'parse_query', '\wpinc\plex\pseudo_front\_cb_parse_query' );

		add_filter( 'display_post_states', '\wpinc\plex\pseudo_front\_cb_display_post_states', 10, 2 );
		add_filter( 'admin_head', '\wpinc\plex\pseudo_front\_cb_admin_head' );
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
 *                            Default ''.
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
 * Retrieves the post IDs of pseudo front pages.
 *
 * @access private
 *
 * @return int[] Post IDs.
 */
function _get_front_page_ids(): array {
	$ids = array();
	foreach ( \wpinc\plex\get_slug_key_to_combination() as $slugs ) {
		$page = get_page_by_path( implode( '/', $slugs ) );
		if ( $page ) {
			$ids[] = $page->ID;
		}
	}
	return $ids;
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
	$ret = get_option( 'blogname_' . \wpinc\plex\get_query_key() );
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
	$ret = get_option( 'blogdescription_' . \wpinc\plex\get_query_key() );
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
	$inst    = _get_instance();
	$def_key = $inst->has_default_front_bloginfo ? '' : \wpinc\plex\get_default_key();

	add_settings_section( 'pseudo-front-section', _x( 'Pseudo Front Pages', 'pseudo front', 'plex' ), function () {}, 'general' );

	foreach ( \wpinc\plex\get_slug_key_to_combination() as $key => $slugs ) {
		if ( $key === $def_key ) {
			continue;
		}
		$key_bn = "blogname_$key";
		$key_bd = "blogdescription_$key";
		register_setting( 'general', $key_bn );
		register_setting( 'general', $key_bd );

		$lab = esc_html( \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format ) );
		add_settings_field(
			$key_bn,
			__( 'Site Title' ) . "<br>$lab",
			function () use ( $key_bn ) {
				\wpinc\plex\pseudo_front\_cb_field_input( $key_bn );
			},
			'general',
			'pseudo-front-section',
			array(
				'label_for' => $key_bn,
				'class'     => 'wpinc-plex-pseudo-front-blogname',
			)
		);
		add_settings_field(
			$key_bd,
			__( 'Tagline' ) . "<br>$lab",
			function () use ( $key_bd ) {
				\wpinc\plex\pseudo_front\_cb_field_input( $key_bd );
			},
			'general',
			'pseudo-front-section',
			array(
				'label_for' => $key_bd,
				'class'     => 'wpinc-plex-pseudo-front-blogdescription',
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
function _cb_field_input( string $key ) {
	$val = get_option( $key );
	printf( '<input id="%1$s" name="%1$s" type="text" value="%2$s" class="regular-text">', esc_attr( $key ), esc_attr( $val ) );
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
	$inst = _get_instance();

	foreach ( \wpinc\plex\get_slug_key_to_combination() as $key => $slugs ) {
		$page = get_page_by_path( implode( '/', $slugs ) );
		if ( $page ) {
			$lab  = __( 'All Pages', 'default' ) . '<br>' . esc_html( \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format ) );
			$slug = add_query_arg( ADMIN_QUERY_VAR, $page->ID, EDIT_PAGE_URL );
			add_pages_page( '', $lab, 'edit_pages', $slug );
		}
	}
}

/**
 * Callback function for 'submenu_file' filter.
 *
 * @access private
 *
 * @param ?string $submenu_file The submenu file.
 * @param string  $parent_file  The submenu item's parent file.
 * @return ?string The filtered file.
 */
function _cb_submenu_file( ?string $submenu_file, string $parent_file ): ?string {
	if ( EDIT_PAGE_URL === $parent_file ) {
		global $post;
		if ( $post ) {
			$as   = get_post_ancestors( $post );
			$as[] = (int) $post->ID;
			foreach ( _get_front_page_ids() as $pf_id ) {
				if ( in_array( $pf_id, $as, true ) ) {
					$submenu_file = add_query_arg( ADMIN_QUERY_VAR, $pf_id, EDIT_PAGE_URL );
					break;
				}
			}
		} else {
			$pf_id = \get_query_var( ADMIN_QUERY_VAR );
			if ( $pf_id ) {
				$submenu_file = add_query_arg( ADMIN_QUERY_VAR, $pf_id, EDIT_PAGE_URL );
			}
		}
	}
	return $submenu_file;
}

/**
 * Callback function for 'parse_query' action.
 *
 * @access private
 * @global string $pagenow
 *
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 */
function _cb_parse_query( \WP_Query $query ) {
	global $pagenow;
	if ( 'edit.php' !== $pagenow ) {
		return;
	}
	$post_type = \get_query_var( 'post_type' );
	if ( 'page' !== $post_type ) {
		return;
	}
	$page_id = \get_query_var( ADMIN_QUERY_VAR );
	if ( empty( $page_id ) ) {
		return;
	}
	$page_id = (int) $page_id;
	// Must contains the posts with (parent_id === 0)
	// because of the algorithm of WP_Posts_List_Table->_display_rows_hierarchical().
	$ids   = array_reverse( get_post_ancestors( $page_id ) );
	$ids[] = $page_id;

	$ps = get_pages(
		array(
			'child_of'    => $page_id,
			'sort_column' => 'menu_order',
			'post_status' => 'publish,future,draft,pending,private',
		)
	);
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
	$is_real_front = isset( $post_states['page_on_front'] );
	unset( $post_states['page_on_front'] );

	$ids = _get_front_page_ids();
	if ( in_array( (int) $post->ID, $ids, true ) ) {
		if ( $is_real_front ) {
			$post_states['page_on_front'] = _x( 'Front Page', 'page label' );
		} else {
			$post_states['page_on_front'] = _x( 'Pseudo Front Page', 'pseudo front', 'plex' );
		}
	}
	return $post_states;
}

/**
 * Callback function for 'admin_head' hook.
 *
 * @access private
 */
function _cb_admin_head() {
	echo '<style>';
	foreach ( _get_front_page_ids() as $id ) {
		echo "body.post-type-page select#parent_id option[value='" . esc_attr( $id ) . "']{font-weight:bold;}\n";
	}
	echo '</style>';
	?>
<style>
	.wpinc-plex-pseudo-front-blogname th { padding-bottom: 10px; }
	.wpinc-plex-pseudo-front-blogname td { padding-bottom: 5px; }
	.wpinc-plex-pseudo-front-blogdescription th { padding-top: 10px; }
	.wpinc-plex-pseudo-front-blogdescription td { padding-top: 5px; }
</style>
	<?php
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
	$inst = _get_instance();

	foreach ( \wpinc\plex\get_slug_key_to_combination() as $key => $slugs ) {
		$path = implode( '/', $slugs );
		$page = get_page_by_path( $path );
		if ( $page ) {
			$lab  = \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format );
			$node = array(
				'id'     => 'view-site-' . str_replace( '_', '-', $key ),
				'parent' => 'site-name',
				'title'  => esc_html( $lab ),
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
		 * Whether the site has the default front bloginfo.
		 *
		 * @var bool
		 */
		public $has_default_front_bloginfo = true;

		/**
		 * Whether redirect is suppressed.
		 *
		 * @var bool
		 */
		public $suppress_redirect = false;
	};
	return $values;
}
