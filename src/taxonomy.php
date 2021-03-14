<?php
/**
 * Taxonomy
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-14
 */

namespace wpinc\plex\taxonomy;

require_once __DIR__ . '/custom-rewrite.php';
require_once __DIR__ . '/util.php';

/**
 * The.
 *
 * @param array $args {
 *     The.
 *
 *     @type array  $query_vars               The.
 *     @type string $name_key_prefix          The.
 *     @type string $singular_name_key_prefix The.
 *     @type string $description_key_prefix   The.
 * }
 */
function initialize( $args = array() ) {
	$inst = _get_instance();

	$args += array(
		'vars'                     => array(),
		'name_key_prefix'          => '_name_',
		'singular_name_key_prefix' => '_singular_name_',
		'description_key_prefix'   => '_description_',
	);

	$inst->vars                  = $args['vars'];
	$inst->key_pre_name          = $args['name_key_prefix'];
	$inst->key_pre_singular_name = $args['singular_name_key_prefix'];
	$inst->key_pre_description   = $args['description_key_prefix'];

	add_filter( 'get_object_terms', '\wpinc\plex\taxonomy\_cb_get_terms', 10 );
	add_filter( 'get_terms', '\wpinc\plex\taxonomy\_cb_get_terms', 10 );
	add_filter( 'get_term', '\wpinc\plex\taxonomy\_cb_get_term', 10 );
	add_filter( 'term_description', '\wpinc\plex\taxonomy\_cb_term_description', 10, 4 );
}

/**
 * Register an array of slug to label.
 *
 * @param array $slug_to_label An array of slug to label.
 */
function register_admin_labels( array $slug_to_label ) {
	$inst = _get_instance();

	$inst->slug_to_label = array_merge( $inst->slug_to_label, $slug_to_label );
}

/**
 * Assign a format for displaying admin labels.
 *
 * @param string $format A format to assign.
 */
function set_admin_label_format( string $format ) {
	_get_instance()->label_format = $format;
}


// -----------------------------------------------------------------------------


/**
 * Generate slug combinations.
 *
 * @return array The array of slug combinations.
 */
