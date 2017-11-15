<?php
namespace Zao\WC_QBO_Integration\Admin;
use Zao\WC_QBO_Integration\Base;
use Zao\WC_QBO_Integration\Plugin;
use Zao\WC_QBO_Integration\Services\Base as Services;
use Zao\WC_QBO_Integration\Services\Invoices;

class Settings extends Base {
	const KEY = 'zwqoi_options';

	protected $cmb;
	protected $invoices;
	protected $is_active = false;

	public function __construct( Invoices $invoices ) {
		$this->invoices = $invoices;
	}

	public function init() {
		add_action( 'cmb2_admin_init', array( $this, 'register_theme_options_metabox' ) );
		add_action( 'load-settings_page_' . self::KEY, array( $this, 'add_help_tab' ) );
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
			add_action( 'load-settings_page_qbo_connect_ui_settings', array( $this, 'add_help_tab' ) );
		}
	}

	/**
	 * Whether we should output the settings form.
	 *
	 * @since  0.1.0
	 *
	 * @return bool
	 */
	public static function token_acquired() {
		return (
			function_exists( 'qbo_connect_ui' )
			&& is_object( qbo_connect_ui()->api )
			&& qbo_connect_ui()->api->token_acquired()
		);
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
			'url'    => self::api_settings_page_url(),
			'active' => \Zao\WC_QBO_Integration\Services\UI_Base::admin_page_matches(
				qbo_connect_ui()->settings->settings_url()
			),
		);

		return $links;
	}

	public static function api_settings_page_url() {
		if ( function_exists( 'qbo_connect_ui' ) && is_object( qbo_connect_ui()->settings ) ) {
			return qbo_connect_ui()->settings->settings_url();
		}
	}

	public static function initation_required_message() {
		$url = self::api_settings_page_url();
		if ( $url )  {
			return sprintf( __( 'Please connect to your QuickBooks App on the <a href="%s">API Connect page</a>.', 'zwqoi' ), $url );
		}

		return __( 'You need to initate the Quickbooks connection.', 'zwqoi' );
	}

	public function add_help_tab() {
		$screen = get_current_screen();

		$screen->add_help_tab( array(
			'id'      => 'zwqoi-help',
			'title'   => __( 'QuickBooks Woo Integration', 'zwqoi' ),
			'content' => '<p>' . __( 'For more documentation, visit the <a href="https://github.com/zao-web/zao-woocommerce-quickbooks-online-integration/wiki">Zao WooCommerce QuickBooks Online Integration wiki</a>.', 'zwqoi' ) . '</p>',
		) );
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
			// 'title'     => esc_html__( 'Zao WooCommerce QuickBooks Integration Settings', 'zwqoi' ),
			'object_types' => array( 'options-page' ),
			'option_key'   => self::KEY,
			'icon_url'     => 'dashicons-book-alt',
			'menu_title'   => esc_html__( 'QB Woo Integration', 'zwqoi' ),
			'parent_slug'  => 'options-general.php',
			'display_cb'   => array( $this, 'options_page_output' ),
			'show_on_cb'   => array( __CLASS__, 'token_acquired' ),
			'capability'   => apply_filters( 'zwqoi_search_page_permission_level', 'edit_pages', $this ), // Cap required to view options-page.
		) );

		$this->cmb->add_field( array(
			'show_on_cb' => array( __CLASS__, 'has_company_name' ),
			'id'         => 'company-title',
			'name'       => __( 'Connected Company:', 'zwqoi' ),
			'type'       => 'title',
		) );

		$this->cmb->add_field( array(
			'name'       => __( 'Enable invoice creation for every order', 'zwqoi' ),
			'after'       => '<p class="cmb2-metabox-description">' . __( 'If enabled, all orders will generate a corresponding QuickBooks Invoice, and auto-create an associated QuickBooks Customer from the order customer.', 'zwqoi' )  . '</p><p>' . __( '<strong>WARNING:</strong> this could cause severe performance bottlenecks for stores with heavy order-volume. It is only recommended you check this if you have a low order-volume store.', 'zwqoi' )  . '</p><p class="cmb2-metabox-description">' . __( 'It is instead recommended to use the <code>zwqoi_sync_invoice_from_order</code> filter to conditionally enable on a per order basis.', 'zwqoi' )  . '</p>',
			'id'         => 'invoice_all_orders',
			'type'       => 'checkbox',
		) );

		$this->cmb->add_field( array(
			// Only show this field if it is necessary.. if there is more than one account in this category.
			'show_on_cb' => array( $this, 'field_has_multiple_type_accounts' ),
			'id'         => 'inventory_asset_account',
			'name'       => __( 'Inventory asset account for Items', 'zwqoi' ),
			'desc'       => __( 'Select the Quickbooks Inventory asset account which will associate with any WordPress Products that are syncronized to Quickbooks.', 'zwqoi' ),
			'type'       => 'select',
			'options_cb' => array( $this, 'get_field_accounts' )
		) );

		$this->cmb->add_field( array(
			// Only show this field if it is necessary.. if there is more than one account in this category.
			'show_on_cb' => array( $this, 'field_has_multiple_type_accounts' ),
			'id'         => 'income_account',
			'name'       => __( 'Income account for Items', 'zwqoi' ),
			'desc'       => __( 'Select the Quickbooks Income account which will associate with any WordPress Products that are syncronized to Quickbooks.', 'zwqoi' ),
			'type'       => 'select',
			'options_cb' => array( $this, 'get_field_accounts' )
		) );

		$this->cmb->add_field( array(
			// Only show this field if it is necessary.. if there is more than one account in this category.
			'show_on_cb' => array( $this, 'field_has_multiple_type_accounts' ),
			'id'         => 'expense_account',
			'name'       => __( 'Expense account for Items', 'zwqoi' ),
			'desc'       => __( 'Select the Quickbooks Expense account which will associate with any WordPress Products that are syncronized to Quickbooks.', 'zwqoi' ),
			'type'       => 'select',
			'options_cb' => array( $this, 'get_field_accounts' )
		) );

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

		$this->cmb->add_field( array(
			'show_on_cb' => array( __CLASS__, 'has_wholesale_plugin' ),
			'name'       => __( 'Enable invoice creation for wholesale orders only', 'zwqoi' ),
			'desc'       => __( 'Overrides the "Enable invoice creation for every order" setting above.', 'zwqoi' ),
			'id'         => 'disable_non_wholesale_invoices',
			'type'       => 'checkbox',
		) );
	}

	public static function get_accounts() {
		$inventory_asset = self::get_type_account( 'inventory_asset_account' );
		$income          = self::get_type_account( 'income_account' );
		$expense         = self::get_type_account( 'expense_account' );

		if ( ! $inventory_asset || ! $income || ! $expense ) {
			return false;
		}

		return compact( 'inventory_asset', 'income', 'expense' );
	}

	public static function get_type_account( $field_id ) {
		$account = false;
		if ( self::get_option( $field_id ) ) {
			$account = self::get_option( $field_id );
		}

		// If multiple, but option is not set, it means we should not default to one.
		if ( ! $account && self::has_multiple_type_accounts( $field_id ) ) {
			return false;
		}

		$accounts = self::get_accounts_of_type( $field_id, false );
		if ( ! empty( $accounts ) ) {
			$account = key( $accounts );
		}

		if ( $account ) {
			list( $Id, $Name ) = explode( ':', $account );
			return (object) compact( 'Id', 'Name' );
		}

		return false;
	}

	public static function field_has_multiple_type_accounts( $field ) {
		return self::has_multiple_type_accounts( $field->id() );
	}

	public static function has_multiple_type_accounts( $field_id ) {
		return count( self::get_accounts_of_type( $field_id, false ) ) > 1;
	}

	public static function get_field_accounts( $field ) {
		return self::get_accounts_of_type( $field->id(), true );
	}

	public static function get_accounts_of_type( $field_id, $include_default_option = true ) {
		$options = get_transient( "zwqoi_{$field_id}s" );
		if ( empty( $options ) || parent::should_refresh_cache() ) {
			$options = array();

			try {
				$accounts = self::fetch_accounts_of_type( $field_id, false );

				if ( ! empty( $accounts ) ) {
					foreach ( $accounts as $account ) {
						if ( isset( $account->Name, $account->Id ) ) {
							$options[ esc_attr( $account->Id . ':' . $account->Name ) ] = $account->Name;
						}
					}
				}

				set_transient( "zwqoi_{$field_id}s", $options, DAY_IN_SECONDS );

			} catch ( \Exception $e ) {}
		}

		if ( false !== $include_default_option ) {
			$options = array(
				'' => __( 'Do not syncronize any products to Quickbooks', 'zwqoi' ),
			) + $options;
		}

		return $options;
	}

	public static function fetch_accounts_of_type( $field_id ) {
		$accounts = array();

		switch ( $field_id ) {

			case 'inventory_asset_account';
				$accounts = self::fetch_query(
					"SELECT * FROM Account WHERE AccountType = 'Other Current Asset' maxresults 300"
				);

				if ( ! empty( $accounts ) ) {
					$accounts = wp_filter_object_list( $accounts, array( 'AccountSubType' => 'Inventory' ) );
				}
				break;

			case 'income_account';
				$accounts = self::fetch_query(
					"SELECT * FROM Account WHERE AccountSubType = 'SalesOfProductIncome' maxresults 300"
				);
				break;

			case 'expense_account';
				$accounts = self::fetch_query(
					"SELECT * FROM Account WHERE AccountType = 'Cost of Goods Sold' maxresults 300"
				);
				break;

		}

		return $accounts;
	}

	public static function fetch_query( $query ) {
		try {
			$accounts = self::get_service()->query( $query );
		} catch ( \Exception $e ) {
			$accounts = array();
		}

		if ( ! is_array( $accounts ) ) {
			$accounts = array();
		}

		return $accounts;
	}

	public static function has_company_name( $field ) {
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

	public static function get_service() {
		static $service = null;
		if ( null === $service ) {
			$service = qbo_connect_ui()->api->get_qb_data_service();
		}

		return $service;
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

