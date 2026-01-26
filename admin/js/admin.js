/**
 * FediBoost admin scripts.
 *
 * @package kraftbj/fediboost
 */

( function() {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function() {
		var disconnectButtons = document.querySelectorAll( '.fediboost-disconnect-btn' );
		disconnectButtons.forEach( function( btn ) {
			btn.addEventListener( 'click', function( e ) {
				if ( ! window.confirm( fediboostAdmin.confirmDisconnect ) ) {
					e.preventDefault();
				}
			} );
		} );
	} );
}() );
