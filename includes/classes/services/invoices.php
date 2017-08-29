<?php
namespace Zao\WC_QBO_Integration\Services;

use WC_Order, WC_Customer, WP_Post, WP_Error, WP_Query;
use Zao\WC_QBO_Integration\Admin\Settings;
use QuickBooksOnline\API;

class Invoices extends Base {

	protected $meta_key = '_qb_invoice_id';
	protected $post_type = 'shop_order';

	public function __construct( Customers $customers, Products $products ) {
		$this->customers = $customers;
		$this->products = $products;
	}

	public function init() {
		add_action( 'woocommerce_new_order', array( $this, 'maybe_invoice' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'maybe_invoice' ), 10, 2 );
	}

	public function maybe_invoice( $order_id ) {
		if ( ! Settings::is_connected() ) {
			return;
		}

		if ( ! apply_filters( 'zwqoi_create_invoice_from_order', true, $order_id ) ) {
			return;
		}

		$order      = $this->get_wp_object( $order_id );
		$invoice_id = $this->get_connected_qb_id( $order_id );
		$invoice    = null;

		if ( $invoice_id ) {
			$invoice = $this->get_by_id( $invoice_id );
		}

		return $invoice
			? $this->update_qb_object_with_wp_object( $invoice, $order )
			: $this->create_qb_object_from_wp_object( $order );
	}

	public function get_order_customer_id( $user_id ) {
		$customer_id = $this->customers->get_connected_qb_id( $user_id );
		if ( ! $customer_id && apply_filters( 'zwqoi_create_customers_from_invoice_user', true ) ) {
			$customer = $this->customers->create_qb_object_from_wp_object( $user_id );

			if ( isset( $customer->Id ) ) {
				$customer_id = $customer->Id;
			}
		}

		return $customer_id;
	}

	public function create_qb_object_from_wp_object( $wp_object ) {
		$order = $this->get_wp_object( $wp_object );
		if ( ! $order ) {
			return false;
		}

		$args = $this->qb_object_args( $order );

		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$result = $this->create( $args );
		$error  = $this->get_error();

		if ( isset( $result[1]->Id ) ) {
			$this->update_connected_qb_id( $order, $result[1]->Id );
		}

		if ( $error ) {
			$result = new WP_Error(
				'zwqoi_invoice_create_error',
				sprintf( __( 'There was an error creating a QuickBooks Invoice for this order: %d', 'zwqoi' ), $order->get_id() ),
				$error
			);
		} else {
			$result = $result[1];
		}

		return $result;
	}

	public function update_qb_object_with_wp_object( $qb_object, $wp_object ) {
		$invoice = $qb_object instanceof API\Data\IPPInvoice ? $qb_object : $this->get_by_id( $qb_object );
		$order   = $this->get_wp_object( $wp_object );

		if ( ! $order || ! $invoice ) {
			return false;
		}

		$args = $this->qb_object_args( $order );

		$has_changes = $this->has_changes( $args, $invoice );

		// error_log( __FUNCTION__ . ':' . __LINE__ .') $has_changes: '. print_r( $has_changes, true ) );
		// error_log( __FUNCTION__ . ':' . __LINE__ .') $args: '. print_r( $args, true ) );
		if ( ! $has_changes ) {
			return false;
		}

		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$args['sparse'] = true;

		$result = $this->update( $invoice, $args );
		$error  = $this->get_error();

		if ( $error ) {
			// error_log( __FUNCTION__ . ':' . __LINE__ .') $result: '. print_r( $result, true ) );
			// error_log( __FUNCTION__ . ':' . __LINE__ .') $error: '. print_r( $error, true ) );
			return new WP_Error(
				'zwqoi_invoice_update_error',
				sprintf( __( 'There was an error updating a QuickBooks Invoice for this order: %d', 'zwqoi' ), $this->get_wp_id( $order ) ),
				$error
			);
		}

		if ( isset( $results[1]->Id ) ) {
			$this->update_connected_qb_id( $order, $results[1]->Id );
		}

		return $result[1];
	}

