<?php
/**
 * Custom Rewrite
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-04-04
 */

namespace wpinc\plex\custom_rewrite;

/**
 * Add a rewrite structure.
 *
 * @param array $args {
 *     Rewrite structure arguments.
 *
 *     @type string   $var          Name of the variable.
 *     @type string[] $slugs        An array of slugs.
 *     @type string   $default_slug The default slug. Default is empty.
 *     @type bool     $is_omittable Whether the variable is omittable. Default is false.
 *     @type bool     $is_global    Whether the global variable is assigned. Default is false.
 * }
 */
function add_structure( array $args ) {
	$args += array(
		'var'          => '',
		'slugs'        => array(),
		'default_slug' => '',
		'is_omittable' => false,
		'is_global'    => false,
	);
	if ( empty( $args['var'] ) || empty( $args['slugs'] ) ) {
		wp_die( '$args[\'var\'] and $args[\'slugs\'] must be assigned' );
	}
	if ( ! in_array( $args['default_slug'], $args['slugs'], true ) ) {
		wp_die( '$args[\'default_slug\'] must be an element of $args[\'slugs\']' );
	}
	if ( $args['is_omittable'] && empty( $args['default_slug'] ) ) {
		$args['default_slug'] = $args['slugs'][0];
	}
	_get_instance()->structures[] = $args;
}

/**
 * Add a post link filter.
 *
 * @param callable $callback Callable for post link filter.
 */
function add_post_link_filter( callable $callback ) {
	_get_instance()->post_link_filters[] = $callback;
}

/**
 * Initialize the custom rewrite.
 */
function initialize() {
	static $initialized = 0;
	if ( $initialized++ ) {
		return;
	}
	if ( ! is_admin() ) {
		add_action( 'after_setup_theme', '\wpinc\plex\custom_rewrite\_cb_after_setup_theme', 1 );
		add_filter( 'request', '\wpinc\plex\custom_rewrite\_cb_request' );
		add_filter( 'redirect_canonical', '\wpinc\plex\custom_rewrite\_cb_redirect_canonical', 1, 2 );
	}
	add_filter( 'page_link', '\wpinc\plex\custom_rewrite\_cb_page_link' );
	add_filter( 'post_link', '\wpinc\plex\custom_rewrite\_cb_link', 10, 2 );
	add_filter( 'post_type_link', '\wpinc\plex\custom_rewrite\_cb_link', 10, 2 );

	add_filter( 'post_type_archive_link', '\wpinc\plex\custom_rewrite\_cb_link', 20 );  // Caution!
	add_filter( 'paginate_links', '\wpinc\plex\custom_rewrite\_cb_link' );
	add_filter( 'term_link', '\wpinc\plex\custom_rewrite\_cb_link' );
	add_filter( 'year_link', '\wpinc\plex\custom_rewrite\_cb_link' );
	add_filter( 'month_link', '\wpinc\plex\custom_rewrite\_cb_link' );
	add_filter( 'day_link', '\wpinc\plex\custom_rewrite\_cb_link' );
	add_filter( 'search_link', '\wpinc\plex\custom_rewrite\_cb_link' );
	add_filter( 'feed_link', '\wpinc\plex\custom_rewrite\_cb_link' );
}

/**
 * Sets the value of a query variable in the custom rewrite.
 *
 * @param string $var   Query variable key.
 * @param string $value Query variable value.
 */
function set_query_var( string $var, string $value ) {
	return _get_instance()->vars[ $var ] = $value;
}

/**
 * Retrieves the value of a query variable in the custom rewrite.
 *
 * @param string $var     The variable key to retrieve.
 * @param string $default (Optional) Value to return if the query variable is not set. Default is empty.
 * @return string Slugs of the query variable.
 */
function get_query_var( string $var, string $default = '' ): string {
	return _get_instance()->vars[ $var ] ?? $default;
}

/**
 * Retrieve rewrite structures.
 *
 * @param ?string $field (Optional) Field of rewrite structure args.
 * @param ?array  $vars  (Optional) Variable names for filtering.
 * @return mixed|array Rewrite structures.
 */
function get_structures( ?string $field = null, ?array $vars = null ) {
	$structs = _get_instance()->structures;
	if ( $vars ) {
		$structs = array_filter(
			$structs,
			function ( $st ) use ( $vars ) {
				return in_array( $st['var'], $vars, true );
			}
		);
		$structs = array_values( $structs );
	}
	if ( $field ) {
		return array_column( $structs, $field );
	}
	return $structs;
}

