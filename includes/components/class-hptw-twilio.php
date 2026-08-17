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
 *
 * The class and its file name are both prefixed. HivePress instantiates one
 * component per `includes/components/*.php` file across every registered
 * extension, and the autoloader loads exactly one file per class name, so an
 * unprefixed `class-twilio.php` shipped by core or another extension would
 * silently replace this one with no error.
 */
final class Hptw_Twilio extends Component {

	/**
	 * The token that carries a plaintext password.
	 *
	 * HivePress passes the real password into `%user_password%` on the
	 * front-end registration flow (`components/class-user.php`, via the
	 * `hivepress/v1/models/user/register` action), and only the REST
	 * controller path masks it. Sending that to the Twilio API would put a
	 * plaintext password in a third party's message logs and in an unencrypted
	 * text message, so this plugin neither advertises nor sends the token.
	 *
	 * @var string
	 */
	const PASSWORD_TOKEN = 'user_password';

	/**
	 * The default user attribute holding phone numbers.
	 *
	 * @var string
	 */
	const DEFAULT_ATTRIBUTE = 'phone';

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

		/*
		 * Add the phone field to the registration form, at priority 200: the
		 * Attribute component registers its own user_register filter at 100
		 * from an init callback, which is LATER than this constructor, so at
		 * equal priority this filter would run first and a required phone
		 * attribute would be downgraded to the optional field (found live on
		 * staging, 2026-08-17). Running after core lets the isset guard defer
		 * to any pre-existing definition, required flag intact.
		 */
		add_filter( 'hivepress/v1/forms/user_register', [ $this, 'add_registration_field' ], 200, 2 );

		/*
		 * Save the registration phone at priority 1: core sends the
		 * registration email from the same action at priority 10, and the SMS
		 * mirrors it, so the number must already be stored by then. The
		 * controller's model save cannot persist it - attribute fields only
		 * exist on loaded model objects, not on the freshly constructed one
		 * the registration flow fills.
		 */
		add_action( 'hivepress/v1/models/user/register', [ $this, 'save_registration_phone' ], 1, 2 );

		if ( is_admin() ) {

			// Keep backslashes alive through the settings screen. The save
			// path unslashes twice (options.php once, Fields\Text::normalize()
			// again) and the display path loses another level, so a template
			// containing a backslash degrades on every save. Priority 9 puts
			// the save-side wp_slash ahead of the validator register_setting()
			// adds at the default priority; the display-side filter is only on
			// while register_settings() captures the stored values.
			add_action( 'admin_init', [ $this, 'protect_backslashes' ], 8 );
			add_action( 'admin_init', [ $this, 'slash_settings_display' ], 9 );
			add_action( 'admin_init', [ $this, 'unslash_settings_display' ], 11 );
		}

