<?php
namespace Zao\WC_QBO_Integration\Services;

use QuickBooksOnline\API, WC_Product, WP_Post, WP_Error, WP_Query;
use Zao\WC_QBO_Integration\Admin\Settings;

class Products extends UI_Base {

	protected $admin_page_slug  = 'qbo-product-search';
	protected $update_query_var = 'update_product';
	protected $import_query_var = 'import_product';
	protected $meta_key         = '_qb_product_id';
	protected $post_type        = 'product';
	protected $sync_meta_keys   = array(
		'SubItem',
		'ParentRef',
		'Level',
		'FullyQualifiedName',
		'SalesTaxIncluded',
		'PercentBased',
		'RatePercent',
		'Type',
		'PaymentMethodRef',
		'UOMSetRef',
		'IncomeAccountRef',
		'PurchaseDesc',
		'PurchaseTaxIncluded',
		'PurchaseCost',
		'ExpenseAccountRef',
		'COGSAccountRef',
		'AssetAccountRef',
		'PrefVendorRef',
		'AvgCost',
		'ReorderPoint',
		'ManPartNum',
		'DepositToAccountRef',
		'SalesTaxCodeRef',
		'PurchaseTaxCodeRef',
		'InvStartDate',
		'BuildPoint',
		'PrintGroupedItems',
		'SpecialItem',
		'SpecialItemType',
		'ItemGroupDetail',
		'ItemAssemblyDetail',
		'AbatementRate',
		'ReverseChargeRate',
		'ServiceType',
		'ItemCategoryType',
		'ItemEx',
		'SyncToken',
		'CustomField',
		'AttachableRef',
		'domain',
		'status',
		'MetaData',
	);

	public function init() {
		parent::init();
		add_filter( 'zwqoi_settings_nav_links', array( $this, 'add_nav_link' ), 6 );
	}

	public function add_nav_link( $links ) {
		$links[] = array(
			'url'    => $this->admin_page_url(),
			'active' => $this->is_on_admin_page(),
			'text'   => esc_html__( 'Product Search', 'zwqoi' ),
		);

		return $links;
	}

	public function parent_slug() {
		return 'woocommerce';
	}

	public function search_page() {
		parent::search_page();
		do_action( 'zwqoi_product_search_page', $this );
	}

	public function validate_qb_object( $qb_object, $force = false ) {
		$item_name = self::get_item_name( $qb_object, false );

		$product = $this->query_wp_by_qb_id( $qb_object->Id );

		if ( ! empty( $product ) ) {
			return $this->found_product_error(
				__( 'A product has already been mapped to this QuickBooks Product: %s', 'zwqoi' ),
				$item_name,
				$product
			);
		}

		$slug = sanitize_title( $item_name );

		if ( ! $force ) {
			$product = get_page_by_path( $slug, OBJECT, 'product' );
			if ( $product ) {
				return $this->found_product_error(
					__( 'A product already exists with this slug: %s', 'zwqoi' ),
					$slug,
					$product
				);
			}
		}

		return $slug;
	}

	protected function import_qb_object( $qb_object ) {
		return new WC_Product();
	}

	public function update_wp_object_with_qb_object( $wp_id, $qb_id ) {
		$wc_product = $this->get_product( $wp_id );

		if ( ! $wc_product ) {
			return new WP_Error(
				'zwqoi_update_wp_object_with_qb_object_error',
				sprintf( __( 'Not able to find the WordPress product with this ID: %s', 'zwqoi' ), $wp_id )
			);
		}

		$item = $qb_id instanceof API\Data\IPPItem ? $qb_id : $this->get_by_id( $qb_id );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$item_name = self::get_item_name( $item, false );

		$props = array(
			'name'         => wc_clean( $item_name ),
			'slug'         => wc_clean( $item_name ),
			'sku'          => wc_clean( self::get_value_from_object( $item, 'Sku', '' ) ),
			'description'  => wc_clean( self::get_value_from_object( $item, 'Description', '' ) ),
			'status'       => ! empty( $item->Active ) ? 'publish' : 'pending',
			'tax_status'   => ! empty( $item->Taxable ) ? 'taxable' : 'pending',
			'price'        => wc_clean( self::get_value_from_object( $item, 'UnitPrice', '' ) ),
			'manage_stock' => !! $item->TrackQtyOnHand,
		);

		$props['regular_price'] = $props['price'];

		$wc_product->set_props( $props );

		if ( $props['manage_stock'] && isset( $item->QtyOnHand ) ) {
			$wc_product->set_stock_quantity( absint( $item->QtyOnHand ) );

			$this->set_product_meta( $wc_product, $item, array(
				'QtyOnPurchaseOrder',
				'QtyOnSalesOrder',
			) );
		}

		$this->set_product_meta( $wc_product, $item, $this->sync_meta_keys );

		$this->update_connected_qb_id( $wc_product, $item->Id );

		add_action( 'woocommerce_new_product', array( $this, 'add_custom_new_product_hook' ) );
		add_action( 'woocommerce_update_product', array( $this, 'add_custom_update_product_hook' ) );

		$updated = $wc_product->save();

		if ( ! $updated ) {
			$updated = new WP_Error(
				'zwqoi_product_get_by_id_error',
				sprintf( __( 'There was an error importing/updating this product: %s', 'zwqoi' ), $item_name )
			);
		}

		return $updated;
	}

