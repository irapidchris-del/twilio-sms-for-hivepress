<?php
/**
 * Twilio component.
 *
 * @package HivePress\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sends SMS notifications via Twilio.
 */
final class Twilio extends Component {

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Add settings.
		add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ], 100 );

		// Set email context.
		add_filter( 'hivepress/v1/emails/email', [ $this, 'set_email_context' ], 100, 2 );

		// Add email hooks.
		add_action( 'init', [ $this, 'add_email_hooks' ], 100 );

		parent::__construct( $args );
	}

	/**
	 * Gets the default SMS text for each event.
	 *
	 * @return array
	 */
	protected function get_sms_defaults() {

		// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.UnorderedPlaceholdersText -- HivePress tokens such as %user_name% are literal placeholders replaced via hp\replace_tokens(), not printf directives.
		return [
			'user_register'         => __( 'Hi, %user_name%! Thanks for registering, your account is now active.', 'twilio-for-hivepress' ),
			'user_email_verify'     => __( 'Hi, %user_name%! Please verify your email address using this link: %email_verify_url%', 'twilio-for-hivepress' ),
			'user_password_request' => __( 'Hi, %user_name%! Use this link to set a new password: %password_reset_url%', 'twilio-for-hivepress' ),
			'listing_submit'        => __( 'A new listing "%listing_title%" has been submitted: %listing_url%', 'twilio-for-hivepress' ),
			'listing_approve'       => __( 'Hi, %user_name%! Your listing "%listing_title%" has been approved: %listing_url%', 'twilio-for-hivepress' ),
			'listing_reject'        => __( 'Hi, %user_name%! Unfortunately, your listing "%listing_title%" has been rejected.', 'twilio-for-hivepress' ),
			'listing_expire'        => __( 'Hi, %user_name%! Your listing "%listing_title%" has expired, renew it here: %listing_url%', 'twilio-for-hivepress' ),
			'listing_update'        => __( 'Listing "%listing_title%" has been updated: %listing_url%', 'twilio-for-hivepress' ),
			'listing_report'        => __( 'Listing "%listing_title%" has been reported: %listing_url%', 'twilio-for-hivepress' ),
			'vendor_register'       => __( 'A new vendor has registered: %vendor_url%', 'twilio-for-hivepress' ),
		];
		// phpcs:enable WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.UnorderedPlaceholdersText
	}

	/**
	 * Gets the token names for emails without meta values.
	 *
	 * @return array
	 */
	protected function get_email_tokens() {
		return [
			'listing_submit'  => [ 'listing_title', 'listing_url', 'user', 'listing' ],
			'listing_update'  => [ 'listing_title', 'listing_attributes', 'listing_url', 'user', 'listing' ],
			'listing_report'  => [ 'listing_title', 'listing_url', 'report_details', 'user', 'listing' ],
			'vendor_register' => [ 'vendor_url', 'user', 'vendor' ],
		];
	}

	/**
	 * Gets the email label.
	 *
	 * @param string $email Email class.
	 * @return string
	 */
	protected function get_email_label( $email ) {
		$label = $email::get_meta( 'label' );

		if ( ! $label ) {

			// Get string key.
			$key = hp\get_array_value(
				[
					'listing_submit'  => 'listing_submitted',
					'listing_update'  => 'listing_updated',
					'listing_report'  => 'listing_reported',
					'vendor_register' => 'vendor_registered',
				],
				$email::get_meta( 'name' )
			);

			if ( $key ) {
				$label = hivepress()->translator->get_string( $key );
			} else {
				$label = ucwords( str_replace( '_', ' ', $email::get_meta( 'name' ) ) );
			}
		}

		return $label;
	}

	/**
	 * Gets the email description.
	 *
	 * @param string $email Email class.
	 * @return string
	 */
	protected function get_email_description( $email ) {
		$description = (string) $email::get_meta( 'description' );

		// Get tokens.
		$tokens = $email::get_meta( 'tokens' );

		if ( ! $tokens ) {
			$tokens = hp\get_array_value( $this->get_email_tokens(), $email::get_meta( 'name' ), [] );
		}

		if ( $tokens ) {
			$names = [];

			foreach ( $tokens as $token ) {
				if ( class_exists( '\HivePress\Models\\' . $token ) ) {
					$names[] = '%' . $token . '.field%';
				} else {
					$names[] = '%' . $token . '%';
				}
			}

			$description .= ' ' . sprintf( hivepress()->translator->get_string( 'these_tokens_are_available' ), implode( ', ', $names ) );
		}

		return trim( $description );
	}

	/**
	 * Adds SMS settings.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings( $settings ) {
		if ( ! is_admin() ) {
			return $settings;
		}

		// Get defaults.
		$defaults = $this->get_sms_defaults();

		// Get fields.
		$fields = [];

		$order = 10;

		foreach ( hivepress()->get_classes( 'emails' ) as $class ) {

			// Get email name.
			$name = $class::get_meta( 'name' );

			if ( ! $name ) {
				continue;
			}

			// Add field.
			$fields[ 'twilio_sms_' . $name ] = [
				'label'       => $this->get_email_label( $class ),
				'description' => $this->get_email_description( $class ),
				'type'        => 'textarea',
				'max_length'  => 1600,
				'default'     => hp\get_array_value( $defaults, $name ),
				'_order'      => $order,
			];

			$order += 10;
		}

		if ( $fields ) {
			$settings = hp\merge_arrays(
				$settings,
				[
					'sms' => [
						'sections' => [
							'events' => [
								'fields' => $fields,
							],
						],
					],
				]
			);
		}

		return $settings;
	}

	/**
	 * Sets the email context.
	 *
	 * @param array  $args Email arguments.
	 * @param object $email Email object.
	 * @return array
	 */
	public function set_email_context( $args, $email ) {
		if ( isset( $args['recipient'] ) ) {

			// Get context.
			$context = hp\get_array_value( $args, 'context', [] );

			if ( is_array( $context ) && ! isset( $context['twilio_recipient'] ) ) {

				// Set recipient.
				$context['twilio_recipient'] = $args['recipient'];

				$args['context'] = $context;
			}
		}

		return $args;
	}

	/**
	 * Adds email hooks.
	 */
	public function add_email_hooks() {
		foreach ( hivepress()->get_classes( 'emails' ) as $class ) {

			// Get email name.
			$name = $class::get_meta( 'name' );

			if ( $name ) {

				// Add hook.
				add_action( 'hivepress/v1/emails/' . $name . '/send', [ $this, 'send_sms' ] );
			}
		}
	}

	/**
	 * Sends an SMS notification.
	 *
	 * @param object $email Email object.
	 */
	public function send_sms( $email ) {

		// Get email name.
		$name = $email::get_meta( 'name' );

		if ( ! $name ) {
			return;
		}

		// Get template.
		$template = get_option( hp\prefix( 'twilio_sms_' . $name ), hp\get_array_value( $this->get_sms_defaults(), $name ) );

		if ( ! $template || ! is_string( $template ) ) {
			return;
		}

		// Check settings.
		if ( ! $this->is_configured() ) {
			return;
		}

		// Get text.
		$text = $this->get_sms_text( $template, $email );

		if ( ! $text ) {
			return;
		}

		// Get recipients.
		$recipient = $email->get_context( 'twilio_recipient' );

		$recipients = [];

		if ( is_array( $recipient ) ) {
			$recipients = $recipient;
		} elseif ( is_string( $recipient ) && $recipient ) {
			$recipients = explode( ',', $recipient );
		}

		// Send messages.
		$numbers = [];

		foreach ( $recipients as $address ) {
			if ( ! is_string( $address ) ) {
				continue;
			}

			// Get phone number.
			$phone = $this->get_phone_number( trim( $address ), $email );

			if ( ! $phone || in_array( $phone, $numbers, true ) ) {
				continue;
			}

			$numbers[] = $phone;

			/**
			 * Filters the SMS send flag. Return false to prevent sending the SMS.
			 *
			 * @hook hptw_sms_send
			 * @param {bool} $send Send flag.
			 * @param {string} $phone Phone number.
			 * @param {string} $text SMS text.
			 * @param {object} $email Email object.
			 * @return {bool} Send flag.
			 */
			if ( ! apply_filters( 'hptw_sms_send', true, $phone, $text, $email ) ) {
				continue;
			}

			// Send request.
			$this->request( $phone, $text );
		}
	}

	/**
	 * Gets the SMS text.
	 *
	 * @param string $template SMS template.
	 * @param object $email Email object.
	 * @return string
	 */
	protected function get_sms_text( $template, $email ) {

		// Replace tokens.
		$text = hp\replace_tokens( $email->get_tokens(), $template );

		// Remove HTML.
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );

		/**
		 * Filters the SMS text. Return an empty value to prevent sending the SMS.
		 *
		 * @hook hptw_sms_text
		 * @param {string} $text SMS text.
		 * @param {object} $email Email object.
		 * @return {string} SMS text.
		 */
		$text = apply_filters( 'hptw_sms_text', $text, $email );

		// Limit length.
		return mb_substr( trim( (string) $text ), 0, 1600 );
	}

	/**
	 * Gets the phone number for an email address.
	 *
	 * @param string $address Email address.
	 * @param object $email Email object.
	 * @return string
	 */
	protected function get_phone_number( $address, $email ) {
		$phone = '';
		$user  = null;

		if ( is_email( $address ) ) {

			// Get administrator phone.
			if ( get_option( 'admin_email' ) === $address ) {
				$phone = get_option( hp\prefix( 'twilio_admin_phone' ) );
			}

			// Get user phone.
			if ( ! $phone ) {
				$user = get_user_by( 'email', $address );

				if ( $user ) {
					$phone = $this->get_user_phone( $user->ID );
				}
			}
		}

		// Normalize phone.
		$normalized = $this->normalize_phone( $phone );

		if ( $phone && ! $normalized ) {

			/* translators: %s: email address. */
			$this->log( sprintf( __( 'Invalid phone number for %s.', 'twilio-for-hivepress' ), $address ) );
		}

		/**
		 * Filters the SMS phone number. Return an empty value to prevent sending the SMS.
		 *
		 * @hook hptw_sms_phone
		 * @param {string} $phone Phone number.
		 * @param {WP_User|null} $user User object.
		 * @param {object} $email Email object.
		 * @return {string} Phone number.
		 */
		return apply_filters( 'hptw_sms_phone', $normalized, $user, $email );
	}

	/**
	 * Gets the user phone number.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	protected function get_user_phone( $user_id ) {

		// Get attribute name.
		$attribute = hp\sanitize_key( get_option( hp\prefix( 'twilio_phone_attribute' ), 'phone' ) );

		if ( ! $attribute ) {
			return '';
		}

		$phone = '';

		// Get field value.
		$user = Models\User::query()->get_by_id( $user_id );

		if ( $user ) {
			$field = hp\get_array_value( $user->_get_fields(), $attribute );

			if ( $field ) {
				$phone = $field->get_value();
			}
		}

		// Get meta value.
		if ( ! $phone ) {
			$phone = get_user_meta( $user_id, hp\prefix( $attribute ), true );
		}

		if ( ! $phone ) {
			$phone = get_user_meta( $user_id, $attribute, true );
		}

		return is_scalar( $phone ) ? (string) $phone : '';
	}

	/**
	 * Normalizes the phone number to the E.164 format.
	 *
	 * @param string $phone Phone number.
	 * @return string
	 */
	protected function normalize_phone( $phone ) {

		// Remove formatting.
		$phone = preg_replace( '/[^\d+]/', '', (string) $phone );
		$phone = preg_replace( '/(?!^)\+/', '', $phone );

		// Replace prefix.
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}

		// Add country code.
		if ( $phone && 0 !== strpos( $phone, '+' ) ) {
			$code = preg_replace( '/\D/', '', (string) get_option( hp\prefix( 'twilio_country_code' ) ) );

			if ( $code ) {
				$phone = '+' . $code . ltrim( $phone, '0' );
			}
		}

		// Validate format.
		if ( ! preg_match( '/^\+[1-9]\d{6,14}$/', $phone ) ) {
			return '';
		}

		return $phone;
	}

	/**
	 * Checks if the Twilio credentials are set.
	 *
	 * @return bool
	 */
	protected function is_configured() {
		return get_option( hp\prefix( 'twilio_account_sid' ) ) && get_option( hp\prefix( 'twilio_auth_token' ) ) && ( get_option( hp\prefix( 'twilio_from_number' ) ) || get_option( hp\prefix( 'twilio_messaging_service_sid' ) ) );
	}

	/**
	 * Sends a request to the Twilio API.
	 *
	 * @param string $to Phone number.
	 * @param string $body SMS text.
	 * @return bool
	 */
	protected function request( $to, $body ) {

		// Get credentials.
		$sid   = get_option( hp\prefix( 'twilio_account_sid' ) );
		$token = get_option( hp\prefix( 'twilio_auth_token' ) );

		// Get parameters.
		$params = [
			'To'   => $to,
			'Body' => $body,
		];

		$service = get_option( hp\prefix( 'twilio_messaging_service_sid' ) );

		if ( $service ) {
			$params['MessagingServiceSid'] = $service;
		} else {
			$params['From'] = get_option( hp\prefix( 'twilio_from_number' ) );
		}

		// Send request.
		$response = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
			[
				'timeout' => 15,

				'headers' => [
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for the HTTP basic authentication header.
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ),
				],

				'body'    => $params,
			]
		);

		// Check response.
		if ( is_wp_error( $response ) ) {
			$this->log( $response->get_error_message() );

			return false;
		}

		if ( 201 !== wp_remote_retrieve_response_code( $response ) ) {

			// Get error details.
			$details = (array) json_decode( wp_remote_retrieve_body( $response ), true );

			$message = hp\get_array_value( $details, 'message' );

			if ( ! is_string( $message ) || ! $message ) {
				$message = __( 'Unknown error.', 'twilio-for-hivepress' );
			}

			$code = hp\get_array_value( $details, 'code' );

			if ( is_scalar( $code ) && $code ) {
				$message .= ' (' . $code . ')';
			}

			$this->log( $message );

			return false;
		}

		return true;
	}

	/**
	 * Logs an error message.
	 *
	 * @param string $text Error message.
	 */
	protected function log( $text ) {
		if ( get_option( hp\prefix( 'twilio_enable_logging' ) ) ) {

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Optional diagnostic logging enabled via the plugin settings.
			error_log( 'Twilio for HivePress: ' . $text );
		}
	}
}
