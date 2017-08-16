<?php
namespace Zao\WC_QBO_Integration;
use Zao\WC_QBO_Integration\Services\Customers;

class Users extends Base {

	protected $customers;
	protected $user;

	public function __construct( Customers $customers ) {
		$this->customers = $customers;
	}

	public function init() {
		if ( is_admin() && self::_param( 'user_id' ) && wp_verify_nonce( self::_param( 'disconnect_quickbooks_user' ), __CLASS__ ) ) {
			add_action( 'all_admin_notices', array( $this, 'disconnect_quickbooks_user_notice' ) );
		}
		add_action( 'show_user_profile', array( $this, 'maybe_add_quickbook_sync_button' ), 2 );
		add_action( 'edit_user_profile', array( $this, 'maybe_add_quickbook_sync_button' ), 2 );
		add_action( 'zwqoi_customer_search_page', array( $this, 'maybe_redirect_back' ) );
		add_action( 'zwqoi_customer_search_page_form', array( $this, 'maybe_add_hidden_inputs' ) );
	}

	public function disconnect_quickbooks_user_notice() {
		delete_user_meta( absint( self::_param( 'user_id' ) ), '_qb_customer_id' );
		$nonce_val = self::_param( 'disconnect_quickbooks_user' );
		?>
		<div id="message" class="updated"><p><?php _e( 'QuickBooks Customer has beeen disconnected from this user.', 'zwqoi' ); ?></p></div>

		<script type="text/javascript">
			if ( window.history.replaceState ) {
				window.history.replaceState( null, null, window.location.href.replace( /\?disconnect_quickbooks_user\=<?php echo $nonce_val; ?>\&/, '?' ).replace( /(\&|\?)disconnect_quickbooks_user\=<?php echo $nonce_val; ?>/, '' ) );
			}
		</script>
		<?php
	}

	public function maybe_add_quickbook_sync_button( $user ) {
		$this->user = $user;
		$customer_id = $user->_qb_customer_id;
		if ( ! $customer_id ) {
			return $this->add_create_connection_button();
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

		$disconnect_button = self::disconnect_quickbooks_user_button(
			$user->ID,
			$customer->Id
		);

		echo '<h2>' . __( 'Connected Quickbooks Customer', 'zwqoi' ) . '</h2>';
		echo '<p><em>' . $this->customers->get_customer_company_name( $customer ) . '</em></p>';
		echo '<p>' . $update_button . '&nbsp;&nbsp;' . $disconnect_button . '</p>';
	}

	public function maybe_redirect_back() {
		$redirect = self::_param( 'redirect' );
		if ( ! $redirect ) {
			return;
		}

		?>
		<script type="text/javascript">
			var notice = document.getElementById( 'setting-error-import-updated' );
			if ( notice && window.jQuery ) {
				var $strong = window.jQuery( notice ).find( 'strong' );
				$strong.text( $strong.text() + ' Redirecting back to user.' );
			}

			window.setTimeout( function() {
				window.location.href = '<?php echo esc_url_raw( $redirect ); ?>';
			}, 2000 );
		</script>
		<?php
	}

	public function add_create_connection_button() {
		echo '<h2>' . __( 'Connect a Quickbooks Customer?', 'zwqoi' ) . '</h2>';
		echo '<p>' . self::connect_customer_button( $this->user->ID ) . '</p>';
	}

	public function maybe_add_hidden_inputs() {
		if (
			! self::_param( 'connect_customer' )
			|| ! wp_verify_nonce( self::_param( 'connect_customer_nonce' ), __CLASS__ )
		) {
			return;
		}


		$user = get_user_by( 'id', absint( self::_param( 'connect_customer' ) ) );
		if ( ! $user || is_wp_error( $user ) ) {
			return;
		}

		$this->user = $user;

		$user_link = '<a href="' . get_edit_user_link( $this->user->ID ) . '">' . $this->user->display_name . '</a>';

		echo '
		<input type="hidden" name="connect_customer" value="' . $this->user->ID . '"/>
		<input type="hidden" name="connect_customer_nonce" value="' . esc_attr( self::_param( 'connect_customer_nonce' ) ) . '"/>
		<p>' . sprintf( __( 'Search for a QuickBooks Customer to associate with this WordPress user (%s).', 'zwqoi' ), $user_link ) . '</p>
		';

		add_filter( 'zwqoi_import_customer_url', array( $this, 'replace_with_update_user_url' ), 10, 2 );
	}

	public function replace_with_update_user_url( $url, $customer_id ) {
		remove_filter( 'zwqoi_import_customer_url', array( $this, 'replace_with_update_user_url' ), 10, 2 );

		$user_link = get_edit_user_link( $this->user->ID );
		$new_url = Customers::update_user_url( $this->user->ID, $customer_id, array( 'redirect' => urlencode( $user_link ) ) );
		add_filter( 'zwqoi_import_customer_url', array( $this, 'replace_with_update_user_url' ), 10, 2 );
		add_filter( 'zwqoi_output_search_result_item', array( $this, 'add_warning' ), 10, 2 );

		return $new_url;
	}

	public function add_warning( $html, $item ) {
		remove_filter( 'zwqoi_output_search_result_item', array( $this, 'add_warning' ), 10, 2 );

		$onclick = 'onclick="return confirm(\'' . esc_attr__( 'This will replace the WordPress user data with the QuickBooks Customer data. Are you sure you want to proceed?', 'zwqoi' ) . '\')" ';

		$html = str_replace( '<a ', '<a ' . $onclick, $html );

		return $html;
	}

	/**
	 * Utilities
	 */

	public static function disconnect_quickbooks_user_button( $user_id, $customer_id ) {
		return '<a class="button-secondary button-link-delete disconnect-qb-customer" href="' . esc_url( self::disconnect_quickbooks_user_url( $user_id, $customer_id ) ) . '">' . __( 'Disconnect QuickBooks Customer', 'zwqoi' ) . '</a>';
	}

	public static function disconnect_quickbooks_user_url( $user_id, $customer_id ) {
		return wp_nonce_url( get_edit_user_link( $user_id ), __CLASS__, 'disconnect_quickbooks_user' );
	}

	public static function connect_customer_button( $user_id, $query_args = array() ) {
		return '<a class="button-secondary connect-qb-customer" onclick="return confirm(\'' . esc_attr__( 'Once a Quickbooks Customer is associated, the WordPress user data for this user will be replaced with the QuickBooks Customer data. Are you sure you want to proceed?', 'zwqoi' ) . '\')" href="' . esc_url( self::connect_customer_url( $user_id, $query_args ) ) . '">' . __( 'Connect QuickBooks Customer', 'zwqoi' ) . '</a>';
	}

	public static function connect_customer_url( $user_id, $query_args = array() ) {
		$query_args['connect_customer'] = $user_id;
		return wp_nonce_url( Customers::settings_url( $query_args ), __CLASS__, 'connect_customer_nonce' );
	}

}
