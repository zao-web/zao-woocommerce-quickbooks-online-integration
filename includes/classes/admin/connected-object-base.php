<?php
namespace Zao\WC_QBO_Integration\Admin;
use Zao\WC_QBO_Integration\Base, Zao\WC_QBO_Integration\Services\UI_Base;
use Zao\WC_QBO_Integration\Services\Base as Service_Base;


abstract class Connected_Object_Base extends Base {

	protected $service;
	protected $wp_object = null;
	protected $disconnect_query_var = '';
	protected $connect_query_var = '';
	protected $connect_nonce_query_var = '';
	protected $id_query_var = '';

	public function __construct( Service_Base $service ) {
		$this->service = $service;
	}

	public function init() {
		if ( $this->should_disconnect() ) {
			add_action( 'all_admin_notices', array( $this, 'disconnect_quickbooks_notice' ) );
		}

		if ( $this->was_updated() ) {
			add_action( 'all_admin_notices', array( $this, 'updated_notice' ) );
		}

		add_action( 'zwqoi_customer_search_page', array( $this, 'maybe_redirect_back' ) );
		add_action( 'zwqoi_search_page_form', array( $this, 'maybe_add_hidden_inputs' ) );
		add_action( 'zwqoi_search_page_import_results_form', array( $this, 'maybe_add_hidden_inputs' ) );

		if ( $this->is_connecting() ) {
			add_filter( 'zwqoi_text_search_help', array( $this, 'text_select_result_to_associate' ) );
			add_action( 'zwqoi_output_product_search_result_item', array( $this, 'change_item_output' ), 11, 2 );
		}
	}

	/*
	 * Text methods
	 */

	abstract public function text_redirect_back();
	abstract public function text_search_to_connect();
	abstract public function text_disconnect_qb_object();
	abstract public function text_disconnect_qb_object_confirm();
	abstract public function text_connect_qb_object();
	abstract public function text_connect_qb_object_confirm();
	abstract public function text_select_result_to_associate();

	/*
	 * Abstract methods
	 */

	abstract public function updated_notice();

	public function disconnect_quickbooks_notice() {
		return $this->service->disconnect_qb_object(
			$this->service->get_wp_object( absint( self::_param( $this->id_query_var ) ) )
		);
	}

	protected function notice( $message, $query_var ) {
		$query_val = self::_param( $query_var );
		?>
		<div id="message" class="updated notice is-dismissible">
			<?php echo wpautop( $message ); ?>
		</div>

		<script type="text/javascript">
			if ( window.history.replaceState ) {
				window.history.replaceState( null, null, window.location.href.replace( /\?<?php echo $query_var; ?>\=<?php echo $query_val; ?>\&/, '?' ).replace( /(\&|\?)<?php echo $query_var; ?>\=<?php echo $query_val; ?>/, '' ) );
			}
		</script>
		<?php
	}

	public function output_connected_qb_buttons( $wp_object ) {
		$this->wp_object = $wp_object;
		$qb_id = $wp_object->{$this->service->meta_key};

		if ( $qb_id ) {
			echo $this->maybe_get_quickbooks_sync_button( $wp_object->{$this->service->meta_key} );
		} else {
			echo '<p>' . $this->connect_qb_button( array(
				'search_term' => $this->service->get_wp_name( $this->wp_object ),
				$this->service->admin_page_slug => wp_create_nonce( $this->service->admin_page_slug ),
			) ) . '</p>';
		}
	}

	protected function maybe_get_quickbooks_sync_button( $qb_id ) {
		$qb_object = $this->service->get_by_id( $qb_id );
		if ( ! $qb_object ) {
			return '<p>' . sprintf( __( 'There was an issue fetching the QuickBooks object (%d) from the QuickBooks API.', 'zwqoi' ), $qb_id ) . '</p>';
		}

		$disconnect_button = $this->disconnect_quickbooks_wp_button();
		$output = '<p><em>' . $this->service->get_qb_object_name( $qb_object ) . '</em></p>';
		$output .= '<p>';

		if ( $this->service instanceof UI_Base ) {
			$update_button = $this->service->update_from_qb_button(
				$this->service->get_wp_id( $this->wp_object ),
				$qb_object->Id,
				array( 'redirect' => urlencode( add_query_arg( 'qb_updated', 1 ) ) )
			);

			$output .= $update_button . '&nbsp;&nbsp;' . $disconnect_button;

		} else {

			$output .= $disconnect_button;
		}

		$output .= '</p>';

		return $output;
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
				$strong.text( $strong.text() + ' <?php esc_html( $this->get_text( 'redirect_back' ) ); ?>' );
			}

