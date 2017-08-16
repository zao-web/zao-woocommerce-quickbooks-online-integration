<?php
namespace Zao\WC_QBO_Integration\Services;

use QuickBooksOnline\API;

class Customers extends Base {

	protected static $admin_page_slug = 'qbo-customer-search';
	protected $search_results = array();
	protected $results_count = 0;

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_qb_customer_search_page' ), 999 );

		if ( self::is_importing_customer() ) {
			add_action( 'qbo_connect_initiated', array( $this, 'maybe_import_customer_or_update_user' ), 99 );
		}

		if ( self::settings_updated() ) {
			self::add_import_success_notice( absint( $_GET['settings-updated'] ) );
		}
	}

	public function register_qb_customer_search_page() {
		add_users_page(
			__( 'QuickBooks Customer Search', 'zwqoi' ),
			__( 'QuickBooks Customers', 'zwqoi' ),
			'manage_options',
			self::$admin_page_slug,
			array( $this, 'customer_search_page' )
		);
	}

	public function customer_search_page() {
		include_once ZWQOI_INC . 'views/customer-search-page.php';
		do_action( 'zwqoi_customer_search_page', $this );
	}

	public function maybe_import_customer_or_update_user() {
		$result = ! empty( $_GET['update_user'] )
			? $this->update_user_with_customer( $_GET['update_user'], $_GET['import_customer'] )
			: $this->import_customer( $_GET['import_customer'] );

		if ( ! is_wp_error( $result ) ) {
			$args = array( 'settings-updated' => $result );

			if ( self::_param( 'redirect' ) ) {
				$args['redirect'] = self::_param( 'redirect' );
			}

			wp_safe_redirect( add_query_arg( $args, self::settings_url() ) );
			exit;
		}

		$error    = $result->get_error_data();
		$msg      = $result->get_error_message();
		$err_type = 'error';

		if ( $error instanceof API\Core\HttpClients\FaultHandler ) {
			$msg .= '<br>' . sprintf( __( 'The Status code is: %s', 'zwqoi' ), $error->getHttpStatusCode() ) . "\n";
			$msg .= '<br>' . sprintf( __( 'The Helper message is: %s', 'zwqoi' ), $error->getOAuthHelperError() ) . "\n";
			$msg .= '<br>' . sprintf( __( 'The Response message is: %s', 'zwqoi' ), $error->getResponseBody() ) . "\n";
		}

		if ( $error instanceof \WP_User ) {
			$err_type = 'notice-warning';
			$msg .= '<br>' . self::update_quickbooks_user_button( $error->ID, absint( $_GET['import_customer'] ) ) . "\n";
		}

		self::add_settings_notice( $msg, $err_type );
	}

	public function get_by_id( $customer_id ) {
		$customer_id = absint( $customer_id );
		if ( empty( $customer_id ) ) {
			return false;
		}

		$customer = $this->query(
			"SELECT * FROM Customer WHERE Id LIKE '{$customer_id}'"
		);

		$error = $this->get_error();

		if ( $error ) {
			return new \WP_Error(
				'zwqoi_customer_get_by_id_error',
				sprintf( __( 'There was an error importing this user: %d', 'zwqoi' ), $customer_id ),
				$error
			);
		}

		return is_array( $customer ) ? end( $customer ) : $customer;
	}

	public function import_customer( $customer_id ) {
		$customer = $this->get_by_id( $customer_id );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$company_name = $this->get_customer_company_name( $customer, false );

		$user = self::get_user_by_customer_id( $customer->Id );

		if ( ! empty( $user ) ) {
			return $this->found_user_error(
				__( 'A user has already been mapped to this QuickBooks Customer: %s', 'zwqoi' ),
				$company_name,
				$user
			);
		}

		if ( ! empty( $customer->PrimaryEmailAddr->Address ) ) {
			$user = get_user_by( 'email', $customer->PrimaryEmailAddr->Address );
			if ( $user ) {
				return $this->found_user_error(
					__( 'A user already exists with this email: %s', 'zwqoi' ),
					$customer->PrimaryEmailAddr->Address,
					$user
				);
			}
		}

		$company_slug = preg_replace( '/\s+/', '', sanitize_user( $company_name, true ) );

		$user = get_user_by( 'login', $company_slug );
		if ( $user ) {
			return $this->found_user_error(
				__( 'A user already exists with this username: %s', 'zwqoi' ),
				$company_slug,
				$user
			);
		}

		$email = $company_slug . '@example.com';
		if ( ! empty( $customer->PrimaryEmailAddr->Address ) ) {
			$email = sanitize_text_field( $customer->PrimaryEmailAddr->Address );
		}

		$user_id = wp_create_user( $company_slug, wp_generate_password(), $email );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return $this->update_user_with_customer( $user_id, $customer );
	}

	public function found_user_error( $message_format, $link_text, \WP_User $user ) {
		$link = get_edit_user_link( $user->ID );

		return new \WP_Error(
			'zwqoi_customer_import_error',
			sprintf( $message_format, '<a href="' . $link . '">' . $link_text . '</a>' ),
			$user
		);
	}

	public function update_user_with_customer( $user_id, $customer_id ) {
		$user = $user_id instanceof \WP_User ? $user_id : get_user_by( 'id', absint( $user_id ) );

		if ( ! $user ) {
			return new \WP_Error(
				'zwqoi_update_user_with_customer_error',
				sprintf( __( 'Not able to find the WordPress user with this ID: %s', 'zwqoi' ), $user_id )
			);
		}

		$customer = $customer_id instanceof API\Data\IPPCustomer ? $customer_id : $this->get_by_id( $customer_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$company_name = $this->get_customer_company_name( $customer, false );

		$args = array(
			'ID'            => $user->ID,
			'user_nicename' => ! empty( $customer->CompanyName ) ? $customer->CompanyName : $company_name,
			'display_name'  => ! empty( $customer->DisplayName ) ? $customer->DisplayName : $company_name,
			'nickname'      => ! empty( $customer->AltContactName ) ? $customer->AltContactName : $company_name,
			'first_name'    => ! empty( $customer->GivenName ) ? $customer->GivenName : $company_name,
			'company'       => $company_name,
		);

		if ( ! empty( $customer->WebAddr ) ) {
			$args['user_url'] = sanitize_text_field( $customer->WebAddr );
		}

		if ( ! empty( $customer->PrimaryEmailAddr->Address ) ) {
			$args['user_email'] = sanitize_text_field( $customer->PrimaryEmailAddr->Address );
		}

		if ( ! empty( $customer->Notes ) ) {
			$args['description'] = sanitize_text_field( $customer->Notes );
		}

		if ( ! empty( $customer->FamilyName ) ) {
			$args['last_name'] = sanitize_text_field( $customer->FamilyName );
		}

		// Update woo first or else the display_name gets improperly overwritten.
		$this->update_woo_customer( $user->ID, $args, $customer );

		$updated = wp_update_user( $args );

		if ( $updated && ! is_wp_error( $updated ) ) {
			update_user_meta( $updated, '_qb_customer_id', $customer->Id );
		}

		return $updated;
	}

	public function update_woo_customer( $user_id, $user_args, API\Data\IPPCustomer $customer ) {
		$wc_user = new \WC_Customer( $user_id );

		$parts = array(
			'first_name' => 'set_billing_first_name',
			'last_name'  => 'set_billing_last_name',
			'user_email' => 'set_billing_email',
			'company'    => 'set_billing_company',
		);

		foreach ( $parts as $part => $cb ) {
			if ( ! empty( $user_args[ $part ] ) ) {
				call_user_func( array( $wc_user, $cb ), $user_args[ $part ] );
			}
		}

		if ( ! empty( $customer->BillAddr ) ) {
			$parts = array(
				'Line1'                  => 'set_billing_address_1',
				'City'                   => 'set_billing_city',
				'Country'                => 'set_billing_country',
				'PostalCode'             => 'set_billing_postcode',
			);
			foreach ( $parts as $part => $cb ) {
				if ( ! empty( $customer->BillAddr->{$part} ) ) {
					call_user_func( array( $wc_user, $cb ), $customer->BillAddr->{$part} );
				}
			}

			$addr_2 = '';
			foreach ( array( 'Line2', 'Line3', 'Line4', 'Line5', ) as $part ) {
				if ( ! empty( $customer->BillAddr->{$part} ) ) {
					$addr_2 .= $customer->BillAddr->{$part};
				}
			}

			if ( ! empty( $addr_2 ) ) {
				$wc_user->set_billing_address_2( $addr_2 );
			}
		}

		if ( ! empty( $customer->PrimaryPhone->FreeFormNumber ) ) {
			$wc_user->set_billing_phone( $customer->PrimaryPhone->FreeFormNumber );
		}

		if ( ! empty( $customer->BillAddr->CountrySubDivisionCode ) ) {

			$state = $customer->BillAddr->CountrySubDivisionCode;

			$countrycode = ! empty( $customer->BillAddr->CountryCode ) ? $customer->BillAddr->CountryCode : 'US';
			$states = WC()->countries->get_states( $countrycode );

			if ( isset( $states[ $state ] ) ) {
				$wc_user->set_billing_country( $countrycode );
				$wc_user->set_billing_state( $state );
			}

			$wc_user->set_billing_state( $state );
		}

		return $wc_user->save();
	}

	public function has_search() {
		if (
			! isset( $_POST['search_term'], $_POST[ self::$admin_page_slug ] )
			|| ! wp_verify_nonce( $_POST[ self::$admin_page_slug ], self::$admin_page_slug )
		) {
			return false;
		}

		$this->set_search_results_from_query();

		return true;
	}

	protected function output_result_item( $item ) {
		$html = '';
		if ( 'error' === $item['id'] ) {
			$html .= '<li class="error">' . $item['name'] . '</li>';
		} elseif ( ! empty( $item['taken'] ) ) {
			$user_edit_link = '<a href="' . get_edit_user_link( $item['taken'] ) . '">' . get_user_by( 'id', $item['taken'] )->display_name . '</a>';
			$html .= '<li><strike>' . $item['name'] . '</strike> ' . sprintf( esc_attr__( 'This Customer is already associated to %s', 'zwqoi' ), $user_edit_link ) . '</li>';
		} else {
			$html .= '<li><span class="dashicons dashicons-download"></span> <a href="' . esc_url( self::import_customer_url( $item['id'] ) ) . '">' . $item['name'] . '</a></li>';
		}

		return apply_filters( 'zwqoi_output_search_result_item', $html, $item );
	}

	protected function set_search_results_from_query() {
		$this->search_results = self::search_results(
			wp_unslash( $_POST['search_term'] ),
			self::_param_is( 'search_type', 'id' ) ? 'id' : 'company_name'
		);

		$this->results_count = count( $this->search_results );
		if ( 1 === $this->results_count && 'error' === $this->search_results[0]['id'] ) {
			$this->results_count = 0;
		}

		return $this->search_results;
	}

	public function search_results( $company_search = '', $search_type = 'company_name' ) {
		global $wpdb;

		$results = array();

		$company_search = 'company_name' === $search_type ? sanitize_text_field( $company_search ) : absint( $company_search );
		if ( empty( $company_search ) ) {
			return $results;
		}

		try {
			$query = $wpdb->prepare(
				'company_name' === $search_type
					? "SELECT * FROM Customer WHERE CompanyName = %s"
					: "SELECT * FROM Customer WHERE Id = %s",
				$company_search
			);

			$customers = $this->query( $query );

			$error = $this->get_error();

			if ( $error ) {

				$results[] = array(
					'id'   => 'error',
					'name' => $error->getOAuthHelperError(),
				);
			} else {
				if ( ! empty( $customers ) ) {
					$users = self::get_users_by_customer_ids( wp_list_pluck( $customers, 'Id' ) );
					$existing = array();
					if ( ! empty( $users ) ) {
						foreach ( (array) $users as $user ) {
							$existing[ $user->_qb_customer_id ] = $user->ID;
						}
					}

					foreach ( (array) $customers as $customer ) {
						if ( isset( $customer->Id ) ) {
							$results[] = array(
								'taken' => isset( $existing[ $customer->Id ] ) ? $existing[ $customer->Id ] : false,
								'id'   => $customer->Id,
								'name' => $this->get_customer_company_name( $customer ),
							);
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			$results[] = array(
				'id'   => 'error',
				'name' => $e->getMessage(),
			);
		}

		if ( empty( $results ) ) {
			$results[] = array(
				'id'   => 'error',
				'name' => __( 'No results for this search.', 'zwqoi' ),
			);
		}

		return $results;
	}

	public function company_name() {
		return self::get_value_from_object( self::$api->get_company_info(), array(
			'CompanyName',
			'LegalName',
			'Id',
		) );
	}

	public function get_customer_company_name( $customer, $with_contact = true ) {
		$name = self::get_value_from_object( $customer, array(
			'CompanyName',
			'DisplayName',
			'FullyQualifiedName',
			'PrintOnCheckName',
			'Id',
		) );

		if ( $with_contact ) {
			$contact_name = self::get_value_from_object( $customer, array(
				array( 'GivenName', 'MiddleName', 'FamilyName' ),
			) );

			$name .= ' (' . $contact_name . ')';
		}

		return $name;
	}

	public static function add_import_success_notice( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( isset( $user->ID ) ) {
			$link = get_edit_user_link( $user->ID );

			$msg = sprintf( __( 'Success! %s imported.', 'zwqoi' ), '<a href="' . $link . '">' . $user->display_name . '</a>' ) . "\n";

			self::add_settings_notice( $msg, 'updated' );
		}
	}

	/*
	 * Utilities
	 */

	public static function add_settings_notice( $msg, $type ) {
		return add_settings_error(
			self::$admin_page_slug . '-notices',
			'import-' . $type,
			$msg,
			$type
		);
	}

	public static function settings_updated() {
		return self::is_admin_page()
			&& isset( $_GET['settings-updated'] )
			&& is_numeric( $_GET['settings-updated'] );
	}

	public static function is_importing_customer() {
		return self::is_admin_page()
			&& isset( $_GET['import_customer'], $_GET['nonce'] )
			&& is_numeric( $_GET['import_customer'] )
			&& wp_verify_nonce( $_GET['nonce'], self::$admin_page_slug );
	}

	public static function is_admin_page() {
		return self::_param_is( 'page', self::$admin_page_slug );
	}

	public static function settings_url( $args = array() ) {
		$url = admin_url( 'users.php?page=' . self::$admin_page_slug );

		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}

	public static function update_quickbooks_user_button( $user_id, $customer_id, $query_args = array() ) {
		return '<a class="button-secondary update-user-from-qb" onclick="return confirm(\'' . esc_attr__( 'This will replace the WordPress user data with the QuickBooks Customer data. Are you sure you want to proceed?', 'zwqoi' ) . '\')" href="' . esc_url( self::update_user_url( $user_id, $customer_id, $query_args ) ) . '">' . __( 'Update user from QuickBooks', 'zwqoi' ) . '</a>';
	}

	public static function update_user_url( $user_id, $customer_id, $query_args = array() ) {
		$query_args['update_user'] = $user_id;

		return add_query_arg( $query_args, self::import_customer_url( $customer_id ) );
	}

	public static function import_customer_url( $customer_id ) {
		return wp_nonce_url( self::settings_url( array( 'import_customer' => $customer_id ) ), self::$admin_page_slug, 'nonce' );
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

	public static function get_user_by_customer_id( $customer_id ) {
		$args = array(
			'meta_key'      => '_qb_customer_id',
			'meta_value'    => $customer_id,
			'number'        => 1,
			'no_found_rows' => true,
		);

		$by_id = new \WP_User_Query( $args );

		$results = $by_id->get_results();

		if ( empty( $results ) ) {
			return false;
		}

		return is_array( $results ) ? end( $results ) : $results;
	}

}
