<?php
namespace Zao\WC_QBO_Integration;

class Plugin extends Base {

	protected static $single_instance = null;
	protected $customers;
	protected $products;
	protected $invoices;
	protected $users;
	protected $wc_products;
	protected $settings;

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return Plugin A single instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}

	protected function __construct() {
		$this->customers = new Services\Customers();
		$this->products = new Services\Products();
		$this->invoices = new Services\Invoices();
		$this->users = new Admin\Users( $this->customers );
		$this->wc_products = new Admin\Products( $this->products );
		$this->settings = new Admin\Settings();
	}

	public function init() {
		add_action( 'qbo_connect_initiated', array( $this, 'api_init' ) );
		$this->customers->init();
		$this->products->init();
		$this->invoices->init();
		$this->users->init();
		$this->wc_products->init();
		$this->settings->init();
	}

	public function api_init( $api ) {
		Services\Base::set_api( $api );
	}

}
