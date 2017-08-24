<?php
namespace Zao\WC_QBO_Integration\Services;

use QuickBooksOnline\API;

class Customers extends UI_Base {

	protected $admin_page_slug  = 'qbo-customer-search';
	protected $update_query_var = 'update_user';
	protected $import_query_var = 'import_customer';
	protected $meta_key         = '_qb_customer_id';

	public function init() {
		parent::init();
		add_filter( 'zwqoi_settings_nav_links', array( $this, 'add_nav_link' ), 6 );
		add_action( 'zwqoi_search_page_form_search_types', array( $this, 'add_email_search' ) );
	}

	public function add_email_search( $service ) {
		if ( $service === $this ) {
			?>
			&nbsp;
			<label><input type="radio" name="search_type" value="email" <?php checked( self::_param_is( 'search_type', 'email' ) ); ?>/> <?php _e( 'Contact Email', 'zwqoi' ); ?></label>
			<?php
		}
	}

	public function add_nav_link( $links ) {
		$links[] = array(
			'url'    => $this->admin_page_url(),
			'active' => $this->is_on_admin_page(),
			'text'   => esc_html__( 'Customer Search', 'zwqoi' ),
		);

		return $links;
	}

	public function parent_slug() {
		return 'users.php';
	}

	public function search_page() {
		parent::search_page();
		do_action( 'zwqoi_customer_search_page', $this );
	}

	public function validate_qb_object( $qb_object, $force = false ) {
		$company_name = self::get_customer_company_name( $qb_object, false );

		$user = $this->query_wp_by_qb_id( $qb_object->Id );

		if ( ! empty( $user ) ) {
			return $this->found_user_error(
				__( 'A user has already been mapped to this QuickBooks Customer: %s', 'zwqoi' ),
				$company_name,
				$user
			);
		}

		if ( ! empty( $qb_object->PrimaryEmailAddr->Address ) ) {
			$user = get_user_by( 'email', $qb_object->PrimaryEmailAddr->Address );
			if ( $user ) {
				return $this->found_user_error(
					__( 'A user already exists with this email: %s', 'zwqoi' ),
					$qb_object->PrimaryEmailAddr->Address,
					$user
				);
			}
		}

		$company_slug = preg_replace( '/\s+/', '', sanitize_user( $company_name, true ) );

		if ( ! $force ) {
			$user = get_user_by( 'login', $company_slug );
			if ( $user ) {
				return $this->found_user_error(
					__( 'A user already exists with this username: %s', 'zwqoi' ),
					$company_slug,
					$user
				);
			}
		}

		return $company_slug;
	}

	protected function import_qb_object( $qb_object ) {
		$company_name = self::get_customer_company_name( $qb_object, false );
		$company_slug = preg_replace( '/\s+/', '', sanitize_user( $company_name, true ) );

		$email = ! empty( $qb_object->PrimaryEmailAddr->Address )
			? sanitize_text_field( $qb_object->PrimaryEmailAddr->Address )
			: $company_slug . '@example.com';

		$userdata = array(
			'user_login' => wp_slash( $company_slug ),
			'user_email' => wp_slash( $email ),
			'user_pass'  => wp_generate_password(),
		);

		$role_for_customer_user = apply_filters( 'zwqoi_role_for_customer_user', '' );
		if ( get_role( $role_for_customer_user ) ) {
			$userdata['role'] = $role_for_customer_user;
		}

		return wp_insert_user( $userdata );
	}

	public function create_qb_object_from_wp_object( $wp_object ) {
		$user = $this->get_wp_object( $wp_object );
		if ( ! $user ) {
			return false;
		}

		$args    = $this->qb_object_args( $user );
		$results = $this->create( $args );
		$error   = $this->get_error();

		if ( $error ) {
			return new WP_Error(
				'zwqoi_customer_create_error',
				sprintf( __( 'There was an error creating a customer for this user: %d', 'zwqoi' ), $this->get_wp_id( $user ) ),
				$error
			);
		}

		if ( isset( $results[1]->Id ) ) {
			$this->update_connected_qb_id( $user, $results[1]->Id );
		}

		return $results[1];
	}

	public function update_qb_object_with_wp_object( $qb_object, $wp_object ) {
		$customer = $qb_object instanceof API\Data\IPPCustomer ? $qb_object : $this->get_by_id( $qb_object );
		$user     = $this->get_wp_object( $wp_object );

		if ( ! $user || ! $customer ) {
			return false;
		}

		$args = $this->qb_object_args( $user );

		$args['sparse'] = true;

		$result = $this->update( $customer, $args );
		$error  = $this->get_error();

		if ( $error ) {
			return new WP_Error(
				'zwqoi_customer_update_error',
				sprintf( __( 'There was an error updating a QuickBooks Customer for this user: %d', 'zwqoi' ), $this->get_wp_id( $user ) ),
				$error
			);
		}

		if ( isset( $results[1]->Id ) ) {
			$this->update_connected_qb_id( $user, $results[1]->Id );
		}

		return $result[1];
	}

