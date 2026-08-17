<?php
/**
 * Settings configuration.
 *
 * @package HivePress\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'sms'          => [
		'title'    => esc_html__( 'SMS', 'twilio-for-hivepress' ),
		'_order'   => 110,

		'sections' => [
			'delivery'  => [
				'title'       => esc_html__( 'Delivery', 'twilio-for-hivepress' ),
				'description' => esc_html__( 'Configure how recipient phone numbers are looked up and how delivery problems are recorded.', 'twilio-for-hivepress' ),
				'_order'      => 10,

				'fields'      => [
					'twilio_phone_attribute' => [
						'label'       => esc_html__( 'Phone Attribute', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the field name of the user attribute that stores phone numbers. If the saved value is ever removed, the default name "phone" is used.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 64,
						'default'     => 'phone',
						'_order'      => 10,
					],

					'twilio_register_phone'  => [
						'label'       => esc_html__( 'Registration Field', 'twilio-for-hivepress' ),
						'caption'     => esc_html__( 'Ask for the phone number during registration', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Add an optional phone number field to the registration form. Without it, the registration SMS cannot be delivered, because users only add their number in the account settings after signing up.', 'twilio-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 15,
					],

					'twilio_country_code'    => [
						'label'       => esc_html__( 'Country Code', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the default country calling code. It is added to phone numbers saved in the national format. Numbers from countries that keep a leading zero (such as Italy) should be saved in the full international format instead.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 8,
						'placeholder' => '+44',
						'_order'      => 20,
					],

					'twilio_admin_phone'     => [
						'label'       => esc_html__( 'Administrator Phone', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the number that should receive the administrator alerts, such as newly submitted or reported listings. Events that HivePress emails to the site administrator are texted to this number. If it is blank, the alerts go to the phone number of the user account matching the administrator email address, if there is one.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 24,
						'placeholder' => '+447700900123',
						'_order'      => 30,
					],

					'twilio_enable_logging'  => [
						'label'       => esc_html__( 'Logging', 'twilio-for-hivepress' ),
						'caption'     => esc_html__( 'Log SMS delivery errors', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Log entries may include partially masked recipient details, so make sure the PHP error log is not publicly accessible.', 'twilio-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 40,
					],
				],
			],

			'events'    => [
				'title'       => esc_html__( 'Events', 'twilio-for-hivepress' ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- %user.first_name% is a literal HivePress token example, not a printf directive.
				'description' => esc_html__( 'Set the SMS text sent for each event below. An SMS mirrors the corresponding email notification and supports the same tokens, including model tokens such as %user.first_name%. The full token list for each event is available on the email edit screen. Leave a message blank to disable its SMS.', 'twilio-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [],
			],

			'uninstall' => [
				'title'       => esc_html__( 'Removing the Plugin', 'twilio-for-hivepress' ),
				'description' => esc_html__( 'Deleting the plugin keeps your Twilio credentials and SMS messages by default, so you get everything back if you reinstall. WordPress shows a generic warning that deleting a plugin also deletes its data; that warning does not apply here unless you tick the box below.', 'twilio-for-hivepress' ),
				'_order'      => 30,

				'fields'      => [
					'twilio_delete_data' => [
						'label'       => esc_html__( 'Delete All Data', 'twilio-for-hivepress' ),
						'caption'     => esc_html__( 'Delete all data when this plugin is deleted', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Tick this before deleting the plugin to remove its stored data: the Twilio credentials, the delivery settings, and every per-event SMS message.', 'twilio-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],
		],
	],

	'integrations' => [
		'sections' => [
			'twilio' => [
				'title'       => 'Twilio',
				'description' => esc_html__( 'Enter your Twilio API credentials to enable SMS delivery. You can find them in the Twilio console. Twilio trial accounts can only send messages to verified phone numbers. When a messaging service SID is set it takes precedence over the phone number; clear it while using the test credentials, which cannot access messaging services.', 'twilio-for-hivepress' ),
				'_order'      => 15,

				'fields'      => [
					'twilio_account_sid'           => [
						'label'       => esc_html__( 'Account SID', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the account SID from the Twilio console.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 256,
						'_order'      => 10,
					],

					'twilio_auth_token'            => [
						'label'        => esc_html__( 'Auth Token', 'twilio-for-hivepress' ),
						'description'  => esc_html__( 'Enter the auth token from the Twilio console. Use the test credentials to try things out without sending real messages.', 'twilio-for-hivepress' ),

						/*
						 * A text field displayed as a password input, so the
						 * token is masked on screen and in screenshots. The
						 * dedicated Password field type cannot be used here:
						 * it renders without the stored value, so saving the
						 * tab would silently wipe the token.
						 */
						'type'         => 'text',
						'display_type' => 'password',
						'max_length'   => 256,
						'_order'       => 20,

						'attributes'   => [
							'autocomplete' => 'new-password',
						],
					],

					'twilio_api_key_sid'           => [
						'label'       => esc_html__( 'API Key SID', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter an API key SID to authenticate with an API key instead of the auth token. Twilio recommends API keys: a leaked key can be revoked on its own, without touching the rest of the account.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 256,
						'_order'      => 22,
					],

					'twilio_api_key_secret'        => [
						'label'        => esc_html__( 'API Key Secret', 'twilio-for-hivepress' ),
						'description'  => esc_html__( 'Enter the secret shown when the API key was created. Twilio only shows it once. When both key fields are set, they are used instead of the auth token.', 'twilio-for-hivepress' ),
						'type'         => 'text',
						'display_type' => 'password',
						'max_length'   => 256,
						'_order'       => 24,

						'attributes'   => [
							'autocomplete' => 'new-password',
						],
					],

					'twilio_from_number'           => [
						'label'       => esc_html__( 'Phone Number', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the Twilio phone number used to send messages, in the international format. Use +15005550006 with the test credentials.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 24,
						'placeholder' => '+447700900123',
						'_order'      => 30,
					],

					'twilio_messaging_service_sid' => [
						'label'       => esc_html__( 'Messaging Service SID', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter a messaging service SID to send messages via a messaging service instead of the phone number above.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 256,
						'_order'      => 40,
					],
				],
			],
		],
	],
];