	public function update_connected_qb_id( $wp_id, $meta_value ) {
		if ( ! ( $wp_id instanceof WC_Product ) ) {
			return false;
		}

		$wp_id->update_meta_data( $this->meta_key, $meta_value );

		return $wp_id;
	}

	public function add_custom_new_product_hook( $product_id ) {
		do_action( 'zwqoi_new_product_from_quickbooks', $product_id, $this );
	}

	public function add_custom_update_product_hook( $product_id ) {
		do_action( 'zwqoi_update_product_from_quickbooks', $product_id, $this );
	}

	public function create_qb_object_from_wp_object( $wp_object ) {
		$product = $this->get_product( $wp_object );

		if ( ! $product ) {
			return false;
		}

		$args = $this->qb_object_args( $product );

		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$result = $this->create( $args );
		$error  = $this->get_error();

		if ( $error ) {
			return new WP_Error(
				'zwqoi_product_create_error',
				sprintf( __( 'There was an error creating a QuickBooks Item for this product: %d', 'zwqoi' ), $this->get_wp_id( $product ) ),
				$error
			);
		}

		if ( isset( $results[1]->Id ) ) {
			$this->update_connected_qb_id( $product, $results[1]->Id )->save();
		}

		return $result[1];
	}

	public function update_qb_object_with_wp_object( $qb_object, $wp_object ) {
		$item    = $qb_object instanceof API\Data\IPPItem ? $qb_object : $this->get_by_id( $qb_object );
		$product = $this->get_product( $wp_object );

		if ( ! $product || ! $item ) {
			return false;
		}

		$args = $this->qb_object_args( $product );

		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$args['sparse'] = true;

		$result = $this->update( $item, $args );
		$error  = $this->get_error();

		if ( $error ) {
			return new WP_Error(
				'zwqoi_product_update_error',
				sprintf( __( 'There was an error updating a QuickBooks Item for this product: %d', 'zwqoi' ), $this->get_wp_id( $product ) ),
				$error
			);
		}

		if ( isset( $results[1]->Id ) ) {
			$this->update_connected_qb_id( $product, $results[1]->Id )->save();
		}

		return $result[1];
	}

	protected function qb_object_args( $wp_object ) {
		$product  = $this->get_wp_object( $wp_object );
		$accounts = Settings::get_accounts();

		if ( ! $accounts ) {
			return new WP_Error(
				'zwqoi_product_error_missing_accounts',
				sprintf( __( 'There was an error creating a QuickBooks Item for this product: %d. You need to setup the inventory/expense/income accounts in the integration settings.', 'zwqoi' ), $this->get_wp_id( $product ) )
			);
		}

		$args = array(
			'Name'               => $product->get_name(),
			'FullyQualifiedName' => $product->get_name(),
			'Description'        => $product->get_description(),
			'Active'             => 'publish' === $product->get_status(),
			'Taxable'            => 'taxable' === $product->get_tax_status(),
			'UnitPrice'          => $product->get_price(),
			'Sku'                => $product->get_sku(),
			'TrackQtyOnHand'     => !! $product->get_manage_stock(),
			'QtyOnHand'          => $product->get_stock_quantity(),
			'Type'               => 'Inventory',
			'InvStartDate'       => new \DateTime( 'NOW' ),
			'IncomeAccountRef'   => array(
				'value' => $accounts['income']->Id,
				'name' => $accounts['income']->Name,
			),
			'ExpenseAccountRef' => array(
				'value' => $accounts['expense']->Id,
				'name' => $accounts['expense']->Name,
			),
			'AssetAccountRef' => array(
				'value' => $accounts['inventory_asset']->Id,
				'name' => $accounts['inventory_asset']->Name,
			),
		);

		return $args;
	}

	public function set_product_meta( $wc_product, $product, $meta_keys ) {
		foreach ( $meta_keys as $product_key ) {
			if ( ! empty( $product->{$product_key} ) ) {
				$meta_key = wc_clean( 'qb_' . $product_key );
				$wc_product->update_meta_data( $meta_key, wc_clean( $product->{$product_key} ) );
			}
		}
	}

	protected function output_result_item( $item ) {
		$html = '';
		if ( 'error' === $item['id'] ) {
			$html .= '<li class="error">' . $item['name'] . '</li>';
		} elseif ( ! empty( $item['taken'] ) ) {
			$product_edit_link = '<a href="' . get_edit_post_link( $item['taken'] ) . '">' . $this->get_product( $item['taken'] )->get_name() . '</a>';
			$html .= '<li><strike>' . $item['name'] . '</strike> ' . sprintf( esc_attr__( 'This Product is already associated to %s', 'zwqoi' ), $product_edit_link ) . '</li>';
		} else {
			$html .= '<li><span class="dashicons dashicons-download"></span> <a href="' . esc_url( $this->import_url( $item['id'] ) ) . '">' . $item['name'] . '</a></li>';
		}

		return apply_filters( 'zwqoi_output_product_search_result_item', $html, $item );
	}

