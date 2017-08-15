<?php
namespace Zao\WC_QBO_Integration\Services;
use Zao\QBO_API\Service;

class Base extends Service {

	protected static $api;
	protected static $api_args;

	public static function set_api( $api ) {
		self::$api = $api;
		self::$api_args = self::$api->get_qb_data_service_args();
	}

	public function __construct() {}

	public function get_service( $reset = false ) {
		parent::__construct( self::$api_args );
		return parent::get_service( $reset );
	}

	protected function get_error() {
		$error = $this->get_service()->getLastError();
		return $error ? $error : false;
	}

	protected function die_if_error() {
		$error = $this->get_error();

		if ( $error ) {
			$msg = '';
			$msg .= "<p>The Status code is: " . $error->getHttpStatusCode() . "\n</p>";
			$msg .= "<p>The Helper message is: " . $error->getOAuthHelperError() . "\n</p>";
			$msg .= "<p>The Response message is: " . $error->getResponseBody() . "\n</p>";
			$msg .= "<p>Full Error Object:</p>";
			$msg .= '<xmp>'. print_r( $error, true ) .'</xmp>';

			wp_die( $msg, 'Quickbooks API Error' );
		}
	}

	public static function _param( $param, $default = '' ) {
		return isset( $_REQUEST[ $param ] ) ? $_REQUEST[ $param ] : $default;
	}

	public static function _param_is( $param, $val_to_check ) {
		return isset( $_REQUEST[ $param ] ) && $val_to_check === $_REQUEST[ $param ];
	}
}
