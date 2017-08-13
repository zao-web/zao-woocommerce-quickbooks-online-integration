<?php
namespace Zao\WC_QBO_Integration;

class Plugin extends Base {

	protected static $single_instance = null;

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
	}

	public function init() {
	}

}
