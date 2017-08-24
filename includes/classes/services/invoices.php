<?php
namespace Zao\WC_QBO_Integration\Services;

use WC_Order, WC_Customer, WP_Post, WP_Error, WP_Query, Zao\WC_QBO_Integration\Admin\Settings;

class Invoices extends Base {

	protected $meta_key = '_qb_invoice_id';
	protected $post_type = 'shop_order';

	public function __construct( Customers $customers, Products $products ) {
		$this->customers = $customers;
		$this->products = $products;
	}

	public function init() {
		// add_action( 'woocommerce_new_order', array( $this, 'maybe_invoice' ), 10, 2 );

		// add_action( 'woocommerce_update_order', array( $this, 'maybe_invoice' ), 10, 2 );
	}

	public function maybe_invoice( $order_id ) {

		if ( ! apply_filters( 'zwqoi_create_invoice_from_order', true, $order_id ) ) {
			return;
		}

		$order = $this->get_wp_object( $order_id );

		$has_invoice_id = $this->get_connected_qb_id( $order_id );
		$customer_id    = $this->get_order_customer_id( $order->get_user_id() );
		if ( is_wp_error( $customer_id ) ) {
			return;
		}

		if ( $has_invoice_id ) {
			$invoice = $this->get_by_id( $has_invoice_id );
			if ( ! $invoice ) {
				$has_invoice_id = false;
			}
		}

		wp_die( '<xmp>'. __FUNCTION__ . ':' . __LINE__ .') '. print_r( get_defined_vars(), true ) .'</xmp>' );
		if ( ! $has_invoice_id ) {
			$result = $this->create_qb_object_from_wp_object( $order, $customer_id );
		} else {

			// $has_invoice_id
			$result = $this->update_invoice( $invoice_obj, array(
				'sparse' => true,
				'Deposit' => 100000,
				'DocNumber' => "12223322"
			) );
		}


	}

	public function get_order_customer_id( $user_id ) {
		$customer_id = $this->customers->get_connected_qb_id( $user_id );
wp_die( '<xmp>'. __FUNCTION__ . ':' . __LINE__ .') '. print_r( get_defined_vars(), true ) .'</xmp>' );
		if ( ! $customer_id && apply_filters( 'zwqoi_create_customers_from_invoice_user', true ) ) {
			$customer = $this->customers->create_qb_object_from_wp_object( $user_id );

			if ( isset( $customer->Id ) ) {
				$customer_id = $customer->Id;
			}
		}

		return $customer_id;
	}

