<?php
/**
 * Taxonomy
 *
 * @package Wpinc Plex
 * @author Takuto Yanagida
 * @version 2021-03-10
 */

namespace wpinc\plex\taxonomy;

require_once __DIR__ . '/custom-rewrite.php';

/**
 * The.
 *
 * @param string $key_prefix The.
 */
function initialize( $key_prefix = '_' ) {
	$inst = _get_instance();

	$inst->_key_term_name = $key_prefix . 'name_';
	$inst->_key_term_desc = $key_prefix . 'description_';

	add_filter( 'single_cat_title', '\wpinc\plex\taxonomy\_cb_single_term_title' );
	add_filter( 'single_tag_title', '\wpinc\plex\taxonomy\_cb_single_term_title' );
	add_filter( 'single_term_title', '\wpinc\plex\taxonomy\_cb_single_term_title' );
}

/**
 * The.
 *
 * @param string|string[] $taxonomy_s The.
 * @param array           $opt        The.
 */
function add_taxonomy( $taxonomy_s, $opt = false ) {
	if ( ! is_array( $taxonomy_s ) ) {
		$taxonomy_s = array( $taxonomy_s );
	}
	if ( is_array( $opt ) ) {
		$has_desc        = isset( $opt['has_description'] ) ? $opt['has_description'] : false;
		$has_def_lang_sg = isset( $opt['has_default_lang_singular_name'] ) ? $opt['has_default_lang_singular_name'] : false;
	} else {
		$has_desc        = $opt;
		$has_def_lang_sg = false;
	}

	$inst = _get_instance();
	foreach ( $taxonomy_s as $t ) {
		add_action( "{$t}_edit_form_fields", '\wpinc\plex\taxonomy\_cb_term_edit_form_fields', 10, 2 );
		add_action( 'edited_' . $t, '\wpinc\plex\taxonomy\_cb_edited_term', 10, 2 );
	}
	if ( $has_desc ) {
		$inst->_tax_with_desc = array_merge( $inst->_tax_with_desc, $taxonomy_s );
	}
	if ( $has_def_lang_sg ) {
		$inst->_tax_with_def_lang_sg = array_merge( $inst->_tax_with_def_lang_sg, $taxonomy_s );
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
		if ( $singular ) {
			$key_s  = $inst->_key_term_name . $lang . '_s';
			$name_s = get_term_meta( $term->term_id, $key_s, true );
			if ( ! empty( $name_s ) ) {
				return $name_s;
			}
		}
		return $term->name;
	}
	$key    = $inst->_key_term_name . $lang;
	$key_s  = $key . '_s';
	$name   = get_term_meta( $term->term_id, $key, true );
	$name_s = get_term_meta( $term->term_id, $key_s, true );

	if ( empty( $name ) && empty( $name_s ) ) {
		return $term->name;
	}
	if ( $singular ) {
		if ( ! empty( $name_s ) ) {
			return $name_s;
		}
		return $name;
	}
	if ( ! empty( $name ) ) {
		return $name;
	}
	return $name_s;
}

/**
 * The.
 *
 * @param int    $term_id  The.
 * @param string $taxonomy The.
 * @param string $lang     The.
 * @return string The.
 */
function term_description( $term_id = 0, $taxonomy, $lang = false ) {
	$inst = _get_instance();
	if ( ! $term_id && ( is_tax() || is_tag() || is_category() ) ) {
		$t        = get_queried_object();
		$term_id  = $t->term_id;
		$taxonomy = $t->taxonomy;
	}
	if ( false === $lang ) {
		$lang = $this->_core->get_site_lang();
	}
	$key  = $inst->_key_term_desc . $lang;
	$desc = get_term_meta( $term_id, $key, true );
	if ( empty( $desc ) ) {
		return \term_description( $term_id, $taxonomy );
	}
	return $desc;
}


// -----------------------------------------------------------------------------


/**
 * The.
 *
 * @access private
 *
 * @return string The.
 */
function _cb_single_term_title() {
	$term = get_queried_object();
	if ( ! $term ) {
		return;
	}
	return get_term_name( $term );
}

/**
 * The.
 *
 * @access private
 *
 * @param \WP_Term $term     The.
 * @param string   $taxonomy The.
 */
