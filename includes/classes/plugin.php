<?php
namespace Zao\WC_QBO_Integration;

class Plugin extends Base {

	protected static $single_instance = null;
	protected $customers;
	protected $users;
	protected $invoices;

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
		$this->invoices = new Services\Invoices();
		$this->users = new Users( $this->customers );
	}

	public function init() {
		add_action( 'qbo_connect_initiated', array( $this, 'api_init' ) );
		$this->customers->init();
		$this->invoices->init();
		$this->users->init();
	}

	public function api_init( $api ) {
		Services\Base::set_api( $api );
	}

}
