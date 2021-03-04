<?php
namespace st;

/**
 *
 * Pseudo-Front
 *
 * @author Takuto Yanagida
 * @version 2021-03-04
 *
 */


require_once __DIR__ . '/custom-rewrite.php';


class PseudoFront {

	const ADMIN_QUERY_VAR = 'pseudo_front';

	private static $_instance = null;
	public static function instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private $_slug_to_label = array();
	private $_label_format = '';
	private $_is_default_front_bloginfo_enabled = true;

	private $_suppress_redirect = false;

	private function __construct() {}

	public function add_admin_labels( array $slug_to_label ) {
		foreach ( $slug_to_label as $slug => $label ) {
			$this->_slug_to_label[ $slug ] = $label;
		}
	}

	public function set_admin_label_format( string $format ) {
		$this->_label_format = $format;
	}

	public function set_default_front_bloginfo_enabled( bool $flag ) {
		$this->_is_default_front_bloginfo_enabled = $flag;
		if ( false === $flag ) {
			$key = $this->_get_default_key();
			delete_option( "blogname_$key" );
			delete_option( "blogdescription_$key" );
		}
	}

	public function initialize() {
		if ( is_admin() ) {
			add_action( 'admin_init',  array( $this, '_cb_admin_init' ) );

			add_filter( 'query_vars',  array( $this, '_cb_query_vars' ) );
			add_action( 'admin_menu',  array( $this, '_cb_admin_menu' ) );
			add_action( 'parse_query', array( $this, '_cb_parse_query' ) );

			add_filter( 'display_post_states', array( $this, '_cb_display_post_states' ), 10, 2 );
		} else {
			add_filter( 'option_page_on_front', array( $this, '_cb_option_page_on_front' ) );
			add_filter( 'redirect_canonical',   array( $this, '_cb_redirect_canonical' ), 1, 2 );

			add_filter( 'option_blogname',        array( $this, '_cb_option_blogname' ) );
			add_filter( 'option_blogdescription', array( $this, '_cb_option_blogdescription' ) );

			add_filter( 'body_class', array( $this, '_cb_body_class' ) );
		}
		if ( is_admin_bar_showing() ) {
			add_action( 'admin_bar_menu', array( $this, '_cb_admin_bar_menu' ) );
		}
	}

	public function home_url( string $path = '', string $scheme = null, array $vars = array() ): string {
		$fp = \st\custom_rewrite\create_norm_path( $vars );
		if ( ! empty( $fp ) ) {
			$fp = "/$fp";
		}
		if ( ! empty( $path ) ) {
			$path = '/' . ltrim( $path, '/' );
		}
		return home_url( $fp . $path, $scheme );
	}


	// -------------------------------------------------------------------------


