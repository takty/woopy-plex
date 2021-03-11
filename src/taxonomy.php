<?php
/**
 * Taxonomy
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-11
 */

namespace wpinc\plex\taxonomy;

require_once __DIR__ . '/custom-rewrite.php';

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

	// add_filter( 'single_cat_title', '\wpinc\plex\taxonomy\_cb_single_term_title' );
	// add_filter( 'single_tag_title', '\wpinc\plex\taxonomy\_cb_single_term_title' );
	// add_filter( 'single_term_title', '\wpinc\plex\taxonomy\_cb_single_term_title' );

	// 'get_object_terms' for object terms
	// $_term = apply_filters( 'get_term', $_term, $taxonomy );
	add_filter( 'term_description', '\wpinc\plex\taxonomy\_cb_term_description', 10, 4 );
}

function _cb_term_description( $value, $term_id, $taxonomy, $context ) {

}

/**
 * Register an array of slug to label.
 *
 * @param array $slug_to_label An array of slug to label.
 */
function register_admin_labels( array $slug_to_label ) {
	$inst = _get_instance();
	foreach ( $slug_to_label as $slug => $label ) {
		$inst->slug_to_label[ $slug ] = $label;
	}
}

/**
 * Assign a format for displaying admin labels.
 *
 * @param string $format A format to assign.
 */
function set_admin_label_format( string $format ) {
	_get_instance()->label_format = $format;
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

	$ls = array();
	foreach ( $slugs as $s ) {
		$ls[] = $inst->slug_to_label[ $s ] ?? $s;
	}
	if ( $inst->label_format ) {
		return sprintf( $inst->label_format, ...$ls );
	}
	return implode( ' ', $ls );
}

/**
 * Generate slug combinations.
 *
 * @return array The array of slug combinations.
 */
function get_slug_combination(): array {
	static $ret = null;
	if ( null === $ret ) {
		$inst        = _get_instance();
		$structs     = \wpinc\plex\custom_rewrite\get_structures();
		$slugs_array = array();

		foreach ( $structs as $struct ) {
			if ( in_array( $struct['var'], $inst->vars, true ) ) {
				$slugs_array[] = $struct['slugs'];
			}
		}
		$ret = \wpinc\plex\pseudo_front\generate_combination( $slugs_array );
	}
	return $ret;
}

/**
 * Retrieve the key of default query variables.
 *
 * @access private
 *
 * @return string The key string.
 */
function _get_default_key(): string {
	$inst    = _get_instance();
	$structs = \wpinc\plex\custom_rewrite\get_structures();
	$slugs   = array();

	foreach ( $structs as $struct ) {
		if ( in_array( $struct['var'], $inst->vars, true ) ) {
			$slugs[] = $struct['default_slug'];
		}
	}
	return implode( '_', $slugs );
}

function _get_current_key(): string {
	$inst  = _get_instance();
	$slugs = array();

	foreach ( $inst->vars as $var ) {
		$val     = get_query_var( $var );
		$slugs[] = $v;
	}
	return implode( '_', $slugs );
}

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
	if ( $args['has_singular_name'] ) {
		$inst->tax_with_sn = array_merge( $inst->tax_with_sn, $taxonomies );
	}
	if ( $args['has_default_singular_name'] ) {
		$inst->tax_with_def_sn = array_merge( $inst->tax_with_def_sn, $taxonomies );
	}
	if ( $args['has_description'] ) {
		$inst->tax_with_desc = array_merge( $inst->tax_with_desc, $taxonomies );
	}
}


// -----------------------------------------------------------------------------


/**
 * The.
 *
 * @param \WP_Term $term     The.
 * @param bool     $singular The.
 * @param string   $lang     The.
 * @return string The.
 */
function get_term_name( $term, $singular = false, $lang = false ) {
	$inst = _get_instance();
	if ( false === $lang ) {
		$lang = $this->_core->get_site_lang();
	}
	if ( $lang === $this->_core->get_default_site_lang() ) {
		$ret = $term->name;
		if ( $singular ) {
			$sn = get_term_meta( $term->term_id, $inst->key_pre_singular_name . $lang, true );
			if ( ! empty( $sn ) ) {
				$ret = $sn;
			}
		}
		return $ret;
	}
	$name = get_term_meta( $term->term_id, $inst->key_pre_name . $lang, true );
	$sn   = get_term_meta( $term->term_id, $inst->key_pre_singular_name . $lang, true );

	if ( empty( $name ) && empty( $sn ) ) {
		return $term->name;
	}
	if ( $singular ) {
		return empty( $sn ) ? $name : $sn;
	}
	return empty( $name ) ? $sn : $name;
}

/**
 * The.
 *
 * @param int    $term_id  The.
 * @param string $lang     The.
 * @return string The.
 */
function term_description( $term_id = 0, $lang = false ) {
	$inst = _get_instance();
	if ( ! $term_id && ( is_tax() || is_tag() || is_category() ) ) {
		$t = get_queried_object();
		if ( $t ) {
			$term_id = $t->term_id;
		}
	}
	if ( false === $lang ) {
		$lang = $this->_core->get_site_lang();
	}
	$d = get_term_meta( $term_id, $inst->_key_pre_description . $lang, true );
	if ( empty( $d ) ) {
		return \term_description( $term_id );
	}
	return $d;
}


// -----------------------------------------------------------------------------


/**
 * The.
 *
 * @access private
 *
 * @return string The.
 */
/*
function _cb_single_term_title() {
	$term = get_queried_object();
	if ( ! $term ) {
		return;
	}
	return get_term_name( $term );
}
*/

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

	$lab_base_n = esc_html_x( 'Name', 'term name', 'default' );

	$has_desc = in_array( $taxonomy, $inst->tax_with_desc, true );
	if ( $has_desc ) {
		$lab_base_d = esc_html__( 'Description' );
	}
	$has_def_sg = in_array( $taxonomy, $inst->tax_with_def_sn, true );

	if ( $has_def_sg ) {
		$lab_pf = _get_admin_label( $slugs );
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
			$id_d   = $inst->_key_pre_description . $lang;
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
		public $tax_with_sn = array();

		/**
		 * The.
		 *
		 * @var array
		 */
		public $tax_with_def_sn = array();

		/**
		 * The.
		 *
		 * @var array
		 */
		public $tax_with_desc = array();
	};
	return $values;
}
