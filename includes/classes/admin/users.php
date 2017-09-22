<?php
namespace Zao\WC_QBO_Integration\Admin;

class Users extends Connected_Object_Base {

	protected $disconnect_query_var = 'disconnect_quickbooks_user';
	protected $connect_query_var = 'connect_customer';
	protected $connect_nonce_query_var = 'connect_customer_nonce';
	protected $id_query_var = 'user_id';

	public function init() {
		parent::init();
		add_action( 'show_user_profile', array( $this, 'maybe_output_quickbook_sync_button' ), 2 );
		add_action( 'edit_user_profile', array( $this, 'maybe_output_quickbook_sync_button' ), 2 );
	}

	/*
	 * Text methods
	 */

	public function text_redirect_back() {
		return __( 'Redirecting back to user.', 'zwqoi' );
	}

	public function text_search_to_connect() {
		return __( 'Search for a QuickBooks Customer to associate with this WordPress user (%s).', 'zwqoi' );
	}

	public function text_disconnect_qb_object() {
		return __( 'Disconnect QuickBooks Customer', 'zwqoi' );
	}

	public function text_disconnect_qb_object_confirm() {
		return __( 'Are you sure you want to disconnect the QuickBooks Customer?', 'zwqoi' );
	}

	public function text_connect_qb_object() {
		return __( 'Connect QuickBooks Customer', 'zwqoi' );
	}

	public function text_connect_qb_object_confirm() {
		return __( 'Once a Quickbooks Customer is associated, the WordPress user data for this user will be replaced with the QuickBooks Customer data. Are you sure you want to proceed?', 'zwqoi' );
	}

	public function text_select_result_to_associate() {
		return __( 'Select the result you want to associate with the WordPress user.', 'zwqoi' );
	}

	public function disconnect_quickbooks_notice() {
		parent::disconnect_quickbooks_notice();
		$this->notice(
			__( 'QuickBooks Customer has beeen disconnected from this user.', 'zwqoi' ),
			$this->disconnect_query_var
		);
	}

	public function updated_notice() {
		$this->notice(
			__( 'User has been syncronized with the QuickBooks Customer.', 'zwqoi' ),
			'qb_updated'
		);
	}

	public function maybe_output_quickbook_sync_button( $wp_object ) {
		echo $wp_object->{$this->service->meta_key}
			? '<h2>' . __( 'Connected Quickbooks Customer', 'zwqoi' ) . '</h2>'
			: '<h2>' . __( 'Connect a Quickbooks Customer?', 'zwqoi' ) . '</h2>';

		echo parent::output_connected_qb_buttons( $wp_object );
	}

	public function maybe_add_hidden_inputs( $obj ) {
		$success = parent::maybe_add_hidden_inputs( $obj );
		if ( $success ) {
			add_filter( 'zwqoi_import_customer_url', array( $this, 'replace_with_update_user_url' ), 10, 2 );
		}
	}

	public function replace_with_update_user_url( $url, $customer_id ) {
		remove_filter( 'zwqoi_import_customer_url', array( $this, 'replace_with_update_user_url' ), 10, 2 );

		$new_url = $this->get_update_and_redirect_url( $customer_id );

		add_filter( 'zwqoi_import_customer_url', array( $this, 'replace_with_update_user_url' ), 10, 2 );
		add_filter( 'zwqoi_output_customer_search_result_item', array( $this, 'add_warning' ), 10, 2 );

		return $new_url;
	}

	public function add_warning( $html, $item ) {
		remove_filter( 'zwqoi_output_customer_search_result_item', array( $this, 'add_warning' ), 10, 2 );

		$onclick = 'onclick="return confirm(\'' . esc_attr__( 'This will replace the WordPress user data with the QuickBooks Customer data. Are you sure you want to proceed?', 'zwqoi' ) . '\')" ';

		$html = str_replace( '<a ', '<a ' . $onclick, $html );

		return $html;
	}

}
