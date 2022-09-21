<?php
/**
 * Term Fields
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2022-09-21
 */

namespace wpinc\plex\term_field;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/slug-key.php';

/**
 * Adds taxonomy.
 *
 * @param string|string[] $taxonomy_s Taxonomy slugs.
 * @param array           $args {
 *     (Optional) Configuration arguments.
 *
 *     @type bool 'has_singular_name'         Whether the terms has singular names.
 *     @type bool 'has_default_singular_name' Whether the default name of the terms has singular form.
 *     @type bool 'has_description'           Whether the terms has custom descriptions.
 * }
 */
function add_taxonomy( $taxonomy_s, array $args = array() ): void {
	$inst = _get_instance();
	$txs  = is_array( $taxonomy_s ) ? $taxonomy_s : array( $taxonomy_s );

	$args += array(
		'has_singular_name'         => false,
		'has_default_singular_name' => false,
		'has_description'           => false,
	);

	$inst->txs = array_merge( $inst->txs, $txs );
	if ( $args['has_singular_name'] ) {
		$inst->txs_sg_name = array_merge( $inst->txs_sg_name, $txs );
	}
	if ( $args['has_default_singular_name'] ) {
		$inst->txs_default_sg_name = array_merge( $inst->txs_default_sg_name, $txs );
	}
	if ( $args['has_description'] ) {
		$inst->txs_description = array_merge( $inst->txs_description, $txs );
	}
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
 * Activates the term name.
 *
 * @global string $pagenow
 *
 * @param array $args {
 *     (Optional) Configuration arguments.
 *
 *     @type array  'vars'                      Query variable names.
 *     @type string 'name_key_prefix'           Key prefix of term metadata for custom names. Default '_name_'.
 *     @type string 'singular_name_key_prefix'  Key prefix of term metadata for custom singular names. Default '_singular_name_'.
 *     @type string 'description_key_prefix'    Key prefix of term metadata for custom descriptions. Default '_description_'.
 *     @type string 'default_singular_name_key' Key of term metadata for default singular names. Default '_singular_name'.
 * }
 */
function activate( array $args = array() ): void {
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'vars'                      => array(),
		'name_key_prefix'           => '_name_',
		'singular_name_key_prefix'  => '_singular_name_',
		'description_key_prefix'    => '_description_',
		'default_singular_name_key' => '_singular_name',
	);

	$inst->vars                = $args['vars'];
	$inst->key_pre_name        = $args['name_key_prefix'];
	$inst->key_pre_sg_name     = $args['singular_name_key_prefix'];
	$inst->key_pre_description = $args['description_key_prefix'];
	$inst->key_default_sg_name = $args['default_singular_name_key'];

	global $pagenow;
	if ( ! is_admin() || ( is_admin() && in_array( $pagenow, array( 'post-new.php', 'post.php', 'edit.php' ), true ) ) ) {
		add_filter( 'get_object_terms', '\wpinc\plex\term_field\_cb_get_terms', 10 );
		add_filter( 'get_terms', '\wpinc\plex\term_field\_cb_get_terms', 10 );
		foreach ( $inst->txs as $tx ) {
			add_filter( "get_{$tx}", '\wpinc\plex\term_field\_cb_get_taxonomy', 10 );
		}
	}
	if ( is_admin() ) {
		foreach ( $inst->txs as $tx ) {
			add_action( "{$tx}_edit_form_fields", '\wpinc\plex\term_field\_cb_taxonomy_edit_form_fields', 10, 2 );
			add_action( "edited_$tx", '\wpinc\plex\term_field\_cb_edited_taxonomy', 10 );
		}
	} else {
		foreach ( $inst->txs_description as $tx ) {
			add_filter( "{$tx}_description", '\wpinc\plex\term_field\_cb_taxonomy_description', 10, 3 );
		}
	}
}

/**
 * Retrieves term name.
 *
 * @param int   $term_id  (Optional) Term ID.
 * @param bool  $singular (Optional) Whether the name is singular.
 * @param mixed $args     (Optional) An array of variable name to slugs.
 * @return string Term name.
 */
