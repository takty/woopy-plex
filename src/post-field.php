<?php
/**
 * Post Fields
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2024-03-14
 */

declare(strict_types=1);

namespace wpinc\plex\post_field;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/slug-key.php';

/**
 * Adds post type
 *
 * @param string|string[] $post_type_s Post types.
 */
function add_post_type( $post_type_s ): void {
	$pts  = is_array( $post_type_s ) ? $post_type_s : array( $post_type_s );
	$inst = _get_instance();

	$inst->post_types = array_merge( $inst->post_types, $pts );  // @phpstan-ignore-line
}

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

/** phpcs:ignore
 * Activates the post content.
 *
 * phpcs:ignore
 * @param array{
 *     vars?              : string[]|null,
 *     editor_type?       : string,
 *     title_key_prefix?  : string,
 *     content_key_prefix?: string,
 * } $args (Optional) Configuration arguments.
 *
 * $args {
 *     (Optional) Configuration arguments.
 *
 *     @type string[]|null 'vars'               Query variable names. Default null.
 *     @type string        'editor_type'        Editor type to be activated: 'block', 'classic', or 'both'. Default 'block'.
 *     @type string        'title_key_prefix'   Key prefix of post metadata for custom title. Default '_post_title_'.
 *     @type string        'content_key_prefix' Key prefix of post metadata for custom content. Default '_post_content_'.
 * }
 */
function activate( array $args = array() ): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'vars'               => null,
		'editor_type'        => 'block',
		'title_key_prefix'   => '_post_title_',
		'content_key_prefix' => '_post_content_',
	);

	$inst->vars            = $args['vars'];  // @phpstan-ignore-line
	$inst->editor_type     = $args['editor_type'];  // @phpstan-ignore-line
	$inst->key_pre_title   = $args['title_key_prefix'];  // @phpstan-ignore-line
	$inst->key_pre_content = $args['content_key_prefix'];  // @phpstan-ignore-line

	if ( 'block' === $inst->editor_type || 'both' === $inst->editor_type ) {
		// The following hook need to be set regardless is_admin() is false.
		if ( did_action( 'widgets_init' ) ) {
			_cb_widgets_init();
		} else {
			add_action( 'widgets_init', '\wpinc\plex\post_field\_cb_widgets_init', 10, 0 );
		}
	}
	if ( is_admin() || wp_doing_ajax() ) {
		if ( 'classic' === $inst->editor_type || 'both' === $inst->editor_type ) {
			add_action( 'add_meta_boxes', '\wpinc\plex\post_field\_cb_add_meta_boxes', 10, 0 );
			foreach ( $inst->post_types as $pt ) {
				add_action( "save_post_$pt", '\wpinc\plex\post_field\_cb_save_post', 10 );
			}
		}
	} else {
		add_filter( 'single_post_title', '\wpinc\plex\post_field\_cb_single_post_title', 10, 2 );
		add_filter( 'the_title', '\wpinc\plex\post_field\_cb_the_title', 10, 2 );
		add_filter( 'the_content', '\wpinc\plex\post_field\_cb_the_content' );
	}
}


// -----------------------------------------------------------------------------


/**
 * Retrieves the post title.
 *
 * @param \WP_Post|null $post The post.
 * @param string|null   $key  Query key.
 * @return string Filtered title.
 */
function get_the_title( ?\WP_Post $post, ?string $key = null ): string {
	$p = get_post( $post );
	if ( $p instanceof \WP_Post ) {
		$inst = _get_instance();
		if ( in_array( $p->post_type, $inst->post_types, true ) ) {
			$t = _get_title( $p, $key );
			if ( is_string( $t ) ) {
				return $t;
			}
		}
		return \get_the_title( $p );
	}
	return '';
}

/**
 * Retrieves the post content.
 *
 * @param \WP_Post|null $post The post.
 * @param string|null   $key  Query key.
 * @return string Filtered content.
 */
