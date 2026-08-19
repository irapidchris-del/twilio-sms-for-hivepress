<?php
/**
 * OTP verify form.
 *
 * @package HivePress\Forms
 */

namespace HivePress\Forms;

use HivePress\Components\Hptw_Otp;
use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Signs in with an SMS code.
 */
class Hptw_Otp_Verify extends Form {

	/**
	 * Class initializer.
	 *
	 * Deliberately NOT captcha-protectable, unlike the request form. A form is
	 * only offered to captcha plugins when its meta carries a 'captcha' key
	 * (core: components/class-form.php::set_captcha; Turnstile for HivePress:
	 * inc/captcha.php::tfhp_set_captcha_meta), so omitting the key here keeps
	 * this form off both checklists.
	 *
	 * Staging proved why (2026-08-18). A captcha widget on this step is minted
	 * when the modal opens, then has to survive the member waiting for a text -
	 * and, if they use "Send a new code", the whole 60-second cooldown. Turnstile
	 * tokens expire in about five minutes, and the widget is never re-rendered
	 * because it lives in a block that was hidden when the modal opened, so the
	 * verify submission failed with "Please verify that you are human." and had
	 * no recovery short of reloading. Sign-in was blocked outright.
	 *
	 * Nothing is lost by dropping it. The request form is the abuse surface and
	 * the only one that spends money, and it stays protectable. This step is
	 * already guarded by an atomic five-attempt lockout per code, a per-visitor
	 * cap on verification attempts, single use, and a ten-minute expiry over a
	 * six-digit space. Making somebody solve a captcha twice in one sign-in was
	 * never worth that.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label' => esc_html__( 'Sign In with Code', 'twilio-for-hivepress' ),
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Form arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'description' => esc_html__( 'Enter the six-digit code from the text message. It expires in 10 minutes.', 'twilio-for-hivepress' ),
				'action'      => hivepress()->router->get_url( 'hptw_otp_verify_action' ),

				// On success core JS reloads the page; on the login page route
				// the server-side redirect then honours ?redirect=, exactly
				// the mechanics core login uses.
				'redirect'    => true,

				'attributes'  => [
					'data-id' => 'hptw-otp',
				],

				'fields'      => [

					// Deliberately a visible phone field: the script prefills
					// and hides it, and without JavaScript the whole flow
					// still works with both forms shown.
					'phone' => [
						'label'       => esc_html__( 'Phone Number', 'twilio-for-hivepress' ),
						'type'        => 'phone',
						'required'    => true,
						'max_length'  => 24,
						'placeholder' => '+447700900123',
						'_order'      => 10,

						'attributes'  => [
							'autocomplete' => 'tel',
						],
					],

					'code'  => [
						'label'      => esc_html__( 'Sign-In Code', 'twilio-for-hivepress' ),
						'type'       => 'text',
						'required'   => true,

						// Derived from the component's CODE_LENGTH so the
						// constraints can never drift from the codes issued.
						'min_length' => Hptw_Otp::CODE_LENGTH,
						'max_length' => Hptw_Otp::CODE_LENGTH,

						// Enforced as an HTML attribute and server-side by the
						// Text field's own pattern check.
						'pattern'    => '[0-9]{' . Hptw_Otp::CODE_LENGTH . '}',
						'_order'     => 20,

						'attributes' => [
							'autocomplete' => 'one-time-code',
							'inputmode'    => 'numeric',
						],
					],
				],

				'button'      => [
					'label' => esc_html__( 'Sign In', 'twilio-for-hivepress' ),
				],

				// Raw footer HTML renders inside .hp-form__footer after the
				// button; the script wires the class to swap back to step one.
				'footer'      => '<a href="#" class="hp-form__action hptw-otp-resend">' . esc_html__( 'Send a new code', 'twilio-for-hivepress' ) . '</a>',
			],
			$args
		);

		parent::__construct( $args );
	}
}
