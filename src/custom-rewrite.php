<?php
namespace st;
/**
 *
 * Custom Rewrite
 *
 * @author Takuto Yanagida
 * @version 2021-03-03
 *
 */


class CustomRewrite {

	static private $_instance = null;
	static public function instance() {
		if ( self::$_instance === null ) self::$_instance = new self();
		return self::$_instance;
	}

	private $_structures = [];
	private $_post_link_filters = [];
	private $_is_initialized = false;

	private $_vars = [];
	private $_is_page_not_found = false;
	private $_invalid_pagename = null;

	private function __construct() {}

	public function add_structure( array $args ) {
		$args += [
			'var' => '',
			'slugs' => [],
			'default_slug' => '',
			'is_default_omittable' => false,
		];
		if ( empty( $args['var'] ) || empty( $args['slugs'] ) ) {
			trigger_error( '$args[\'var\'] and $args[\'slugs\'] must be assigned', E_USER_ERROR );
		}
		if ( ! in_array( $args['default_slug'], $args['slugs'], true ) ) {
			trigger_error( '$args[\'default_slug\'] must be an element of $args[\'slugs\']', E_USER_ERROR );
		}
		if ( $args['is_default_omittable'] && empty( $args['default_slug'] ) ) {
			$args['default_slug'] = $args['slugs'][0];
		}
		$this->_structures[] = $args;
	}

	public function add_post_link_filter( $callback ) {
		$this->_post_link_filters[] = $callback;
	}

	public function initialize() {
		if ( $this->_is_initialized ) return;
		$this->_is_initialized = true;

		if ( ! is_admin() ) {
			add_action( 'after_setup_theme',  [ $this, '_cb_after_setup_theme' ], 1 );
			add_filter( 'query_vars',         [ $this, '_cb_query_vars' ] );
			add_filter( 'request',            [ $this, '_cb_request' ] );
			add_filter( 'redirect_canonical', [ $this, '_cb_redirect_canonical' ], 1, 2 );
		}
		add_filter( 'post_type_link',         [ $this, '_cb_post_link' ], 10, 2 );
		add_filter( 'post_link',              [ $this, '_cb_post_link' ], 10, 2 );
		add_filter( 'page_link',              [ $this, '_cb_link' ] );

		add_filter( 'post_type_archive_link', [ $this, '_cb_link' ] );
		add_filter( 'paginate_links',         [ $this, '_cb_link' ] );
		add_filter( 'term_link',              [ $this, '_cb_link' ] );
		add_filter( 'year_link',              [ $this, '_cb_link' ] );
		add_filter( 'month_link',             [ $this, '_cb_link' ] );
		add_filter( 'day_link',               [ $this, '_cb_link' ] );
		add_filter( 'search_link',            [ $this, '_cb_link' ] );
		add_filter( 'feed_link',              [ $this, '_cb_link' ] );
	}

	public function get_structures( string $field = null ) {
		if ( $field ) return array_map( function ( $st ) use ( $field ) { return $st[ $field ]; }, $this->_structures );
		return $this->_structures;
	}

	public function get_query_var( string $var, string $default = '' ) {
		return $this->_vars[ $var ] ?? $default;
	}

	public function get_invalid_pagename() {
		return $this->_invalid_pagename;
	}

	public function create_full_path( array $vars = [] ) {
		$vars += $this->_vars;
		$ps = [];
		foreach ( $this->_structures as $st ) {
			$v = $vars[ $st['var'] ];
			if ( ! $v ) $v = $st['default_slug'];
			$ps[] = $v;
		}
		return implode( '/', $ps );
	}

	public function create_norm_path( array $vars = [] ) {
		$vars += $this->_vars;
		$ps = [];
		foreach ( $this->_structures as $st ) {
			$v = $vars[ $st['var'] ];
			if ( $st['is_default_omittable'] && $v === $st['default_slug'] ) continue;
			if ( ! $v ) $v = $st['default_slug'];
			$ps[] = $v;
		}
		return implode( '/', $ps );
	}


	// -------------------------------------------------------------------------