function get_term_name( int $term_id = 0, bool $singular = false, $args = null ) {
	list( $term_id, $tx ) = _get_term_id_taxonomy( $term_id );

	$inst = _get_instance();
	$key  = \wpinc\plex\get_argument_key( $args, $inst->vars );
	$ret  = '';

	if ( $term_id && in_array( $tx, $inst->txs, true ) ) {
		if ( \wpinc\plex\get_default_key( $inst->vars ) === $key ) {
			if ( $singular && in_array( $tx, $inst->txs_default_sg_name, true ) ) {
				$sn = get_term_meta( $term_id, $inst->key_default_sg_name, true );
				if ( ! empty( $sn ) ) {
					$ret = $sn;
				}
			}
		} else {
			$name = get_term_meta( $term_id, $inst->key_pre_name . $key, true );
			$sn   = get_term_meta( $term_id, $inst->key_pre_sg_name . $key, true );

			if ( $singular && in_array( $tx, $inst->txs_sg_name, true ) ) {
				$ret = empty( $sn ) ? $name : $sn;
			} else {
				$ret = empty( $name ) ? $sn : $name;
			}
		}
	}
	return empty( $ret ) ? _get_term_field( 'name', $term_id ) : $ret;
}

/**
 * Retrieves term description.
 *
 * @param int   $term_id (Optional) Term ID. Defaults to the current term ID.
 * @param mixed $args    (Optional) An array of variable name to slugs.
 * @return string Term description, if available.
 */
function term_description( int $term_id = 0, $args = null ) {
	list( $term_id, $tx ) = _get_term_id_taxonomy( $term_id );

	$inst = _get_instance();
	$key  = \wpinc\plex\get_argument_key( $args, $inst->vars );
	$ret  = '';

	if ( $term_id && in_array( $tx, $inst->txs, true ) ) {
		if ( \wpinc\plex\get_default_key( $inst->vars ) !== $key ) {
			$ret = get_term_meta( $term_id, $inst->key_pre_description . $key, true );
		}
		if ( empty( $ret ) ) {
			$ret = _get_term_field( 'description', $term_id );
		}
	}
	return $ret;
}


// -----------------------------------------------------------------------------


/**
 * Retrieves term ID and taxonomy.
 *
 * @param int $term_id (Optional) Term ID. Defaults to the current term ID.
 * @return array An array of term ID and taxonomy.
 */
