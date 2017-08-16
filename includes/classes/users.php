<?php
namespace Zao\WC_QBO_Integration;
use Zao\WC_QBO_Integration\Services\Customers;

class Users extends Base {

	public $customers;

	public function __construct( Customers $customers ) {
		$this->customers = $customers;
	}

	public function init() {
		add_action( 'show_user_profile', array( $this, 'maybe_add_quickbook_sync_button' ), 2 );
		add_action( 'edit_user_profile', array( $this, 'maybe_add_quickbook_sync_button' ), 2 );
		add_action( 'zwqoi_customer_search_page', array( $this, 'maybe_redirect_back' ) );
	}

	public function maybe_add_quickbook_sync_button( $user ) {
		$customer_id = $user->_qb_customer_id;
		if ( ! $customer_id ) {
			return;
		}

		$customer = $this->customers->get_by_id( $customer_id );
		// echo '<xmp>'. __LINE__ .') $user->_qb_customer_id: '. print_r( $user->_qb_customer_id, true ) .'</xmp>';
		// print( '<xmp>'. __FUNCTION__ . ':' . __LINE__ .') '. print_r( get_defined_vars(), true ) .'</xmp>' );
		// '_qb_customer_id'
		//
		// echo '<xmp>'. __LINE__ .') $customer: '. print_r( $customer, true ) .'</xmp>';

		$update_button = Customers::update_quickbooks_user_button(
			$user->ID,
			$customer->Id,
			array( 'redirect' => urlencode( remove_query_arg( 'test' ) ) )
		);

		echo '<h2>' . __( 'Connected Quickbooks Customer', 'zwqoi' ) . '</h2>';
		echo '<p><em>' . $this->customers->get_customer_company_name( $customer ) . '</em></p>';
		echo '<p>' . $update_button . '</p>';
	}

	public function maybe_redirect_back() {
		$redirect = self::_param( 'redirect' );
		if ( ! $redirect ) {
			return;
		}

		?>
		<script type="text/javascript">
			var notice = document.getElementById( 'setting-error-import-updated' );
			if ( notice && jQuery ) {
				var $strong = jQuery( notice ).find( 'strong' )
				$strong.text( $strong.text() + ' Redirecting back to user.' );
			}

			window.setTimeout( function() {
				window.location.href = '<?php echo esc_url_raw( $redirect ); ?>';
			}, 2000 );
		</script>
		<?php
	}

}