	static private function _get_request() {
		global $wp_rewrite;
		$rewrite = $wp_rewrite->wp_rewrite_rules();
		if ( empty( $rewrite ) ) return '';

		$pathinfo         = $_SERVER['PATH_INFO'] ?? '';
		list( $pathinfo ) = explode( '?', $pathinfo );
		$pathinfo         = str_replace( "%", "%25", $pathinfo );

		list( $req_uri ) = explode( '?', $_SERVER['REQUEST_URI'] );
		$home_path       = trim( parse_url( home_url(), PHP_URL_PATH ), '/' );
		$home_path_regex = sprintf( '|^%s|i', preg_quote( $home_path, '|' ) );

		$req_uri  = str_replace( $pathinfo, '', $req_uri );
		$req_uri  = trim( $req_uri, '/' );
		$req_uri  = preg_replace( $home_path_regex, '', $req_uri );
		$req_uri  = trim( $req_uri, '/' );
		$pathinfo = trim( $pathinfo, '/' );
		$pathinfo = preg_replace( $home_path_regex, '', $pathinfo );
		$pathinfo = trim( $pathinfo, '/' );

		if ( ! empty( $pathinfo ) && ! preg_match( '|^.*' . $wp_rewrite->index . '$|', $pathinfo ) ) {
			$req_path = $pathinfo;
		} else {
			if ( $req_uri === $wp_rewrite->index ) $req_uri = '';
			$req_path = $req_uri;
		}
		$req_file = $req_uri;
		return [ $req_path, $req_file ];
	}

