<?php
/*
Plugin Name: Pix Category Icons
Plugin URI:  http://pixelgrade.com
Description: WordPress photo gallery proofing plugin.
Version: 0.0.1
Author: PixelGrade
Author URI: http://pixelgrade.com
Author Email: contact@pixelgrade.com
Text Domain: proof
License:     GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Domain Path: /lang
*/

global $pixcategoryicons_plugin;
$pixcategoryicons_plugin = PixCategoryIconsPlugin::get_instance();

class PixCategoryIconsPlugin {

	protected static $instance;
	protected $plugin_basepath = null;
	protected $plugin_baseurl = null;
	protected $plugin_screen_hook_suffix = null;
	protected $version = '1.2.1';
	protected $plugin_slug = 'pix-category-icons';
	protected $plugin_key = 'pix_category_icons';

	/**
	 * Initialize the plugin by setting localization, filters, and administration functions.
	 * @since     1.0.0
	 */
	protected function __construct() {
		$this->plugin_basepath = plugin_dir_path( __FILE__ );
		$this->plugin_baseurl = plugin_dir_url( __FILE__ );

		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'plugin_admin_init' ) );

		// Load plugin text domain
		add_action( 'init', array( $this, 'plugin_init' ), 9999999999 );
		add_action( 'init', array( $this, 'register_the_termmeta_table' ), 1 );
		add_action('wpmu_new_blog', array($this, 'new_blog'), 10, 6);

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		register_activation_hook( __FILE__, array($this, 'activate') );
	}

	/**
	 * This will run when the plugin will turn On
	 *
	 * @param bool|false $network_wide
	 */
	function activate( $network_wide = false ) {
		global $wpdb;

		// if activated on a particular blog, just set it up there.
		if ( !$network_wide ) {
			$this->create_the_termmeta_table();
			return;
		}

		$blogs = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = '{$wpdb->siteid}'" );
		foreach ( $blogs as $blog_id ) {
			$this->create_the_termmeta_table( $blog_id );
		}
		// I feel dirty... this line smells like perl.
		do {} while ( restore_current_blog() );
	}

	function new_blog( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {
		if ( is_plugin_active_for_network(plugin_basename(__FILE__)) )
			$this->create_the_termmeta_table($blog_id);
	}

	/**
	 * Return an instance of this class.
	 * @since     1.0.0
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	function plugin_init() {

		$selected_taxonomies = $this->get_plugin_option('taxonomies');

		if ( ! is_wp_error( $selected_taxonomies ) && ! empty( $selected_taxonomies ) ) {
			foreach ( $selected_taxonomies as $tax_name => $somerandomvalue ) {
				add_action( $tax_name . '_add_form_fields', array( $this, 'taxonomy_add_new_meta_field'), 10, 2 );
				add_action( $tax_name . '_edit_form_fields', array( $this, 'taxonomy_edit_new_meta_field'), 10, 2 );
				add_action( 'edited_' . $tax_name,  array( $this, 'save_taxonomy_custom_meta' ), 10, 2 );
				add_action( 'create_' . $tax_name,  array( $this, 'save_taxonomy_custom_meta' ), 10, 2 );

				add_filter( "manage_edit-" . $tax_name . "_columns", array( $this, 'add_custom_tax_column' ) );
				//add_filter( "manage_edit-' . $tax_name . '_sortable_columns", 'make_sortable_col' );
				add_filter( "manage_" . $tax_name . "_custom_column", array( $this, 'output_custom_tax_column' ), 10, 3 );
			}
		}
	}

	function enqueue_admin_scripts () {
		wp_enqueue_style( $this->plugin_slug . '-admin-style', plugins_url( 'assets/css/pix-category-icons.css', __FILE__ ), array(  ), $this->version );
		wp_enqueue_media();
		wp_enqueue_script( $this->plugin_slug . '-admin-script', plugins_url( 'assets/js/pix-category-icons.js', __FILE__ ), array( 'jquery' ), $this->version );
		wp_localize_script( $this->plugin_slug . '-admin-script', 'locals', array(
			'ajax_url' => admin_url( 'admin-ajax.php' )
		) );
	}

	function taxonomy_add_new_meta_field ( $tax ) { ?>
		<tr class="form-field">
			<td>
				<input type="hidden" name="term_icon_value" id="term_icon_value" value="">
				<div class="open_term_icon_preview">
					<span class="open_term_icon_upload button button-secondary" >
						<?php _e( 'Select Icon', 'pix-category-icons'); ?>
					</span>
				</div>
			</td>
		</tr>
		<?php
	}

	function taxonomy_edit_new_meta_field ( $term ) {
		$current_value = '';
		if ( isset( $term->term_id ) ) {
			$current_value = get_term_meta( $term->term_id, 'pix_term_icon', true );
		} ?>
		<tr class="form-field">
			<th scope="row" valign="top"><label for="term_icon_value"><?php _e( 'icon', 'pix-category-icons' ); ?></label></th>
			<td>
				<input type="hidden" name="term_icon_value" id="term_icon_value" value="<?php echo $current_value; ?>">
				<?php if ( empty( $current_value ) ) { ?>
				<div class="open_term_icon_preview">
					<span class="open_term_icon_upload button button-secondary">
						<?php _e( 'Select Icon', 'pix-category-icons');?>
					</span>
				</div>
			<?php } else { ?>
				<div class="open_term_icon_preview">
					<?php echo wp_get_attachment_image( $current_value ); ?>
					<span class="open_term_icon_upload button button-secondary">
						<?php _e( 'Select Icon', 'pix-category-icons');?>
					</span>
				</div>
			<?php } ?>
			</td>
		</tr>
		<?php
	}

	function save_taxonomy_custom_meta ( $term_id ) {
		if ( isset( $_POST['term_icon_value'] ) && ! empty( $_POST['term_icon_value'] ) ) {
			$value = $_POST['term_icon_value'];
			$current_value = get_term_meta( $term_id, 'pix_term_icon', true );

			if ( empty( $current_value ) ) {
				$updated = update_term_meta( $term_id, 'pix_term_icon', $value );
			} else {
				$updated = update_term_meta( $term_id, 'pix_term_icon', $value, $current_value );
			}
			update_termmeta_cache( array( $term_id ) );
		}
	}

	/**
	 * Taxonomy columns
	 */
	function add_custom_tax_column( $current_columns ) {

		$input = array_shift( $current_columns );
		$new_columns = array(
			'cb' => $input,
			'pix-category-icon' => __( 'Icon', $this->plugin_slug ),
		);

		$new_columns = $new_columns + $current_columns;
		return $new_columns;
	}

	function output_custom_tax_column(  $value, $name, $id ) {
		$icon_id = get_term_meta( $id, 'pix_term_icon', true );
		if ( is_numeric( $icon_id ) )  {
			$src = wp_get_attachment_image_src( $icon_id, 'thumbnail' );
			if ( isset( $src[0] ) && ! empty( $src[0] ) ) {
				echo '<div class="pix-category-icon-column_wrap media-icon">';
					echo '<img src="' . $src[0] . '" width="60px" height="60px" />';
				echo '</div>';
			}
		}
	}


	/** Ensure compat with wp 4.4 */

	function create_the_termmeta_table( $id = false ) {
		global $wpdb;

		if ( $id !== false)
			switch_to_blog( $id );

		$max_index_length = 191;
		$charset_collate = '';

		if ( ! empty($wpdb->charset) )
			$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		if ( ! empty($wpdb->collate) )
			$charset_collate .= " COLLATE $wpdb->collate";

		$blog_tables = "CREATE TABLE {$wpdb->prefix}termmeta (
		meta_id bigint(20) unsigned NOT NULL auto_increment,
		term_id bigint(20) unsigned NOT NULL default '0',
		meta_key varchar(255) default NULL,
		meta_value longtext,
		PRIMARY KEY (meta_id),
		KEY term_id (term_id),
		KEY meta_key (meta_key($max_index_length))
	) $charset_collate; ";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		dbDelta( $blog_tables );
	}

	function register_the_termmeta_table() {
		global $wpdb;

		//register the termmeta table with the wpdb object if this is older than 4.4
		if ( ! isset($wpdb->termmeta)) {
			$wpdb->termmeta = $wpdb->prefix . "termmeta";
			//add the shortcut so you can use $wpdb->stats
			$wpdb->tables[] = str_replace($wpdb->prefix, '', $wpdb->prefix . "termmeta");
		}
	}


	/**
	 * create an admin page
	 */
	function add_plugin_admin_menu( ) {
		$this->plugin_screen_hook_suffix = add_options_page(
			__( 'Category Icons', $this->plugin_slug ),
			__( 'Category Icons', $this->plugin_slug ),
			'manage_options',
			$this->plugin_slug, array( $this, 'display_plugin_admin_page' )
		);

	}

	function plugin_admin_init() {


		register_setting( 'pix_category_icons', 'pix_category_icons', array( $this, 'save_setting_values' ) );
		add_settings_section(
			'pix_category_icons',
			null,
			array( $this, 'render_settings_section_title' ),
			'pix-category-icons'
		);
		add_settings_field('taxonomies', 'Select Taxonomies', array( $this, 'render_taxonomies_select' ), 'pix-category-icons', 'pix_category_icons');
	}

	function render_taxonomies_select ( ) {
		$taxonomies = get_taxonomies();

		// get the current selected taxonomies
		$options = get_option('pix_category_icons');

		$selected_taxonomies = array();

		if ( isset( $options['taxonomies'] ) ) {
			$selected_taxonomies = $options['taxonomies'];
		} ?>
		<field class="select_taxonomies">
			<?php
			if ( ! empty( $taxonomies ) || ! is_wp_error( $taxonomies ) ) {
				foreach ( $taxonomies as $key => $tax ) {
					$selected = '';
					if ( ! empty( $selected_taxonomies ) && isset( $selected_taxonomies[$key] ) &&  $selected_taxonomies[$key] = 'on' ) {
						$selected = ' checked="selected"';
					}
					$full_key = 'pix_category_icons[taxonomies][' . $key  . ']'; ?>
					<label for="<?php echo $full_key; ?>">
						<input id='<?php echo $full_key; ?>' name='<?php echo $full_key; ?>' size='40' type='checkbox' <?php echo $selected ?>/>
						<?php echo $key ?>
						</br>
					</label>
				<?php }
			}?>

		</field>
	<?php }

	// this should sanitize things around
	function save_setting_values( $input ) {
		return $input;
	}

	function render_settings_section_title() { ?>
		<h2><?php _e('Category Icons Options', 'category_icons_txtd'); ?></h2>
	<?php }

	/**
	 * Render the settings page for this plugin.
	 */
	function display_plugin_admin_page() {
		include_once( 'views/admin.php' );
	}

	function get_plugin_option( $key ) {

		$options = get_option('pix_category_icons');

		if ( isset( $options [$key] ) ) {
			return $options [$key];
		}

		return null;
	}
}



