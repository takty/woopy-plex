<?php
/**
 * Pseudo-Front
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2024-03-14
 */

declare(strict_types=1);

namespace wpinc\plex\pseudo_front;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/slug-key.php';

const ADMIN_QUERY_VAR = 'pseudo_front';
const EDIT_PAGE_URL   = 'edit.php?post_type=page';

/**
 * Adds an array of slug to label.
 *
 * @param array<string, string> $slug_to_label An array of slug to label.
 * @param string|null           $format        A format to assign.
 */
function add_admin_labels( array $slug_to_label, ?string $format = null ): void {
	$inst = _get_instance();

	$inst->slug_to_label = array_merge( $inst->slug_to_label, $slug_to_label );  // @phpstan-ignore-line
	if ( is_string( $format ) ) {
		$inst->label_format = $format;  // @phpstan-ignore-line
	}
}

/**
 * Activates the pseudo-front.
 *
 * @param array<string, mixed> $args {
 *     (Optional) Configuration arguments.
 *
 *     @type bool 'has_default_front_bloginfo'  Whether the site has the default front bloginfo. Default true.
 *     @type bool 'do_set_page_on_front_option' Whether to set page_on_front option.
 * }
 */
function activate( array $args = array() ): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'has_default_front_bloginfo'  => true,
		'do_set_page_on_front_option' => true,
	);

	$inst->has_default_front_bloginfo  = $args['has_default_front_bloginfo'];  // @phpstan-ignore-line
	$inst->do_set_page_on_front_option = $args['do_set_page_on_front_option'];  // @phpstan-ignore-line

	if ( ! $inst->has_default_front_bloginfo ) {
		$key = \wpinc\plex\get_default_key();
		delete_option( "blogname_$key" );
		delete_option( "blogdescription_$key" );
	}

	if ( is_admin() ) {
		add_action( 'admin_init', '\wpinc\plex\pseudo_front\_cb_admin_init', 10, 0 );

		add_filter( 'query_vars', '\wpinc\plex\pseudo_front\_cb_query_vars' );
		add_action( 'admin_menu', '\wpinc\plex\pseudo_front\_cb_admin_menu', 10, 0 );
		/**
		 * Because the definition of 'submenu_file' and the actual argument type are different.
		 *
		 * @psalm-suppress InvalidArgument
		 */
		add_filter( 'submenu_file', '\wpinc\plex\pseudo_front\_cb_submenu_file', 10, 2 );
		add_action( 'parse_query', '\wpinc\plex\pseudo_front\_cb_parse_query' );

		add_filter( 'display_post_states', '\wpinc\plex\pseudo_front\_cb_display_post_states', 10, 2 );
		add_action( 'admin_head', '\wpinc\plex\pseudo_front\_cb_admin_head', 10, 0 );
	} else {
		add_filter( 'option_page_on_front', '\wpinc\plex\pseudo_front\_cb_option_page_on_front' );
		add_filter( 'page_link', '\wpinc\plex\pseudo_front\_cb_page_link', 9, 2 );  // Add a hook before that of custom-rewrite.
		add_filter( 'redirect_canonical', '\wpinc\plex\pseudo_front\_cb_redirect_canonical', 1, 1 );

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
 * @param string                                $path   (Optional) Path relative to the home URL.
 *                                                      Default ''.
 * @param 'http'|'https'|'relative'|'rest'|null $scheme (Optional) Scheme to give the home URL context.
 *                                                      Accepts 'http', 'https', 'relative', 'rest', or null.
 * @param array<string, string>                 $vars   (Optional) An array of variable name to slug.
 * @return string Home URL link with optional path appended.
 */
function home_url( string $path = '', ?string $scheme = null, array $vars = array() ): string {
	$fp = \wpinc\plex\custom_rewrite\build_norm_path( $vars );
	if ( '' !== $fp ) {
		$fp = "/$fp";
	}
	if ( '' !== $path ) {
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
		if ( $page instanceof \WP_Post ) {
			$ids[] = $page->ID;
		}
	}
	return $ids;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'option_{$option}' filter (page_on_front).
 *
 * @access private
 * @global \WP_Post|null $post
 *
 * @param mixed $value Value of the option.
 * @return mixed The filtered string.
 */
function _cb_option_page_on_front( $value ) {
	$inst = _get_instance();

	$fp = get_page_by_path( \wpinc\plex\custom_rewrite\build_full_path() );
	global $post;
	if ( $post && $fp instanceof \WP_Post && $fp->ID === $post->ID ) {
		$inst->original_page_on_front = (int) $value;  // @phpstan-ignore-line
		$inst->suppress_redirect      = true;  // @phpstan-ignore-line

		$value = $fp->ID;
	}
	return $value;
}

/**
 * Callback function for 'page_link' filter.
 *
 * @access private
 *
 * @param string $link    The page's permalink.
 * @param int    $post_id The ID of the page.
 * @return string The filtered URL.
 */
function _cb_page_link( string $link, int $post_id ): string {
	$inst = _get_instance();
	if ( $inst->suppress_redirect && 'page' === get_option( 'show_on_front' ) ) {
		if ( $post_id === $inst->original_page_on_front ) {
			$link = \home_url( '/' );
		} else {
			$temp = _get_raw_page_link( $post_id );
			if ( is_string( $temp ) ) {
				$link = $temp;
			}
		}
	}
	return $link;
}

/**
 * Retrieves raw page link.
 *
 * @access private
 * @global \WP_Rewrite $wp_rewrite
 *
 * @param int $post_id The ID of the page.
 * @return string|null The raw page link.
 */
function _get_raw_page_link( int $post_id ): ?string {
	global $wp_rewrite;
	$struct = $wp_rewrite->get_page_permastruct();
	if ( ! is_string( $struct ) ) {
		return null;
	}
	$p = get_post( $post_id );
	if ( ! ( $p instanceof \WP_Post ) ) {
		return null;
	}
	$path = get_page_uri( $p );
	if ( ! is_string( $path ) ) {
		return null;
	}
	$link = \home_url( str_replace( '%pagename%', $path, $struct ) );
	return user_trailingslashit( $link, 'page' );
}

/**
 * Callback function for 'redirect_canonical' filter.
 *
 * @access private
 *
 * @param string $redirect_url  The redirect URL.
 * @return string The filtered string.
 */
function _cb_redirect_canonical( string $redirect_url ) {
	if ( _get_instance()->suppress_redirect ) {
		return '';
	}
	return $redirect_url;
}

/**
 * Callback function for 'option_{$option}' filter (blogname).
 *
 * @access private
 *
 * @param string $value Value of the option.
 * @return string The filtered string.
 */
function _cb_option_blogname( string $value ): string {
	$ret = get_option( 'blogname_' . \wpinc\plex\get_query_key() );
	if ( ! is_string( $ret ) || '' === $ret ) {  // Check for non-empty-string.
		return $value;
	}
	return $ret;
}

/**
 * Callback function for 'option_{$option}' filter (blogdescription).
 *
 * @access private
 *
 * @param string $value Value of the option.
 * @return string The filtered string.
 */
function _cb_option_blogdescription( string $value ): string {
	$ret = get_option( 'blogdescription_' . \wpinc\plex\get_query_key() );
	if ( ! is_string( $ret ) || '' === $ret ) {  // Check for non-empty-string.
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
	$cs   = array();
	$vars = \wpinc\plex\custom_rewrite\get_structure_vars();
	foreach ( $vars as $var ) {
		$val = \wpinc\plex\custom_rewrite\get_query_var( $var );
		if ( '' !== $val ) {
			$cs[] = str_replace( '_', '-', "$var-$val" );
		}
	}
	return array_merge( $classes, $cs );
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'admin_init' action.
 *
 * @access private
 */
function _cb_admin_init(): void {
	$inst    = _get_instance();
	$def_key = $inst->has_default_front_bloginfo ? '' : \wpinc\plex\get_default_key();

	add_settings_section( 'pseudo-front-section', _x( 'Pseudo Front Pages', 'pseudo front', 'wpinc_plex' ), function () {}, 'general' );

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
	if ( $inst->do_set_page_on_front_option ) {
		$path = \wpinc\plex\custom_rewrite\build_full_path();
		if ( '' !== $path ) {
			$fp = get_page_by_path( $path );
			if ( $fp instanceof \WP_Post ) {
				update_option( 'page_on_front', $fp->ID );
			}
		}
	}
}

/**
 * Function that fills the field with the desired form inputs.
 *
 * @access private
 *
 * @param string $key The key of the field.
 */
function _cb_field_input( string $key ): void {
	$val = get_option( $key );
	$val = is_string( $val ) ? $val : '';
	printf(
		'<input id="%1$s" name="%1$s" type="text" value="%2$s" class="regular-text">',
		esc_attr( $key ),
		esc_attr( $val )
	);
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
function _cb_admin_menu(): void {
	$inst = _get_instance();

	foreach ( \wpinc\plex\get_slug_key_to_combination() as $_key => $slugs ) {
		$page = get_page_by_path( implode( '/', $slugs ) );
		if ( $page instanceof \WP_Post ) {
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
 * @global \WP_Post|null $post
 *
 * @param string|null $submenu_file The submenu file.
 * @param string      $parent_file  The submenu item's parent file.
 * @return string|null The filtered file.
 */
function _cb_submenu_file( ?string $submenu_file, string $parent_file ): ?string {
	global $post;
	if ( EDIT_PAGE_URL === $parent_file ) {
		if ( $post ) {
			$as   = get_post_ancestors( $post );
			$as[] = $post->ID;
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
function _cb_parse_query( \WP_Query $query ): void {
	if ( ! $query->is_main_query() ) {
		return;
	}
	global $pagenow;
	if ( 'edit.php' !== $pagenow ) {
		return;
	}
	$post_type = \get_query_var( 'post_type' );
	if ( 'page' !== $post_type ) {
		return;
	}
	$page_id = \get_query_var( ADMIN_QUERY_VAR );
	if ( ! is_numeric( $page_id ) ) {
		return;
	}
	$page_id = (int) $page_id;
	// Must contains the posts with (parent_id === 0)
	// because of the algorithm of WP_Posts_List_Table->_display_rows_hierarchical().
	$ids   = array_reverse( get_post_ancestors( $page_id ) );
	$ids[] = $page_id;

	$ps = get_pages(  // In this function, parse_query and 'parse_query' hook is called since 6.3.
		array(
			'child_of'    => $page_id,
			'sort_column' => 'menu_order',
			'post_status' => 'publish,future,draft,pending,private',
		)
	);
	if ( is_array( $ps ) ) {
		foreach ( $ps as $p ) {
			$ids[] = $p->ID;
		}
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
	if ( in_array( $post->ID, $ids, true ) ) {
		if ( $is_real_front ) {
			$post_states['page_on_front'] = _x( 'Front Page', 'page label' );
		} else {
			$post_states['page_on_front'] = _x( 'Pseudo Front Page', 'pseudo front', 'wpinc_plex' );
		}
	}
	return $post_states;
}

/**
 * Callback function for 'admin_head' action.
 *
 * @access private
 */
function _cb_admin_head(): void {
	echo '<style>';
	foreach ( _get_front_page_ids() as $id ) {
		echo "body.post-type-page select#parent_id option[value='" . esc_attr( (string) $id ) . "']{font-weight:bold;}\n";
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
function _cb_admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ): void {
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
 * Gets instance.
 *
 * @access private
 *
 * @return object{
 *     slug_to_label              : array<string, string>,
 *     label_format               : string,
 *     has_default_front_bloginfo : bool,
 *     do_set_page_on_front_option: bool,
 *     suppress_redirect          : bool,
 *     original_page_on_front     : int,
 * } Instance.
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
		 * @var array<string, string>
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
		 * Whether to set page_on_front option.
		 *
		 * @var bool
		 */
		public $do_set_page_on_front_option = true;

		/**
		 * Whether redirect is suppressed.
		 *
		 * @var bool
		 */
		public $suppress_redirect = false;

		/**
		 * Original value of page_on_front when that is replaced.
		 *
		 * @var int
		 */
		public $original_page_on_front = 0;
	};
	return $values;
}