	protected function qb_object_args( $wp_object ) {
		$user     = $this->get_wp_object( $wp_object );
		$customer = new \WC_Customer( $this->get_wp_id( $user ) );

		$args = array(
			'Notes'       =>  __( 'Created via "Zao WooCommerce QuickBooks Online Integration" plugin.', 'zwqoi' ),
			'DisplayName' => $this->get_wp_name( $user ),
			'Active'      => true,
		);

		if ( $user->user_url ) {
			$args['WebAddr']['URI'] = $user->user_url;
		}

		$data_methods = array(
			'GivenName' => array( 'get_first_name', 'get_billing_first_name', 'get_shipping_first_name' ),
			'FamilyName' => array( 'get_last_name', 'get_billing_last_name', 'get_shipping_last_name' ),
			'CompanyName' => array( 'get_billing_company', 'get_shipping_company' ),
		);

		foreach ( $data_methods as $prop => $methods ) {
			foreach ( $methods as $method ) {
				$args[ $prop ] = $customer->{$method}();
				if ( $args[ $prop ] ) {
					break;
				}
			}
		}

		$address_methods = array(
			'address_1' => 'Line1',
			'address_2' => 'Line2',
			'city'      => 'City',
			'state'     => 'CountrySubDivisionCode',
			'postcode'  => 'PostalCode',
			'country'   => 'Country',
		);

		foreach ( $address_methods as $method => $prop ) {
			$billing_data = call_user_func( array( $customer, 'get_billing_' . $method ) );
			$shipping_data = call_user_func( array( $customer, 'get_shipping_' . $method ) );
			if ( $billing_data ) {
				$args['BillAddr'][ $prop ] = $billing_data;
			}
			if ( $shipping_data ) {
				$args['ShipAddr'][ $prop ] = $shipping_data;
			}
		}

		$email = $customer->get_email();
		if ( ! $email ) {
			$email = $customer->get_billing_email();
		}
		if ( $email ) {
			$args['PrimaryEmailAddr']['Address'] = $email;
		}

		$phone = $customer->get_billing_phone();
		if ( $phone ) {
			$args['PrimaryPhone']['FreeFormNumber'] = $phone;
		}

		return $args;
	}

	public function update_wp_object_with_qb_object( $wp_id, $qb_id ) {
		$user = $this->is_wp_object( $wp_id ) ? $wp_id : get_user_by( 'id', absint( $wp_id ) );

		if ( ! $user ) {
			return new \WP_Error(
				'zwqoi_update_wp_object_with_qb_object_error',
				sprintf( __( 'Not able to find the WordPress user with this ID: %s', 'zwqoi' ), $wp_id )
			);
		}

		$customer = $qb_id instanceof API\Data\IPPCustomer ? $qb_id : $this->get_by_id( $qb_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$company_name = self::get_customer_company_name( $customer, false );

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
			$this->update_connected_qb_id( $updated, $customer->Id );
		}

		return $updated;
	}

	public function update_connected_qb_id( $wp_id, $meta_value ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		$result = update_user_meta( $wp_id, $this->meta_key, $meta_value );

		if ( $result ) {
			do_action( 'zwqoi_customer_connected_to_user', $meta_value, $wp_id );
		}

		return $result;
	}