	protected function qb_object_args( $wp_object ) {
		$order          = $this->get_wp_object( $wp_object );
		$qb_customer_id = $this->get_order_customer_id( $order->get_user_id() );

		if ( is_wp_error( $qb_customer_id ) ) {
			return false;
		}

		$customer = new WC_Customer( $order->get_user_id() );

		$args = array(
			'CustomerRef' => array(
				'value' => $qb_customer_id,
			),
			'Line' => array(),
		);

		$line_items = $order->get_items( 'line_item' );

		// TODO: Add the following items to the invoice.
		// $tax_lines      = $order->get_items( 'tax' );
		// $shipping_lines = $order->get_items( 'shipping' );
		// $fee_lines      = $order->get_items( 'fee' );
		// $coupon_lines   = $order->get_items( 'coupon' );

		if ( ! empty( $line_items ) ) {
			foreach ( (array) $line_items as $item ) {
				$product = $item->get_product();

				$line = array(
					'Description'         => $item->get_name(),
					'Amount'              => number_format( $item->get_total(), 2 ),
					'DetailType'          => 'SalesItemLineDetail',
					'SalesItemLineDetail' => array(
						'UnitPrice' => number_format( $product->get_price(), 2 ),
						'Qty'       => max( 1, $item->get_quantity() ),
					),
				);

				$item_id = $this->products->get_connected_qb_id( $product );

				if ( ! $item_id && apply_filters( 'zwqoi_create_items_from_invoice_products', true ) ) {
					$result = $this->products->create_qb_object_from_wp_object( $product );
					$item_id = isset( $result->Id ) ? $result->Id : 0;
				}

				if ( $item_id ) {
					$line['SalesItemLineDetail']['ItemRef'] = array(
						'value' => $item_id,
						'name'  => $product->get_name(),
					);
				}

				$args['Line'][] = $line;
			}
		}

		// $preferences = $this->get_preferences();
		// if ( ! empty( $preferences->SalesFormsPrefs->CustomTxnNumbers ) && 'false' !== $preferences->SalesFormsPrefs->CustomTxnNumbers ) {
			$args['DocNumber'] = $order->get_id();
		// }

		return $args;
	}

	public function has_changes( $args, $compare ) {
		foreach ( $args as $key => $value ) {

			$compare_value = null;
			$type          = null;

			if ( is_scalar( $compare ) ) {
				$type = 'scalar';
				$compare_value = $compare;
			} elseif ( is_object( $compare ) && isset( $compare->{$key} ) ) {
				$type = 'object';
				$compare_value = $compare->{$key};
			} elseif ( is_array( $compare ) && isset( $compare[ $key ] ) ) {
				$type = 'array';
				$compare_value = $compare[ $key ];
			}

			if (
				is_object( $compare ) && ! isset( $compare->{$key} )
				|| is_array( $compare ) && ! isset( $compare[ $key ] )
			) {
				if ( $compare === ( is_scalar( $value ) ? (string) $value : $value ) ) {
					continue;
				}

				return compact( 'compare_value', 'value', 'type', 'compare' );
			}

			// Recurse.
			if ( is_array( $value ) ) {
				$has_changes = $this->has_changes( $value, $compare_value );
				if ( $has_changes ) {
					return $has_changes;
				}
				continue;
			}

			if ( $compare_value !== ( is_scalar( $value ) ? (string) $value : $value ) ) {
				if ( is_numeric( $value ) && is_numeric( $compare_value ) )  {
					$float = false !== strpos( $value, '.' ) || false !== strpos( $compare_value, '.' );
					if ( $float ) {
						$value = number_format( $value, 2 );
						$compare_value = number_format( $compare_value, 2 );
					} else {
						$value = intval( $value );
						$compare_value = intval( $compare_value );
					}
				}
			}

			if ( $compare_value !== ( is_scalar( $value ) ? (string) $value : $value ) ) {
				return compact( 'compare_value', 'value', 'type', 'compare' );
			}
		}
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

	public function get_qb_object_name( $qb_object ) {
		return sprintf( __( 'Invoice #%d', 'zwqoi' ), self::get_value_from_object( $qb_object, array(
			'DocNumber',
			'Id',
		) ) );
	}

	public function update( $object, $args ) {
		return $this->update_invoice( $object, $args );
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