/**
 * Retrieve invalid pagename.
 *
 * @return ?array Invalid pagename.
 */
function get_invalid_pagename(): ?array {
	return _get_instance()->invalid_pagename;
}

/**
 * Build a full path.
 *
 * @param string[] $vars (Optional) An array of variable name to slug.
 * @return string The full path.
 */
function build_full_path( array $vars = array() ): string {
	$inst = _get_instance();
	$ps   = array();

	$vars += $inst->vars;
	foreach ( $inst->structures as $st ) {
		$v    = empty( $vars[ $st['var'] ] ) ? $st['default_slug'] : $vars[ $st['var'] ];
		$ps[] = $v;
	}
	return implode( '/', $ps );
}

/**
 * Build a normalized path.
 *
 * @param string[] $vars (Optional) An array of variable name to slug.
 * @return string The normalized path.
 */
function build_norm_path( array $vars = array() ): string {
	$inst = _get_instance();
	$ps   = array();

	$vars += $inst->vars;
	foreach ( $inst->structures as $st ) {
		$v = empty( $vars[ $st['var'] ] ) ? $st['default_slug'] : $vars[ $st['var'] ];
		if ( ! $st['is_omittable'] || $v !== $st['default_slug'] ) {
			$ps[] = $v;
		}
	}
	return implode( '/', $ps );
}


// -----------------------------------------------------------------------------


/**
 * Replace path.
 *
 * @access private
 *
 * @param string $url    Original URL.
 * @param string $before Searched value.
 * @param string $after  Replacement value.
 * @return string Modified URL.
 */
function _replace_path( string $url, string $before, string $after ): string {
	$home = trim( wp_parse_url( \home_url(), PHP_URL_PATH ), '/' );
	$home = empty( $home ) ? '/' : "/$home/";
	$pu   = wp_parse_url( $url );

	// phpcs:disable
	$scheme = isset( $pu['scheme'] )   ? $pu['scheme'] . '://' : '';
	$host   = isset( $pu['host'] )     ? $pu['host']           : '';
	$port   = isset( $pu['port'] )     ? ':' . $pu['port']     : '';
	$user   = isset( $pu['user'] )     ? $pu['user']           : '';
	$pass   = isset( $pu['pass'] )     ? ':' . $pu['pass']     : '';
	$pass   = ( $user || $pass )       ? "$pass@"              : '';
	$path   = isset( $pu['path'] )     ? $pu['path']           : '';
	$query  = isset( $pu['query'] )    ? '?' . $pu['query']    : '';
	$frag   = isset( $pu['fragment'] ) ? '#' . $pu['fragment'] : '';
	// phpcs:enable

	$path = str_replace(
		trailingslashit( "$home$before" ),
		trailingslashit( "$home$after" ),
		trailingslashit( $path )
	);
	$path = ( $home === $path ) ? $path : user_trailingslashit( $path );

	return "$scheme$user$pass$host$port$path$query$frag";
}

/**
 * Extract variable slugs from URL.
 *
 * @access private
 *
 * @param string $url URL.
 * @return array An array of variable name to slug.
 */
function _extract_vars( string $url ): array {
	list( $path ) = explode( '?', $url );

	$ps   = explode( '/', trim( str_replace( \home_url(), '', $path ), '/' ) );
	$vars = array();
	$sps  = array();
	$p    = array_shift( $ps );

	foreach ( _get_instance()->structures as $st ) {
		if ( in_array( $p, $st['slugs'], true ) ) {
			$vars[ $st['var'] ] = $p;
			$sps[]              = $p;

			$p = array_shift( $ps );
		} else {
			$vars[ $st['var'] ] = $st['is_omittable'] ? $st['default_slug'] : null;
		}
	}
	return array( $vars, implode( '/', $sps ) );
}

/**
 * Extract query path from URL.
 *
 * @access private
 *
 * @param string $url URL.
 * @return string The query path.
 */
