<?php
namespace Zao\WC_QBO_Integration\Services;

class Invoices extends Base {

	public function init() {
	}

	public function testing() {
		try {

			$customers = $this->query( "SELECT * FROM Customer WHERE PrimaryEmailAddr = 'jt@zao.is'" );
			$this->die_if_error();

			if ( empty( $customers ) ) {

				list( $customer, $result ) = $this->create_customer( array(
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
					'CompanyName'        => 'Zao',
					'DisplayName'        => 'Zao',
					'PrimaryPhone'       =>  array(
						'FreeFormNumber' => '(408) 606-5775'
					),
					'PrimaryEmailAddr' =>  array(
						'Address' => 'jt@zao.is',
					)
				) );

				echo '<xmp>'. __LINE__ .') $result: '. print_r( $result, true ) .'</xmp>';

			} else {
				$customer = end( $customers );
			}

			if ( empty( $customer->Id ) ) {
				wp_die( '<xmp>'. __LINE__ .') $customer: '. print_r( $customer, true ) .'</xmp>' );
			}

			echo '<xmp>'. __LINE__ .') $customer->Id: '. print_r( $customer->Id, true ) .'</xmp>';
			print( '<xmp>'. __LINE__ .') $customer: '. print_r( $customer, true ) .'</xmp>' );

			list( $invoice_obj, $result ) = $this->create_invoice( array(
				// 'DocNumber' => '1070',
				// 'LinkedTxn' => array(),
				'Line' => array(
					array(
						'Description' => 'Yarn Purchases',
						'Amount' => 192.55,
						'DetailType' => 'SalesItemLineDetail',
						'SalesItemLineDetail' => array(
							'ItemRef' => array(
								'value' => '1',
								'name' => 'Yarn'
							),
						),
					),
				),
				'CustomerRef' => array(
					'value' => $customer->Id,
				)
			) );
			$this->die_if_error();

			if ( $result instanceof \Exception ) {
				wp_die( '<xmp>'. __LINE__ .') $error: '. print_r( $result, true ) .'</xmp>' );
			}

			wp_die( '<xmp>'. __LINE__ .') $result: '. print_r( $result, true ) .'</xmp>' );
			$invoice_obj = $invoice[0];

			$updated_invoice = $this->update_invoice( $invoice_obj, array(
				"sparse" => true,
				"Deposit" => 100000,
				"DocNumber" => "12223322"
			) );
			$this->die_if_error();

			echo '<xmp>'. __LINE__ .') $updated_invoice: '. print_r( $updated_invoice, true ) .'</xmp>';
			wp_die( '<xmp>'. __LINE__ .') $invoice: '. print_r( $invoice, true ) .'</xmp>' );

		} catch ( \Exception $e ) {
			wp_die( '<xmp>'. __LINE__ .') Exception: '. print_r( $e, true ) .'</xmp>' );
		}

	}
}
