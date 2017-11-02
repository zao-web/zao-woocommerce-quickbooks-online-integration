<?php
namespace Zao\WC_QBO_Integration\Services;

use WC_Order, WC_Customer, WP_Post, WP_Error, WP_Query;
use Zao\WC_QBO_Integration\Admin\Settings;
use QuickBooksOnline\API;

class Invoices extends Base {

	protected $meta_key = '_qb_invoice_id';
	protected $post_type = 'shop_order';
	protected $customer_objects = array();
	protected $to_invoice = array();

	public function __construct( Customers $customers, Products $products ) {
		$this->customers = $customers;
		$this->products = $products;
	}

	public function init() {
		add_action( 'all_admin_notices', array( $this, 'maybe_output_invoice_error' ) );
		add_action( 'woocommerce_new_order', array( $this, 'store_invoice_order_ids' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'store_invoice_order_ids' ), 10, 2 );

		// if ( isset( $_GET['debug_invoice'] ) ) {
		// 	add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'maybe_sync_invoice' ) );
		// }
	}

	public function maybe_output_invoice_error() {
		$screen = get_current_screen();

		if ( 'woocommerce' !== $screen->parent_base || 'shop_order' !== $screen->post_type  ) {
			return;
		}

		$order = wc_get_order( get_the_ID() );
		if ( ! $order ) {
			return;
		}

		$results = $order->get_meta( 'zwqoi_invoice_order_error' );

		if ( ! is_array( $results ) ) {
			return;
		}

		foreach ( $results as $result ) {
			if ( ! isset( $result['err_type'], $result['message'] ) ) {
				continue;
			}
			echo '<div id="message" class="' . $result['err_type'] . ' notice is-dismissible"><p>';
			echo $result['message'];
			echo '</p></div>';
		}

		$order->delete_meta_data( 'zwqoi_invoice_order_error' );
		$order->save_meta_data();
	}

	public function store_invoice_order_ids( $order_id ) {
		$this->to_invoice[ $order_id ] = $order_id;
		add_action( 'shutdown', array( $this, 'sync_invoices' ) );
	}

	public function sync_invoices() {
		if ( ! empty( $this->to_invoice ) ) {
			foreach ( $this->to_invoice as $order_id => $order_id ) {
				$this->maybe_sync_invoice( $order_id );
			}
		}
	}

	public function maybe_sync_invoice( $order_id ) {
		$order = $this->get_wp_object( $order_id );

		// Do not sync when the order is completed.
		$do_sync = 'completed' !== $order->get_status();

		if ( ! apply_filters( 'zwqoi_sync_invoice_from_order', $do_sync, $order ) ) {
			return false;
		}

		$invoice_id = $this->get_connected_qb_id( $order_id );
		$invoice    = null;

		if ( $invoice_id ) {
			$invoice = $this->get_by_id( $invoice_id );
		}

		if ( is_wp_error( $invoice ) ) {
			$this->store_api_error( __( 'Invoice fetch error:', 'zwqoi' ), $invoice, $order );
			return false;
		}

		$result = $invoice
			? $this->update_qb_object_with_wp_object( $invoice, $order )
			: $this->create_qb_object_from_wp_object( $order );

		if ( is_wp_error( $result ) ) {
			$this->store_api_error( __( 'Invoice create/update error:', 'zwqoi' ), $result, $order );
			return false;
		}

		return $result;
	}

