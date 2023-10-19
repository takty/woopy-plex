<?php
/**
 * Custom Rewrite
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2023-10-19
 */

declare(strict_types=1);

namespace wpinc\plex\custom_rewrite;

/** phpcs:ignore
 * Adds a rewrite structure.
 *
 * phpcs:ignore
 * @param array{
 *     var          : string,
 *     slugs        : string[],
 *     default_slug?: string,
 *     omittable?   : bool,
 *     global?      : bool,
 * } $args Rewrite structure arguments.
 *
 * $args {
 *     Rewrite structure arguments.
 *
 *     @type string   'var'          Name of the variable.
 *     @type string[] 'slugs'        An array of slugs.
 *     @type string   'default_slug' The default slug. Default empty.
 *     @type bool     'omittable'    Whether the variable is omittable. Default false.
 *     @type bool     'global'       Whether the global variable is assigned. Default false.
 * }
 */
function add_structure( array $args ): void {
	$args += array(
		'default_slug' => '',
		'omittable'    => false,
		'global'       => false,
	);
	if ( empty( $args['var'] ) ) {
		wp_die( '$args[\'var\'] must be assigned.' );
	}
	if ( empty( $args['slugs'] ) ) {
		wp_die( '$args[\'slugs\'] must be a non-empty array.' );
	}
	if ( $args['omittable'] ) {
		if ( ! empty( $args['default_slug'] ) && ! in_array( $args['default_slug'], $args['slugs'], true ) ) {
			wp_die( '$args[\'default_slug\'] must be an element of $args[\'slugs\'] when omittable.' );
		}
		if ( empty( $args['default_slug'] ) ) {
			// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
			$args['default_slug'] = $args['slugs'][0];
		}
	}
	_get_instance()->structures[] = $args;  // @phpstan-ignore-line
}

/**
 * Adds a post link filter.
 *
 * @param callable $callback Callable for post link filter.
 */
function add_post_link_filter( callable $callback ): void {
	_get_instance()->post_link_filters[] = $callback;  // @phpstan-ignore-line
}

/**
 * Activates the custom rewrite.
 */
function activate(): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	if ( ! is_admin() ) {
		add_action( 'after_setup_theme', '\wpinc\plex\custom_rewrite\_cb_after_setup_theme', 1 );
		add_filter( 'request', '\wpinc\plex\custom_rewrite\_cb_request' );
		add_filter( 'redirect_canonical', '\wpinc\plex\custom_rewrite\_cb_redirect_canonical', 1, 2 );
	}
	add_filter( 'url_to_postid', '\wpinc\plex\custom_rewrite\_cb_url_to_postid' );

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
 * @param string $var_name Query variable name.
 * @param string $value    Query variable value.
 */
function set_query_var( string $var_name, string $value ): string {
	return _get_instance()->vars[ $var_name ] = $value;  // @phpstan-ignore-line
}

/**
 * Retrieves the value of a query variable in the custom rewrite.
 *
 * @param string $var_name    The variable name to retrieve.
 * @param string $default_val (Optional) Value to return if the query variable is not set. Default empty.
 * @return string Slugs of the query variable.
 */
function get_query_var( string $var_name, string $default_val = '' ): string {
	return _get_instance()->vars[ $var_name ] ?? $default_val;
}

/**
 * Retrieves rewrite structures.
 *
 * @param string|null   $field (Optional) Field of rewrite structure args.
 * @param string[]|null $vars  (Optional) Variable names for filtering.
 * @return string[]|array<string[]>|bool[]|array{ var: string, slugs: string[], default_slug: string, omittable: bool, global: bool }[] Rewrite structures.
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
 * Retrieves slugs of the rewrite structures.
 *
 * @psalm-suppress InvalidReturnType, InvalidReturnStatement
 *
 * @param string[]|null $vars  (Optional) Variable names for filtering.
 * @return list<string[]> Slugs.
 */
function get_structure_slugs( ?array $vars = null ): array {
	return get_structures( 'slugs', $vars );  // @phpstan-ignore-line
}

