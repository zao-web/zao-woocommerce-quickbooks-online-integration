<?php
namespace Zao\WC_QBO_Integration\Admin;

class Products extends Connected_Object_Base {

	protected $disconnect_query_var = 'disconnect_quickbooks_product';
	protected $connect_query_var = 'connect_product';
	protected $connect_nonce_query_var = 'connect_product_nonce';
	protected $id_query_var = 'post';

	public function init() {
		parent::init();

		if ( is_admin() ) {

			$post = self::_param( $this->id_query_var );
			$post = $post ? get_post( $post ) : false;

			$title = $post && $post->{$this->service->meta_key}
				? __( 'Connected Quickbooks Product', 'zwqoi' )
				: __( 'Connect a Quickbooks Product?', 'zwqoi' );

			add_meta_box( 'qb-connect-product', $title, array( $this, 'output_connected_qb_buttons' ), 'product', 'side' );
		}
	}

	/*
	 * Text methods
	 */

	public function text_redirect_back() {
		return __( 'Redirecting back to product.', 'zwqoi' );
	}

	public function text_search_to_connect() {
		return __( 'Search for a QuickBooks Product to associate with this WordPress product (%s).', 'zwqoi' );
	}

	public function text_disconnect_qb_object() {
		return __( 'Disconnect QuickBooks Product', 'zwqoi' );
	}

	public function text_connect_qb_object() {
		return __( 'Connect QuickBooks Product', 'zwqoi' );
	}

	public function text_connect_qb_object_confirm() {
		return __( 'Once a Quickbooks Product is associated, the WordPress data for this product will be replaced with the QuickBooks Product data. Are you sure you want to proceed?', 'zwqoi' );
	}

	protected function maybe_get_quickbook_sync_button( $qb_id ) {
		return str_replace( '&nbsp;&nbsp;', '</p><p>', parent::maybe_get_quickbook_sync_button( $qb_id ) );
	}

	public function disconnect_quickbooks_notice() {
		parent::disconnect_quickbooks_notice();
		$this->notice(
			__( 'QuickBooks Product has beeen disconnected.', 'zwqoi' ),
			$this->disconnect_query_var
		);
	}

	public function updated_notice() {
		$this->notice(
			__( 'Product has been syncronized with the QuickBooks Product.', 'zwqoi' ),
			'qb_updated'
		);
	}

	public function maybe_add_hidden_inputs( $obj ) {
		$success = parent::maybe_add_hidden_inputs( $obj );
		if ( $success ) {
			add_filter( 'zwqoi_import_product_url', array( $this, 'replace_with_updated_product_url' ), 10, 2 );
		}
	}

	public function replace_with_updated_product_url( $url, $customer_id ) {
		remove_filter( 'zwqoi_import_product_url', array( $this, 'replace_with_updated_product_url' ), 10, 2 );

		$new_url = $this->get_update_and_redirect_url( $customer_id );

		add_filter( 'zwqoi_import_product_url', array( $this, 'replace_with_updated_product_url' ), 10, 2 );
		add_filter( 'zwqoi_output_product_search_result_item', array( $this, 'add_warning' ), 10, 2 );

		return $new_url;
	}

	public function add_warning( $html, $item ) {
		remove_filter( 'zwqoi_output_product_search_result_item', array( $this, 'add_warning' ), 10, 2 );

		$onclick = 'onclick="return confirm(\'' . esc_attr__( 'This will replace the WordPress product data with the QuickBooks Product data. Are you sure you want to proceed?', 'zwqoi' ) . '\')" ';

		$html = str_replace( '<a ', '<a ' . $onclick, $html );

		return $html;
	}

}
