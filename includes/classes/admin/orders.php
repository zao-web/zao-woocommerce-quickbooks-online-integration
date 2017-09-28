<?php
namespace Zao\WC_QBO_Integration\Admin;

class Orders extends Connected_Object_Base {

	protected $disconnect_query_var = 'disconnect_quickbooks_invoice';
	protected $connect_query_var = 'connect_invoice';
	protected $connect_nonce_query_var = 'connect_invoice_nonce';
	protected $id_query_var = 'post';

	public function init() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		}

		add_action( 'zwoowh_order_cloner_pre_save', array( $this, 'remove_qb_id_from_cloned_order' ) );
	}

	public function remove_qb_id_from_cloned_order( $cloner ) {
		// Make sure the _qb_invoice_id meta is not set for the wholesale cloned orders.
		$cloner->order->delete_meta_data( '_qb_invoice_id' );
	}

	public function register_metabox( $post_type ) {
		if ( $this->should_disconnect() ) {
			add_action( 'all_admin_notices', array( $this, 'disconnect_quickbooks_notice' ) );
			return;
		}

		if (
			$post_type !== $this->service->post_type
			|| ! self::_param( 'post' )
			|| ! $this->service->get_connected_qb_id( self::_param( 'post' ) )
		) {
			return;
		}

		add_meta_box( 'qb-connect-invoice', __( 'Connected Quickbooks Invoice', 'zwqoi' ), array( $this, 'output_connected_qb_buttons' ), $post_type, 'side' );
	}

	public function output_connected_qb_buttons( $wp_object ) {
		$this->wp_object = $this->service->get_wp_object( $wp_object );

		if ( $this->service->get_connected_qb_id( $this->wp_object ) ) {
			echo $this->maybe_get_quickbook_sync_button( $wp_object->{$this->service->meta_key} );
		}
	}

	/*
	 * Text methods
	 */

	public function text_redirect_back() {
		return __( 'Redirecting back to order.', 'zwqoi' );
	}

	public function text_search_to_connect() {
		return __( 'Search for a QuickBooks Invoice to associate with this WordPress order (%s).', 'zwqoi' );
	}

	public function text_disconnect_qb_object() {
		return __( 'Disconnect QuickBooks Invoice', 'zwqoi' );
	}

	public function text_disconnect_qb_object_confirm() {
		return __( 'Are you sure you want to disconnect the QuickBooks Invoice?', 'zwqoi' );
	}

	public function text_connect_qb_object() {
		return __( 'Connect QuickBooks Invoice', 'zwqoi' );
	}

	public function text_connect_qb_object_confirm() {
		return __( 'Once a Quickbooks Invoice is associated, the WordPress data for this order will be replaced with the QuickBooks Invoice data. Are you sure you want to proceed?', 'zwqoi' );
	}

	public function text_select_result_to_associate() {
		return __( 'Select the result you want to associate with the WordPress order.', 'zwqoi' );
	}

	public function disconnect_quickbooks_notice() {
		parent::disconnect_quickbooks_notice();
		$this->notice(
			__( 'QuickBooks Invoice has beeen disconnected.', 'zwqoi' ),
			$this->disconnect_query_var
		);
	}

	public function updated_notice() {
		$this->notice(
			__( 'Order has been syncronized with the QuickBooks Invoice.', 'zwqoi' ),
			'qb_updated'
		);
	}
}
