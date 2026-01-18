/**
 * Quick 2FA Settings Page JavaScript
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Initialize Select2 for protected roles field.
		$('#quick2fa_protected_roles').select2({
			placeholder: quick2faSettings.selectRolesPlaceholder,
			width: '100%'
		});

		// Show/hide protected roles field based on selected mode.
		$('input[name="' + quick2faSettings.optionMode + '"]').on('change', function() {
			if ($(this).val() === quick2faSettings.modeRoles) {
				$('#protected-roles-row').show();
			} else {
				$('#protected-roles-row').hide();
			}
		});
	});
})(jQuery);
