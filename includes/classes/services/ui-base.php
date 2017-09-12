<?php
namespace Zao\WC_QBO_Integration\Services;
use Zao\QBO_API\Service;

abstract class UI_Base extends Base {
	protected $search_results   = null;
	protected $results_count    = 0;
	protected $permission_level = 'manage_options';
	protected $admin_page_slug  = '';
	protected $update_query_var = '';
	protected $import_query_var = '';

	/*
	 * Text methods
	 */

	abstract public function text_search_page_title();
	abstract public function text_search_page_menu_title();
	abstract public function text_update_from_qb_button_confirm();
	abstract public function text_update_from_qb_button();
	abstract public function text_import_as_new_from_qb();
	abstract public function text_search_placeholder();
	abstract public function text_object_single_name_name();
	abstract public function text_object_id_name();
	abstract public function text_submit_button();
	abstract public function text_search_help();

	/*
	 * Abstract methods
	 */

	abstract public function admin_page_url();
	abstract protected function output_result_item( $item );
	abstract public function validate_qb_object( $qb_id, $force = false );
	abstract protected function import_qb_object( $qb_object );
	abstract public function update_wp_object_with_qb_object( $wp_id, $qb_object );

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_qb_search_page' ), 999 );

		if ( $this->has_search() ) {
			add_action( 'qbo_connect_initiated', array( $this, 'set_search_results_from_query' ), 99 );
		}

		if ( $this->is_importing() ) {
			add_action( 'qbo_connect_initiated', array( $this, 'maybe_import_or_update' ), 99 );
		}