if ( ! function_exists( 'add_term_meta' ) ) {
	/**
	 * Adds metadata to a term.
	 *
	 * @since 4.4.0
	 *
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Metadata name.
	 * @param mixed  $meta_value Metadata value.
	 * @param bool   $unique     Optional. Whether to bail if an entry with the same key is found for the term.
	 *                           Default false.
	 * @return int|bool Meta ID on success, false on failure.
	 */
	function add_term_meta( $term_id, $meta_key, $meta_value, $unique = false ) {
		$added = add_metadata( 'term', $term_id, $meta_key, $meta_value, $unique );

		// Bust term query cache.
		if ( $added ) {
			wp_cache_set( 'last_changed', microtime(), 'terms' );
		}

		return $added;
	}
}


if ( ! function_exists( 'delete_term_meta' ) ) {
	/**
	 * Removes metadata matching criteria from a term.
	 *
	 * @since 4.4.0
	 *
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Metadata name.
	 * @param mixed  $meta_value Optional. Metadata value. If provided, rows will only be removed that match the value.
	 * @return bool True on success, false on failure.
		 */
	function delete_term_meta( $term_id, $meta_key, $meta_value = '' ) {
		$deleted = delete_metadata( 'term', $term_id, $meta_key, $meta_value );

		// Bust term query cache.
		if ( $deleted ) {
			wp_cache_set( 'last_changed', microtime(), 'terms' );
		}

		return $deleted;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	/**
	 * Retrieves metadata for a term.
	 *
	 * @since 4.4.0
	 *
	 * @param int $term_id Term ID.
	 * @param string $key Optional. The meta key to retrieve. If no key is provided, fetches all metadata for the term.
	 * @param bool $single Whether to return a single value. If false, an array of all values matching the
	 *                        `$term_id`/`$key` pair will be returned. Default: false.
	 *
	 * @return mixed If `$single` is false, an array of metadata values. If `$single` is true, a single metadata value.
	 */
	function get_term_meta( $term_id, $key = '', $single = false ) {
		return get_metadata( 'term', $term_id, $key, $single );
	}
}


if ( ! function_exists( 'update_term_meta' ) ) {
	/**
	 * Updates term metadata.
	 *
	 * Use the `$prev_value` parameter to differentiate between meta fields with the same key and term ID.
	 *
	 * If the meta field for the term does not exist, it will be added.
	 *
	 * @since 4.4.0
	 *
	 * @param int $term_id Term ID.
	 * @param string $meta_key Metadata key.
	 * @param mixed $meta_value Metadata value.
	 * @param mixed $prev_value Optional. Previous value to check before removing.
	 *
	 * @return int|bool Meta ID if the key didn't previously exist. True on successful update. False on failure.
	 */
	function update_term_meta( $term_id, $meta_key, $meta_value, $prev_value = '' ) {
		$updated = update_metadata( 'term', $term_id, $meta_key, $meta_value, $prev_value );

		// Bust term query cache.
		if ( $updated ) {
			wp_cache_set( 'last_changed', microtime(), 'terms' );
		}

		return $updated;
	}
}

if ( ! function_exists( 'update_termmeta_cache' ) ) {
	/**
	 * Updates metadata cache for list of term IDs.
	 *
	 * Performs SQL query to retrieve all metadata for the terms matching `$term_ids` and stores them in the cache.
	 * Subsequent calls to `get_term_meta()` will not need to query the database.
	 *
	 * @since 4.4.0
	 *
	 * @param array $term_ids List of term IDs.
	 *
	 * @return array|false Returns false if there is nothing to update. Returns an array of metadata on success.
	 */
	function update_termmeta_cache( $term_ids ) {
		return update_meta_cache( 'term', $term_ids );
	}
}

if ( ! function_exists( 'wp_lazyload_term_meta' ) ) {
	function wp_lazyload_term_meta( $check, $term_id ) {
		global $wp_query;

		if ( $wp_query instanceof WP_Query && ! empty( $wp_query->posts ) && $wp_query->get( 'update_post_term_cache' ) ) {
			// We can only lazyload if the entire post object is present.
			$posts = array();
			foreach ( $wp_query->posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$posts[] = $post;
				}
			}

			if ( empty( $posts ) ) {
				return;
			}

			// Fetch cached term_ids for each post. Keyed by term_id for faster lookup.
			$term_ids = array();
			foreach ( $posts as $post ) {
				$taxonomies = get_object_taxonomies( $post->post_type );
				foreach ( $taxonomies as $taxonomy ) {
					// No extra queries. Term cache should already be primed by 'update_post_term_cache'.
					$terms = get_object_term_cache( $post->ID, $taxonomy );
					if ( false !== $terms ) {
						foreach ( $terms as $term ) {
							if ( ! isset( $term_ids[ $term->term_id ] ) ) {
								$term_ids[ $term->term_id ] = 1;
							}
						}
					}
				}
			}

			if ( $term_ids ) {
				update_termmeta_cache( array_keys( $term_ids ) );
			}
		}

		return $check;
	}
	add_filter( 'get_term_metadata',        'wp_lazyload_term_meta',        10, 2 );
}
