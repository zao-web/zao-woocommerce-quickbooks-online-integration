<?php
namespace Zao\WC_QBO_Integration\Admin;
use Zao\WC_QBO_Integration\Base;
use Zao\WC_QBO_Integration\Plugin;
use Zao\WC_QBO_Integration\Services\Base as Services;

class Settings extends Base {
	const KEY = 'zwqoi_options';

	protected $cmb;
	protected $is_active = false;

	public function init() {
		add_action( 'cmb2_admin_init', array( $this, 'register_theme_options_metabox' ) );
		add_filter( 'plugin_action_links_' . ZWQOI_BASENAME, array( $this, 'settings_link' ) );
		add_filter( 'zwqoi_settings_nav_links', array( $this, 'add_nav_link' ), 5 );

		add_action( 'cmb2_save_options-page_fields_' . self::KEY . '_box', array( $this, 'maybe_reset_wholesale_users_cache' ), 10, 2 );

		add_action( 'zwqoi_customer_connected_to_user', array( $this, 'reset_wholesale_users_cache_if_limiting' ) );
		add_action( 'zwqoi_customer_disconnect_user', array( $this, 'reset_wholesale_users_cache_if_limiting' ) );

		add_filter( 'zwqoi_role_for_customer_user', array( __CLASS__, 'maybe_set_wholesaler_role' ) );
		add_action( 'zwqoi_new_product_from_quickbooks', array( __CLASS__, 'maybe_set_wholesale_category' ) );
		add_filter( 'zwoowh_set_wholesale_users_args', array( __CLASS__, 'maybe_limit_wholesalers_to_customers' ) );

		if ( function_exists( 'qbo_connect_ui' ) && is_object( qbo_connect_ui()->settings ) ) {
			add_filter( 'zwqoi_settings_nav_links', array( $this, 'add_connect_link' ), 20 );
			remove_action( 'qbo_connect_ui_settings_output', array( qbo_connect_ui()->settings, 'settings_title_output' ) );
			add_action( 'qbo_connect_ui_settings_output', array( '\\Zao\\WC_QBO_Integration\\Services\\UI_Base', 'admin_page_title' ) );
		}
	}

	/**
	 * Whether we should output the settings form.
	 *
	 * @since  0.1.0
	 *
	 * @return bool
	 */
	public function show_settings() {
		return true;
	}

	/**
	 * Add Settings page to plugin action links in the Plugins table.
	 *
	 * @since 0.1.0
	 *
	 * @param  array $links Default plugin action links.
	 * @return array $links Amended plugin action links.
	 */
	public function settings_link( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', $this->settings_url(), __( 'Settings', 'zwqoi' ) )
		);

