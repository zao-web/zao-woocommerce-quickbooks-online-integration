/*! Zao WooCommerce QuickBooks Online Integration - v0.1.0
 * https://zao.is
 * Copyright (c) 2017; * Licensed GPL-2.0+ */
window.ZWQOI = window.ZWQOI || {};

( function( window, document, $, app, undefined ) {
	'use strict';

	// Cached jQuery objects.
	var $c = {};

	app.maybeDeleteInvoice = function( evt ) {
		evt.preventDefault();
		$c.check.show();

		$c.action.off( 'click', '.submitdelete', app.maybeDeleteInvoice );
	};

	app.cancelDeleteInvoice = function( evt ) {
		evt.preventDefault();

		if ( ! $c.check.find( '.spinner' ).hasClass( 'is-active' ) ) {
			$c.check.hide();
			$c.action.on( 'click', '.submitdelete', app.maybeDeleteInvoice );
		}
	};

	app.confirmDeleteInvoice = function( evt ) {
		evt.preventDefault();
		$c.check.find( '.spinner' ).addClass( 'is-active' );

		$.get( $c.delete.attr( 'href' ) + '&json=1', function( response ) {
			if ( response && response.data ) {
				$c.deleteConfirm.html( '<strong>' + response.data + '</strong>' );
			}

			if ( response.success ) {
				return window.setTimeout( app.trashIt, 500 );
			}

			$c.check.find( '.spinner' ).removeClass( 'is-active' );
			if ( ! response || ! response.data ) {
				$c.deleteConfirm.html( '<strong>' + app.l10n.unableToDelete + '</strong>' );
			}
		} );

	};

	app.noDeleteInvoice = function( evt ) {
		evt.preventDefault();
		app.trashIt();
	};

	app.trashIt = function() {
		window.location.href = $c.action.find( '.submitdelete' ).attr( 'href' );
	};

	app.init = function() {
		$c.action = $( document.getElementById( 'delete-action' ) );
		$c.delete = $( '.delete-qb-object' );

		$c.action.append( '<div id="check-delete-invoice" style="display:none;"><p id="delete-confirm-message">' + app.l10n.alsoDeleteInvoice + '</p><span class="clear spinner"></span><p><a href="#" id="cancel-delete-invoice">' + app.l10n.cancel + '</a><span><a class="button-link-delete" href="#" id="confirm-delete-invoice">' + app.l10n.yes + '</a>&nbsp;&nbsp;&nbsp;<a href="#" id="no-delete-invoice">' + app.l10n.no + '</a></span></p></div>' );

		$c.check = $( document.getElementById( 'check-delete-invoice' ) );
		$c.deleteConfirm = $c.check.find( '#delete-confirm-message' );

		$c.action
			.on( 'click', '.submitdelete', app.maybeDeleteInvoice )
			.on( 'click', '#cancel-delete-invoice', app.cancelDeleteInvoice )
			.on( 'click', '#confirm-delete-invoice', app.confirmDeleteInvoice )
			.on( 'click', '#no-delete-invoice', app.noDeleteInvoice );
	};

	$( app.init );

} )( window, document, window.jQuery, window.ZWQOI );
