/**
 * Two-step progressive enhancement for the SMS sign-in modal.
 *
 * Without this script both forms simply render stacked and the flow still
 * works (type phone, submit, type phone and code, submit); the script only
 * streamlines it into two steps. It never sees or handles the code value
 * beyond the input the user types into, and it never writes to the console.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var requestForm = $( '.hp-form--hptw-otp-request' ),
			verifyForm = $( '.hp-form--hptw-otp-verify' );

		if ( ! requestForm.length || ! verifyForm.length || 'undefined' === typeof hptwOtpData ) {
			return;
		}

		/*
		 * Step two hides what the member types into step one, but deliberately
		 * NOT that form's messages container: core renders both the uniform
		 * "code sent" copy and the cooldown refusal into it, and "Send a new
		 * code" below re-submits this same form, so those replies have to stay
		 * readable from step two. Hiding the whole form is what let the resend
		 * control look like it had done something when it had not - the member
		 * saw the success line left over from the first request (staging,
		 * 2026-08-18).
		 *
		 * The fields container is left alone for the same reason: when an admin
		 * lists this form under Protected Forms, core adds the captcha as a
		 * FIELD of it (components/class-form.php::add_captcha), and it resets
		 * that widget after every submission - so hiding the whole block would
		 * leave the member unable to re-solve a captcha the resend needs. Only
		 * the phone field itself is hidden, below, once core has finished
		 * dressing it.
		 */
		var requestParts = requestForm.find( '.hp-form__description, .hp-form__footer' );

		// Step two starts hidden; the no-JS fallback never adds this class.
		verifyForm.addClass( 'hptw-otp-hidden' );

		// Core submits with jQuery's default global flag on, and the global
		// ajaxSuccess event fires before the local complete handler, so the
		// typed phone number is still present in the request form here.
		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( settings.url !== hptwOtpData.requestUrl ) {
				return;
			}

			var phoneInput = verifyForm.find( 'input[name=phone]' );

			phoneInput.val( requestForm.find( 'input[name=phone]' ).val() );
			phoneInput.closest( '.hp-form__field' ).addClass( 'hptw-otp-hidden' );

			// Resolved here rather than up front: core's phone widget rewrites
			// this field during initUI, moving the name onto a hidden input it
			// adds, so the wrapper is only reliable once a submit has happened.
			requestForm.find( 'input[name=phone]' ).closest( '.hp-form__field' ).addClass( 'hptw-otp-hidden' );

			verifyForm.removeClass( 'hptw-otp-hidden' );
			requestParts.addClass( 'hptw-otp-hidden' );

			verifyForm.find( 'input[name=code]' ).trigger( 'focus' );
		} );

		/*
		 * "Send a new code" really does request one. Submitting step one again
		 * runs core's own form handler, so the resend goes to the same
		 * endpoint with the same payload and meets the same cooldown, hourly
		 * caps and uniform response as the first request - and the number the
		 * member already typed is still in the field, so they never retype it.
		 */
		$( document ).on( 'click', '.hptw-otp-resend', function () {

			// Core disables the submit button for the life of a request, so
			// reusing that flag stops repeat clicks queueing more requests.
			if ( ! requestForm.find( ':submit' ).prop( 'disabled' ) ) {
				requestForm.trigger( 'submit' );
			}

			return false;
		} );
	} );
} )( jQuery );
