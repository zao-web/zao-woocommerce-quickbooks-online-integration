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
			add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
			add_action( 'restrict_manage_posts', array( $this, 'products_qb_filter' ) );
			if ( self::_param( 'qb_connected' ) ) {
				add_action( 'pre_get_posts', array( $this, 'maybe_limit_to_qb_products' ) );
			}
		}
	}

	public function register_metabox() {
		$post = self::_param( $this->id_query_var );
		$post = $post ? get_post( $post ) : false;

		$title = $post && $post->{$this->service->meta_key}
			? __( 'Connected Quickbooks Product', 'zwqoi' )
			: __( 'Connect a Quickbooks Product?', 'zwqoi' );

		add_meta_box( 'qb-connect-product', $title, array( $this, 'output_connected_qb_buttons' ), 'product', 'side' );
	}

	public function products_qb_filter( $post_type ) {
		if ( 'product' !== $post_type ) {
			return;
		}

		echo '
			<label>
				<input type="checkbox" value="1" name="qb_connected" id="connected-to-qb" ' , checked( self::_param( 'qb_connected' ) ) , '/>
				&nbsp' . __( 'QuickBooks Connected', 'zwqoi' ) . '
			</label>
		';
	}

	public function maybe_limit_to_qb_products( $query ) {
		$meta_query = $query->get( 'meta_query' );
		if ( empty( $meta_query ) || ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'key'     => $this->service->meta_key,
			'compare' => 'EXISTS',
		);

		$query->set( 'meta_query', $meta_query );
	}

	public function change_item_output( $html, $item ) {
		if ( 'error' !== $item['id'] && empty( $item['taken'] ) ) {
			$html = str_replace( 'type="checkbox"', 'type="radio"', $html );
			$html = preg_replace_callback( '~name="(.+)\[\]"~', function( $matches ) {
				return str_replace( '[]', '', $matches[0] );
			}, $html );

			add_action( 'admin_footer', array( __CLASS__, 'warn_when_submit' ) );
		}
		return $html;
	}

	public static function warn_when_submit() {
		?>
		<script type="text/javascript">
			jQuery( function( $ ) {
				$( '#qbo-items-import' ).on( 'submit', function( evt ) {
					if ( ! $( this ).find( '.zwqoi-result-item:checked' ).val() ) {
						return;
					}
					if ( ! confirm( '<?php esc_attr_e( 'This will replace the WordPress product data with the QuickBooks Product data. Are you sure you want to proceed?', 'zwqoi' ); ?>' ) ) {
						evt.preventDefault();
					}
				} );
			});
		</script>
		<?php
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

	public function text_disconnect_qb_object_confirm() {
		return __( 'Are you sure you want to disconnect the QuickBooks Product?', 'zwqoi' );
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

	public function text_select_result_to_associate() {
		return __( 'Select the result you want to associate with the WordPress product.', 'zwqoi' );
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