function _extract_query_path( string $url ): string {
	list( $path ) = explode( '?', $url );

	$ps  = explode( '/', trim( str_replace( \home_url(), '', $path ), '/' ) );
	$sps = array();
	$p   = array_shift( $ps );

	foreach ( _get_instance()->structures as $st ) {
		if ( in_array( $p, $st['slugs'], true ) ) {
			$sps[] = $p;
			$p     = array_shift( $ps );
		}
	}
	return implode( '/', $sps );
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'after_setup_theme' hook.
 *
 * @access private
 */
function _cb_after_setup_theme() {
	$inst = _get_instance();
	if ( empty( $inst->structures ) ) {
		return;
	}
	list( $req, $req_file )   = _parse_request();
	list( $inst->vars, $cur ) = _extract_vars( $req );
	_register_globals();
	if ( empty( $req ) ) {
		return;
	}
	$full = build_full_path( $inst->vars );
	$norm = build_norm_path( $inst->vars );

	$erased = trim( empty( $cur ) ? "/$req/" : _str_replace_one( "/$cur/", '/', "/$req/" ), '/' );
	$added  = trim( empty( $cur ) ? "/$full/$req/" : _str_replace_one( "/$cur/", "/$full/", "/$req/" ), '/' );
	$ideal  = trim( empty( $cur ) ? "/$norm/$req/" : _str_replace_one( "/$cur/", "/$norm/", "/$req/" ), '/' );

	list( $is_page_req, $pn_erased ) = _is_page_request( $erased, $req_file );

	if ( $is_page_req ) {
		list( , $pn_added ) = _is_page_request( $added, $req_file );
		if ( ! empty( $pn_added ) && get_page_by_path( $pn_added ) ) {
			if ( $cur !== $norm ) {
				_redirect( $req, $ideal );
			}
			_replace_request( $req, $added );
		} else {
			$inst->is_page_not_found = true;

			if ( is_user_logged_in() ) {
				list(, $pn_orig ) = _is_page_request( $req, $req_file );

				$post_orig = get_page_by_path( $pn_orig );
				if ( ! empty( $pn_orig ) && $post_orig ) {
					$inst->invalid_pagename = array( $pn_orig, $pn_added );
				}
			}
		}
	} else {
		if ( $cur !== $norm ) {
			_redirect( $req, $ideal );
		}
		_replace_request( $req, $erased );
	}
}

/**
 * Parse request to find query.
 *
 * @access private
 * @see WP::parse_request()
 * @global WP_Rewrite $wp_rewrite WordPress rewrite component.
 *
 * @return array Array of requested path and requested file.
 */
function _parse_request(): array {
	global $wp_rewrite;
	$rewrite = $wp_rewrite->wp_rewrite_rules();
	if ( empty( $rewrite ) ) {
		return array( '', '' );
	}
	// phpcs:disable
	$pathinfo         = $_SERVER['PATH_INFO'] ?? '';
	list( $pathinfo ) = explode( '?', $pathinfo );
	$pathinfo         = str_replace( '%', '%25', $pathinfo );

	list( $req_uri ) = explode( '?', $_SERVER['REQUEST_URI'] );
	$home_path       = trim( parse_url( \home_url(), PHP_URL_PATH ), '/' );
	$home_path_regex = sprintf( '|^%s|i', preg_quote( $home_path, '|' ) );
	// phpcs:enable

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
		if ( $req_uri === $wp_rewrite->index ) {
			$req_uri = '';
		}
		$req_path = $req_uri;
	}
	$req_file = $req_uri;
	return array( $req_path, $req_file );
}

/**
 * Set up the global variables.
 *
 * @access private
 */
function _register_globals() {
	$inst = _get_instance();
	foreach ( $inst->structures as $st ) {
		if ( $st['is_global'] ) {
			$GLOBALS[ $st['var'] ] = $inst->vars[ $st['var'] ];
		}
	}
}

/**
 * Replace one occurrence of the search string with the replacement string.
 *
 * @access private
 *
 * @param string $search  The value being searched for.
 * @param string $replace The replacement value that replaces found search values.
 * @param string $subject The string being searched and replaced on.
 * @return string A string with the replaced values.
 */
function _str_replace_one( string $search, string $replace, string $subject ): string {
	$s = preg_quote( $search, '/' );
	return preg_replace( "/$s/", $replace, $subject, 1 );
}

/**
 * Whether the request is for page.
 *
 * @access private
 * @global WP_Rewrite $wp_rewrite WordPress rewrite component.
 *
 * @param string $req_path Requested path.
 * @param string $req_file Requested file.
 * @return array Array of boolean value and pagename.
 */
