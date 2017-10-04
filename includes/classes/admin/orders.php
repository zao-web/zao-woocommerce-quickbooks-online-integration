<?php
namespace Zao\WC_QBO_Integration\Admin;

class Orders extends Connected_Object_Base {

	protected $disconnect_query_var = 'disconnect_quickbooks_invoice';
	protected $delete_query_var = 'delete_quickbooks_invoice';
	protected $connect_query_var = 'connect_invoice';
	protected $connect_nonce_query_var = 'connect_invoice_nonce';
	protected $id_query_var = 'post';
	protected $delete_notice = '';

	public function init() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		}

		add_action( 'zwoowh_order_cloner_pre_save', array( $this, 'remove_qb_id_from_cloned_order' ) );

		if ( $this->is_deleting() ) {
			add_action( 'qbo_connect_initiated', array( $this, 'delete_connected_invoice' ), 999 );
		}
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
			$qb_id = $this->service->get_connected_qb_id( $this->wp_object );
			echo $this->maybe_get_quickbooks_sync_button( $qb_id );
			$delete_button = $this->maybe_get_quickbooks_delete_button( $qb_id );
			if ( $delete_button ) {
				echo $delete_button;
				add_action( 'admin_footer', array( $this, 'listen_for_order_trash' ) );
			}
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

	public function text_delete_qb_object() {
		return __( 'Delete QuickBooks Invoice', 'zwqoi' );
	}

	public function text_delete_qb_object_confirm() {
		return __( 'Are you sure you want to delete the associated QuickBooks Invoice?', 'zwqoi' );
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

	public function delete_connected_invoice() {
		$result       = $this->service->delete_connected_qb_object( $this->wp_object );
		$message      = __( 'QuickBooks Invoice has beeen deleted.', 'zwqoi' );
		$message_type = 'updated';

		if ( ! $result ) {

			$message      = __( 'QuickBooks Invoice failed to delete.', 'zwqoi' );
			$message_type = 'error';

		} elseif ( is_wp_error( $result ) ) {

			$error        = $result->get_error_data();
			$message      = $result->get_error_message();
			$message_type = 'error';

			if ( $this->service->is_fault_handler( $error ) ) {
				$message .= $this->service->fault_handler_error_output( $error );
			}
		}

		$this->delete_notice = array(
			$message,
			$this->delete_query_var,
			$message_type
		);

		if ( self::_param( 'json' ) ) {
			wp_send_json( array(
				'success' => 'updated' === $message_type,
				'data'    => $message,
			) );
		}

		add_action( 'all_admin_notices', array( $this, 'delete_connected_invoice_notice' ) );
	}

	public function delete_connected_invoice_notice() {
		call_user_func_array( array( $this, 'notice' ), $this->delete_notice );
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

	public function listen_for_order_trash() {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script( 'zao-woocommerce-quickbooks-online-integration', ZWQOI_URL . "assets/js/zao-woocommerce-quickbooks-online-integration{$min}.js", array(), ZWQOI_VERSION, true );

		wp_localize_script( 'zao-woocommerce-quickbooks-online-integration', 'ZWQOI', array(
			'l10n' => array(
				'unableToDelete' => esc_attr__( 'Unable to delete the connected QuickBooks Invoice.', 'zwqoi' ),
				'alsoDeleteInvoice' => esc_attr__( 'Would you like to also delete the connected QuickBooks Invoice?', 'zwqoi' ),
				'cancel' => esc_attr__( 'Cancel', 'zwqoi' ),
				'yes' => esc_attr__( 'Yes', 'zwqoi' ),
				'no' => esc_attr__( 'No', 'zwqoi' ),
			),
		) );

		?>
		<style type="text/css">
			#check-delete-invoice {
				background: #f5f5f5;
				padding: 2px 10px;
				margin: 6px 0 16px;
			}
			#check-delete-invoice p span {
				padding-left: 30px;
			}
		</style>
		<?php
	}

}
