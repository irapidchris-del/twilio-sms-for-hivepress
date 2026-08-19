<?php
/**
 * OTP controller.
 *
 * @package HivePress\Controllers
 */

namespace HivePress\Controllers;

use HivePress\Helpers as hp;
use HivePress\Forms;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Serves the SMS sign-in REST endpoints.
 *
 * Same basename as the OTP component, which is safe: HivePress buckets its
 * autoloaded classes per namespace, the precedent being the notifications
 * plugin shipping a component and a model from equally named files.
 *
 * Guests send no REST nonce (core JS only attaches one for logged-in users)
 * and the login page may be served from a full-page cache, so these endpoints
 * follow the cached-page discipline instead: strict input validation through
 * the form classes, server-side resolution of everything, rate limiting, and
 * uniform responses wherever an answer could confirm that a phone number
 * belongs to an account.
 */
final class Hptw_Otp extends Controller {

	/**
	 * Class constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'routes' => [
					'hptw_otp_request_action' => [
						'base'   => 'users_resource',
						'path'   => '/request-login-code',
						'method' => 'POST',
						'action' => [ $this, 'request_code' ],
						'rest'   => true,
					],

					'hptw_otp_verify_action'  => [
						'base'   => 'users_resource',
						'path'   => '/login-with-code',
						'method' => 'POST',
						'action' => [ $this, 'verify_code' ],
						'rest'   => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Requests a sign-in code.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function request_code( $request ) {
		$otp = hivepress()->hptw_otp;

		// Check status.
		if ( ! $otp->is_enabled() ) {
			return hp\rest_error( 404, esc_html__( 'Sign-in with a code is not available.', 'twilio-for-hivepress' ) );
		}

		// Check permissions.
		if ( is_user_logged_in() && ! current_user_can( 'edit_users' ) ) {
			return hp\rest_error( 403 );
		}

		// Validate the form. This also runs the captcha check when the form
		// is listed under Protected Forms.
		$form = ( new Forms\Hptw_Otp_Request() )->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		$raw  = (string) $form->get_value( 'phone' );
		$e164 = hivepress()->hptw_twilio->normalize_phone_number( $raw );

		if ( ! $e164 ) {

			// Format validity is account-independent, so naming it leaks nothing.
			return hp\rest_error( 400, esc_html__( 'Please enter a valid phone number in international format.', 'twilio-for-hivepress' ) );
		}

		// Rate limits run before and regardless of account resolution, and
		// all three share one message, so the limiter cannot be used to tell
		// known numbers from unknown ones.
		if ( $otp->is_request_limited( $e164 ) ) {
			return hp\rest_error( 429, esc_html__( 'Please wait before requesting another code.', 'twilio-for-hivepress' ) );
		}

		// Resolve the account.
		$user_id = $otp->get_user_id_by_phone( $e164, $raw );

		/*
		 * A code is only issued when the number resolves to exactly one
		 * account, that account is not awaiting email verification (a code it
		 * could never redeem would still cost an SMS), and the site-wide send
		 * budget has room. Every branch falls through to the identical
		 * response below - unknown, duplicate, unverified and budget-limited
		 * requests are indistinguishable from a sent code.
		 */
		if ( $user_id
			&& ! ( get_option( 'hp_user_verify_email' ) && get_user_meta( $user_id, 'hp_email_verify_key', true ) )
			&& $otp->consume_send_budget( $e164 ) ) {
			$otp->issue_code( $user_id, $e164 );
		}

		return hp\rest_response( 200, [] );
	}

	/**
	 * Signs in with a code.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function verify_code( $request ) {
		$otp = hivepress()->hptw_otp;

		// Check status.
		if ( ! $otp->is_enabled() ) {
			return hp\rest_error( 404, esc_html__( 'Sign-in with a code is not available.', 'twilio-for-hivepress' ) );
		}

		// Check permissions.
		if ( is_user_logged_in() && ! current_user_can( 'edit_users' ) ) {
			return hp\rest_error( 403 );
		}

		// Validate the form.
		$form = ( new Forms\Hptw_Otp_Verify() )->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		// Check the per-IP verification limit.
		if ( $otp->is_verify_limited() ) {
			return hp\rest_error( 429, esc_html__( 'Please wait before requesting another code.', 'twilio-for-hivepress' ) );
		}

		// Resolve the account.
		$raw  = (string) $form->get_value( 'phone' );
		$e164 = hivepress()->hptw_twilio->normalize_phone_number( $raw );

		$user_id = 0;

		if ( $e164 ) {
			$user_id = $otp->get_user_id_by_phone( $e164, $raw );
		}

		if ( ! $user_id ) {
			return $this->get_uniform_error();
		}

		// Get the code record.
		$record = $otp->get_record( $user_id );

		if ( ! $record ) {
			return $this->get_uniform_error();
		}

		// Expiry is checked at read time; a stale record is inert until here.
		if ( time() > (int) $record['expires'] ) {
			$otp->delete_code( $user_id );

			return $this->get_uniform_error();
		}

		// Burn the attempt BEFORE comparing, atomically, so a crashed
		// comparison still costs it and parallel requests cannot share one.
		if ( ! $otp->consume_attempt( $user_id ) ) {
			return $this->get_uniform_error();
		}

		// Compare the code, in constant time, bound to the phone it was sent to.
		if ( ! $otp->is_code_valid( $record, (string) $form->get_value( 'code' ), $e164 ) ) {
			return $this->get_uniform_error();
		}

		// Single use: consumed before any further gate, so a blocked account
		// cannot retry-oracle the same code against the checks below.
		$otp->delete_code( $user_id );

		$user_object = get_user_by( 'id', $user_id );

		if ( ! $user_object ) {
			return $this->get_uniform_error();
		}

		/*
		 * Explicit account gates: this path bypasses the authenticate filter
		 * where core enforces them. Email verification and the multisite spam
		 * flag are the complete set - core has no other ban state.
		 */
		if ( get_option( 'hp_user_verify_email' ) && get_user_meta( $user_id, 'hp_email_verify_key', true ) ) {
			return hp\rest_error( 401, esc_html__( 'Please check your email to activate your account.', 'twilio-for-hivepress' ) );
		}

		if ( is_multisite() && is_user_spammy( $user_object ) ) {
			return $this->get_uniform_error();
		}

		/*
		 * Log in, exactly as Social Login and core's email-verify flow do -
		 * but only for a guest. A logged-in caller past the permission guard
		 * above holds edit_users, and core's login_user skips the whole
		 * authentication block for them, so this mirrors it: the code is
		 * validated and consumed, the success envelope is returned, and the
		 * admin's own session is never silently replaced by the member's.
		 */
		if ( ! is_user_logged_in() ) {
			do_action( 'hivepress/v1/models/user/login' );

			wp_set_auth_cookie( $user_id, true );

			do_action( 'wp_login', $user_object->user_login, $user_object );
		}

		return hp\rest_response(
			200,
			[
				'id' => $user_id,
			]
		);
	}

	/**
	 * Gets the uniform verification error.
	 *
	 * One string and one status for every failure branch - wrong code,
	 * expired, exhausted, unknown number, duplicate number, malformed number,
	 * spam-flagged account - so the responses cannot enumerate accounts.
	 *
	 * @return \WP_REST_Response
	 */
	protected function get_uniform_error() {
		return hp\rest_error( 401, esc_html__( 'The code is incorrect or has expired. Please request a new one.', 'twilio-for-hivepress' ) );
	}
}