		if ( $this->settings_updated() ) {
			$this->add_import_success_notice( absint( $_GET['settings-updated'] ) );
		}
	}

	public function register_qb_search_page() {
		$parent_slug = $this->parent_slug();

		$args = array(
			$this->text_search_page_title(),
			$this->text_search_page_menu_title(),
			$this->permission_level,
			$this->admin_page_slug,
			array( $this, 'search_page' ),
		);
		$func = 'add_menu_page';

		if ( $parent_slug ) {
			$args = array_merge( array( $parent_slug ), $args );
			$func = 'add_submenu_page';
		}

		$page = call_user_func_array( $func, $args );
		add_action( 'load-' . $page, array( $this, 'add_help_tab' ) );
	}

	public function add_help_tab() {
		$screen = get_current_screen();

		$screen->add_help_tab( array(
			'id'      => 'zwqoi-help',
			'title'   => __( 'QuickBooks Woo Integration', 'zwqoi' ),
			'content' => '<p>' . __( 'By default, these will perform &quot;fuzzy&quot; searches, trying to find any results which match the given term. If you want more control of the search parameters, you can use the <code>%</code> character to indicate a wildcard. For instance, to search for all Customers whose email ends with <code>zao.is</code>, you can search for <code>%zao.is%</code>..', 'zwqoi' ) . '</p><p>' . __( 'For more documentation, visit the <a href="https://github.com/zao-web/zao-woocommerce-quickbooks-online-integration/wiki">Zao WooCommerce QuickBooks Online Integration wiki</a>', 'zwqoi' ) . '</p>',
		) );
	}

	public function parent_slug() {
		return '';
	}

	public function search_page() {
		include_once ZWQOI_INC . 'views/search-page.php';
	}

	public function maybe_import_or_update() {
		$result = ! empty( $_GET[ $this->update_query_var ] )
			? $this->update_wp_object_with_qb_object( $_GET[ $this->update_query_var ], $_GET[ $this->import_query_var ] )
			: $this->validate_and_import_qb_object( $_GET[ $this->import_query_var ], ! empty( $_GET['force'] ) );

		// If update/import was successful:
		if ( ! is_wp_error( $result ) ) {

			$url = self::_param( 'redirect' )
				? urldecode( self::_param( 'redirect' ) )
				: $this->settings_url( array( 'settings-updated' => $result ) ) ;

			// Redirect to requested location, or redirect back to search page with success message.
			self::redirect( esc_url_raw( $url ) );
		}

		$error    = $result->get_error_data();
		$msg      = $result->get_error_message();
		$err_type = 'error';

		if ( self::is_fault_handler( $error ) ) {
			$msg .= self::fault_handler_error_output( $error );
		}

		if ( $this->is_wp_object( $error ) ) {
			$qb_id = absint( $_GET[ $this->import_query_var ] );
			$err_type = 'notice-warning';
			$msg .= '<span class="qb-import-button-wrap">' . $this->update_from_qb_button( $error->ID, $qb_id );
			$msg .= ' or ' . $this->force_import_from_qb_button( $qb_id, true ) . "</span>\n";
		}

		$this->add_settings_notice( $msg, $err_type );
	}

	public function validate_and_import_qb_object( $qb_id, $force = true ) {
		$qb_object = $this->get_by_id( $qb_id );

		if ( is_wp_error( $qb_object ) ) {
			return $qb_object;
		}

		$validation_error = $this->validate_qb_object( $qb_object, $force );

		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		$wp_id = $this->import_qb_object( $qb_object );

		if ( is_wp_error( $wp_id ) ) {
			return $wp_id;
		}

		return $this->update_wp_object_with_qb_object( $wp_id, $qb_object );
	}

	public function has_search() {
		static $has_search = null;
		if ( null === $has_search ) {
			$has_search = (
				isset( $_POST['search_term'], $_POST[ $this->admin_page_slug ] )
				&& wp_verify_nonce( $_POST[ $this->admin_page_slug ], $this->admin_page_slug )
			);
		}

		return $has_search;
	}

	public function set_search_results_from_query() {
		if ( null === $this->search_results ) {
			$this->search_results = $this->search_results(
				wp_unslash( $_POST['search_term'] ),
				wp_unslash( self::_param( 'search_type' ) )
			);

			$this->results_count = count( $this->search_results );
			if ( 1 === $this->results_count && 'error' === $this->search_results[0]['id'] ) {
				$this->results_count = 0;
			}
		}

		return $this->search_results;
	}

	public function search_results( $search_term = '', $search_type = 'name' ) {
		global $wpdb;

		$result_items = array();

		$search_term = 'id' === $search_type ? absint( $search_term ) : sanitize_text_field( $search_term );
		if ( empty( $search_term ) ) {
			return $result_items;
		}

		try {
			$query = $wpdb->prepare(
				$this->search_query_format( $search_type ),
				// Make this a fuzzy search if it is not already.
				false === strpos( $search_term, '%' ) ? "%$search_term%" : $search_term
			);

			$results = $this->query( $query );

			$error = $this->get_error();

			if ( $error ) {

				$result_items[] = array(
					'id'   => 'error',
					'name' => $error->getOAuthHelperError(),
				);

			} else {
				if ( ! empty( $results ) ) {
					$existing = $this->query_wp_by_qb_ids( wp_list_pluck( $results, 'Id' ), true );

					foreach ( (array) $results as $qb_object ) {
						if ( isset( $qb_object->Id ) ) {
							$result_items[] = array(
								'taken' => isset( $existing[ $qb_object->Id ] ) ? $existing[ $qb_object->Id ] : false,
								'id'   => $qb_object->Id,
								'name' => $this->get_qb_object_name( $qb_object ),
							);
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			$result_items[] = array(
				'id'   => 'error',
				'name' => $e->getMessage(),
			);
		}

		if ( empty( $result_items ) ) {
			$result_items[] = array(
				'id'   => 'error',
				'name' => __( 'No results for this search.', 'zwqoi' ),
			);
		}

		return $result_items;
	}

	/*
	 * Utilities
	 */

	public static function admin_page_title( $echo = true ) {
		$links = apply_filters( 'zwqoi_settings_nav_links', array() );
		if ( empty( $links ) ) {
			$title = '<h2>' . get_admin_page_title() . '</h2>';
		} else {
			$title = '<h2 class="nav-tab-wrapper">';
			foreach ( $links as $item ) {
				$title .= sprintf(
					'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
					$item['url'],
					! empty( $item['active'] ) ? ' nav-tab-active' : '' ,
					$item['text']
				);
			}
			$title .= '</h2>';

		}

		if ( $echo ) {
			echo $title;
		} else {
			return $title;
		}
	}

	public function settings_url( $args = array() ) {
		$url = $this->admin_page_url();

		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}

	public function add_import_success_notice( $wp_id ) {
		$link = $this->get_wp_object_edit_link( $wp_id );

		if ( $link ) {
			$msg = sprintf( __( 'Success! %s imported.', 'zwqoi' ), $link ) . "\n";

			$this->add_settings_notice( $msg, 'updated' );
		}
	}

	public function add_settings_notice( $msg, $type ) {
		return add_settings_error(
			$this->admin_page_slug . '-notices',
			'import-' . $type,
			$msg,
			$type
		);
	}

	public function settings_updated() {
		return $this->is_admin_page()
			&& isset( $_GET['settings-updated'] )
			&& is_numeric( $_GET['settings-updated'] );
	}

	public function is_importing() {
		return $this->is_admin_page()
			&& isset( $_GET[ $this->import_query_var ], $_GET['nonce'] )
			&& is_numeric( $_GET[ $this->import_query_var ] )
			&& wp_verify_nonce( $_GET['nonce'], $this->admin_page_slug );
	}

	public function is_admin_page() {
		return self::_param_is( 'page', $this->admin_page_slug );
	}

	public function update_from_qb_button( $wp_id, $qb_id, $query_args = array() ) {
		return sprintf(
			'<a class="button-secondary update-from-qb" onclick="return confirm(\'%1$s\')" href="%2$s">%3$s</a>',
			esc_attr( $this->text_update_from_qb_button_confirm() ),
			esc_url( $this->update_url( $wp_id, $qb_id, $query_args ) ),
			esc_html( $this->text_update_from_qb_button() )
		);
	}

	public function force_import_from_qb_button( $qb_id, $force = true, $button_text = null ) {
		if ( null === $button_text ) {
			$button_text = $this->text_import_as_new_from_qb();
		}
		return sprintf(
			'<a class="button-secondary import-from-qb" href="%1$s">%2$s</a>',
			esc_url( $this->import_url( $qb_id, $force ) ),
			esc_html( $button_text )
		);
	}

	public function update_url( $wp_id, $qb_id, $query_args = array() ) {
		$query_args[ $this->update_query_var ] = $wp_id;
		return add_query_arg( $query_args, $this->import_url( $qb_id ) );
	}

	public function import_url( $qb_id, $force = false ) {
		$args = array( $this->import_query_var => $qb_id );
		if ( $force ) {
			$args['force'] = 1;
		}
		return wp_nonce_url( $this->settings_url( $args ), $this->admin_page_slug, 'nonce' );
	}

	public function get_wp_object_edit_link( $wp_id, $link_text = null ) {
		$name = '';
		$object = $this->get_wp_object( $wp_id );
		if ( ! $object ) {
			return $name;
		}

		if ( null === $link_text ) {
			$link_text = $this->get_wp_name( $object );
		}

		$name = '<a href="' . $this->get_wp_edit_url( $object ) . '">' . $link_text . '</a>';

		return $name;
	}

	public static function admin_page_matches( $page_url ) {
		$parts = parse_url( $page_url );
		$curr  = parse_url( remove_query_arg( 'testtest' ) );

		if ( ! isset( $parts['query'] ) ) {
			return false;
		}

		parse_str( $parts['query'], $params );

		return (
			isset( $_GET['page'], $params['page'], $curr['path'], $parts['path'] )
			&& untrailingslashit( $curr['path'] ) === untrailingslashit( $parts['path'] )
			&& $_GET['page'] === $params['page']
		);
	}

	public function is_on_admin_page() {
		return self::admin_page_matches( $this->admin_page_url() );
	}

}