			window.setTimeout( function() {
				window.location.href = '<?php echo esc_url_raw( $redirect ); ?>';
			}, 2000 );
		</script>
		<?php
	}

	public function maybe_add_hidden_inputs( $service ) {
		if ( $this->service !== $service ) {
			return false;
		}

		if ( ! $this->is_connecting() ) {
			return false;
		}

		$wp_id = $this->service->get_wp_id( $this->wp_object );

		echo '
		<input type="hidden" name="' . $this->connect_query_var . '" value="' . $wp_id . '"/>
		<input type="hidden" name="' . $this->connect_nonce_query_var . '" value="' . esc_attr( self::_param( $this->connect_nonce_query_var ) ) . '"/>
		';

		if ( 'zwqoi_search_page_form' === current_filter() ) {

			$edit_link = $this->service->get_wp_object_edit_link( $this->wp_object );
			echo ' <p>' . sprintf( $this->get_text( 'search_to_connect' ), $edit_link ) . '</p>';

		} else {

			echo '
			<input type="hidden" name="redirect" value="' . esc_url( add_query_arg( 'qb_updated', 1, $this->service->get_wp_edit_url( $this->wp_object ) ) ) . '"/>
			<input type="hidden" name="' . $this->service->update_query_var . '" value="' . absint( $wp_id ) . '"/>
			';

		}

		return true;
	}

	public function get_update_and_redirect_url( $qb_id ) {
		$edit_link = $this->service->get_wp_edit_url( $this->wp_object );

		return $this->service->update_url(
			$this->service->get_wp_id( $this->wp_object ), $qb_id,
			array( 'redirect' => urlencode( add_query_arg( 'qb_updated', 1, $edit_link ) ) )
		);
	}

	/**
	 * Utilities
	 */

	public function disconnect_quickbooks_wp_button() {
		return '<a class="button-secondary button-link-delete disconnect-qb-customer" onclick="return confirm(\'' . esc_attr( $this->get_text( 'disconnect_qb_object_confirm' ) ) . '\')" href="' . esc_url( $this->disconnect_qb_from_wp_object_url() ) . '">' . $this->get_text( 'disconnect_qb_object' ) . '</a>';
	}

	public function disconnect_qb_from_wp_object_url() {
		return wp_nonce_url( $this->service->get_wp_edit_url( $this->wp_object ), get_class( $this ), $this->disconnect_query_var );
	}

	public function connect_qb_button( $query_args = array() ) {
		return '<a class="button-secondary connect-qb-customer" onclick="return confirm(\'' . esc_attr( $this->get_text( 'connect_qb_object_confirm' ) ) . '\')" href="' . esc_url( $this->connect_qb_url( $query_args ) ) . '">' . $this->get_text( 'connect_qb_object' ) . '</a>';
	}

	public function connect_qb_url( $query_args = array() ) {
		$query_args[ $this->connect_query_var ] = $this->service->get_wp_id( $this->wp_object );
		return wp_nonce_url( $this->service->settings_url( $query_args ), get_class( $this ), $this->connect_nonce_query_var );
	}

	public function should_disconnect() {
		return (
			is_admin()
			&& self::_param( $this->id_query_var )
			&& wp_verify_nonce( self::_param( $this->disconnect_query_var ), get_class( $this ) )
		);
	}

	public function is_connecting() {
		if (
			! self::_param( $this->connect_query_var )
			|| ! wp_verify_nonce( self::_param( $this->connect_nonce_query_var ), get_class( $this ) )
		) {
			return false;
		}

		if ( null === $this->wp_object ) {
			$this->wp_object = $this->service->get_wp_object( absint( self::_param( $this->connect_query_var ) ) );
		}

		if ( ! $this->wp_object || is_wp_error( $this->wp_object ) ) {
			return false;
		}

		return $this->wp_object;
	}

	public function was_updated() {
		return (
			is_admin()
			&& self::_param( $this->id_query_var )
			&& self::_param_is( 'qb_updated', '1' )
		);
	}

}
