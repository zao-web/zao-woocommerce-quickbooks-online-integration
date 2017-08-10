<?php
namespace Zao\WC_QBO_Integration;

abstract class Base {

	abstract public function init();

	/**
	 * Public getter method for retrieving protected/private variables.
	 * Provides access, but prevents stomping protected object properties.
	 *
	 * @since  0.1.0
	 * @param  string  $field Field to retrieve
	 * @return mixed          Field value or exception is thrown
	 */
	public function __get( $field ) {
		return $this->{$field};
	}
}
