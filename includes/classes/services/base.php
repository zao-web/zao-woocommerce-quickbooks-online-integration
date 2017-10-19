<?php
namespace Zao\WC_QBO_Integration\Services;

use Zao\QBO_API\Service, Zao\WC_QBO_Integration\Base_Trait, QuickBooksOnline\API, WP_Query;

abstract class Base extends Service {
	use Base_Trait;

	protected static $api;
	protected static $api_args;
	protected $post_type = '';
	protected $meta_key  = '';

	abstract public function init();

	/*
	 * Abstract methods
	 */

	abstract protected function search_query_format( $search_type );
	abstract public function get_by_id_error( $error, $qb_id );
	abstract public function is_wp_object( $object );
	abstract public function get_wp_object( $wp_id );
	abstract public function get_wp_id( $object );
	abstract public function get_wp_name( $object );
	abstract public function create( $args );
	abstract public function update( $object, $args );
	abstract protected function qb_object_args( $wp_object );
	abstract public function update_connected_qb_id( $wp_id, $meta_value );
	abstract public function create_qb_object_from_wp_object( $wp_object );
	abstract public function get_qb_object_name( $qb_object );

	public static function set_api( $api ) {
		self::$api = $api;
		self::$api_args = self::$api->get_qb_data_service_args();
	}

	public function __construct() {
		add_action( 'zao_qbo_api_connect_updated_args', array( $this, 'update_api_args' ), 55 );
	}

	public function update_api_args( $args ) {
		self::$api_args = $args;
	}

	public function get_service( $reset = false ) {
		parent::update_args( self::$api_args );
		return parent::get_service( $reset );
	}

	public static function get_value_from_object( $object, $properties_to_check, $fallback = 'unknown' ) {
		$value = '';

		foreach ( (array) $properties_to_check as $prop ) {
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

		return $value ? $value : $fallback;
	}

	protected function get_error() {
		if ( is_wp_error( self::$api->get_company_info() ) ) {
			return false;
		}

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
		$info = self::$api->get_company_info();

		if ( ! is_wp_error( $info ) ) {
			return self::get_value_from_object( $info, array(
				'CompanyName',
				'LegalName',
				'Id',
			) );
		}

		return '';
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

			$intuit_errors = array(
				__( 'Intuit Error Type', 'zwqoi' )    => $error->getIntuitErrorType(),
				__( 'Intuit Error Code', 'zwqoi' )    => $error->getIntuitErrorCode(),
				__( 'Intuit Error Element', 'zwqoi' ) => is_callable( array( $error, 'getIntuitErrorElement' ) ) ? $error->getIntuitErrorElement() : null,
				__( 'Intuit Error Message', 'zwqoi' ) => $error->getIntuitErrorMessage(),
				__( 'Intuit Error Detail', 'zwqoi' )  => $error->getIntuitErrorDetail(),
			);
			foreach ( array_filter( $intuit_errors ) as $label => $error_msg ) {
				$msg .= '<br>' . sprintf( __( 'The %s is: %s', 'zwqoi' ), $label, $error_msg ) . "\n";
			}
		}

		return $msg;
	}

	public static function is_fault_handler( $error ) {
		return $error instanceof API\Core\HttpClients\FaultHandler;
	}

	public function get_by_id( $qb_id ) {
		static $objects = array();

		$key = get_class( $this ) . $qb_id;
		if ( isset( $objects[ $key ] ) ) {
			return $objects[ $key ];
		}

		if ( ! is_object( self::$api ) || is_wp_error( self::$api->get_company_info() ) ) {
			return $this->get_by_id_error( null, $qb_id );
		}

		global $wpdb;

		$qb_id = absint( $qb_id );
		if ( empty( $qb_id ) ) {
			return false;
		}

		$query  = $wpdb->prepare( $this->search_query_format( 'id' ), $qb_id );
		$result = $this->query( $query );
		$error  = $this->get_error();

		if ( $error ) {
			$objects[ $key ] = $this->get_by_id_error( $error, $qb_id );
		} else {
			$objects[ $key ] = is_array( $result ) ? end( $result ) : $result;
		}

		return $objects[ $key ];
	}

	public function query_wp_by_qb_id( $qb_id ) {
		$args = array(
			'meta_key'      => $this->meta_key,
			'meta_value'    => $qb_id,
			'number'        => 1,
			'no_found_rows' => true,
			'post_type'     => $this->post_type,
		);

		$by_id = new WP_Query( $args );

		if ( empty( $by_id->posts ) ) {
			return false;
		}

		return is_array( $by_id->posts ) ? end( $by_id->posts ) : $by_id->posts;
	}

	public function query_wp_by_qb_ids( $qb_ids, $key_value = true ) {
		$args = array(
			'meta_query' => array(
				array(
					'key'     => $this->meta_key,
					'value'   => (array) $qb_ids,
					'compare' => 'IN',
				),
			),
			'number'        => count( $qb_ids ),
			'no_found_rows' => true,
			'post_type'     => $this->post_type,
		);

		$by_id = new WP_Query( $args );

		if ( empty( $by_id->posts ) ) {
			return false;
		}

		if ( ! $key_value ) {
			return $by_id->posts;
		}

		$existing = array();
		foreach ( $by_id->posts as $product ) {
			$existing[ $product->{$this->meta_key} ] = $product->ID;
		}

		return $existing;
	}

	public function get_wp_edit_url( $wp_id ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		return get_edit_post_link( $wp_id, 'edit' );
	}

	public function disconnect_qb_object( $wp_id ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		return delete_post_meta( $wp_id, $this->meta_key );
	}

	public function get_connected_qb_id( $wp_id ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		return get_post_meta( $wp_id, $this->meta_key, true );
	}

}