	public function store_api_error( $title, $error, $order ) {
		$result = $this->products->get_error_message_from_result( $error );
		$result['message'] = '<strong>'. $title .'</strong><br>' . $result['message'];

		$results = $order->get_meta( 'zwqoi_invoice_order_error' );

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$results[] = $result;

		$order->update_meta_data( 'zwqoi_invoice_order_error', $results );
		$order->save_meta_data();
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
		if ( is_wp_error( $args ) || ! $args ) {
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

		$args = $this->qb_object_args( $order, true );
		if ( is_wp_error( $args ) || ! $args ) {
			return $args;
		}

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
		if ( is_wp_error( $result ) ) {
			return $result;
		}

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

	protected function qb_object_args( $wp_object, $update = false ) {
		$order          = $this->get_wp_object( $wp_object );
		$qb_customer_id = $this->get_order_customer_id( $order->get_user_id() );
		$customer       = $this->get_customer_by_id( $qb_customer_id );

		if ( is_wp_error( $qb_customer_id ) ) {
			return $qb_customer_id;
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

		$billing = self::get_formatted_billing_address( $order, $customer );
		if ( ! empty( $billing ) ) {
			$args['BillAddr'] = $billing;
		}

		$shipping = self::get_formatted_shipping_address( $order, $customer );
		if ( ! empty( $shipping ) ) {
			$args['ShipAddr'] = $shipping;
		}

		if ( empty( $args['CustomerRef']['value'] ) || empty( $args['BillEmail']['Address'] ) ) {
			return false;
		}

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

		// Add as line item with DetailType DiscountLineDetail
		$discount_total = $order->get_discount_total();

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

		// Add the discount line items.
		if ( ! empty( $discount_total ) ) {
			$coupon_lines = $order->get_items( 'coupon' );
			$lines = $this->create_discount_lines( $discount_total, $coupon_lines );
			foreach ( $lines as $line ) {
				$line['LineNum'] = ++$linenums;
				$args['Line'][] = $line;
			}

			$summary = $this->get_coupon_summary( $coupon_lines, $order );

			if ( ! empty( $summary ) ) {
				$args['CustomerMemo'] = $summary;
			}
		}

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

		if ( empty( $args['Line'] ) ) {
			return false;
		}

		// $preferences = $this->get_preferences();
		// if ( ! empty( $preferences->SalesFormsPrefs->CustomTxnNumbers ) && 'false' !== $preferences->SalesFormsPrefs->CustomTxnNumbers ) {
			$args['DocNumber'] = $order->get_id();
		// }

		$args = apply_filters( 'zwqoi_discount_lines', $args, $order, $qb_customer_id, $customer );

		// echo '<xmp>'. __LINE__ .') $customer: '. print_r( $customer, true ) .'</xmp>';
		// if ( isset( $_GET['debug_invoice'] ) ) {
		// 	wp_die( '<xmp>'. __LINE__ .') $args: '. print_r( $args, true ) .'</xmp>' );
		// }
		// error_log( __FUNCTION__ . ':' . __LINE__ .') $args (update? '. ( $update ? '1' : '0' ) .'): '. print_r( $args, true ) );
		return $args;
	}

	protected function create_invoice_line_from_item( $item ) {
		$product      = $parent = $item->get_product();
		$is_variation = 'variation' === $product->get_type();
		$price        = $product->get_price();
		// Use subtotal, QB calculates coupons separately.
		$total        = $item->get_subtotal();

		$line = array(
			'Description'         => $item->get_name(),
			'Amount'              => floatval( self::number_format( $total ) ),
			'DetailType'          => 'SalesItemLineDetail',
			'SalesItemLineDetail' => array(
				'UnitPrice' => $price ? self::number_format( $product->get_price() ) : '0',
				'Qty'       => intval( max( 1, $item->get_quantity() ) ),
			),
		);

		// Connected QB products are currently set on parent product, not per-variation.
		if ( $is_variation ) {
			$parent = wc_get_product( $product->get_parent_id() );
		}

		$qb_item_id = $this->products->get_connected_qb_id( $parent );

		if ( ! $qb_item_id && apply_filters( 'zwqoi_create_items_from_invoice_products', true ) ) {
			$result = $this->products->create_qb_object_from_wp_object( $parent );
			$qb_item_id = isset( $result->Id ) ? $result->Id : 0;
		}

		if ( $qb_item_id ) {
			$sku = $product->get_sku();

			if ( $sku ) {
				$line['Description'] .= sprintf( __( ', SKU: %s', 'zwqoi' ), $sku );
			}

			if ( apply_filters( 'zwqoi_invoice_line_description_include_ids', false ) ) {
				if ( $is_variation ) {
					$line['Description'] .= sprintf( __( ', Product ID: %d', 'zwqoi' ), $parent->get_id() );
					$line['Description'] .= sprintf( __( ', Variation ID: %d', 'zwqoi' ), $product->get_id() );
				} else {
					$line['Description'] .= sprintf( __( ', Product ID: %d', 'zwqoi' ), $product->get_id() );
				}
			}

			$line['SalesItemLineDetail']['ItemRef'] = array(
				'value' => $qb_item_id, // Use parent connected QB ID
				'name'  => $line['Description'], // Will use variation product name if applicable.
			);
		}

		return apply_filters( 'zwqoi_invoice_line', $line, $product, $qb_item_id );
	}

	protected function create_fee_line_from_item( $fee ) {
		$total = self::number_format( $fee->get_total() );
		$line = self::fee_line( $fee->get_name(), $total );

		return apply_filters( 'zwqoi_fee_line', $line, $fee );
	}

	protected static function fee_line( $description, $total ) {
		return array(
			'Description'         => $description,
			'Amount'              => floatval( $total ),
			'DetailType'          => 'SalesItemLineDetail',
			'SalesItemLineDetail' => array(
				'MarkupInfo' => array(
					'PercentBased' => false,
					'Value'        => $total,
				),
			),
		);
	}

	protected function create_discount_lines( $discount_total, $coupon_lines ) {
		$lines         = array();
		$total_percent = 0;

		$line = array(
			'Description'        => __( 'Discounts', 'zwqoi' ),
			'DetailType'         => 'DiscountLineDetail',
			'Amount'             => $discount_total,
			'DiscountLineDetail' => array(
				'PercentBased' => false,
			),
		);

		if ( ! empty( $coupon_lines ) ) {
			$coupon_names  = array();
			$percent_based = true;

			foreach ( $coupon_lines as $coupon_item ) {
				$coupon = new \WC_Coupon( $coupon_item->get_code() );

				if ( ! $coupon->is_valid() ) {
					continue;
				}

				$coupon_names[] = $coupon_item->get_name();

				if ( ! $coupon->is_type( 'percent' ) ) {
					$percent_based = false;
				} elseif ( $percent_based ) {
					$total_percent += $coupon->get_amount();
				}
			}

			if ( $percent_based ) {
				unset( $line['Amount'] );
				$line['DiscountLineDetail']['PercentBased']    = true;
				$line['DiscountLineDetail']['DiscountPercent'] = $total_percent;
			}

			if ( ! empty( $coupon_names ) ) {
				$line['Description'] = sprintf( __( 'Coupon Discounts: %s', 'zwqoi' ), implode( ', ', $coupon_names ) );
			}
		}

		$lines[] = $line;

		return apply_filters( 'zwqoi_discount_lines', $lines, $coupon_lines );
	}

	protected function create_shipping_line_from_items( $shipping_items ) {
		$description = array();
		$shipping_total = 0;
		foreach ( $shipping_items as $shipping ) {
			$description[]  = $shipping->get_name();
			$shipping_total += $shipping->get_total();
		}

		$line = array(
			'Amount'      => floatval( self::number_format( $shipping_total ) ),
			'Description' => implode( '; ', $description ),
			'DetailType'  => 'SalesItemLineDetail',
			'SalesItemLineDetail' => array(
				'ItemRef' => array(
					'value' => 'SHIPPING_ITEM_ID',
				),
			),
		);

		return apply_filters( 'zwqoi_shipping_line', $line, $shipping_items );
	}

	protected function get_coupon_summary( $coupon_lines, $order ) {
		$summary = array();

		if ( ! empty( $coupon_lines ) ) {
			foreach ( $coupon_lines as $coupon_item ) {
				$coupon = new \WC_Coupon( $coupon_item->get_code() );

				if ( ! $coupon->is_valid() ) {
					continue;
				}

				$discount = $coupon->is_type( 'percent' )
					? $coupon->get_amount() . '%'
					: self::price_with_currency( $coupon_item->get_discount(), array(
						'currency' => $order->get_currency()
					) );

				$summary[] = sprintf(
					__( '- "%s", %s off order', 'zwqoi' ),
					esc_attr( $coupon_item->get_name() ),
					esc_attr( $discount )
				);
			}
		}

		$summary = implode( "\n", $summary );
		if ( ! empty( $summary ) ) {
			$summary = __( 'Applied Coupons:', 'zwqoi' ) . "\n" . $summary;
		}

		return apply_filters( 'zwqoi_coupon_summary', $summary, $coupon_lines, $order );
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
						$value = self::number_format( $value );
						$compare_value = self::number_format( $compare_value );
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

	public function delete_connected_qb_object( $wp_id ) {
		$invoice_id = $this->get_connected_qb_id( $wp_id );
		$result     = false;
		$invoice    = false;

		if ( $invoice_id ) {
			$invoice = $this->get_by_id( $invoice_id );
		}

		if ( $error = $this->get_error() ) {
			return self::invoice_delete_error( __LINE__, $invoice_id, $order->get_id(), $error );
		}

		if ( empty( $invoice->Id ) || ! isset( $invoice->SyncToken ) ) {
			return self::invoice_delete_error( __LINE__, $invoice_id, $order->get_id(), $invoice );
		}

		if ( isset( $invoice->SyncToken ) ) {
			$result = $this->delete_entity( $invoice );
		}

		if ( $error = $this->get_error() ) {
			return self::invoice_delete_error( __LINE__, $invoice_id, $order->get_id(), $error );
		}

		if ( $result && ! is_wp_error( $result ) ) {
			$this->disconnect_qb_object( $wp_id );
		}

		return $result;
	}

	protected static function invoice_delete_error( $line, $invoice_id, $order_id, $data = null ) {
		return new WP_Error(
			"zwqoi_invoice_delete_error_$line",
			sprintf( __( 'There was an error deleting the QuickBooks Invoice (%s) for this order: %d', 'zwqoi' ), $invoice_id, $order_id ),
			$data
		);
	}

	public function update_connected_qb_id( $wp_id, $meta_value ) {
		$order = $this->get_wp_object( $wp_id );
		if ( ! $order ) {
			return false;
		}

		$order->update_meta_data( $this->meta_key, $meta_value );
		return $order->save_meta_data();
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
		if ( $wp_id instanceof WP_Post ) {
			$wp_id = wc_get_order( $wp_id->ID );
		}

		return $this->is_wp_object( $wp_id ) ? $wp_id : wc_get_order( absint( $wp_id ) );
	}

	public function get_wp_id( $object ) {
		if ( ! $this->get_wp_object( $object ) ) {
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

		return $this->get_wp_object( $object )->get_name();
	}

	public function get_connected_qb_id( $wp_id ) {
		$order = $this->get_wp_object( $wp_id );
		return $order ? $order->get_meta( $this->meta_key ) : false;
	}

	public function disconnect_qb_object( $wp_id ) {
		$order = $this->get_wp_object( $wp_id );
		if ( ! $order ) {
			return false;
		}

		$order->delete_meta_data( $this->meta_key );
		return $order->save_meta_data();
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

	/*
	 * Utilities
	 */

	protected static function number_format( $value ) {
		return number_format( floatval( $value ), 2, '.', '' );
	}

	public static function get_formatted_billing_address( $order, $customer ) {
		$address = self::get_formatted_address( $order->get_formatted_billing_address() );
		if ( empty( $address ) ) {
			$address = self::get_formatted_address_from_customer( $customer, 'BillAddr' );
		}

		return $address;
	}

	public static function get_formatted_shipping_address( $order, $customer ) {
		$address = self::get_formatted_address( $order->get_formatted_shipping_address() );
		if ( empty( $address ) ) {
			$address = self::get_formatted_address_from_customer( $customer, 'ShipAddr' );
		}

		return $address;
	}

	public static function get_formatted_address_from_customer( $customer, $addressType ) {
		$lines = $address_lines = array();
		if ( empty( $customer->{$addressType} ) ) {
			return $lines;
		}

		$to_check = array(
			'Line1',
			'Line2',
			'Line3',
			'Line4',
			'Line5',
		);

		$name = '';

		if ( ! empty( $customer->GivenName ) ) {
			$name .= $customer->GivenName;
		}

		if ( ! empty( $customer->FamilyName ) ) {
			$name .= $name ? ' ' . $customer->FamilyName : $customer->FamilyName;
		}

		if ( ! empty( $name ) ) {
			$lines[] = $name;
		}

		if ( ! empty( $customer->CompanyName ) ) {
			$lines[] = $customer->CompanyName;
		}

		foreach ( $to_check as $part ) {
			if ( ! empty( $customer->{$addressType}->{$part} ) ) {
				$lines[] = $customer->{$addressType}->{$part};
			}
		}

		$combined = '';
		if ( ! empty( $customer->{$addressType}->City ) ) {
			$combined .= $customer->{$addressType}->City;
		}

		if ( ! empty( $customer->{$addressType}->CountrySubDivisionCode ) ) {
			$combined .= $combined ? ', ' . $customer->{$addressType}->CountrySubDivisionCode : $customer->{$addressType}->CountrySubDivisionCode;
		}

		if ( ! empty( $customer->{$addressType}->PostalCode ) ) {
			$combined .= $combined ? ' ' . $customer->{$addressType}->PostalCode : $customer->{$addressType}->PostalCode;
		}

		if ( ! empty( $combined ) ) {
			$lines[] = $combined;
		}

		if ( ! empty( $customer->{$addressType}->Country ) ) {
			$lines[] = $customer->{$addressType}->Country;
		}

		$lines = array_unique( $lines );
		foreach ( $parts as $index => $line ) {
			$line_num = $index + 1;
			if ( $line_num > 5 ) {
				break;
			}
			$address_lines[ 'Line' . $line_num ] = html_entity_decode( $line );
		}

		return $address_lines;
	}

	public static function get_formatted_address( $address ) {
		if ( ! empty( $address ) ) {
			$address_lines = array();
			$parts = array_unique( explode( '<br/>', $address ) );
			foreach ( $parts as $index => $line ) {
				$line_num = $index + 1;
				if ( $line_num > 5 ) {
					break;
				}
				$address_lines[ 'Line' . $line_num ] = html_entity_decode( $line );
			}

			return $address_lines;
		}
	}

	public static function price_with_currency( $price, $args = array() ) {
		return strip_tags( html_entity_decode( wc_price( $price, $args ) ) );
	}

}
