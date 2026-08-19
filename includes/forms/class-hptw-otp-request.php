<?php
/**
 * OTP request form.
 *
 * @package HivePress\Forms
 */

namespace HivePress\Forms;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Requests an SMS sign-in code.
 */
class Hptw_Otp_Request extends Form {

	/**
	 * Class initializer.
	 *
	 * The captcha key plus the label are the whole captcha contract: core's
	 * reCAPTCHA lists any form declaring them under Protected Forms and flips
	 * the flag when ticked, and the Turnstile extension discovers the form
	 * the same way. This is the request form, the one that costs money per
	 * submission, so it is the one admins are expected to protect.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'   => esc_html__( 'Request Sign-In Code', 'twilio-for-hivepress' ),
				'captcha' => false,
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
				'description' => esc_html__( 'Enter the phone number saved on your account and we will text you a sign-in code.', 'twilio-for-hivepress' ),

				// Uniform for every accepted request, known number or not, so
				// the response cannot confirm which numbers have accounts.
				'message'     => esc_html__( 'If this number matches an account, we have sent it a code.', 'twilio-for-hivepress' ),
				'action'      => hivepress()->router->get_url( 'hptw_otp_request_action' ),

				// The data-id presence stops core JS resetting the form on
				// success; the typed phone number must survive for the verify
				// step to copy.
				'attributes'  => [
					'data-id' => 'hptw-otp',
				],

				'fields'      => [
					'phone' => [
						'label'       => esc_html__( 'Phone Number', 'twilio-for-hivepress' ),
						'type'        => 'phone',
						'required'    => true,
						'max_length'  => 24,

						// The format example lives in the placeholder, not the
						// label, mirroring the registration field.
						'placeholder' => '+447700900123',
						'_order'      => 10,

						'attributes'  => [
							'autocomplete' => 'tel',
						],
					],
				],

				'button'      => [
					'label' => esc_html__( 'Send Code', 'twilio-for-hivepress' ),
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
