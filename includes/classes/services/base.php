<?php
namespace Zao\WC_QBO_Integration\Services;

use Zao\QBO_API\Service;
use QuickBooksOnline\API;

abstract class Base extends Service {

	protected static $api;
	protected static $api_args;

	abstract public function init();
	abstract public function create( $args );

	public static function set_api( $api ) {
		self::$api = $api;
		self::$api_args = self::$api->get_qb_data_service_args();
	}

	public function __construct() {}

	public function get_service( $reset = false ) {
		parent::__construct( self::$api_args );
		return parent::get_service( $reset );
	}

	public static function get_value_from_object( $object, $properties_to_check ) {
		$value = '';

		foreach ( $properties_to_check as $prop ) {
			// if prop is array, we want to concatenate the results.
			if ( is_array( $prop ) ) {
				$value_arr = array();
				foreach ( $prop as $prop_name ) {
					if ( isset( $object->{$prop_name} ) ) {
						$value_arr[] = $object->{$prop_name};
					}
				}

				$value_arr = array_filter( $value_arr );

				if ( ! empty( $value_arr ) ) {
					$value = implode( ' ', $value_arr );
					break;
				}

			} elseif ( ! empty( $object->{$prop} ) ) {
				// Otherwise, we found the property we want.
				$value = $object->{$prop};
				break;
			}
		}

		return $value ? $value : 'unknown';
	}

	protected function get_error() {
		$error = $this->get_service()->getLastError();
		return $error ? $error : false;
	}

	/*
	 * Utilities
	 */

	public static function redirect( $url, $safe = true ) {
		call_user_func( $safe ? 'wp_safe_redirect' : 'wp_redirect', $url );
		exit;
	}

	public static function company_name() {
		if ( self::$api ) {
			return self::get_value_from_object( self::$api->get_company_info(), array(
				'CompanyName',
				'LegalName',
				'Id',
			) );
		}

		return '';
	}

	public static function _param( $param, $default = '' ) {
		return isset( $_REQUEST[ $param ] ) ? $_REQUEST[ $param ] : $default;
	}

	public static function _param_is( $param, $val_to_check ) {
		return isset( $_REQUEST[ $param ] ) && $val_to_check === $_REQUEST[ $param ];
	}

	protected function die_if_error() {
		$error = $this->get_error();

		if ( $error ) {
			if ( ! self::is_fault_handler( $error ) ) {
				wp_die( '<xmp>'. __LINE__ .') $error: '. print_r( $error, true ) .'</xmp>' );
			}

			$msg = self::fault_handler_error_output( $error );
			$msg .= "<p>Full Error Object:</p>";
			$msg .= '<xmp>'. print_r( $error, true ) .'</xmp>';

			wp_die( $msg, 'Quickbooks API Error' );
		}
	}

	public static function fault_handler_error_output( $error ) {
		$msg = '';

		if ( self::is_fault_handler( $error ) ) {
			$msg .= '<br>' . sprintf( __( 'The Status code is: %s', 'zwqoi' ), $error->getHttpStatusCode() ) . "\n";
			$msg .= '<br>' . sprintf( __( 'The Helper message is: %s', 'zwqoi' ), $error->getOAuthHelperError() ) . "\n";
			$msg .= '<br>' . sprintf( __( 'The Response message is: %s', 'zwqoi' ), $error->getResponseBody() ) . "\n";
		}

		return $msg;
	}

	public static function is_fault_handler( $error ) {
		return $error instanceof API\Core\HttpClients\FaultHandler;
	}

}