		return $links;
	}

	/**
	 * Adds this settings page to the nav tabs filter.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $links Array of links for nav tabs.
	 *
	 * @return array
	 */
	public function add_nav_link( $links ) {
		$links[] = array(
			'url'    => $this->settings_url(),
			'active' => $this->is_active,
			'text'   => esc_html__( 'QuickBooks Woo Integration Settings', 'zwqoi' ),
		);

		return $links;
	}

	/**
	 * Adds the API Connect plugin's settings to the nav tabs filter if it is found.
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $links Array of links for nav tabs.
	 *
	 * @return array
	 */
	public function add_connect_link( $links ) {
		$links[] = array(
			'text'   => esc_html__( 'QuickBooks API Connect', 'zwqoi' ),
			'url'    => qbo_connect_ui()->settings->settings_url(),
			'active' => \Zao\WC_QBO_Integration\Services\UI_Base::admin_page_matches(
				qbo_connect_ui()->settings->settings_url()
			),
		);

		return $links;
	}

	/**
	 * Hook in and register a metabox to handle a theme options page and adds a menu item.
	 */
	public function register_theme_options_metabox() {

		/**
		 * Registers options page menu item and form.
		 */
		$this->cmb = new_cmb2_box( array(
			'id'           => self::KEY . '_box',
			// 'title'        => esc_html__( 'Zao WooCommerce QuickBooks Integration Settings', 'zwqoi' ),
			'object_types' => array( 'options-page' ),

			/*
			 * The following parameters are specific to the options-page box
			 * Several of these parameters are passed along to add_menu_page()/add_submenu_page().
			 */

			'option_key'      => self::KEY, // The option key and admin menu page slug.
			'icon_url'        => 'dashicons-book-alt', // Menu icon. Only applicable if 'parent_slug' is left empty.
			'menu_title'      => esc_html__( 'QB Woo Integration', 'zwqoi' ), // Falls back to 'title' (above).
			'parent_slug'     => 'options-general.php', // Make options page a submenu item of the themes menu.
			// 'save_button'     => esc_html__( 'Save Theme Options', 'zwqoi' ), // The text for the options-page save button. Defaults to 'Save'.
			'display_cb' => array( $this, 'options_page_output' ),
		) );

		$this->cmb->add_field( array(
			'show_on_cb' => array( __CLASS__, 'is_connected' ),
			'id'         => 'company-title',
			'name'       => __( 'Connected Company:', 'zwqoi' ),
			'type'       => 'title',
		) );


		// $group_field_id is the field id string, so in this case: $prefix . 'demo'
		// $group_field_id = $this->cmb->add_field( array(
		// 	'show_on_cb' => array( __CLASS__, 'has_wholesale_plugin' ),
		// 	'id'          => 'wholesale',
		// 	'type'        => 'group',
		// 	// 'description' => esc_html__( 'Settings for Zao WooCommerce Wholesale', 'zwqoi' ),
		// 	'options'     => array(
		// 		'group_title'   => esc_html__( 'Wholesale Settings', 'zwqoi' ),
		// 		// 'group_title'   => esc_html__( 'These settings are specific to the "Zao WooCommerce Wholesale" plugin.', 'zwqoi' ),
		// 	),
		// 	'repeatable' => false,
		// ) );

		$this->cmb->add_field( array(
			'show_on_cb' => array( __CLASS__, 'has_wholesale_plugin' ),
			'id'         => 'zww-title',
			'name'   => esc_html__( 'Wholesale Settings', 'zwqoi' ),
			'desc'       => __( 'These settings are specific to the "Zao WooCommerce Wholesale" plugin.', 'zwqoi' ),
			'type'       => 'title',
		) );

		$this->cmb->add_field( array(
			'show_on_cb' => array( __CLASS__, 'has_wholesale_plugin' ),
			'name'       => __( 'Import Customers as Wholesalers?', 'zwqoi' ),
			'id'         => 'customers_as_wholesalers',
			'type'       => 'checkbox',
		) );

		$this->cmb->add_field( array(
			'show_on_cb'       => array( __CLASS__, 'has_wholesale_plugin' ),
			'name'             => __( 'Import Products as Wholesale?', 'zwqoi' ),
			'id'               => 'products_as_wholesale',
			'taxonomy'         => class_exists( 'Zao\\ZaoWooCommerce_Wholesale\\Taxonomy' ) ? \Zao\ZaoWooCommerce_Wholesale\Taxonomy::SLUG : 'wholesale-category',
			'type'             => 'taxonomy_radio',
			'show_option_none' => __( 'No', 'zwqoi' ),
		) );

		$this->cmb->add_field( array(
			'show_on_cb' => array( __CLASS__, 'has_wholesale_plugin' ),
			'name'       => __( 'Limit wholesale customers to QuickBooks customers?', 'zwqoi' ),
			'desc'       => __( 'By default, the wholesaler users are <strong>not</strong> limited to users with connected QuickBooks customers.', 'zwqoi' ),
			'id'         => 'wholesalers_must_be_customers',
			'type'       => 'checkbox',
		) );

	}

	public static function is_connected( $field ) {
		$name = Services::company_name();

		if ( ! $name ) {
			return false;
		}

		$field->set_prop( 'description', $name );

		return true;
	}

	public static function has_wholesale_plugin() {
		return defined( 'ZWOOWH_VERSION' );
	}

	/**
	 * Display options-page output. To override, set 'display_cb' box property.
	 *
	 * @since  2.2.5
	 */
	public function options_page_output( $hookup ) {
		$this->is_active = true;
		include_once ZWQOI_INC . 'views/settings-page.php';
	}

	public function maybe_reset_wholesale_users_cache( $key, $updated ) {
		if ( self::has_wholesale_plugin() && in_array( 'wholesalers_must_be_customers', $updated ) ) {
			// Trigger a re-caching of wholesaler users.
			do_action( 'zwoowh_set_wholesale_users' );
		}
	}

	public function reset_wholesale_users_cache_if_limiting() {
		if ( self::has_wholesale_plugin() && self::get_option( 'wholesalers_must_be_customers' ) ) {
			// Trigger a re-caching of wholesaler users.
			do_action( 'zwoowh_set_wholesale_users' );
		}
	}

	public static function maybe_set_wholesaler_role( $role ) {
		if ( self::has_wholesale_plugin() && self::get_option( 'customers_as_wholesalers' ) ) {
			$role = \Zao\ZaoWooCommerce_Wholesale\User::ROLE;
		}

		return $role;
	}

	public static function maybe_set_wholesale_category( $product_id ) {
		if ( self::has_wholesale_plugin() && self::get_option( 'customers_as_wholesalers' ) ) {
			$term = self::get_option( 'products_as_wholesale' );
			\Zao\ZaoWooCommerce_Wholesale\Taxonomy::set_wholesale_term( $product_id, $term );
		}
	}

	public static function maybe_limit_wholesalers_to_customers( $args ) {
		if ( self::get_option( 'wholesalers_must_be_customers' ) ) {
			$args['meta_key']     = Plugin::get_instance()->customers->meta_key;
			$args['meta_compare'] = 'EXISTS';
		}

		return $args;
	}

	public function settings_url() {
		return admin_url( 'options-general.php?page=' . self::KEY );
	}

	/**
	 * Wrapper function around cmb2_get_option
	 * @since  0.1.0
	 * @param  string $key     Options array key
	 * @param  mixed  $default Optional default value
	 * @return mixed           Option value
	 */
	public static function get_option( $key = '', $default = false ) {
		if ( function_exists( 'cmb2_get_option' ) ) {
			// Use cmb2_get_option as it passes through some key filters.
			return cmb2_get_option( self::KEY, $key, $default );
		}

		// Fallback to get_option if CMB2 is not loaded yet.
		$opts = get_option( self::KEY, $default );

		$val = $default;

		if ( 'all' == $key ) {
			$val = $opts;
		} elseif ( is_array( $opts ) && array_key_exists( $key, $opts ) && false !== $opts[ $key ] ) {
			$val = $opts[ $key ];
		}

		return $val;
	}
}