	public static function generate_combination( array $arrays ): array {
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

	public static function get_slug_combination(): array {
		static $ret = null;
		if ( null === $ret ) {
			$slugs_array = \st\custom_rewrite\get_structures( 'slugs' );
			$ret = self::generate_combination( \st\custom_rewrite\get_structures( 'slugs' ) );
		}
		return $ret;
	}

	private function _get_key(): string {
		$vars = \st\custom_rewrite\get_structures( 'var' );
		$vals = array_map(
			function ( $e ) {
				return \st\custom_rewrite\get_query_var( $e );
			},
			$vars
		);
		return implode( '_', $vals );
	}

	private function _get_default_key(): string {
		$slugs = \st\custom_rewrite\get_structures( 'default_slug' );
		return implode( '_', $slugs );
	}

	private function _get_front_label( array $slugs ): string {
		$ls = array();
		foreach ( $slugs as $s ) {
			$ls[] = $this->_slug_to_label[ $s ] ?? $s;
		}
		if ( $this->_label_format ) {
			return sprintf( $this->_label_format, ...$ls );
		}
		return implode( ' ', $ls );
	}


	// -------------------------------------------------------------------------


	public function _cb_option_page_on_front( string $value ) {
		$fp = get_page_by_path( \st\custom_rewrite\create_full_path() );
		global $post;
		if ( $fp && $post && $fp->ID === $post->ID ) {
			$value = $fp->ID;
			$this->_suppress_redirect = true;
		}
		return $value;
	}

	public function _cb_redirect_canonical( string $redirect_url, string $requested_url ) {
		if ( $this->_suppress_redirect ) {
			return false;
		}
		return $redirect_url;
	}

	public function _cb_option_blogname( string $value ): string {
		$ret = get_option( 'blogname_' . $this->_get_key() );
		if ( false === $ret ) {
			return $value;
		}
		return $ret;
	}

	public function _cb_option_blogdescription( string $value ): string {
		$ret = get_option( 'blogdescription_' . $this->_get_key() );
		if ( false === $ret ) {
			return $value;
		}
		return $ret;
	}

	public function _cb_body_class( array $classes ): array {
		$vars = \st\custom_rewrite\get_structures( 'var' );
		$cs = array_map(
			function ( $var ) {
				$val = \st\custom_rewrite\get_query_var( $var );
				return str_replace( '_', '-', "$var-$val" );
			},
			$vars
		);
		return array_merge( $classes, $cs );
	}


	// -------------------------------------------------------------------------


	public function _cb_admin_init() {
		$skip_key = $this->_is_default_front_bloginfo_enabled ? '' : $this->_get_default_key();

		add_settings_section( 'pseudo-front-section', __( 'Sites' ), function () {}, 'general' );

		foreach ( self::get_slug_combination() as $slugs ) {
			$key = implode( '_', $slugs );
			if ( $key === $skip_key ) {
				continue;
			}
			$title = $this->_get_front_label( $slugs );

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
					PseudoFront::_cb_field_input( $key_bn );
				},
				'general',
				'pseudo-front-section'
			);
			add_settings_field(
				$key_bd,
				$title_bd,
				function () use ( $key_bd ) {
					PseudoFront::_cb_field_input( $key_bd );
				},
				'general',
				'pseudo-front-section'
			);
		}
	}

	public static function _cb_field_input( string $key ) {
		$_key = esc_attr( $key );
		$_val = esc_attr( get_option( $key ) );
		echo "<input id=\"$_key\" name=\"$_key\" type=\"text\" value=\"$_val\" class=\"regular-text\">";
	}

	public function _cb_query_vars( array $vars ): array {
		$vars[] = self::ADMIN_QUERY_VAR;
		return $vars;
	}

	public function _cb_admin_menu() {
		foreach ( self::get_slug_combination() as $slugs ) {
			$page = get_page_by_path( implode( '/', $slugs ) );
			if ( null === $page ) {
				continue;
			}
			$key = implode( '_', $slugs );
			$title = __( 'All Pages', 'default' ) . '<br>' . $this->_get_front_label( $slugs );

			$slug = add_query_arg( self::ADMIN_QUERY_VAR, $page->ID, 'edit.php?post_type=page' );
			add_pages_page( '', $title, 'edit_pages', $slug );
		}
	}

	public function _cb_parse_query( \WP_Query $query ) {
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$post_type = get_query_var( 'post_type' );
		if ( 'page' !== $post_type ) {
			return;
		}
		$page_id = get_query_var( self::ADMIN_QUERY_VAR );
		if ( empty( $page_id ) ) {
			return;
		}
		$page_id = intval( $page_id );
		// Must contains the posts with (parent_id === 0)
		// because of the algorithm of WP_Posts_List_Table->_display_rows_hierarchical().
		$ids = array_reverse( get_post_ancestors( $page_id ) );
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

	public function _cb_display_post_states( array $post_states, \WP_Post $post ): array {
		unset( $post_states['page_on_front'] );

		foreach ( self::get_slug_combination() as $slugs ) {
			$page = get_page_by_path( implode( '/', $slugs ) );
			if ( null === $page || $page->ID !== $post->ID ) {
				continue;
			}
			$post_states['page_on_front'] = _x( 'Front Page', 'page label' );
		}
		return $post_states;
	}


	// -------------------------------------------------------------------------


	public function _cb_admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ) {
		foreach ( self::get_slug_combination() as $slugs ) {
			$path = implode( '/', $slugs );
			$page = get_page_by_path( $path );
			if ( null === $page ) {
				continue;
			}
			$node = array(
				'id'     => 'view-site-' . implode( '-', $slugs ),
				'parent' => 'site-name',
				'title'  => $this->_get_front_label( $slugs ),
				'href'   => home_url( $path ),
			);
			$wp_admin_bar->add_menu( $node );
		}
	}

}


// -----------------------------------------------------------------------------


namespace st\pseudo_front;

function generate_combination( array $arrays ) { return \st\PseudoFront::generate_combination( $arrays ); }

function add_admin_labels( array $slug_to_label ) { \st\PseudoFront::instance()->add_admin_labels( $slug_to_label ); }
function set_admin_label_format( string $format ) { \st\PseudoFront::instance()->set_admin_label_format( $format ); }
function set_default_front_bloginfo_enabled( bool $flag ) { \st\PseudoFront::instance()->set_default_front_bloginfo_enabled( $flag ); }
function initialize() { \st\PseudoFront::instance()->initialize(); }

function home_url( string $path = '', string $scheme = null, array $vars = array() ) { \st\PseudoFront::instance()->home_url( $path, $scheme, $vars ); }
