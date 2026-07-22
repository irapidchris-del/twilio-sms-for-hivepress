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
			'delivery' => [
				'title'  => esc_html__( 'Delivery', 'twilio-for-hivepress' ),
				'_order' => 10,

				'fields' => [
					'twilio_phone_attribute' => [
						'label'       => esc_html__( 'Phone Attribute', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the field name of the user attribute that stores phone numbers.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 64,
						'default'     => 'phone',
						'_order'      => 10,
					],

					'twilio_country_code'    => [
						'label'       => esc_html__( 'Country Code', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the default country calling code (e.g. +44). It is added to phone numbers saved in the national format.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 8,
						'_order'      => 20,
					],

					'twilio_admin_phone'     => [
						'label'       => esc_html__( 'Administrator Phone', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the phone number for notifications addressed to the site email address.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 24,
						'_order'      => 30,
					],

					'twilio_enable_logging'  => [
						'label'   => esc_html__( 'Logging', 'twilio-for-hivepress' ),
						'caption' => esc_html__( 'Log SMS delivery errors', 'twilio-for-hivepress' ),
						'type'    => 'checkbox',
						'_order'  => 40,
					],
				],
			],

			'events'   => [
				'title'       => esc_html__( 'Events', 'twilio-for-hivepress' ),
				// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- %user.first_name% is a literal HivePress token example, not a printf directive.
				'description' => esc_html__( 'Set the SMS text sent for each event below. An SMS mirrors the corresponding email notification and supports the same tokens, including model tokens such as %user.first_name%. The full token list for each event is available on the email edit screen. Leave a message blank to disable its SMS.', 'twilio-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [],
			],
		],
	],

	'integrations' => [
		'sections' => [
			'twilio' => [
				'title'  => 'Twilio',
				'_order' => 15,

				'fields' => [
					'twilio_account_sid'           => [
						'label'       => esc_html__( 'Account SID', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the account SID from the Twilio console.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 256,
						'_order'      => 10,
					],

					'twilio_auth_token'            => [
						'label'       => esc_html__( 'Auth Token', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the auth token from the Twilio console. Use the test credentials to try things out without sending real messages.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 256,
						'_order'      => 20,
					],

					'twilio_from_number'           => [
						'label'       => esc_html__( 'Phone Number', 'twilio-for-hivepress' ),
						'description' => esc_html__( 'Enter the Twilio phone number used to send messages, in the international format (e.g. +447700900123). Use +15005550006 with the test credentials.', 'twilio-for-hivepress' ),
						'type'        => 'text',
						'max_length'  => 24,
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
