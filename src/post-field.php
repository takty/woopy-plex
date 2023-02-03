<?php
/**
 * Post Fields
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2023-02-03
 */

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

	$inst->post_types = array_merge( $inst->post_types, $pts );
}

/**
 * Adds an array of slug to label.
 *
 * @param array  $slug_to_label An array of slug to label.
 * @param string $format        A format to assign.
 */
function add_admin_labels( array $slug_to_label, ?string $format = null ): void {
	$inst = _get_instance();

	$inst->slug_to_label = array_merge( $inst->slug_to_label, $slug_to_label );
	if ( $format ) {
		$inst->label_format = $format;
	}
}

/**
 * Activates the post content.
 *
 * @param array $args {
 *     (Optional) Configuration arguments.
 *
 *     @type array  'vars'               Query variable names.
 *     @type string 'editor_type'        Editor type to be activated: 'block' or 'classic'. Default 'block'.
 *     @type string 'title_key_prefix'   Key prefix of post metadata for custom title. Default '_post_title_'.
 *     @type string 'content_key_prefix' Key prefix of post metadata for custom content. Default '_post_field_'.
 * }
 */
function activate( array $args = array() ): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'vars'               => array(),
		'editor_type'        => 'block',
		'title_key_prefix'   => '_post_title_',
		'content_key_prefix' => '_post_field_',
	);

	$inst->vars            = $args['vars'];
	$inst->editor_type     = $args['editor_type'];
	$inst->key_pre_title   = $args['title_key_prefix'];
	$inst->key_pre_content = $args['content_key_prefix'];

	if ( 'block' === $inst->editor_type ) {
		if ( did_action( 'widgets_init' ) ) {
			_cb_widgets_init();
		} else {
			add_action( 'widgets_init', '\wpinc\plex\post_field\_cb_widgets_init' );
		}
	}
	if ( is_admin() ) {
		if ( 'classic' === $inst->editor_type ) {
			add_action( 'admin_head', '\wpinc\plex\post_field\_cb_admin_head' );
			add_action( 'add_meta_boxes', '\wpinc\plex\post_field\_cb_add_meta_boxes' );
			foreach ( $inst->post_types as $pt ) {
				add_action( "save_post_$pt", '\wpinc\plex\post_field\_cb_save_post', 10, 2 );
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
	$inst = _get_instance();
	$p    = get_post( $post );
	if (
		$p instanceof \WP_Post &&
		in_array( $p->post_type, $inst->post_types, true )
	) {
		$t = _get_title( $p, $key );
		if ( null !== $t ) {
			return $t;
		}
	}
	return \get_the_title( $post );
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
		if ( null !== $c ) {
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
		if ( null !== $t ) {
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
 * @return string Title.
 */
function _get_title( \WP_Post $post, ?string $key = null ): ?string {
	$inst = _get_instance();
	if ( null === $key ) {
		$key = \wpinc\plex\get_query_key( $inst->vars );
	}
	if ( \wpinc\plex\get_default_key( $inst->vars ) === $key ) {
		return null;
	}
	$id = isset( $post->ID ) ? $post->ID : 0;
	$t  = get_post_meta( $id, $inst->key_pre_title . $key, true );
	if ( empty( $t ) ) {
		return null;
	}

	if ( ! is_admin() ) {
		if ( ! empty( $post->post_password ) ) {
			/* translators: %s: Protected post title. */
			$f = __( 'Protected: %s' );
			$f = apply_filters( 'protected_title_format', $f, $post );
			$t = sprintf( $f, $t );
		} elseif ( isset( $post->post_status ) && 'private' === $post->post_status ) {
			/* translators: %s: Private post title. */
			$f = __( 'Private: %s' );
			$f = apply_filters( 'private_title_format', $f, $post );
			$t = sprintf( $f, $t );
		}
	}
	remove_filter( 'the_title', '\wpinc\plex\post_field\_cb_the_title', 10, 2 );
	$t = apply_filters( 'the_title', $t, $id );
	add_filter( 'the_title', '\wpinc\plex\post_field\_cb_the_title', 10, 2 );
	return $t;
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
		if ( null !== $c ) {
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
 * @return string Content.
 */
function _get_content( \WP_Post $post, ?string $key = null ): ?string {
	$inst = _get_instance();
	if ( null === $key ) {
		$key = \wpinc\plex\get_query_key( $inst->vars );
	}
	if ( \wpinc\plex\get_default_key( $inst->vars ) === $key ) {
		return null;
	}
	$c = get_post_meta( $post->ID, $inst->key_pre_content . $key, true );
	if ( empty( $c ) ) {
		return null;
	}
	return $c;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'widgets_init' action.
 */
function _cb_widgets_init() {
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

			\wpinc\blok\input\add_block(
				array(
					'key'       => $inst->key_pre_title . $key,
					'label'     => _x( 'Title', 'post field', 'wpinc_plex' ) . " $lab_pf",
					'post_type' => $pt,
				)
			);
			\wpinc\blok\field\add_block(
				array(
					'key'       => $inst->key_pre_content . $key,
					'label'     => _x( 'Content', 'post field', 'wpinc_plex' ) . " $lab_pf",
					'post_type' => $pt,
				)
			);
		}
	}
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'save_post_{$post_type}' action.
 *
 * @access private
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post object.
 */
function _cb_save_post( int $post_id, \WP_Post $post ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$inst = _get_instance();
	$skc  = \wpinc\plex\get_slug_key_to_combination( $inst->vars, true );

	foreach ( $skc as $key => $slugs ) {
		if ( ! isset( $_POST[ "post_{$key}_nonce" ] ) ) {
			continue;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST[ "post_{$key}_nonce" ] ), "post_$key" ) ) {
			continue;
		}
		// phpcs:disable
		$title   = $_POST[ $inst->key_pre_title . $key ];
		$content = $_POST[ $inst->key_pre_content . $key ];
		// phpcs:enable
		$title   = apply_filters( 'title_save_pre', $title );
		$content = apply_filters( 'content_save_pre', $content );
		update_post_meta( $post_id, $inst->key_pre_title . $key, $title );
		update_post_meta( $post_id, $inst->key_pre_content . $key, $content );
	}
}

/**
 * Callback function for 'admin_head' action.
 *
 * @access private
 */
function _cb_admin_head(): void {
	?>
<style>
	.wpinc-plex-post-field-title input {
		margin          : 0 0 6px;
		padding         : 3px 8px;
		width           : 100%;
		height          : 1.7em;
		font-size       : 1.7em;
		line-height     : 100%;
		background-color: #fff;
		outline         : 0;
	}
</style>
	<?php
}

/**
 * Callback function for 'add_meta_boxes' action.
 *
 * @access private
 */
function _cb_add_meta_boxes(): void {
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
				'high'
			);
		}
	}
}

/**
 * Function that echos the field of title and content.
 *
 * @access private
 * @global $post
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
		 * The array of variable names.
		 *
		 * @var array
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
		 * @var array
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