function get_the_content( ?\WP_Post $post, ?string $key = null ): string {
	$inst = _get_instance();
	$p    = get_post( $post );
	if (
		$p instanceof \WP_Post &&
		! post_password_required( $p ) &&
		in_array( $p->post_type, $inst->post_types, true )
	) {
		$c = _get_content( $p, $key );
		if ( is_string( $c ) ) {
			return $c;
		}
	}
	return \get_the_content( null, false, $post );
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'single_post_title' filter.
 *
 * @access private
 *
 * @param string   $title The single post page title.
 * @param \WP_Post $post  The current post.
 * @return string Filtered title.
 */
function _cb_single_post_title( string $title, \WP_Post $post ): string {
	return _cb_the_title( $title, $post->ID );
}

/**
 * Callback function for 'the_title' filter.
 *
 * @access private
 *
 * @param string $title The post title.
 * @param int    $id    The post ID.
 * @return string Filtered title.
 */
function _cb_the_title( string $title, int $id ): string {
	$inst = _get_instance();
	$p    = get_post( $id );
	if (
		$p instanceof \WP_Post &&
		in_array( $p->post_type, $inst->post_types, true )
	) {
		$t = _get_title( $p );
		if ( is_string( $t ) ) {
			return $t;
		}
	}
	return $title;
}

/**
 * Gets post title from post meta.
 *
 * @access private
 *
 * @param \WP_Post    $post The post.
 * @param string|null $key  Query key.
 * @return string|null Title.
 */
function _get_title( \WP_Post $post, ?string $key = null ): ?string {
	$inst = _get_instance();
	if ( null === $key ) {
		$key = \wpinc\plex\get_query_key( $inst->vars );
	}
	if ( \wpinc\plex\get_default_key( $inst->vars ) === $key ) {
		return null;
	}
	$id  = $post->ID;
	$ret = get_post_meta( $id, $inst->key_pre_title . $key, true );
	if ( ! is_string( $ret ) || '' === $ret ) {  // Check for non-empty-string.
		return null;
	}

	if ( ! is_admin() ) {
		if ( ! empty( $post->post_password ) ) {  // Same as post-template.php of WordPress core.
			/* translators: %s: Protected post title. */
			$f   = __( 'Protected: %s' );
			$f   = apply_filters( 'protected_title_format', $f, $post );
			$ret = sprintf( $f, $ret );
		} elseif ( 'private' === $post->post_status ) {
			/* translators: %s: Private post title. */
			$f   = __( 'Private: %s' );
			$f   = apply_filters( 'private_title_format', $f, $post );
			$ret = sprintf( $f, $ret );
		}
	}
	remove_filter( 'the_title', '\wpinc\plex\post_field\_cb_the_title', 10 );
	$ret = apply_filters( 'the_title', $ret, $id );
	add_filter( 'the_title', '\wpinc\plex\post_field\_cb_the_title', 10, 2 );
	return $ret;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'the_content' filter.
 *
 * @access private
 *
 * @param string $content Content of the current post.
 * @return string Filtered content.
 */
function _cb_the_content( string $content ): string {
	$inst = _get_instance();
	$p    = get_post();
	if (
		$p instanceof \WP_Post &&
		! post_password_required( $p ) &&
		in_array( $p->post_type, $inst->post_types, true )
	) {
		$c = _get_content( $p );
		if ( is_string( $c ) ) {
			remove_filter( 'the_content', '\wpinc\plex\post_field\_cb_the_content' );
			$c = apply_filters( 'the_content', $c );
			add_filter( 'the_content', '\wpinc\plex\post_field\_cb_the_content' );
			return $c;
		}
	}
	return $content;
}

/**
 * Gets post content from post meta.
 *
 * @access private
 *
 * @param \WP_Post    $post The post.
 * @param string|null $key  Query key.
 * @return string|null Content.
 */
function _get_content( \WP_Post $post, ?string $key = null ): ?string {
	$inst = _get_instance();
	if ( null === $key ) {
		$key = \wpinc\plex\get_query_key( $inst->vars );
	}
	if ( \wpinc\plex\get_default_key( $inst->vars ) === $key ) {
		return null;
	}
	$ret = get_post_meta( $post->ID, $inst->key_pre_content . $key, true );
	if ( ! is_string( $ret ) || '' === $ret ) {  // Check for non-empty-string.
		return null;
	}
	return $ret;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'widgets_init' action.
 *
 * @access private
 * @psalm-suppress UnusedForeachValue, UnusedVariable
 */
function _cb_widgets_init(): void {
	if (
		! function_exists( '\wpinc\blok\field\add_block' ) ||
		! function_exists( '\wpinc\blok\input\add_block' )
	) {
		trigger_error( 'Function \wpinc\blok\field\add_block and \wpinc\blok\input\add_block is required!', E_USER_DEPRECATED );  // phpcs:ignore
	}
	$inst = _get_instance();
	$skc  = \wpinc\plex\get_slug_key_to_combination( $inst->vars, true );

	foreach ( $inst->post_types as $pt ) {
		foreach ( $skc as $key => $slugs ) {
			$lab_pf = \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format );
			$as_t   = array(
				'key'                => $inst->key_pre_title . $key,
				'label'              => _x( 'Title', 'post field', 'wpinc_plex' ) . " $lab_pf",
				'post_type'          => $pt,
				'do_support_classic' => 'both' === $inst->editor_type,
			);
			$as_c   = array(
				'key'                => $inst->key_pre_content . $key,
				'label'              => _x( 'Content', 'post field', 'wpinc_plex' ) . " $lab_pf",
				'post_type'          => $pt,
				'do_render'          => true,
				'do_support_classic' => 'both' === $inst->editor_type,
			);
			\wpinc\blok\input\add_block( $as_t );
			\wpinc\blok\field\add_block( $as_c );
		}
	}
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'save_post_{$post_type}' action.
 *
 * @access private
 *
 * @param int $post_id Post ID.
 */
function _cb_save_post( int $post_id ): void {
	/** @psalm-suppress RedundantCondition */  // phpcs:ignore
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$inst = _get_instance();
	$skc  = \wpinc\plex\get_slug_key_to_combination( $inst->vars, true );

	foreach ( $skc as $key => $_slugs ) {
		$nonce = $_POST[ "post_{$key}_nonce" ] ?? null;  // phpcs:ignore
		if ( ! is_string( $nonce ) ) {
			continue;
		}
		if ( false === wp_verify_nonce( sanitize_key( $nonce ), "post_$key" ) ) {
			continue;
		}
		$key_t = $inst->key_pre_title . $key;
		$key_c = $inst->key_pre_content . $key;
		// phpcs:disable
		$title   = $_POST[ $key_t ] ?? '';
		$content = $_POST[ $key_c ] ?? '';
		// phpcs:enable
		$title   = apply_filters( 'title_save_pre', $title );
		$content = apply_filters( 'content_save_pre', $content );
		update_post_meta( $post_id, $key_t, $title );
		update_post_meta( $post_id, $key_c, $content );
	}
}

/**
 * Callback function for 'add_meta_boxes' action.
 *
 * @access private
 */
function _cb_add_meta_boxes(): void {
	$data = '.wpinc-plex-post-field-title input{width:100%;height:1.7em;margin:0 0 6px;padding:3px 8px;font-size:1.7em;line-height:100%;background-color:#fff;outline:none}';
	wp_add_inline_style( 'wp-admin', $data );

	$inst = _get_instance();
	$skc  = \wpinc\plex\get_slug_key_to_combination( $inst->vars, true );

	foreach ( $inst->post_types as $pt ) {
		$pto = get_post_type_object( $pt );
		if ( null === $pto ) {
			continue;
		}
		$post_type_name = $pto->labels->name;
		foreach ( $skc as $key => $slugs ) {
			$lab_pf = \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format );
			add_meta_box(
				"post_$key",
				"$post_type_name $lab_pf",
				function () use ( $key ) {
					\wpinc\plex\post_field\_echo_title_content_field( $key );
				},
				$pt,
				'advanced',
				'high',
				array( '__back_compat_meta_box' => true )
			);
		}
	}
}

/**
 * Function that echos the field of title and content.
 *
 * @access private
 * @global \WP_Post $post
 *
 * @param string $key The key of the fields.
 */
function _echo_title_content_field( string $key ): void {
	global $post;
	$inst   = _get_instance();
	$name_t = $inst->key_pre_title . $key;
	$name_c = $inst->key_pre_content . $key;

	$title   = get_post_meta( $post->ID, $name_t, true );
	$content = get_post_meta( $post->ID, $name_c, true );
	if ( ! is_string( $title ) ) {
		$title = '';
	}
	if ( ! is_string( $content ) ) {
		$content = '';
	}

	$placeholder = apply_filters( 'enter_title_here', __( 'Add title' ), $post );
	wp_nonce_field( "post_$key", "post_{$key}_nonce" );
	?>
	<div class="wpinc-plex-post-field-title">
		<input
			id="<?php echo esc_attr( $name_t ); ?>"
			name="<?php echo esc_attr( $name_t ); ?>"
			value="<?php echo esc_attr( $title ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			size="30"
			type="text"
			spellcheck="true"
			autocomplete="off"
		>
	</div>
	<div class="wpinc-plex-post-field-content">
		<?php wp_editor( $content, $name_c ); ?>
	</div>
	<?php
}


// -----------------------------------------------------------------------------


/**
 * Gets instance.
 *
 * @access private
 *
 * @return object{
 *     slug_to_label  : array<string, string>,
 *     label_format   : string,
 *     vars           : string[],
 *     editor_type    : string,
 *     post_types     : string[],
 *     key_pre_title  : string,
 *     key_pre_content: string,
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
		 * The array of variable names.
		 *
		 * @var string[]
		 */
		public $vars = array();

		/**
		 * Editor type to be activated: 'block' or 'classic'.
		 *
		 * @var string
		 */
		public $editor_type = '';

		/**
		 * The post types.
		 *
		 * @var string[]
		 */
		public $post_types = array();

		/**
		 * The key prefix of term metadata of a custom name.
		 *
		 * @var string
		 */
		public $key_pre_title = '';

		/**
		 * The key prefix of term metadata of a custom singular name.
		 *
		 * @var string
		 */
		public $key_pre_content = '';
	};
	return $values;
}