function get_slug_combination(): array {
	static $ret = null;
	if ( null === $ret ) {
		$slugs_a = \wpinc\plex\custom_rewrite\get_structures( 'slugs', _get_instance()->vars );
		$ret     = \wpinc\plex\generate_combination( $slugs_a );
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
	$slugs = array_map(
		function ( $v ) {
			return get_query_var( $v );
		},
		_get_instance()->vars
	);
	return implode( '_', $slugs );
}

/**
 * Retrieve the key of default query variables.
 *
 * @access private
 *
 * @return string The key string.
 */
function _get_default_key(): string {
	$slugs = \wpinc\plex\custom_rewrite\get_structures( 'default_slug', _get_instance()->vars );
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
function _get_admin_label( array $slugs ): string {
	$inst = _get_instance();
	$ls   = array_map(
		function ( $s ) use ( $inst ) {
			return $inst->slug_to_label[ $s ] ?? $s;
		},
		$slugs
	);
	if ( $inst->label_format ) {
		return sprintf( $inst->label_format, ...$ls );
	}
	return implode( ' ', $ls );
}


// -----------------------------------------------------------------------------


/**
 * Retrieve.
 *
 * @access private
 *
 * @param array $args The.
 * @return string The key string.
 */
function _get_argument_key( $args ): string {
	if ( is_array( $args ) && ! empty( $args ) ) {
		$key = _make_key_from_argument( $args );
	} elseif ( is_string( $args ) && ! empty( $args ) ) {
		$key = $args;
	} else {
		$key = _get_key();
	}
	return $key;
}

/**
 * Retrieve.
 *
 * @access private
 *
 * @param array $args The.
 * @return string The key string.
 */
function _make_key_from_argument( array $args ): string {
	$slugs = array_map(
		function ( $st ) use ( $args ) {
			return isset( $args[ $st['var'] ] ) ? $args[ $st['var'] ] : $st['default_slug'];
		},
		\wpinc\plex\custom_rewrite\get_structures( null, _get_instance()->vars )
	);
	return implode( '_', $slugs );
}


// -----------------------------------------------------------------------------


/**
 * The.
 *
 * @param string|string[] $taxonomy_s The.
 * @param array           $args {
 *     The.
 *
 *     @type bool $has_singular_name         The.
 *     @type bool $has_default_singular_name The.
 *     @type bool $has_description           The.
 * }
 */
function add_taxonomy( $taxonomy_s, array $args = array() ) {
	$taxonomies = is_array( $taxonomy_s ) ? $taxonomy_s : array( $taxonomy_s );

	$args += array(
		'has_singular_name'         => false,
		'has_default_singular_name' => false,
		'has_description'           => false,
	);
	foreach ( $taxonomies as $t ) {
		add_action( "{$t}_edit_form_fields", '\wpinc\plex\taxonomy\_cb_taxonomy_edit_form_fields', 10, 2 );
		add_action( "edited_$t", '\wpinc\plex\taxonomy\_cb_edited_taxonomy', 10, 2 );
	}
	$inst = _get_instance();

	$inst->taxonomies = array_merge( $inst->taxonomies, $taxonomies );
	if ( $args['has_singular_name'] ) {
		$inst->taxonomies_singular_name = array_merge( $inst->taxonomies_singular_name, $taxonomies );
	}
	if ( $args['has_default_singular_name'] ) {
		$inst->taxonomies_default_singular_name = array_merge( $inst->taxonomies_default_singular_name, $taxonomies );
	}
	if ( $args['has_description'] ) {
		$inst->taxonomies_description = array_merge( $inst->taxonomies_description, $taxonomies );
	}
}


// -----------------------------------------------------------------------------


/**
 * The.
 *
 * @access private
 *
 * @param WP_Term[] $terms The.
 * @return WP_Term[] The.
 */
function _cb_get_terms( array $terms ) {
	$inst = _get_instance();
	$key  = _get_key();

	if ( _get_default_key() !== $key ) {
		foreach ( $terms as $t ) {
			if ( in_array( $t->taxonomy, $inst->taxonomies, true ) ) {
				_replace_term_name( $t, $t->taxonomy, $inst, $key );
			}
		}
	}
	return $terms;
}

/**
 * The.
 *
 * @access private
 *
 * @param WP_Term $term The.
 * @return WP_Term The.
 */
function _cb_get_term( WP_Term $term ) {
	$inst = _get_instance();
	$key  = _get_key();

	if ( _get_default_key() !== $key ) {
		if ( in_array( $term->taxonomy, $inst->taxonomies, true ) ) {
			_replace_term_name( $term, $term->taxonomy, $inst, $key );
		}
	}
	return $term;
}

/**
 * The.
 *
 * @access private
 *
 * @param WP_Term $term     The.
 * @param string  $taxonomy The.
 * @param object  $inst     The.
 * @param string  $key      The.
 */
function _replace_term_name( WP_Term $term, string $taxonomy, object $inst, string $key ) {
	if ( isset( $term->orig_name ) ) {
		return;
	}
	$name = get_term_meta( $term_id, $inst->key_pre_name . $key, true );
	if ( in_array( $taxonomy, $inst->taxonomies_singular_name, true ) ) {
		$sn = get_term_meta( $term_id, $inst->key_pre_singular_name . $key, true );
	}
	$ret = empty( $name ) ? $sn : $name;
	if ( ! empty( $ret ) ) {
		$term->orig_name = $term->name;
		$term->name      = $ret;
	}
}

/**
 * The.
 *
 * @param int   $term_id  The.
 * @param bool  $singular The.
 * @param array $args     The.
 * @return string The.
 */
function get_term_name( int $term_id, bool $singular = false, $args = null ) {
	$inst = _get_instance();
	$key  = _get_argument_key();
	$ret  = '';

	if ( _get_default_key() === $key ) {
		if ( $singular ) {
			$sn = get_term_meta( $term_id, $inst->key_pre_singular_name . $key, true );
			if ( ! empty( $sn ) ) {
				$ret = $sn;
			}
		}
	} else {
		$name = get_term_meta( $term_id, $inst->key_pre_name . $key, true );
		$sn   = get_term_meta( $term_id, $inst->key_pre_singular_name . $key, true );

		if ( $singular ) {
			$ret = empty( $sn ) ? $name : $sn;
		} else {
			$ret = empty( $name ) ? $sn : $name;
		}
	}
	return empty( $ret ) ? _get_term_field( $term_id, 'name' ) : $ret;
}


// -----------------------------------------------------------------------------


/**
 * The.
 *
 * @access private
 *
 * @param mixed  $value    The.
 * @param int    $term_id  The.
 * @param string $taxonomy The.
 * @param string $context  The.
 * @return mixed The.
 */
function _cb_term_description( $value, int $term_id, string $taxonomy, string $context ) {
	if ( 'display' !== $context ) {
		return $value;
	}
	$inst = _get_instance();
	if ( ! in_array( $taxonomy, $inst->taxonomies_description, true ) ) {
		return $value;
	}
	$key = _get_key();
	$ret = '';

	if ( _get_default_key() !== $key ) {
		$ret = get_term_meta( $term_id, $inst->_key_pre_description . $key, true );
	}
	if ( empty( $ret ) ) {
		$ret = $value;
	}
	return $ret;
}

/**
 * The.
 *
 * @param int   $term_id The.
 * @param array $args    The.
 * @return string The.
 */
function term_description( int $term_id = 0, $args = null ) {
	if ( ! $term_id && ( is_tax() || is_tag() || is_category() ) ) {
		$t = get_queried_object();
		if ( $t ) {
			$term_id = $t->term_id;
		}
	}
	$inst = _get_instance();
	$key  = _get_argument_key();
	$ret  = '';

	if ( _get_default_key() !== $key ) {
		$ret = get_term_meta( $term_id, $inst->_key_pre_description . $key, true );
	}
	if ( empty( $ret ) ) {
		$ret = _get_term_field( $term_id, 'description' );
	}
	return $ret;
}

/**
 * The.
 *
 * @access private
 *
 * @param int    $term_id The.
 * @param string $field   The.
 */
function _get_term_field( int $term_id, string $field ) {
	$term = WP_Term::get_instance( $term_id );
	if ( is_wp_error( $term ) || ! is_object( $term ) || ! isset( $term->$field ) ) {
		return '';
	}
	return $term->$field;
}


// -----------------------------------------------------------------------------


/**
 * The.
 *
 * @access private
 *
 * @param \WP_Term $term     The.
 * @param string   $taxonomy The.
 */
function _cb_taxonomy_edit_form_fields( $term, $taxonomy ) {
	$inst    = _get_instance();
	$def_key = _get_default_key();
	$t_meta  = get_term_meta( $term->term_id );

	$def_slugs = \wpinc\plex\custom_rewrite\get_structures( 'default_slug', $inst->vars );

	$lab_base_n = esc_html_x( 'Name', 'term name', 'default' );

	$has_desc = in_array( $taxonomy, $inst->taxonomies_description, true );
	if ( $has_desc ) {
		$lab_base_d = esc_html__( 'Description' );
	}
	$has_def_sn = in_array( $taxonomy, $inst->taxonomies_default_singular_name, true );

	if ( $has_def_sn ) {
		$lab_pf = _get_admin_label( $def_slugs );
		$lab_n  = "$lab_base_n $lab_pf";

		$id_sn   = $inst->key_pre_singular_name . $def_key;
		$name_sn = $inst->key_pre_singular_name . "array[$def_key]";
		$val_sn  = isset( $t_meta[ $id_sn ] ) ? $t_meta[ $id_sn ][0] : '';
		_echo_name_field( $lab_n . __( ' (Singular Form)' ), $id_sn, $name_sn, $val_sn );
	}
	foreach ( get_slug_combination() as $slugs ) {
		$key = implode( '_', $slugs );
		if ( $key === $def_key ) {
			continue;
		}
		$lab_pf = _get_admin_label( $slugs );
		$lab_n  = "$lab_base_n $lab_pf";

		$id_n   = $inst->key_pre_name . $key;
		$name_n = $inst->key_pre_name . "array[$key]";
		$val_n  = isset( $t_meta[ $id_n ] ) ? $t_meta[ $id_n ][0] : '';
		_echo_name_field( $lab_n, $id_n, $name_n, $val_n, 'padding-bottom: 6px;' );

		$id_sn   = $inst->key_pre_singular_name . $key;
		$name_sn = $inst->key_pre_singular_name . "array[$key]";
		$val_sn  = isset( $t_meta[ $id_sn ] ) ? $t_meta[ $id_sn ][0] : '';
		_echo_name_field( $lab_n . __( ' (Singular Form)' ), $id_sn, $name_sn, $val_sn, 'padding-top: 6px;' );

		if ( $has_desc ) {
			$lab_d  = "$lab_base_d $lab_pf";
			$id_d   = $inst->_key_pre_description . $key;
			$name_d = $inst->_key_pre_description . "array[$key]";
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
function _echo_name_field( $label, $id, $name, $val, $style = '' ) {
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
function _echo_description_field( $label, $id, $name, $val ) {
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
 * The.
 *
 * @access private
 *
 * @param int    $term_id  The.
 * @param string $taxonomy The.
 */
function _cb_edited_taxonomy( $term_id, $taxonomy ) {
	$inst     = _get_instance();
	$key_name = $inst->key_pre_name . 'array';
	$key_sn   = $inst->key_pre_singular_name . 'array';
	$key_desc = $inst->key_pre_description . 'array';

	// phpcs:disable
	if ( isset( $_POST[ $key_name ] ) ) {
		foreach ( $_POST[ $key_name ] as $key => $val ) {
			_delete_or_update_term_meta( $term_id, $inst->key_pre_name . $key, wp_unslash( $val ) );
		}
	}
	if ( isset( $_POST[ $key_sn ] ) ) {
		foreach ( $_POST[ $key_sn ] as $key => $val ) {
			_delete_or_update_term_meta( $term_id, $inst->key_pre_singular_name . $key, wp_unslash( $val ) );
		}
	}
	if ( isset( $_POST[ $key_desc ] ) ) {
		foreach ( $_POST[ $key_desc ] as $key => $val ) {
			_delete_or_update_term_meta( $term_id, $inst->key_pre_description . $key, wp_unslash( $val ) );
		}
	}
	// phpcs:enable
}

/**
 * The.
 *
 * @access private
 *
 * @param int    $term_id The.
 * @param string $key     The.
 * @param mixed  $val     The.
 */
function _delete_or_update_term_meta( $term_id, $key, $val ) {
	if ( empty( $val ) ) {
		delete_term_meta( $term_id, $key );
	} else {
		update_term_meta( $term_id, $key, $val );
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
		 * The.
		 *
		 * @var array
		 */
		public $vars_to_slugs = array();

		/**
		 * The.
		 *
		 * @var string
		 */
		public $key_pre_name = '';

		/**
		 * The.
		 *
		 * @var string
		 */
		public $key_pre_singular_name = '';

		/**
		 * The.
		 *
		 * @var string
		 */
		public $key_pre_description = '';

		/**
		 * The.
		 *
		 * @var array
		 */
		public $taxonomies = array();

		/**
		 * The.
		 *
		 * @var array
		 */
		public $taxonomies_singular_name = array();

		/**
		 * The.
		 *
		 * @var array
		 */
		public $taxonomies_default_singular_name = array();

		/**
		 * The.
		 *
		 * @var array
		 */
		public $taxonomies_description = array();
	};
	return $values;
}
