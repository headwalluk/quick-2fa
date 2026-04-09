/**
 * Quick 2FA — Password reminder page
 *
 * Toggles the visibility of the new-password input on the password
 * reminder page. Reads localised aria-labels from data-attributes on
 * the toggle button so no inline strings are required.
 *
 * @package Quick_2FA
 * @since   1.0.0
 */
( function() {
	'use strict';

	var button = document.querySelector( '.wp-hide-pw' );
	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function() {
		var input    = document.getElementById( 'q2fa_new_password' );
		var isHidden = '0' === this.getAttribute( 'data-toggle' );

		if ( ! input ) {
			return;
		}

		input.type = isHidden ? 'text' : 'password';
		this.setAttribute( 'data-toggle', isHidden ? '1' : '0' );
		this.setAttribute(
			'aria-label',
			isHidden ? this.getAttribute( 'data-label-hide' ) : this.getAttribute( 'data-label-show' )
		);
	} );
} )();