	static private function _is_page_request( string $req_path, string $req_file ) {
		if ( empty( $req_path ) ) return [ true, '' ];

		global $wp_rewrite;
		$rewrite = $wp_rewrite->wp_rewrite_rules();
		if ( empty( $rewrite ) ) return [ false, null ];

		$req_match = $req_path;
		foreach ( (array) $rewrite as $match => $query ) {
			if ( ! empty( $req_file ) && strpos( $match, $req_file ) === 0 && $req_file != $req_path ) {
				$req_match = $req_file . '/' . $req_path;
			}
			if ( preg_match( "#^$match#", $req_match, $matches ) || preg_match( "#^$match#", urldecode( $req_match ), $matches ) ) {
				if ( preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch ) ) {
					return [ true, $matches[ $varmatch[1] ] ];  // A page is requested!
				}
				break;
			}
		}
		return [ false, null ];
	}

	static private function _replacePath( string $url, string $before, string $after ) {
		$home = trim(  parse_url( home_url(), PHP_URL_PATH ), '/' );
		$home = empty( $home ) ? '/' : "/$home/";

		$pu = parse_url( $url );
		$scheme = isset( $pu['scheme'] )   ? $pu['scheme'] . '://' : '';
		$host   = isset( $pu['host'] )     ? $pu['host']           : '';
		$port   = isset( $pu['port'] )     ? ':' . $pu['port']     : '';
		$user   = isset( $pu['user'] )     ? $pu['user']           : '';
		$pass   = isset( $pu['pass'] )     ? ':' . $pu['pass']     : '';
		$pass   = ( $user || $pass )       ? "$pass@"              : '';
		$path   = isset( $pu['path'] )     ? $pu['path']           : '';
		$query  = isset( $pu['query'] )    ? '?' . $pu['query']    : '';
		$frag   = isset( $pu['fragment'] ) ? '#' . $pu['fragment'] : '';

		$path = str_replace( trailingslashit( "$home$before" ), trailingslashit( "$home$after" ), trailingslashit( $path ) );
		$path = ( $home === $path ) ? $path : user_trailingslashit( $path );

		return "$scheme$user$pass$host$port$path$query$frag";
	}

	static private function _replaceRequest( string $req_path, string $after ) {
		$_SERVER['REQUEST_URI_ORIG'] = $_SERVER['REQUEST_URI'];
		$_SERVER['REQUEST_URI'] = self::_replacePath( $_SERVER['REQUEST_URI'], $req_path, $after );
	}

	static private function _redirect( string $req_path, string $after ) {
		$url = self::_replacePath( $_SERVER['REQUEST_URI'], $req_path, $after );
		wp_redirect( $url, 301 );
		exit;
	}


	// -------------------------------------------------------------------------


	private function _extract_vars( string $url ) {
		list( $path ) = explode( '?', $url );
		$path = trim( str_replace( \home_url(), '', $path ), '/' );
		$ps = explode( '/', $path );
		$vars = [];
		$sps = [];

		$p = array_shift( $ps );
		foreach ( $this->_structures as $st ) {
			if ( in_array( $p, $st['slugs'], true ) ) {
				$vars[ $st['var'] ] = $p;
				$sps[] = $p;
				$p = array_shift( $ps );
			} else {
				if ( $st['is_default_omittable'] ) {
					$vars[ $st['var'] ] = $st['default_slug'];
				} else {
					$vars[ $st['var'] ] = null;
				}
			}
		}
		return [ $vars, implode( '/', $sps ) ];
	}


	// -------------------------------------------------------------------------


	public function _cb_after_setup_theme() {  // Private
		if ( empty( $this->_structures ) ) return;
		list( $req, $req_file ) = self::_get_request();
		list( $this->_vars, $cur ) = $this->_extract_vars( $req );

		$full = $this->create_full_path( $this->_vars );
		$norm = $this->create_norm_path( $this->_vars );

		$erased = trim( empty( $cur ) ? "/$req/"       : str_replace( "/$cur/", '/',       "/$req/" ), '/' );
		$added  = trim( empty( $cur ) ? "/$full/$req/" : str_replace( "/$cur/", "/$full/", "/$req/" ), '/' );
		$ideal  = trim( empty( $cur ) ? "/$norm/$req/" : str_replace( "/$cur/", "/$norm/", "/$req/" ), '/' );

		list( $is_page_req, $pn_erased ) = self::_is_page_request( $erased, $req_file );

		if ( $is_page_req ) {
			list( , $pn_added ) = self::_is_page_request( $added, $req_file );
			if ( ! empty( $pn_added ) && get_page_by_path( $pn_added ) ) {
				if ( $cur !== $norm ) self::_redirect( $req, $ideal );
				self::_replaceRequest( $req, $added );
			} else {
				$this->_is_page_not_found = true;

				if ( is_user_logged_in() ) {
					list(, $pn_orig ) = self::_is_page_request( $req, $req_file );
					$post_orig = get_page_by_path( $pn_orig );
					if ( ! empty( $pn_orig ) && $post_orig ) {
						$this->_invalid_pagename = [ $pn_orig, $pn_added ];
					}
				}
			}
		} else {
			if ( $cur !== $norm ) self::_redirect( $req, $ideal );
			self::_replaceRequest( $req, $erased );
		}
	}

	public function _cb_query_vars( array $vars ) {  // Private
		foreach ( $this->_structures as $st ) $vars[] = $st['var'];
		return $vars;
	}

	public function _cb_request( array $query_vars ) {  // Private
		if ( $this->_is_page_not_found ) $query_vars['error'] = '404';
		foreach ( $this->_vars as $key => $val ) $query_vars[ $key ] = $val;
		return $query_vars;
	}

	public function _cb_redirect_canonical( string $redirect_url, string $requested_url ) {  // Private
		if ( $this->_is_page_not_found ) return false;
		return $redirect_url;
	}


	// -------------------------------------------------------------------------


	public function _cb_post_link( string $link, \WP_Post $post ) {  // Private
		list( $vars, $cur ) = $this->_extract_vars( $link );

		foreach ( $this->_post_link_filters as $f ) {
			$ret = call_user_func( $f, $vars, $post );
			if ( $ret ) $vars = $ret;
		}
		$norm = $this->create_norm_path( $vars );

		if ( $norm !== $cur ) $link = self::_replacePath( $link, $cur, $norm );
		return $link;
	}

	public function _cb_link( string $link ) {  // Private
		list( $vars, $cur ) = $this->_extract_vars( $link );
		$norm = $this->create_norm_path( $vars );

		if ( $norm !== $cur ) $link = self::_replacePath( $link, $cur, $norm );
		return $link;
	}

}


// -----------------------------------------------------------------------------


namespace st\custom_rewrite;

function add_structure( array $args ) { \st\CustomRewrite::instance()->add_structure( $args ); }
function add_post_link_filter( $callback ) { \st\CustomRewrite::instance()->add_post_link_filter( $callback ); }
function initialize() { \st\CustomRewrite::instance()->initialize(); }

function get_structures( string $field = null ) { return \st\CustomRewrite::instance()->get_structures( $field ); }
function get_query_var( string $var, string $default = '' ) { return \st\CustomRewrite::instance()->get_query_var( $var, $default ); }
function get_invalid_pagename() { return \st\CustomRewrite::instance()->get_invalid_pagename(); }

function create_full_path( array $vars = [] ) { return \st\CustomRewrite::instance()->create_full_path( $vars ); }
function create_norm_path( array $vars = [] ) { return \st\CustomRewrite::instance()->create_norm_path( $vars ); }


// initialize ???