	public function create_qb_object_from_wp_object( $object ) {
		$order = $object;
		$qb_customer_id = $this->get_order_customer_id( $order->get_user_id() );
		if ( is_wp_error( $qb_customer_id ) ) {
			return false;
		}

		$customer = new WC_Customer( $order->get_user_id() );
		// $preferences = $this->get_preferences();

		$args = array(
			'CustomerRef' => array(
				'value' => $customer->Id,
			),
			'CustomField' =>  array(
				array(
					'DefinitionId' => '1',
					'Name'         => 'WordPress Invoice ID',
					'Type'         => 'StringType',
					'StringValue'  => $order->get_id(),
				)
			),
			'Line' => array(),
		);

		$line_items     = $order->get_items( 'line_item' );
		$tax_lines      = $order->get_items( 'tax' );
		$shipping_lines = $order->get_items( 'shipping' );
		$fee_lines      = $order->get_items( 'fee' );
		$coupon_lines   = $order->get_items( 'coupon' );

		if ( ! empty( $line_items ) ) {
			foreach ( (array) $line_items as $item ) {
				$product = $item->get_product();

				$line = array(
					'Description'         => $item->get_name(),
					'Amount'              => $item->get_subtotal(),
					'DetailType'          => 'SalesItemLineDetail',
					'SalesItemLineDetail' => array(
						'UnitPrice' => $product->get_price(),
						'Qty' => max( 1, $item->get_quantity() ),
					),
				);

				$item_id = $this->products->get_connected_qb_id( $product );

				if ( ! $item_id && Settings::get_option( 'item_account' ) ) {
					$result = $this->products->create_qb_object_from_wp_object( $product );
					$item_id = isset( $result->Id ) ? $result->Id : 0;
				}

				if ( $item_id ) {
					$line['SalesItemLineDetail']['ItemRef'] = array(
						'value' => $item_id,
						'name'  => $product->get_name(),
					);
				}

				$query = $wpdb->prepare(
					$this->search_query_format( $search_type ),
					$search_term
				);

				$results = $this->query( $query );

				$error = $this->get_error();

				$args['Line'][] = $line;
			}
		}

		// if ( ! empty( $preferences->SalesFormsPrefs->CustomTxnNumbers ) && 'false' !== $preferences->SalesFormsPrefs->CustomTxnNumbers ) {
			$args['DocNumber'] = $order->get_id();
		// }

		$result = $this->create( $args );

		$error = $this->get_error();

		if ( $error ) {
			return new WP_Error(
				'zwqoi_invoice_create_error',
				sprintf( __( 'There was an error creating a QuickBooks Invoice for this order: %d', 'zwqoi' ), $order->get_id() ),
				$error
			);
		}

		if ( isset( $results[1]->Id ) ) {
			$this->update_connected_qb_id( $product, $results[1]->Id )->save();
		}

		return $result[1];
	}

	public function update_connected_qb_id( $wp_id, $meta_value ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$wp_id = $this->get_wp_id( $wp_id );
		}

		return update_post_meta( $wp_id, $this->meta_key, $meta_value );
	}

	public function search_query_format( $search_type ) {
		return "SELECT * FROM Invoice WHERE Id = %s";
	}

	public function get_by_id_error( $error, $qb_id ) {
		return new WP_Error(
			'zwqoi_invoice_get_by_id_error',
			sprintf( __( 'There was an error retrieving this invoice: %d', 'zwqoi' ), $qb_id ),
			$error
		);
	}

	public function is_wp_object( $object ) {
		return $object instanceof WP_Post || $object instanceof WC_Order;
	}

	public function get_wp_object( $wp_id ) {
		return $this->is_wp_object( $wp_id ) ? $wp_id : wc_get_order( absint( $wp_id ) );
	}

	public function get_wp_id( $object ) {
		if ( ! $this->is_wp_object( $object ) ) {
			return 0;
		}

		if ( $object instanceof WP_Post ) {
			return $object->ID;
		}

		if ( $object instanceof WC_Order ) {
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

		if ( $object instanceof WC_Order ) {
			return $object->get_name();
		}
	}

	public function get_connected_qb_id( $wp_id ) {
		if ( $this->is_wp_object( $wp_id ) ) {
			$order = $wp_id;
		} else {
			$order = $this->get_wp_object( $wp_id );
		}

		if ( is_callable( array( $order, 'get_meta' ) ) ) {
			return $order->get_meta( $this->meta_key );
		}

		return get_post_meta( $this->get_wp_id( $order ), $this->meta_key, true );
	}

	public function create( $args ) {
		return $this->create_invoice( $args );

		/**
		list( $invoice_obj, $result ) = $this->create_invoice( array(
			// 'DocNumber' => '1070',
			// 'LinkedTxn' => array(),
			'Line' => array(
				array(
					'Description' => 'Sprinkler Purchases ?',
					'Amount' => 192.55,
					'DetailType' => 'SalesItemLineDetail',
					'SalesItemLineDetail' => array(
						'ItemRef' => array(
							'value' => '17',
							'name' => 'HOWDY'
						),
						'Qty' => 2,
					),
				),
			),
			'CustomerRef' => array(
				'value' => $customer->Id,
			)
		) );
		*/

	}

	// public function maybe_invoice( $order, $data_store ) {

	// }

}
