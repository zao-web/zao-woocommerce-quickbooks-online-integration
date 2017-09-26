<?php
namespace Zao\WC_QBO_Integration\Services;

use WC_Order, WC_Customer, WP_Post, WP_Error, WP_Query;
use Zao\WC_QBO_Integration\Admin\Settings;
use QuickBooksOnline\API;

class Invoices extends Base {

	protected $meta_key = '_qb_invoice_id';
	protected $post_type = 'shop_order';
	protected $customer_objects = array();

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

		if ( is_wp_error( $invoice ) ) {
			// TODO: surface errors.
			error_log( __FUNCTION__ . ':' . __LINE__ .') Invoice get error: '. print_r( $invoice, true ) );
			return false;
		}

		$result = $invoice
			? $this->update_qb_object_with_wp_object( $invoice, $order )
			: $this->create_qb_object_from_wp_object( $order );

		if ( is_wp_error( $result ) ) {
			// TODO: surface errors.
			error_log( __FUNCTION__ . ':' . __LINE__ .') Invoice create/update error: '. print_r( $result, true ) );
			return false;
		}

		return $result;
	}

	public function get_order_customer_id( $user_id ) {
		$customer_id = $this->customers->get_connected_qb_id( $user_id );
		if ( ! $customer_id && apply_filters( 'zwqoi_create_customers_from_invoice_user', true ) ) {
			$customer = $this->customers->create_qb_object_from_wp_object( $user_id );

			if ( isset( $customer->Id ) ) {
				// Store this customer.
				$this->customer_objects[ $customer->Id ] = $customer;
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
		$customer       = $this->get_customer_by_id( $qb_customer_id );

		if ( is_wp_error( $qb_customer_id ) ) {
			return false;
		}

		$args = array(
			'CustomerRef' => array(
				'value' => $qb_customer_id,
			),
			'BillEmail' => array(
				'Address' => $order->get_billing_email(),
			),

			/*
			 * Individual line items of a transaction. Valid Line types include:
			 *   * Sales item line (DetailType: SalesItemLineDetailfor)
			 *   * Group item line  (DetailType: GroupLineDetailfor)
			 *   * Description only (also used for inline Subtotal lines) (DetailType: DescriptionOnlyfor)
			 *   * Discount line (DetailType: DiscountLineDetailfor)
			 *   * Subtotal Line (used for the overall transaction) (DetailType: SubtotalLineDetailfor)
			 */
			'Line' => array(),
		);

		if ( ! empty( $customer->SalesTermRef ) ) {
			$args['SalesTermRef'] = $customer->SalesTermRef;
		}

		if ( ! empty( $customer->ShipMethodRef ) ) {
			$args['ShipMethodRef'] = $customer->ShipMethodRef;
		}

		$line_items = $order->get_items( 'line_item' );

		// Fees. Should come before shipping.
		$fee_lines = $order->get_items( 'fee' );

		// Concatenate shipping item names and save to shipping name
		// and add values to add as line item with SHIPPING_ITEM_ID itemref
		$shipping_lines = $order->get_items( 'shipping' );

		// Add values to add as line item with DetailType DiscountLineDetail
		$coupon_lines = $order->get_items( 'coupon' );

		// TODO: implement taxes someday
		// $tax_lines = $order->get_items( 'tax' );

		$linenums = 0;
		if ( ! empty( $line_items ) ) {
			foreach ( (array) $line_items as $item ) {
				$line = $this->create_invoice_line_from_item( $item );
				$line['LineNum'] = ++$linenums;
				$args['Line'][] = $line;
			}
		}

		// Add fees as normal line items. Should come before shipping or will be a blank spot in the invoice.
		if ( ! empty( $fee_lines ) ) {
			foreach ( (array) $fee_lines as $fee ) {
				$line = $this->create_fee_line_from_item( $fee );
				$line['LineNum'] = ++$linenums;
				$args['Line'][] = $line;
			}
		}

		// TODO; Maybe Wait for coupon improvements in WC 3.2,
		// https://woocommerce.wordpress.com/2017/08/24/coupon-and-cart-improvements-in-3-2/.
		// if ( ! empty( $coupon_lines ) ) {
		// 	$lines = $this->create_discount_lines_from_coupon_items( $coupon_lines );
		// 	foreach ( $lines as $line ) {
		// 		$line['LineNum'] = ++$linenums;
		// 		$args['Line'][] = $line;
		// 	}
		// }

		if ( ! empty( $shipping_lines ) ) {
			$shipping_line = $this->create_shipping_line_from_items( $shipping_lines );
			if ( ! empty( $shipping_line ) ) {

				// This avoids ValidationFault (code 2050):
				// String length is either shorter or longer than supported by specification.  Min:0 Max:30 supported.
				$args['ShipMethodRef'] = substr( $shipping_line['Description'], 0, 30 );

				// Remove this so it doesn't show as a line item in the invoice.
				unset( $shipping_line['Description'] );

				$args['Line'][] = $shipping_line;
			}
		}

		// $preferences = $this->get_preferences();
		// if ( ! empty( $preferences->SalesFormsPrefs->CustomTxnNumbers ) && 'false' !== $preferences->SalesFormsPrefs->CustomTxnNumbers ) {
			$args['DocNumber'] = $order->get_id();
		// }

		// echo '<xmp>'. __LINE__ .') $customer: '. print_r( $customer, true ) .'</xmp>';
		// error_log( __FUNCTION__ . ':' . __LINE__ .') $args: '. print_r( $args, true ) );
		// wp_die( '<xmp>'. __LINE__ .') $args: '. print_r( $args, true ) .'</xmp>' );
		return $args;
	}

	protected function create_invoice_line_from_item( $item ) {
		$product = $parent = $item->get_product();

		$line = array(
			'Description'         => $item->get_name(),
			'Amount'              => number_format( $item->get_total(), 2 ),
			'DetailType'          => 'SalesItemLineDetail',
			'SalesItemLineDetail' => array(
				'UnitPrice' => number_format( $product->get_price(), 2 ),
				'Qty'       => max( 1, $item->get_quantity() ),
			),
		);

		// Connected QB products are currently set on parent product, not per-variation.
		if ( 'variation' === $product->get_type() ) {
			$parent = wc_get_product( $product->get_parent_id() );
		}

		$item_id = $this->products->get_connected_qb_id( $parent );

		if ( ! $item_id && apply_filters( 'zwqoi_create_items_from_invoice_products', true ) ) {
			$result = $this->products->create_qb_object_from_wp_object( $parent );
			$item_id = isset( $result->Id ) ? $result->Id : 0;
		}

		if ( $item_id ) {
			$line['SalesItemLineDetail']['ItemRef'] = array(
				'value' => $item_id, // Use parent connected QB ID
				'name'  => $product->get_formatted_name(), // But use variation product name.
			);
		}

		return $line;
	}

	protected function create_fee_line_from_item( $fee ) {
		$total = number_format( $fee->get_total(), 2 );
		return self::fee_line( $fee->get_name(), $total );
	}

	protected static function fee_line( $description, $total ) {
		return array(
			'Description'         => $description,
			'Amount'              => $total,
			'DetailType'          => 'SalesItemLineDetail',
			'SalesItemLineDetail' => array(
				'MarkupInfo' => array(
					'PercentBased' => false,
					'Value'        => $total,
				),
			),
		);
	}

	protected function create_discount_lines_from_coupon_items( $coupon_lines ) {
		$lines = array();
		$total_percent = 0;

		foreach ( $coupon_lines as $coupon_item ) {
			$coupon = new \WC_Coupon( $coupon_item->get_code() );

			if ( ! $coupon->is_valid() ) {
				continue;
			}

			if ( $coupon->is_type( 'percent' ) ) {
				$total_percent += $coupon_item->get_discount();

			} else {

				$lines[] = self::fee_line(
					$coupon_item->get_name(),
					number_format( $coupon_item->get_discount(), 2 )
				);
			}
		}

		if ( ! empty( $total_percent ) ) {
			$lines[] = array(
				'Description'         => __( 'Coupon(s)', 'zwqoi' ),
				'DetailType'          => 'DiscountLineDetail',
				'DiscountLineDetail' => array(
					'PercentBased' => true,
					'DiscountPercent' => $total_percent,
				),
			);
		}

		return $lines;
	}

	protected function create_shipping_line_from_items( $shipping_items ) {
		$description = array();
		$shipping_total = 0;
		foreach ( $shipping_items as $shipping ) {
			$description[]  = $shipping->get_name();
			$shipping_total += $shipping->get_total();
		}

		$line = array(
			'Amount'      => number_format( $shipping_total, 2 ),
			'Description' => implode( '; ', $description ),
			'DetailType'  => 'SalesItemLineDetail',
			'SalesItemLineDetail' => array(
				'ItemRef' => array(
					'value' => 'SHIPPING_ITEM_ID',
				),
			),
		);

		return $line;
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

	public function get_customer_by_id( $customer_id ) {
		if ( ! isset( $this->customer_objects[ $customer_id ] ) ) {

			$customer = $this->customers->get_by_id( $customer_id );
			$error    = $this->get_error();

			if ( $error ) {
				return new WP_Error(
					'zwqoi_invoice_get_customer_by_id_error',
					sprintf( __( 'There was an error fetching this QuickBooks Customer (%d).', 'zwqoi' ), $customer_id ),
					$error
				);
			}

			$this->customer_objects[ $customer_id ] = $customer;
		}

		return $this->customer_objects[ $customer_id ];
	}

}