function _is_page_request( string $req_path, string $req_file ): array {
	if ( empty( $req_path ) ) {
		return array( true, '' );
	}
	global $wp_rewrite;
	$rewrite = $wp_rewrite->wp_rewrite_rules();
	if ( empty( $rewrite ) ) {
		return array( false, null );
	}
	$req_match = $req_path;
	foreach ( (array) $rewrite as $match => $query ) {
		if ( ! empty( $req_file ) && strpos( $match, $req_file ) === 0 && $req_file !== $req_path ) {
			$req_match = $req_file . '/' . $req_path;
		}
		if ( preg_match( "#^$match#", $req_match, $matches ) || preg_match( "#^$match#", urldecode( $req_match ), $matches ) ) {
			if ( preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch ) ) {
				return array( true, $matches[ $varmatch[1] ] );  // A page is requested!
			}
			break;
		}
	}
	return array( false, null );
}

/**
 * Redirect.
 *
 * @access private
 *
 * @param string $req_path Requested path.
 * @param string $after    Replacement value.
 */
function _redirect( string $req_path, string $after ) {
	// phpcs:disable
	$url = _replace_path( $_SERVER['REQUEST_URI'] ?? '', $req_path, $after );
	// phpcs:enable
	wp_safe_redirect( $url, 301 );
	exit;
}

/**
 * Replace request.
 *
 * @access private
 *
 * @param string $req_path Requested path.
 * @param string $after    Replacement value.
 */
function _replace_request( string $req_path, string $after ) {
	// phpcs:disable
	$_SERVER['REQUEST_URI_ORIG'] = $_SERVER['REQUEST_URI'] ?? '';
	$_SERVER['REQUEST_URI']      = _replace_path( $_SERVER['REQUEST_URI'] ?? '', $req_path, $after );
	// phpcs:enable
}

/**
 * Callback function for 'request' filter.
 *
 * @access private
 *
 * @param array $query_vars The array of requested query variables.
 * @return array The filtered array.
 */
function _cb_request( array $query_vars ): array {
	if ( _get_instance()->is_page_not_found ) {
		$query_vars['error'] = '404';
	}
	return $query_vars;
}

/**
 * Callback function for 'redirect_canonical' filter.
 *
 * @access private
 *
 * @param string $redirect_url  The redirect URL.
 * @param string $requested_url The requested URL.
 * @return string The redirect URL.
 */
function _cb_redirect_canonical( string $redirect_url, string $requested_url ): string {
	$inst = _get_instance();
	if ( $inst->is_page_not_found ) {
		return false;
	}
	if ( isset( $_SERVER['REQUEST_URI_ORIG'] ) ) {
		// phpcs:disable
		$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
		$url  = ( is_ssl() ? 'https://' : 'http://' ) . $host . $_SERVER['REQUEST_URI_ORIG'];
		// phpcs:enable
		if ( $url === $redirect_url ) {
			return false;  // For avoiding redirect loop.
		}
	}
	return $redirect_url;
}


// -------------------------------------------------------------------------


/**
 * Callback function for 'page_link' filter.
 *
 * @access private
 *
 * @param string $link The page's permalink.
 * @return string The filtered URL.
 */
function _cb_page_link( string $link ): string {
	list( $vars, $cur ) = _extract_vars( $link );

	$norm = build_norm_path( $vars );
	if ( $norm !== $cur ) {
		$link = _replace_path( $link, $cur, $norm );
	}
	return $link;
}

/**
 * Callback function for 'link' filter.
 *
 * @access private
 *
 * @param string    $link The permalink.
 * @param ?\WP_Post $post (When used as 'post_link' filter) The post in question.
 * @return string The filtered URL.
 */
function _cb_link( string $link, ?\WP_Post $post = null ): string {
	$cur  = _extract_query_path( $link );
	$inst = _get_instance();
	if ( $post ) {
		foreach ( $inst->post_link_filters as $f ) {
			$ret = call_user_func( $f, $inst->vars, $post );
			if ( $ret ) {
				$inst->vars = $ret;
			}
		}
	}
	$norm = build_norm_path( $inst->vars );
	if ( $norm !== $cur ) {
		$link = _replace_path( $link, $cur, $norm );
	}
	return $link;
}


// -------------------------------------------------------------------------


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
		 * The rewrite structures.
		 *
		 * @var array
		 */
		public $structures = array();

		/**
		 * The link filters.
		 *
		 * @var callable[]
		 */
		public $post_link_filters = array();

		/**
		 * The query vars.
		 *
		 * @var string[]
		 */
		public $vars = array();

		/**
		 * Whether the status is 'page not found'.
		 *
		 * @var bool
		 */
		public $is_page_not_found = false;

		/**
		 * The invalid pagename data.
		 *
		 * @var string[]
		 */
		public $invalid_pagename = null;
	};
	return $values;
}