/**
 * Retrieves vars of the rewrite structures.
 *
 * @psalm-suppress InvalidReturnType, InvalidReturnStatement
 *
 * @param string[]|null $vars  (Optional) Variable names for filtering.
 * @return string[] Vars.
 */
function get_structure_vars( ?array $vars = null ): array {
	return get_structures( 'var', $vars );  // @phpstan-ignore-line
}

/**
 * Retrieves default slugs of the rewrite structures.
 *
 * @psalm-suppress InvalidReturnType, InvalidReturnStatement
 *
 * @param string[]|null $vars  (Optional) Variable names for filtering.
 * @return string[] Default slugs.
 */
function get_structure_default_slugs( ?array $vars = null ): array {
	return get_structures( 'default_slug', $vars );  // @phpstan-ignore-line
}

/**
 * Retrieves invalid pagename.
 *
 * @return array<string|null>|null Invalid pagename.
 */
function get_invalid_pagename(): ?array {
	return _get_instance()->invalid_pagename;
}

/**
 * Builds a full path.
 *
 * @param array<string, string> $vars (Optional) An array of variable name to slug.
 * @return string The full path.
 */
function build_full_path( array $vars = array() ): string {
	$inst = _get_instance();
	$ps   = array();

	$vars += $inst->vars;
	foreach ( $inst->structures as $st ) {
		if ( empty( $vars[ $st['var'] ] ) ) {
			if ( ! $st['omittable'] ) {
				break;
			}
			$v = $st['default_slug'];
		} else {
			$v = $vars[ $st['var'] ];
		}
		$ps[] = $v;
	}
	return implode( '/', $ps );
}

/**
 * Builds a normalized path.
 *
 * @param array<string, string> $vars (Optional) An array of variable name to slug.
 * @return string The normalized path.
 */
function build_norm_path( array $vars = array() ): string {
	$inst = _get_instance();
	$ps   = array();

	$vars += $inst->vars;
	foreach ( $inst->structures as $st ) {
		$v = empty( $vars[ $st['var'] ] ) ? $st['default_slug'] : $vars[ $st['var'] ];
		if ( ! $st['omittable'] || $v !== $st['default_slug'] ) {
			$ps[] = $v;
		}
	}
	return implode( '/', $ps );
}


// -----------------------------------------------------------------------------


/**
 * Replaces path.
 *
 * @access private
 *
 * @param string $url    Original URL.
 * @param string $before Searched value.
 * @param string $after  Replacement value.
 * @return string Modified URL.
 */
function _replace_path( string $url, string $before, string $after ): string {
	$home = wp_parse_url( \home_url(), PHP_URL_PATH );
	$home = is_string( $home ) ? trim( $home, '/' ) : '';
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

	$has_slash = substr( $path, -1 ) === '/';

	$path = _str_replace_one(
		trailingslashit( "$home$before" ),
		trailingslashit( "$home$after" ),
		trailingslashit( $path )
	);
	$path = ( $home === $path || $has_slash ) ? $path : untrailingslashit( $path );

	return "$scheme$user$pass$host$port$path$query$frag";
}

/**
 * Extracts variable slugs from URL.
 *
 * @access private
 *
 * @param string $url URL.
 * @return array{array<string, string>, string} An array of variable name to slug.
 */
function _extract_vars( string $url ): array {
	list( $path ) = explode( '?', $url );

	$ps   = explode( '/', trim( str_replace( \home_url(), '', $path ), '/' ) );
	$vars = array();
	$sps  = array();
	$p    = array_shift( $ps );

	foreach ( _get_instance()->structures as $st ) {
		$var = $st['var'];
		if ( in_array( $p, $st['slugs'], true ) ) {
			$vars[ $var ] = $p;
			$sps[]        = $p;

			$p = array_shift( $ps );
		} else {
			$vars[ $var ] = $st['omittable'] ? $st['default_slug'] : '';
		}
	}
	return array( $vars, implode( '/', $sps ) );
}

/**
 * Extracts query path from URL.
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
 * Callback function for 'after_setup_theme' action.
 *
 * @access private
 */