function _cb_term_edit_form_fields( $term, $taxonomy ) {
	$inst = _get_instance();

	$t_meta     = get_term_meta( $term->term_id );
	$label_base = esc_html_x( 'Name', 'term name', 'default' );

	$has_desc = in_array( $taxonomy, $inst->_tax_with_desc, true );
	if ( $has_desc ) {
		$label_desc_base = esc_html__( 'Description' );
	}
	$has_def_lang_sg = in_array( $taxonomy, $inst->_tax_with_def_lang_sg, true );
	if ( $has_def_lang_sg ) {
		$lang   = $this->_core->get_default_site_lang();
		$label  = esc_html( "$label_base [$lang]" );
		$id_s   = esc_attr( $inst->_key_term_name . $lang . '_s' );
		$name_s = esc_attr( $inst->_key_term_name . "array_s[$id_s]" );
		$val_s  = isset( $t_meta[ $id_s ] ) ? esc_attr( $t_meta[ $id_s ][0] ) : '';
		?>
<tr class="form-field">
	<th><label for="<?php echo $id_s; ?>"><?php echo $label; ?> (Singular Form)</label></th>
	<td>
		<input type="text" name="<?php echo $name_s; ?>" id="<?php echo $id_s; ?>" size="40" value="<?php echo $val_s; ?>" />
	</td>
</tr>
		<?php
	}
	foreach ( $this->_core->get_site_langs( false ) as $lang ) {
		$label  = "$label_base [$lang]";
		$id     = $inst->_key_term_name . $lang;
		$id_s   = $id . '_s';
		$name   = esc_attr( $inst->_key_term_name . "array[$id]" );
		$name_s = esc_attr( $inst->_key_term_name . "array_s[$id_s]" );
		$val    = isset( $t_meta[ $id ] ) ? esc_attr( $t_meta[ $id ][0] ) : '';
		$val_s  = isset( $t_meta[ $id_s ] ) ? esc_attr( $t_meta[ $id_s ][0] ) : '';
		?>
<tr class="form-field">
	<th style="padding-bottom: 6px;"><label for="<?php echo $id; ?>"><?php echo $label; ?></label></th>
	<td style="padding-bottom: 6px;">
		<input type="text" name="<?php echo $name; ?>" id="<?php echo $id; ?>" size="40" value="<?php echo $val; ?>" />
	</td>
</tr>
<tr class="form-field">
	<th style="padding-top: 6px;"><label for="<?php echo $id_s; ?>"><?php echo $label; ?> (Singular Form)</label></th>
	<td style="padding-top: 6px;">
		<input type="text" name="<?php echo $name_s; ?>" id="<?php echo $id_s; ?>" size="40" value="<?php echo $val_s; ?>" />
	</td>
</tr>
		<?php
		if ( $has_desc ) {
			$label_desc = esc_html( $label_desc_base . " [$lang]" );
			$desc_id    = esc_attr( $inst->_key_term_desc . $lang );
			$desc_name  = esc_attr( $inst->_key_term_desc . "array[$desc_id]" );
			$desc_val   = isset( $t_meta[ $desc_id ] ) ? esc_html( $t_meta[ $desc_id ][0] ) : '';
			?>
<tr class="form-field term-description-wrap">
	<th scope="row"><label for="<?php echo $desc_id; ?>"><?php echo $label_desc; ?></label></th>
	<td><textarea name="<?php echo $desc_name; ?>" id="<?php echo $desc_id; ?>" rows="5" cols="50" class="large-text"><?php echo $desc_val; ?></textarea></td>
</tr>
			<?php
		}
	}
}

/**
 * The.
 *
 * @access private
 *
 * @param int    $term_id  The.
 * @param string $taxonomy The.
 */
function _cb_edited_term( $term_id, $taxonomy ) {
	$inst = _get_instance();
	if ( isset( $_POST[ $inst->_key_term_name . 'array' ] ) ) {
		foreach ( $_POST[ $inst->_key_term_name . 'array' ] as $key => $val ) {
			_delete_or_update_term_meta( $term_id, $key, $val );
		}
	}
	if ( isset( $_POST[ $inst->_key_term_name . 'array_s' ] ) ) {
		foreach ( $_POST[ $inst->_key_term_name . 'array_s' ] as $key => $val ) {
			_delete_or_update_term_meta( $term_id, $key, $val );
		}
	}
	if ( isset( $_POST[ $inst->_key_term_desc . 'array' ] ) ) {
		foreach ( $_POST[ $inst->_key_term_desc . 'array' ] as $key => $val ) {
			_delete_or_update_term_meta( $term_id, $key, $val );
		}
	}
}

/**
 * The.
 *
 * @access private
 *
 * @param int    $term_id The.
 * @param string $key     The.
 * @param mixed  $val     The.
 * @return mixed The.
 */
function _delete_or_update_term_meta( $term_id, $key, $val ) {
	if ( empty( $val ) ) {
		return delete_term_meta( $term_id, $key );
	}
	return update_term_meta( $term_id, $key, $val );
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
		 * @var string
		 */
		public $key_term_name = '';

		/**
		 * The.
		 *
		 * @var string
		 */
		public $key_term_desc = '';

		/**
		 * The.
		 *
		 * @var array
		 */
		public $tax_with_desc = array();

		/**
		 * The.
		 *
		 * @var array
		 */
		public $tax_with_def_lang_sg = array();
	};
	return $values;
}