	protected function update_woo_customer( $user_id, $user_args, API\Data\IPPCustomer $customer ) {
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

	protected function output_result_item( $item ) {
		$html = '';
		if ( 'error' === $item['id'] ) {
			$html .= '<li class="error">' . $item['name'] . '</li>';
		} elseif ( ! empty( $item['taken'] ) ) {
			$user = $this->get_wp_object( $item['taken'] );
			$user_edit_link = '<a href="' . $this->get_wp_edit_url( $user ) . '">' . $this->get_wp_name( $user ) . '</a>';
			$html .= '<li><strike>' . $item['name'] . '</strike> ' . sprintf( esc_attr__( 'This Customer is already associated to %s', 'zwqoi' ), $user_edit_link ) . '</li>';
		} else {
			$html .= '<li><span class="dashicons dashicons-download"></span> <a href="' . esc_url( $this->import_url( $item['id'] ) ) . '">' . $item['name'] . '</a></li>';
		}

		return apply_filters( 'zwqoi_output_customer_search_result_item', $html, $item );
	}

	/*
	 * Text methods
	 */

	public function text_search_page_title() {
		return __( 'QuickBooks Customer Search', 'zwqoi' );
	}

	public function text_search_page_menu_title() {
		return __( 'QuickBooks Customers', 'zwqoi' );
	}

	public function text_update_from_qb_button_confirm() {
		return __( 'This will replace the WordPress user data with the QuickBooks Customer data. Are you sure you want to proceed?', 'zwqoi' );
	}

	public function text_update_from_qb_button() {
		return __( 'Sync QuickBooks data to this user', 'zwqoi' );
	}

	public function text_import_as_new_from_qb() {
		return __( 'Import as new user', 'zwqoi' );
	}

	public function text_search_placeholder() {
		return __( 'Enter search term', 'zwqoi' );
	}

	public function text_object_single_name_name() {
		return __( 'Company Name', 'zwqoi' );
	}

	public function text_object_id_name() {
		return __( 'Company ID', 'zwqoi' );
	}

	public function text_submit_button() {
		return __( 'Search for Company', 'zwqoi' );
	}

	public function text_search_help() {
		return __( 'Click on one of the results to import the result as a WordPress user.', 'zwqoi' );
	}

	/*
	 * Utilities
	 */

	public function search_query_format( $search_type ) {
		switch ( $search_type ) {
			case 'name':
				return "SELECT * FROM Customer WHERE CompanyName = %s";
			case 'email':
				return "SELECT * FROM Customer WHERE PrimaryEmailAddr = %s";
			default:
				return "SELECT * FROM Customer WHERE Id = %s";
		}
	}

	public function get_by_id_error( $error, $qb_id ) {
		return new WP_Error(
			'zwqoi_customer_get_by_id_error',
			sprintf( __( 'There was an error retrieving this customer: %d', 'zwqoi' ), $qb_id ),
			$error
		);
	}

	public function is_wp_object( $object ) {
		return $object instanceof \WP_User;
	}

	public function get_wp_object( $wp_id ) {
		return $this->is_wp_object( $wp_id ) ? $wp_id : get_user_by( 'id', absint( $wp_id ) );
	}

	public function get_wp_id( $object ) {
		if ( ! $this->is_wp_object( $object ) ) {
			return 0;
		}

		return $object->ID;
	}

	public function get_wp_name( $object ) {
		if ( ! $this->is_wp_object( $object ) ) {
			return '';
		}

		return $object->display_name;
	}

	public function get_wp_edit_url( $wp_id ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		return get_edit_user_link( $wp_id, 'edit' );
	}

	public function disconnect_qb_object( $wp_id ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		if ( delete_user_meta( $wp_id, $this->meta_key ) ) {
			do_action( 'zwqoi_customer_disconnect_user', $wp_id );
		}
	}

	public function get_connected_qb_id( $wp_id ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		return get_user_meta( $wp_id, $this->meta_key, true );
	}

	public function found_user_error( $message_format, $link_text, \WP_User $user ) {
		$link = $this->get_wp_edit_url( $user );

		return new \WP_Error(
			'zwqoi_customer_import_error',
			sprintf( $message_format, '<a href="' . $link . '">' . $link_text . '</a>' ),
			$user
		);
	}

	public function get_qb_object_name( $qb_object ) {
		return self::get_customer_company_name( $qb_object );
	}

	public static function get_customer_company_name( $customer, $with_contact = true ) {
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

	public function admin_page_url() {
		return admin_url( 'users.php?page=' . $this->admin_page_slug );
	}

	public function update_url( $wp_id, $qb_id, $query_args = array() ) {
		$url = parent::update_url( $wp_id, $qb_id, $query_args );

		return apply_filters( 'zwqoi_update_user_with_quickbooks_customer_url', $url, $wp_id, $qb_id, $query_args );
	}

	public function import_url( $qb_id, $force = false ) {
		$url = parent::import_url( $qb_id, $force );

		return apply_filters( 'zwqoi_import_customer_url', $url, $qb_id );
	}

	public function query_wp_by_qb_id( $qb_id ) {
		$args = array(
			'meta_key'      => $this->meta_key,
			'meta_value'    => $qb_id,
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
		);

		$by_id = new \WP_User_Query( $args );

		$results = $by_id->get_results();

		if ( empty( $results ) ) {
			return false;
		}

		if ( ! $key_value ) {
			return $results;
		}

		$existing = array();
		foreach ( $results as $user ) {
			$existing[ $user->{$this->meta_key} ] = $user->ID;
		}

		return $existing;
	}

	public function update( $object, $args ) {
		return $this->update_customer( $object, $args );
	}

	/**
	 * The DisplayName attribute or at least one of Title, GivenName,
	 * MiddleName, FamilyName, or Suffix attributes is required during
	 * object create.
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $args Array of customer args.
	 *
	 * @return array
	 */
	public function create( $args ) {
		return $this->create_customer( $args );
		/**
		$args = array(
			'BillAddr' => array(
				'Line1'                  => '1 Infinite Loop',
				'City'                   => 'Cupertino',
				'Country'                => 'USA',
				'CountrySubDivisionCode' => 'CA',
				'PostalCode'             => '95014'
			),
			'Notes'              => 'Test... cras justo odio, dapibus ac facilisis in, egestas eget quam.',
			'GivenName'          => 'Justin',
			'MiddleName'         => 'T',
			'FamilyName'         => 'Sternberg',
			'FullyQualifiedName' => 'Zao',
			'CompanyName'        => 'Zao',
			'DisplayName'        => 'Zao',
			'PrimaryPhone'       =>  array(
				'FreeFormNumber' => '(408) 606-5775'
			),
			'PrimaryEmailAddr' =>  array(
				'Address' => 'jt@zao.is',
			)
		);
		list( $customer, $result ) = $this->create_customer( $args );
		*/
	}

}