function _cb_after_setup_theme(): void {
	$inst = _get_instance();
	if ( empty( $inst->structures ) ) {
		return;
	}
	list( $req, $req_file )   = _parse_request();
	list( $inst->vars, $cur ) = _extract_vars( $req );  // @phpstan-ignore-line
	_register_globals();
	if ( empty( $req ) ) {
		return;
	}
	$full = build_full_path( $inst->vars );
	$norm = build_norm_path( $inst->vars );

	$erased = trim( empty( $cur ) ? "/$req/" : _str_replace_one( "/$cur/", '/', "/$req/" ), '/' );
	$added  = trim( empty( $cur ) ? "/$full/$req/" : _str_replace_one( "/$cur/", "/$full/", "/$req/" ), '/' );
	$ideal  = trim( empty( $cur ) ? "/$norm/$req/" : _str_replace_one( "/$cur/", "/$norm/", "/$req/" ), '/' );

	list( $is_page_req, ) = _is_page_request( $erased, $req_file );

	if ( $is_page_req ) {
		list( , $pn_added ) = _is_page_request( $added, $req_file );
		if ( ! empty( $pn_added ) && get_page_by_path( $pn_added ) ) {
			if ( $cur !== $norm ) {
				_redirect( $req, $ideal );
			}
			_replace_request( $req, $added );
		} else {
			$inst->is_page_not_found = true;  // @phpstan-ignore-line

			if ( is_user_logged_in() ) {
				list( , $pn_orig ) = _is_page_request( $req, $req_file );
				if ( $pn_orig ) {
					$post_orig = get_page_by_path( $pn_orig );
					if ( $post_orig ) {
						$inst->invalid_pagename = array( $pn_orig, $pn_added );  // @phpstan-ignore-line
					}
				}
			}
		}
	} elseif ( _has_feed_query() ) {
		if ( $cur !== $norm ) {
			_redirect( $req, $ideal );
		}
		_replace_request( $req, $added );
	} else {
		if ( $cur !== $norm ) {
			_redirect( $req, $ideal );
		}
		_replace_request( $req, $erased );
	}
}

/**
 * Parses request to find query.
 *
 * @access private
 * @see WP::parse_request()
 * @global \WP_Rewrite $wp_rewrite
 *
 * @return string[] Array of requested path and requested file.
 */