function _get_term_id_taxonomy( int $term_id = 0 ): array {
	if ( ! $term_id && ( is_tax() || is_tag() || is_category() ) ) {
		$t = get_queried_object();
	} else {
		$t = get_term( $term_id );
	}
	if ( ! ( $t instanceof \WP_Term ) ) {
		return array( 0, '' );
	}
	return array( $t->term_id, $t->taxonomy );
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'get_terms' filter.
 *
 * @access private
 *
 * @param \WP_Term[] $terms Array of found terms.
 * @return \WP_Term[] The filtered terms.
 */
function _cb_get_terms( array $terms ): array {
	$inst = _get_instance();
	$key  = \wpinc\plex\get_query_key( $inst->vars );

	$ts = array();
	foreach ( $terms as $t ) {
		$ts[] = ( $t instanceof \WP_Term ) ? $t : get_term( $t );
	}

	if ( \wpinc\plex\get_default_key( $inst->vars ) === $key ) {
		foreach ( $ts as $t ) {
			if (
				( $t instanceof \WP_Term ) &&
				in_array( $t->taxonomy, $inst->txs_default_sg_name, true )
			) {
				_add_singular_name( $t, $t->taxonomy, $inst );
			}
		}
	} else {
		foreach ( $ts as $t ) {
			if (
				( $t instanceof \WP_Term ) &&
				in_array( $t->taxonomy, $inst->txs, true )
			) {
				_replace_name( $t, $t->taxonomy, $inst, $key );
			}
		}
	}
	return $terms;
}

/**
 * Callback function for 'get_{$taxonomy}' filter.
 *
 * @access private
 *
 * @param \WP_Term $t Term object.
 * @return \WP_Term The filtered term.
 */
function _cb_get_taxonomy( \WP_Term $t ): \WP_Term {
	$inst = _get_instance();
	$key  = \wpinc\plex\get_query_key( $inst->vars );

	if ( \wpinc\plex\get_default_key( $inst->vars ) === $key ) {
		if ( in_array( $t->taxonomy, $inst->txs_default_sg_name, true ) ) {
			_add_singular_name( $t, $t->taxonomy, $inst );
		}
	} else {
		_replace_name( $t, $t->taxonomy, $inst, $key );
	}
	return $t;
}

/**
 * Replaces the name field of terms.
 *
 * @access private
 *
 * @param \WP_Term $t        Term object.
 * @param string   $taxonomy The taxonomy slug.
 * @param object   $inst     The instance of plex\term_field.
 * @param string   $key      The key of term metadata.
 */
function _replace_name( \WP_Term $t, string $taxonomy, object $inst, string $key ): void {
	if ( isset( $t->orig_name ) ) {
		return;
	}
	$name = get_term_meta( $t->term_id, $inst->key_pre_name . $key, true );
	$sn   = '';
	if ( in_array( $taxonomy, $inst->txs_sg_name, true ) ) {
		$sn = get_term_meta( $t->term_id, $inst->key_pre_sg_name . $key, true );

		$t->singular_name = empty( $sn ) ? $t->name : $sn;
	}
	$ret = empty( $name ) ? $sn : $name;
	if ( ! empty( $ret ) ) {
		$t->orig_name = $t->name;
		$t->name      = $ret;
	}
}

/**
 * Adds singular name of default key.
 *
 * @access private
 *
 * @param \WP_Term $t        Term object.
 * @param string   $taxonomy The taxonomy slug.
 * @param object   $inst     The instance of plex\term_field.
 */
function _add_singular_name( \WP_Term $t, string $taxonomy, object $inst ): void {
	if ( ! isset( $t->singular_name ) ) {
		$sn = get_term_meta( $t->term_id, $inst->key_default_sg_name, true );

		$t->singular_name = empty( $sn ) ? $t->name : $sn;
	}
}


// -----------------------------------------------------------------------------


/**
 * Callback function for '{$taxonomy}_{$field}' filter.
 *
 * @access private
 *
 * @param mixed  $value   Value of the term field.
 * @param int    $term_id Term ID.
 * @param string $context Context to retrieve the term field value.
 * @return mixed Filtered value.
 */
function _cb_taxonomy_description( $value, int $term_id, string $context ) {
	if ( 'display' !== $context ) {
		return $value;
	}
	$inst = _get_instance();
	$key  = \wpinc\plex\get_query_key( $inst->vars );
	$ret  = '';

	if ( \wpinc\plex\get_default_key( $inst->vars ) !== $key ) {
		$ret = get_term_meta( $term_id, $inst->key_pre_description . $key, true );
	}
	if ( empty( $ret ) ) {
		$ret = $value;
	}
	return $ret;
}

/**
 * Gets Term field.
 *
 * @access private
 *
 * @param string $field   Term field to fetch.
 * @param int    $term_id Term ID.
 */
function _get_term_field( string $field, int $term_id ) {
	$t = \WP_Term::get_instance( $term_id );
	if ( is_wp_error( $t ) || ! is_object( $t ) || ! isset( $t->$field ) ) {
		return '';
	}
	return $t->$field;
}


// -----------------------------------------------------------------------------


/**
 * Callback function for '{$taxonomy}_edit_form_fields' action.
 *
 * @access private
 *
 * @param \WP_Term $t        Current taxonomy term object.
 * @param string   $taxonomy Current taxonomy slug.
 */
function _cb_taxonomy_edit_form_fields( \WP_Term $t, string $taxonomy ): void {
	$inst   = _get_instance();
	$t_meta = get_term_meta( $t->term_id );

	$def_slugs  = \wpinc\plex\custom_rewrite\get_structures( 'default_slug', $inst->vars );
	$lab_base_n = esc_html_x( 'Name', 'term name', 'default' );

	$has_sn     = in_array( $taxonomy, $inst->txs_sg_name, true );
	$has_def_sn = in_array( $taxonomy, $inst->txs_default_sg_name, true );
	$has_desc   = in_array( $taxonomy, $inst->txs_description, true );

	if ( $has_def_sn ) {
		$lab_pf = \wpinc\plex\get_admin_label( $def_slugs, $inst->slug_to_label, $inst->label_format );
		$lab_n  = "$lab_base_n $lab_pf";

		$id_name_sn = $inst->key_default_sg_name;
		$val_sn     = isset( $t_meta[ $id_sn ] ) ? $t_meta[ $id_sn ][0] : '';
		_echo_name_field( $lab_n . _x( ' (Singular Form)', 'term field', 'wpinc_plex' ), $id_name_sn, $id_name_sn, $val_sn );
	}
	$skc = \wpinc\plex\get_slug_key_to_combination( $inst->vars, true );
	foreach ( $skc as $key => $slugs ) {
		$lab_pf = \wpinc\plex\get_admin_label( $slugs, $inst->slug_to_label, $inst->label_format );
		$lab_n  = "$lab_base_n $lab_pf";

		$id_n   = $inst->key_pre_name . $key;
		$name_n = $inst->key_pre_name . "array[$key]";
		$val_n  = isset( $t_meta[ $id_n ] ) ? $t_meta[ $id_n ][0] : '';
		_echo_name_field( $lab_n, $id_n, $name_n, $val_n, 'padding-bottom: 6px;' );

		if ( $has_sn ) {
			$id_sn   = $inst->key_pre_sg_name . $key;
			$name_sn = $inst->key_pre_sg_name . "array[$key]";
			$val_sn  = isset( $t_meta[ $id_sn ] ) ? $t_meta[ $id_sn ][0] : '';
			_echo_name_field( $lab_n . _x( ' (Singular Form)', 'term field', 'wpinc_plex' ), $id_sn, $name_sn, $val_sn, 'padding-top: 6px;' );
		}
		if ( $has_desc ) {
			$lab_d  = __( 'Description' ) . " $lab_pf";
			$id_d   = $inst->key_pre_description . $key;
			$name_d = $inst->key_pre_description . "array[$key]";
			$val_d  = isset( $t_meta[ $id_d ] ) ? $t_meta[ $id_d ][0] : '';
			_echo_description_field( $lab_d, $id_d, $name_d, $val_d );
		}
	}
}

/**
 * Function that echos the field of name.
 *
 * @access private
 *
 * @param string $label The label of the field.
 * @param string $id    The id of the field.
 * @param string $name  The name of the field.
 * @param string $val   The value of the field.
 * @param string $style The style of the field.
 */
function _echo_name_field( string $label, string $id, string $name, string $val, string $style = '' ): void {
	?>
<tr class="form-field">
	<th style="<?php echo esc_attr( $style ); ?>">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
	</th>
	<td style="<?php echo esc_attr( $style ); ?>">
		<input type="text" size="40" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $val ); ?>" />
	</td>
</tr>
	<?php
}