	/*
	 * Text methods
	 */

	public function text_search_page_title() {
		return __( 'QuickBooks Product Search', 'zwqoi' );
	}

	public function text_search_page_menu_title() {
		return __( 'QuickBooks Products', 'zwqoi' );
	}

	public function text_update_from_qb_button_confirm() {
		return __( 'This will replace the WordPress Product data with the QuickBooks Product data. Are you sure you want to proceed?', 'zwqoi' );
	}

	public function text_update_from_qb_button() {
		return __( 'Update product with QuickBooks data', 'zwqoi' );
	}

	public function text_import_as_new_from_qb() {
		return __( 'Import as new product', 'zwqoi' );
	}

	public function text_search_placeholder() {
		return __( 'Item Name or ID', 'zwqoi' );
	}

	public function text_object_single_name_name() {
		return __( 'Item Name', 'zwqoi' );
	}

	public function text_object_id_name() {
		return __( 'Item ID', 'zwqoi' );
	}

	public function text_submit_button() {
		return __( 'Search for Item', 'zwqoi' );
	}

	public function text_search_help() {
		return __( 'Click on one of the results to import the result as a WordPress product.', 'zwqoi' );
	}

	/*
	 * Utilities
	 */

	public function search_query_format( $search_type ) {
		return 'name' === $search_type
			? "SELECT * FROM Item WHERE Name = %s"
			: "SELECT * FROM Item WHERE Id = %s";
	}

	public function get_by_id_error( $error, $qb_id ) {
		return new WP_Error(
			'zwqoi_product_get_by_id_error',
			sprintf( __( 'There was an error retrieving this product: %d', 'zwqoi' ), $qb_id ),
			$error
		);
	}

	public function is_wp_object( $object ) {
		return $object instanceof WP_Post || $object instanceof WC_Product;
	}

	public function get_wp_object( $wp_id ) {
		return $this->is_wp_object( $wp_id ) ? $wp_id : wc_get_product( absint( $wp_id ) );
	}

	public function get_wp_id( $object ) {
		if ( ! $this->is_wp_object( $object ) ) {
			return 0;
		}

		if ( $object instanceof WP_Post ) {
			return $object->ID;
		}

		if ( $object instanceof WC_Product ) {
			return $object->get_id();
		}
	}

	public function get_wp_name( $object ) {
		if ( ! $this->is_wp_object( $object ) ) {
			return '';
		}

		if ( $object instanceof WP_Post ) {
			return get_the_title( $object->ID );
		}

		if ( $object instanceof WC_Product ) {
			return $object->get_name();
		}
	}

	public function found_product_error( $message_format, $link_text, WP_Post $product ) {
		$link = $this->get_wp_object_edit_link( $product, $link_text );

		return new WP_Error(
			'zwqoi_product_import_error',
			sprintf( $message_format, $link ),
			$product
		);
	}

	public function get_product( $wp_id ) {
		$wc_product = false;
		if ( $wp_id instanceof WC_Product ) {
			$wc_product = $wp_id;
		} elseif ( $this->is_wp_object( $wp_id ) ) {
			$wc_product = wc_get_product( $this->get_wp_id( $wp_id ) );
		} elseif ( is_numeric( $wp_id ) ) {
			$wc_product = wc_get_product( absint( $wp_id ) );
		}

		return $wc_product;
	}

	public function get_qb_object_name( $qb_object ) {
		return self::get_item_name( $qb_object );
	}

	public static function get_item_name( $item, $with_sku = true ) {
		$name = self::get_value_from_object( $item, array(
			'Name',
			'FullyQualifiedName',
			'Id',
		) );

		if ( $with_sku ) {
			$sku = self::get_value_from_object( $item, array(
				'Sku',
			) );

			$name .= ' (' . $sku . ')';
		}

		return $name;
	}

	public function admin_page_url() {
		return admin_url( 'admin.php?page=' . $this->admin_page_slug );
	}

	public function update_url( $wp_id, $qb_id, $query_args = array() ) {
		$url = parent::update_url( $wp_id, $qb_id, $query_args );

		return apply_filters( 'zwqoi_update_product_with_quickbooks_product_url', $url, $wp_id, $qb_id, $query_args );
	}

	public function import_url( $qb_id, $force = false ) {
		$url = parent::import_url( $qb_id, $force );

		return apply_filters( 'zwqoi_import_product_url', $url, $qb_id );
	}

	public function update( $object, $args ) {
		return $this->update_product( $object, $args );
	}

	public function create( $args ) {
		return $this->create_product( $args );
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
			'Name'        => 'Zao',
			'DisplayName'        => 'Zao',
			'PrimaryPhone'       =>  array(
				'FreeFormNumber' => '(408) 606-5775'
			),
			'PrimaryEmailAddr' =>  array(
				'Address' => 'jt@zao.is',
			)
		);
		list( $product, $result ) = $this->create_product( $args );
		*/
	}

}