function _parse_request(): array {
	global $wp_rewrite;
	$rewrite = $wp_rewrite->wp_rewrite_rules();
	if ( empty( $rewrite ) ) {
		return array( '', '' );
	}
	$pathinfo         = $_SERVER['PATH_INFO'] ?? '';  // phpcs:ignore
	list( $pathinfo ) = explode( '?', $pathinfo );
	$pathinfo         = str_replace( '%', '%25', $pathinfo );

	$req_uri         = $_SERVER['REQUEST_URI'] ?? '';  // phpcs:ignore
	list( $req_uri ) = explode( '?', $req_uri );
	$home_path       = parse_url( \home_url(), PHP_URL_PATH );  // phpcs:ignore
	$home_path       = is_string( $home_path ) ? trim( $home_path, '/' ) : '';
	$home_path_regex = sprintf( '|^%s|i', preg_quote( $home_path, '|' ) );

	$req_uri  = str_replace( $pathinfo, '', $req_uri );
	$req_uri  = trim( $req_uri, '/' );
	$req_uri  = preg_replace( $home_path_regex, '', $req_uri );
	$req_uri  = trim( $req_uri ?? '', '/' );
	$pathinfo = trim( $pathinfo, '/' );
	$pathinfo = preg_replace( $home_path_regex, '', $pathinfo );
	$pathinfo = trim( $pathinfo ?? '', '/' );

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
 * Sets up the global variables.
 *
 * @access private
 */
function _register_globals(): void {
	$inst = _get_instance();
	foreach ( $inst->structures as $st ) {
		if ( $st['global'] ) {
			$GLOBALS[ $st['var'] ] = $inst->vars[ $st['var'] ];
		}
	}
}

/**
 * Replaces one occurrence of the search string with the replacement string.
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
	return preg_replace( "/$s/", $replace, $subject, 1 ) ?? $subject;
}

/**
 * Determines whether the request is for page.
 *
 * @access private
 * @global \WP_Rewrite $wp_rewrite
 *
 * @param string $req_path Requested path.
 * @param string $req_file Requested file.
 * @return array{bool, string|null} Array of boolean value and pagename.
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
	foreach ( $rewrite as $match => $query ) {
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
 * Determines whether the request has feed query.
 *
 * @access private
 *
 * @return bool True if the request has feed query.
 */
function _has_feed_query(): bool {
	$query = $_SERVER['QUERY_STRING'] ?? '';  // phpcs:ignore
	$parts = explode( '&', $query );
	foreach ( $parts as $part ) {
		if ( 'feed' === $part ) {
			return true;
		}
		$kv = explode( '=', $part );
		if ( ! empty( $kv[0] ) && 'feed' === $kv[0] ) {
			return true;
		}
	}
	return false;
}

/**
 * Redirects.
 *
 * @access private
 *
 * @param string $req_path Requested path.
 * @param string $after    Replacement value.
 */
function _redirect( string $req_path, string $after ): void {
	$url = _replace_path( $_SERVER['REQUEST_URI'] ?? '', $req_path, $after );  // phpcs:ignore
	wp_safe_redirect( $url, 301 );
	exit;
}

/**
 * Replaces request.
 *
 * @access private
 *
 * @param string $req_path Requested path.
 * @param string $after    Replacement value.
 */
function _replace_request( string $req_path, string $after ): void {
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
 * @param array<string, mixed> $query_vars The array of requested query variables.
 * @return array<string, mixed> The filtered array.
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
 * @return string Filtered URL.
 */
function _cb_redirect_canonical( string $redirect_url, string $requested_url ): string {
	$inst = _get_instance();
	if ( $inst->is_page_not_found ) {
		return '';
	}
	if ( isset( $_SERVER['REQUEST_URI_ORIG'] ) ) {
		$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';  // phpcs:ignore
		if ( empty( $host ) ) {
			return '';  // Failure.
		}
		$url = ( is_ssl() ? 'https://' : 'http://' ) . $host . $_SERVER['REQUEST_URI_ORIG'];  // phpcs:ignore
		if ( $url === $redirect_url ) {
			return '';  // For avoiding redirect loop.
		}
		// When a redirect just for add/remove trailing slash occurs.
		if ( untrailingslashit( $redirect_url ) === untrailingslashit( $requested_url ) ) {
			$ts = ( 0 === substr_compare( $redirect_url, '/', -1 ) );
			if ( $ts ) {
				$redirect_url = trailingslashit( $url );
			} else {
				$redirect_url = untrailingslashit( $url );
			}
		}
	}
	return $redirect_url;
}

/**
 * Callback function for 'url_to_postid' filter.
 *
 * @access private
 *
 * @param string $url The URL to derive the post ID from.
 * @return string Original URL.
 */
function _cb_url_to_postid( string $url ): string {
	list( $vars, $cur ) = _extract_vars( $url );

	$full = build_full_path( $vars );
	if ( $full !== $cur ) {
		$url = _replace_path( $url, $cur, $full );
	}
	return $url;
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
 * @param string        $link The permalink.
 * @param \WP_Post|null $post (When used as 'post_link' filter) The post in question.
 * @return string The filtered URL.
 */
function _cb_link( string $link, ?\WP_Post $post = null ): string {
	$cur  = _extract_query_path( $link );
	$inst = _get_instance();
	if ( $post ) {
		foreach ( $inst->post_link_filters as $f ) {
			$ret = call_user_func( $f, $inst->vars, $post );
			if ( $ret ) {
				$inst->vars = $ret;  // @phpstan-ignore-line
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
 * Gets instance.
 *
 * @access private
 *
 * @return object{
 *     structures       : array{ var: string, slugs: string[], default_slug: string, omittable: bool, global: bool }[],
 *     post_link_filters: callable[],
 *     vars             : string[],
 *     is_page_not_found: bool,
 *     invalid_pagename : array<string|null>|null,
 * } Instance.
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
		 * @var array{ var: string, slugs: string[], default_slug: string, omittable: bool, global: bool }[]
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
		 * @var array<string|null>|null
		 */
		public $invalid_pagename = null;
	};
	return $values;
}