/**
 * Function that echos the field of description.
 *
 * @access private
 *
 * @param string $label The label of the field.
 * @param string $id    The id of the field.
 * @param string $name  The name of the field.
 * @param string $val   The value of the field.
 */
function _echo_description_field( string $label, string $id, string $name, string $val ): void {
	?>
<tr class="form-field term-description-wrap">
	<th scope="row">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
	</th>
	<td>
		<textarea class="large-text" rows="5" cols="50" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $val ); ?></textarea>
	</td>
</tr>
	<?php
}

/**
 * Callback function for 'edited_{$taxonomy}' action.
 *
 * @access private
 *
 * @param int $term_id Term ID.
 */
function _cb_edited_taxonomy( int $term_id ): void {
	$inst     = _get_instance();
	$key_name = $inst->key_pre_name . 'array';
	$key_sn   = $inst->key_pre_sg_name . 'array';
	$key_desc = $inst->key_pre_description . 'array';

	// phpcs:disable
	if ( isset( $_POST[ $key_name ] ) ) {
		foreach ( $_POST[ $key_name ] as $key => $val ) {
			_modify_term_meta( $term_id, $inst->key_pre_name . $key, $val );
		}
	}
	if ( isset( $_POST[ $key_sn ] ) ) {
		foreach ( $_POST[ $key_sn ] as $key => $val ) {
			_modify_term_meta( $term_id, $inst->key_pre_sg_name . $key, $val );
		}
	}
	if ( isset( $_POST[ $key_desc ] ) ) {
		foreach ( $_POST[ $key_desc ] as $key => $val ) {
			_modify_term_meta( $term_id, $inst->key_pre_description . $key, $val );
		}
	}
	if ( isset( $_POST[ $inst->key_default_sg_name ] ) ) {
		_modify_term_meta( $term_id, $inst->key_default_sg_name, $_POST[ $inst->key_default_sg_name ] );
	}
	// phpcs:enable
}

/**
 * Updates or removes term metadata.
 *
 * @access private
 *
 * @param int    $term_id Term ID.
 * @param string $key     Metadata name.
 * @param mixed  $val     Metadata value. Must be serializable if non-scalar.
 */
function _modify_term_meta( int $term_id, string $key, $val ): void {
	if ( empty( $val ) ) {
		delete_term_meta( $term_id, $key );
	} else {
		update_term_meta( $term_id, $key, $val );
	}
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
		 * The key prefix of term metadata of a custom name.
		 *
		 * @var string
		 */
		public $key_pre_name = '';

		/**
		 * The key prefix of term metadata of a custom singular name.
		 *
		 * @var string
		 */
		public $key_pre_sg_name = '';

		/**
		 * The key prefix of term metadata of a custom description.
		 *
		 * @var string
		 */
		public $key_pre_description = '';

		/**
		 * The key of term metadata of a singular name.
		 *
		 * @var string
		 */
		public $key_default_sg_name = '';

		/**
		 * The taxonomies with custom names.
		 *
		 * @var array
		 */
		public $txs = array();

		/**
		 * The taxonomies with custom singular names.
		 *
		 * @var array
		 */
		public $txs_sg_name = array();

		/**
		 * The taxonomies with a custom singular name for default name.
		 *
		 * @var array
		 */
		public $txs_default_sg_name = array();

		/**
		 * The taxonomies with custom descriptions.
		 *
		 * @var array
		 */
		public $txs_description = array();
	};
	return $values;
}