		parent::__construct( $args );
	}

	/**
	 * Runs a regular expression replacement, keeping the original on failure.
	 *
	 * The /u patterns used on SMS text return null on malformed UTF-8, and a
	 * bare `$s = preg_replace( ..., $s )` would then silently blank the whole
	 * message instead of sending it.
	 *
	 * @param string $pattern Pattern.
	 * @param string $replacement Replacement.
	 * @param string $subject Subject text.
	 * @return string
	 */
	protected function replace( $pattern, $replacement, $subject ) {
		$result = preg_replace( $pattern, $replacement, $subject );

		return null === $result ? $subject : $result;
	}

	/**
	 * Gets the option names whose values may contain a backslash.
	 *
	 * @return array
	 */
	protected function get_backslash_options() {
		$options = [];

		foreach ( hivepress()->get_classes( 'emails' ) as $class ) {
			$name = $class::get_meta( 'name' );

			if ( $name ) {
				$options[] = hp\prefix( 'twilio_sms_' . $name );
			}
		}

		return $options;
	}

	/**
	 * Protects backslashes on the settings save path.
	 */
	public function protect_backslashes() {
		foreach ( $this->get_backslash_options() as $option ) {
			add_filter( 'sanitize_option_' . $option, 'wp_slash', 9 );
		}
	}

	/**
	 * Pre-slashes stored values for the settings screen read.
	 */
	public function slash_settings_display() {
		foreach ( $this->get_backslash_options() as $option ) {
			add_filter( 'option_' . $option, 'wp_slash' );
		}
	}

	/**
	 * Removes the display-side slashing after the settings screen read.
	 */
	public function unslash_settings_display() {
		foreach ( $this->get_backslash_options() as $option ) {
			remove_filter( 'option_' . $option, 'wp_slash' );
		}
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
	 * Tokens that hold a model rather than a plain value carry the `.field`
	 * suffix here, since these names are displayed verbatim as hints.
	 *
	 * @return array
	 */
	protected function get_email_tokens() {
		return [
			'listing_submit'       => [ 'listing_title', 'listing_url', 'user.field', 'listing.field' ],
			'listing_update'       => [ 'listing_title', 'listing_attributes', 'listing_url', 'user.field', 'listing.field' ],
			'listing_report'       => [ 'listing_title', 'listing_url', 'report_details', 'user.field', 'listing.field' ],
			'vendor_register'      => [ 'vendor_url', 'user.field', 'vendor.field' ],
			'listing_claim_submit' => [ 'listing_title', 'claim_details', 'claim_url' ],
			'listing_import'       => [ 'listings_url' ],
			'order_dispute'        => [ 'order_number', 'dispute_details', 'order_url' ],
			'order_refund_fail'    => [ 'order_number', 'fail_reason', 'order_url' ],
			'order_refund_request' => [ 'order_number', 'order_url' ],
			'payout_fail'          => [ 'order_number', 'fail_reason', 'order_url' ],
			'payout_request'       => [ 'payout_amount', 'payout_method', 'payout_url' ],
			'offer_submit'         => [ 'offer_url', 'user.field', 'request.field', 'offer.field' ],
			'request_submit'       => [ 'request_title', 'request_url', 'request.field' ],
		];
	}

	/**
	 * Gets the labels for emails without meta values.
	 *
	 * @return array
	 */
	protected function get_email_labels() {
		return [
			'listing_claim_submit' => __( 'Claim Submitted', 'twilio-for-hivepress' ),
			'order_dispute'        => __( 'Order Disputed', 'twilio-for-hivepress' ),
			'order_refund_fail'    => __( 'Refund Failed', 'twilio-for-hivepress' ),
			'order_refund_request' => __( 'Refund Requested', 'twilio-for-hivepress' ),
			'payout_fail'          => __( 'Payout Failed', 'twilio-for-hivepress' ),
			'payout_request'       => __( 'Payout Requested', 'twilio-for-hivepress' ),
			'offer_submit'         => __( 'Offer Submitted', 'twilio-for-hivepress' ),
			'request_submit'       => __( 'Request Submitted', 'twilio-for-hivepress' ),
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
					'listing_import'  => 'listings_imported',
				],
				$email::get_meta( 'name' )
			);

			if ( $key ) {
				$label = hivepress()->translator->get_string( $key );
			}

			if ( ! $label ) {
				$label = hp\get_array_value( $this->get_email_labels(), $email::get_meta( 'name' ) );
			}

			if ( ! $label ) {
				$label = ucwords( str_replace( '_', ' ', (string) $email::get_meta( 'name' ) ) );
			}
		}

		return $label;
	}

	/**
	 * Gets the email description.
	 *
	 * The email class's own description meta is deliberately NOT used: it is
	 * phrased for the email screen ("This email is sent to users when...")
	 * and reads wrong under an SMS field. The recipient plus the label carry
	 * the same information, uniformly for every event.
	 *
	 * @param string $email Email class.
	 * @return string
	 */
	protected function get_email_description( $email ) {
		$description = '';

		// Get recipient.
		$recipient = (string) $email::get_meta( 'recipient' );

		if ( ! $recipient && hp\get_array_value( $this->get_email_tokens(), $email::get_meta( 'name' ) ) ) {

			// Every curated event without meta is addressed to the site email.
			$recipient = __( 'Site administrator', 'twilio-for-hivepress' );
		}

		if ( $recipient ) {
			/* translators: %s: recipient of the message. */
			$description = sprintf( __( 'Sent to: %s.', 'twilio-for-hivepress' ), $recipient );
		}

		// Get tokens.
		$tokens = $email::get_meta( 'tokens' );

		if ( $tokens ) {

			// Meta token lists name whole models, so mark those tokens as such.
			$tokens = array_map(
				function ( $token ) {
					return class_exists( '\HivePress\Models\\' . $token ) ? $token . '.field' : $token;
				},
				$tokens
			);
		} else {
			$tokens = hp\get_array_value( $this->get_email_tokens(), $email::get_meta( 'name' ), [] );
		}

		// Never advertise the password token, which this plugin also refuses to send.
		$tokens = array_values( array_diff( $tokens, [ self::PASSWORD_TOKEN ] ) );

		if ( $tokens ) {
			$names = [];

			foreach ( $tokens as $token ) {
				$names[] = '%' . $token . '%';
			}

			$format = hivepress()->translator->get_string( 'these_tokens_are_available' );

			if ( $format ) {
				$description .= ' ' . sprintf( $format, implode( ', ', $names ) );
			}
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

				/*
				 * A non-empty allowlist routes sanitisation through wp_kses()
				 * instead of sanitize_textarea_field(), which strips any
				 * %-token whose first two characters are both hex digits
				 * (%fail_reason%, %decline_reason%) as a URL-encoded octet -
				 * silently, on save. HTML is stripped from the SMS text at
				 * send time either way.
				 */
				'html'        => [ 'strong' => [] ],
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

		// Say so on the SMS tab while sending is impossible, rather than
		// presenting fifty message boxes that silently do nothing.
		if ( ! $this->is_configured() ) {
			$settings = hp\merge_arrays(
				$settings,
				[
					'sms' => [
						'sections' => [
							'delivery' => [
								'description' => '<strong>' . esc_html__( 'SMS sending is currently disabled because the Twilio credentials are missing.', 'twilio-for-hivepress' ) . '</strong> ' . sprintf(
									/* translators: %s: settings tab URL. */
									esc_html__( 'Enter them on the %s tab to enable it.', 'twilio-for-hivepress' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=integrations' ) ) . '">' . esc_html__( 'Integrations', 'twilio-for-hivepress' ) . '</a>'
								),
							],
						],
					],
				]
			);
		} else {

			// Surface the last delivery failure: the matching email delivers,
			// so nothing else tells the admin the SMS half is failing.
			$error = get_option( hp\prefix( 'twilio_last_error' ) );

			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				$settings = hp\merge_arrays(
					$settings,
					[
						'sms' => [
							'sections' => [
								'delivery' => [
									'description' => '<strong>' . sprintf(
										/* translators: %s: date and time. */
										esc_html__( 'The last SMS attempt failed (%s).', 'twilio-for-hivepress' ),
										esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) hp\get_array_value( $error, 'time' ) + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) )
									) . '</strong> ' . sprintf(
										/* translators: %s: error message from Twilio. */
										esc_html__( 'Twilio said: %s. This notice clears when a message is delivered.', 'twilio-for-hivepress' ),
										'<i>' . esc_html( hp\get_array_value( $error, 'message' ) ) . '</i>'
									),
								],
							],
						],
					]
				);
			}
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
		$template = get_option( hp\prefix( 'twilio_sms_' . $name ), null );

		if ( null === $template || false === $template ) {

			// Fall back to the default text only if the option was never saved.
			$template = hp\get_array_value( $this->get_sms_defaults(), $name );
		}

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

		// Drop the password token, with any fallback value, before it can resolve.
		$template = $this->replace( '/%' . self::PASSWORD_TOKEN . '(\s*\|[^%]*)?%/u', '', (string) $template );

		// Replace tokens.
		$text = hp\replace_tokens( $email->get_tokens(), $template );

		// Remove HTML without consuming stray "<" characters in text. The /u
		// flag keeps the byte-level engine from matching inside a multibyte
		// character; templates and titles routinely carry emoji.
		$text = $this->replace( '/<(script|style)[^>]*>.*?<\/\1>/isu', '', (string) $text );
		$text = $this->replace( '/<[a-zA-Z\/!][^>]*>/u', '', $text );
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
			if ( 0 === strcasecmp( (string) get_option( 'admin_email' ), $address ) ) {
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

			/* translators: %s: masked email address. */
			$this->log( sprintf( __( 'Invalid phone number for %s.', 'twilio-for-hivepress' ), $this->mask_address( $address ) ) );
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
	 * Gets the configured phone attribute name.
	 *
	 * @return string
	 */
	protected function get_phone_attribute() {
		$stored = get_option( hp\prefix( 'twilio_phone_attribute' ), self::DEFAULT_ATTRIBUTE );

		/*
		 * A cleared text setting stores as '' rather than reverting to the
		 * default, and an empty attribute name has no useful meaning here: it
		 * would silently disable every user SMS while the settings screen
		 * still showed the default. Fall back instead.
		 */
		return hp\sanitize_key( is_string( $stored ) && '' !== trim( $stored ) ? $stored : self::DEFAULT_ATTRIBUTE );
	}

	/**
	 * Adds the phone field to the registration form.
	 *
	 * The stock registration form collects no phone number, so on a default
	 * install the registration SMS has no recipient and can never deliver -
	 * users only add their number in the account settings afterwards. With
	 * the setting enabled, the field is offered at sign-up.
	 *
	 * @param array  $args Form arguments.
	 * @param object $form Form object.
	 * @return array
	 */
	public function add_registration_field( $args, $form ) {

		// The filter also fires for any subclassed form; target this one only.
		if ( 'HivePress\Forms\User_Register' !== get_class( $form ) ) {
			return $args;
		}

		if ( ! get_option( hp\prefix( 'twilio_register_phone' ) ) ) {
			return $args;
		}

		$attribute = $this->get_phone_attribute();

		if ( ! $attribute || isset( $args['fields'][ $attribute ] ) ) {
			return $args;
		}

		$args['fields'][ $attribute ] = [
			'label'       => __( 'Phone Number', 'twilio-for-hivepress' ),
			'type'        => 'phone',
			'required'    => false,
			'max_length'  => 24,

			// Numbers typed in the national format fail silently unless the
			// Country Code setting converts them, so lead by example.
			'placeholder' => '+447700900123',
			'_order'      => 50,
		];

		return $args;
	}

	/**
	 * Saves the phone number submitted on the registration form.
	 *
	 * Runs at priority 1 so the number is stored before core sends the
	 * registration email (priority 10 on the same action), which the SMS
	 * mirrors. Social Login fires this action too, with no phone value, and
	 * falls straight through.
	 *
	 * @param int   $user_id User ID.
	 * @param array $values Form values.
	 */
	public function save_registration_phone( $user_id, $values ) {
		if ( ! get_option( hp\prefix( 'twilio_register_phone' ) ) ) {
			return;
		}

		$attribute = $this->get_phone_attribute();

		if ( ! $attribute ) {
			return;
		}

		$phone = hp\get_array_value( (array) $values, $attribute );

		if ( $phone && is_string( $phone ) ) {
			update_user_meta( $user_id, hp\prefix( $attribute ), sanitize_text_field( $phone ) );
		}
	}

	/**
	 * Gets the user phone number.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	protected function get_user_phone( $user_id ) {

		// Get attribute name.
		$attribute = $this->get_phone_attribute();

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
		$phone = preg_replace( '/\(\s*0\s*\)/', '', (string) $phone );
		$phone = preg_replace( '/[^\d+]/', '', $phone );
		$phone = preg_replace( '/(?!^)\+/', '', $phone );

		// Replace prefix.
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}

		// Add country code.
		if ( $phone && 0 !== strpos( $phone, '+' ) ) {
			$code = ltrim( preg_replace( '/\D/', '', (string) get_option( hp\prefix( 'twilio_country_code' ) ) ), '0' );

			if ( $code ) {
				if ( 0 === strpos( $phone, $code ) && strlen( $phone ) > strlen( $code ) + 6 ) {
					$phone = '+' . $phone;
				} else {
					$phone = '+' . $code . preg_replace( '/^0/', '', $phone );
				}
			}
		}

		// Validate format.
		if ( ! preg_match( '/^\+[1-9]\d{6,14}$/', $phone ) ) {
			return '';
		}

		return $phone;
	}

	/**
	 * Gets the API credentials for HTTP basic authentication.
	 *
	 * An API key pair is preferred when both halves are set: Twilio's own
	 * guidance reserves the account auth token for testing, since a leaked
	 * key can be revoked on its own while a leaked token compromises the
	 * whole account. The auth token remains the fallback.
	 *
	 * @return array<string> Username and password, or an empty array.
	 */
	protected function get_credentials() {
		$key_sid    = get_option( hp\prefix( 'twilio_api_key_sid' ) );
		$key_secret = get_option( hp\prefix( 'twilio_api_key_secret' ) );

		if ( $key_sid && $key_secret ) {
			return [ $key_sid, $key_secret ];
		}

		$token = get_option( hp\prefix( 'twilio_auth_token' ) );

		if ( $token ) {
			return [ get_option( hp\prefix( 'twilio_account_sid' ) ), $token ];
		}

		return [];
	}

	/**
	 * Checks if the Twilio credentials are set.
	 *
	 * @return bool
	 */
	protected function is_configured() {
		return get_option( hp\prefix( 'twilio_account_sid' ) ) && $this->get_credentials() && ( get_option( hp\prefix( 'twilio_from_number' ) ) || get_option( hp\prefix( 'twilio_messaging_service_sid' ) ) );
	}

	/**
	 * Normalizes the configured sender.
	 *
	 * Twilio requires the international format for a sending phone number, but
	 * a number pasted from the Twilio console often arrives without the "+"
	 * (seen in real use: 15005550006). Only values that are clearly a full
	 * phone number are touched: alphanumeric sender IDs pass through as typed,
	 * and so do short codes, which have few digits and no "+".
	 *
	 * @param string $from Configured sender.
	 * @return string
	 */
	protected function normalize_sender( $from ) {
		$from = trim( (string) $from );

		// Anything with a letter is an alphanumeric sender ID.
		if ( ! preg_match( '/^[0-9\s\-().+]+$/', $from ) ) {
			return $from;
		}

		$digits = preg_replace( '/\D/', '', $from );

		if ( null === $digits ) {
			return $from;
		}

		// International prefix written as 00.
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}

		// Fewer than eight digits cannot be a full international number; a
		// short code stays exactly as configured.
		if ( strlen( $digits ) < 8 && 0 !== strpos( $from, '+' ) ) {
			return $from;
		}

		// A leading zero after the prefix strip means a national-format
		// number; the country cannot be guessed for a sender, so it goes
		// through as typed and Twilio's own error names the problem.
		if ( 0 === strpos( $digits, '0' ) ) {
			return $from;
		}

		return '+' . $digits;
	}

	/**
	 * Sends a request to the Twilio API.
	 *
	 * @param string $to Phone number.
	 * @param string $body SMS text.
	 * @return bool
	 */
	protected function request( $to, $body ) {

		// Get credentials. Messages are always filed under the account SID;
		// the basic-auth pair may be the account credentials or an API key.
		$sid         = get_option( hp\prefix( 'twilio_account_sid' ) );
		$credentials = $this->get_credentials();

		if ( ! $credentials ) {
			return false;
		}

		// Get parameters.
		$params = [
			'To'   => $to,
			'Body' => $body,
		];

		$service = get_option( hp\prefix( 'twilio_messaging_service_sid' ) );

		if ( $service ) {
			$params['MessagingServiceSid'] = $service;
		} else {
			$params['From'] = $this->normalize_sender( get_option( hp\prefix( 'twilio_from_number' ) ) );
		}

		// Send request. The call is synchronous inside the request that
		// triggered the email, so the timeout is kept tight: queueing through
		// a scheduled action was considered and rejected, because Action
		// Scheduler stores its arguments (here, a phone number and the
		// message) in a table that any admin can browse and that retains
		// completed actions for a month.
		$response = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
			[
				'timeout'    => 5,

				'headers'    => [
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for the HTTP basic authentication header.
					'Authorization' => 'Basic ' . base64_encode( $credentials[0] . ':' . $credentials[1] ),
				],

				// Without an explicit user agent WordPress sends its version
				// and the site URL with every request; Twilio needs neither.
				'user-agent' => 'twilio-for-hivepress/' . \TwilioForHivePress\VERSION,

				'body'       => $params,
			]
		);

		// Check response.
		if ( is_wp_error( $response ) ) {
			$this->record_failure( $response->get_error_message() );

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

			// Mask phone numbers echoed back in API error messages.
			$this->record_failure( $this->replace( '/\+\d{5,13}(\d{2})/u', '+***$1', $message ) );

			return false;
		}

		// A delivery succeeded, so any recorded failure is stale.
		if ( get_option( hp\prefix( 'twilio_last_error' ) ) ) {
			delete_option( hp\prefix( 'twilio_last_error' ) );
		}

		return true;
	}

	/**
	 * Records a delivery failure where the admin can see it.
	 *
	 * SMS failure is asymmetric: the email delivers, the SMS silently does
	 * not, and by default the only trace is an opt-in log the owner may never
	 * find. Twilio trial and unverified-compliance accounts reject every send
	 * this way. The last provider message (already masked by the caller, and
	 * never containing the recipient) is therefore surfaced on the SMS
	 * settings tab until a send succeeds.
	 *
	 * @param string $message Provider message, already masked.
	 */
	protected function record_failure( $message ) {
		$this->log( $message );

		update_option(
			hp\prefix( 'twilio_last_error' ),
			[
				'message' => mb_substr( (string) $message, 0, 500 ),
				'time'    => time(),
			],
			false
		);
	}

	/**
	 * Masks an email address for logging.
	 *
	 * @param string $address Email address.
	 * @return string
	 */
	protected function mask_address( $address ) {
		if ( false === strpos( $address, '@' ) ) {
			return $address;
		}

		list( $name, $domain ) = explode( '@', $address, 2 );

		return substr( $name, 0, 1 ) . '***@' . $domain;
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
