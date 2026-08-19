<?php
/**
 * Notification channel component.
 *
 * @package HivePress\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Bridges SMS into the Notifications for HivePress preference system.
 *
 * Email-backed notification types keep the existing SMS mirror engine and are
 * gated per member through the hptw_sms_send filter; the email-less extras
 * (such as a completed booking or a new favourite) are texted from the
 * hivepress/v1/notification_add action, exactly as push notifications are.
 * The two paths cover disjoint type sets, so nothing is ever texted twice.
 *
 * SMS is registered as an opt-in channel, which the notifications plugin
 * (1.1.0+) excludes from every role default: for events enabled under the
 * notification settings, a text only goes to a member who has ticked SMS
 * there. Events left disabled there keep the plain mirror behaviour and go
 * to anyone with a saved message - transactional events such as password
 * requests must not be member-suppressible, so no absolute promise holds.
 * Role defaults granting a paid channel would mean unsolicited texts on
 * the site owner's bill.
 */
final class Hptw_Channel extends Component {

	/**
	 * Default cap on member texts: how many one recipient can get per window.
	 *
	 * A constant plus the hptw_sms_member_limits filter rather than a settings
	 * field: the cap is a safety net against runaway loops and griefing, not a
	 * product knob, and a settings field would invite raising it.
	 *
	 * @var int
	 */
	const MEMBER_SMS_LIMIT = 10;

	/**
	 * Length of the member cap window, in seconds (one hour).
	 *
	 * @var int
	 */
	const MEMBER_SMS_WINDOW = 3600;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		/*
		 * Detection is lazy on purpose. These filters register during
		 * component construction (plugins_loaded -10), while the notifications
		 * plugin first evaluates its channel and type registries no earlier
		 * than init 1000, so the registrations can never be missed - but
		 * touching another plugin's component HERE would fail whenever it is
		 * missing, older than 1.1.0, or deactivated, so every callback
		 * re-checks availability at call time via is_active() instead.
		 */
		add_filter( 'hivepress/v1/notification_channels', [ $this, 'add_channel' ] );
		add_filter( 'hivepress/v1/notification_types', [ $this, 'add_type_channel' ] );
		add_filter( 'hivepress/v1/notification_optin_channels', [ $this, 'add_optin_channel' ] );

		// Delivery.
		add_filter( 'hptw_sms_send', [ $this, 'filter_mirror_send' ], 10, 6 );
		add_action( 'hivepress/v1/notification_add', [ $this, 'send_notification_sms' ] );

		// Member-facing copy on the preferences form.
		add_filter( 'hivepress/v1/forms/hpnf_notification_update', [ $this, 'alter_preferences_form' ], 100, 2 );

		// Admin settings: the Member Preferences section plus the Defaults
		// sentence, after Twilio (100) and the notifications plugin (10) have
		// both built their screens.
		add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ], 200 );

		parent::__construct( $args );
	}

	/**
	 * Gets the notifications component, if present.
	 *
	 * The single access point to the other plugin, so a future rename of its
	 * component costs exactly one line here.
	 *
	 * @return object|null
	 */
	protected function notifications() {
		return hivepress()->hpnf_notification;
	}

	/**
	 * Checks whether the SMS channel is live.
	 *
	 * Toggle on, the notifications component present and at least 1.1.0 (the
	 * method_exists test is the version gate: without the opt-in mechanism,
	 * registering the channel would switch SMS ON for every member of a
	 * never-configured role), and Twilio configured. The toggle is a plain
	 * checkbox shipped off, so a truthy read is correct: absent and stored ''
	 * both mean off.
	 *
	 * @return bool
	 */
	protected function is_active() {
		return get_option( hp\prefix( 'twilio_notification_channel' ) )
			&& $this->notifications()
			&& method_exists( $this->notifications(), 'get_optin_channels' )
			&& hivepress()->hptw_twilio && hivepress()->hptw_twilio->is_ready();
	}

	/**
	 * Registers the SMS notification channel.
	 *
	 * The channel registry is rebuilt on every call, so if Twilio becomes
	 * unconfigured mid-flight the channel simply vanishes from the member
	 * form, the defaults, validation and export on the next request.
	 *
	 * @param array $channels Channel labels keyed by name.
	 * @return array
	 */
	public function add_channel( $channels ) {
		if ( $this->is_active() ) {
			$channels['sms'] = esc_html__( 'SMS', 'twilio-for-hivepress' );
		}

		return $channels;
	}

	/**
	 * Adds the SMS channel to every non-system notification type.
	 *
	 * System types bypass member preferences entirely, so adding SMS to them
	 * would text every user with no opt-out; they are skipped here and again
	 * defensively in the delivery handler.
	 *
	 * @param array $types Notification types.
	 * @return array
	 */
	public function add_type_channel( $types ) {
		if ( $this->is_active() ) {
			foreach ( $types as $name => $args ) {
				if ( ! hp\get_array_value( $args, '_system' ) ) {
					$types[ $name ]['channels'] = array_values( array_unique( array_merge( (array) hp\get_array_value( $args, 'channels', [ 'onsite' ] ), [ 'sms' ] ) ) );
				}
			}
		}

		return $types;
	}

	/**
	 * Registers SMS as an opt-in channel.
	 *
	 * Unconditional, unlike the two registrations above: whenever the channel
	 * exists at all it must be opt-in, and when it does not exist the name is
	 * intersected away against the live channel registry, so there is no
	 * state in which a role default could grant SMS.
	 *
	 * @param array $channels Opt-in channel names.
	 * @return array
	 */
	public function add_optin_channel( $channels ) {
		$channels[] = 'sms';

		return $channels;
	}

	/**
	 * Gates the email-mirror SMS on the member's own preferences.
	 *
	 * @param bool          $send Send flag.
	 * @param string        $phone Phone number.
	 * @param string        $text SMS text.
	 * @param object        $email Email object.
	 * @param \WP_User|null $user Recipient account, or null when the address matches no user.
	 * @param string        $address Recipient email address.
	 * @return bool
	 */
	public function filter_mirror_send( $send, $phone, $text, $email, $user = null, $address = '' ) {
		if ( ! $send || ! $this->is_active() ) {
			return $send;
		}

		// The administrator recipient keeps the mirror unconditionally,
		// whether the number came from the Administrator Phone option or
		// their own account: it is not a member preference.
		if ( $address && 0 === strcasecmp( (string) get_option( 'admin_email' ), (string) $address ) ) {
			return $send;
		}

		// Recipients without an account are untouched (unchanged 1.6.x behaviour).
		if ( ! $user instanceof \WP_User ) {
			return $send;
		}

		$type = $email::get_meta( 'name' );

		// Types the notifications admin has not enabled are left alone
		// entirely, mirroring the notifications plugin's own philosophy for
		// them: it only governs what it manages, and transactional events
		// such as password requests must not be member-suppressible.
		if ( ! in_array( $type, (array) $this->notifications()->get_enabled_types(), true ) ) {
			return $send;
		}

		if ( ! in_array( 'sms', (array) $this->notifications()->get_user_channels( $user->ID, $type ), true ) ) {
			return false;
		}

		// Quiet hours suppress and drop, never queue: queueing was rejected in
		// this plugin on privacy grounds, and the on-site record still exists
		// whenever the member ticked On-site.
		if ( $this->notifications()->is_quiet( $user->ID ) ) {
			return false;
		}

		if ( $this->is_capped( $user->ID ) ) {
			return false;
		}

		return $send;
	}

	/**
	 * Texts an on-site notification that has no email behind it.
	 *
	 * Mirrors the push channel: fires on hivepress/v1/notification_add, so it
	 * inherits the documented on-site gate (no notification, no SMS), which is
	 * acceptable because without on-site these events produce nothing anywhere.
	 *
	 * @param object $notification Notification object.
	 */
	public function send_notification_sms( $notification ) {
		if ( ! $this->is_active() ) {
			return;
		}

		$type = (string) $notification->get_type();
		$args = (array) $this->notifications()->get_type_args( $type );

		// System types bypass preferences; email-backed types are the mirror's job.
		if ( hp\get_array_value( $args, '_system' ) || hp\get_array_value( $args, 'email' ) ) {
			return;
		}

		$user_id = (int) $notification->get_user__id();

		if ( ! in_array( 'sms', (array) $this->notifications()->get_user_channels( $user_id, $type ), true ) ) {
			return;
		}

		if ( $this->notifications()->is_quiet( $user_id ) ) {
			return;
		}

		$phone = hivepress()->hptw_twilio->get_user_phone_number( $user_id );

		if ( ! $phone ) {
			return;
		}

		$text = trim( (string) $notification->get_text() . ' ' . (string) $notification->get_url() );

		/**
		 * Filters the SMS text sent for an on-site notification. The
		 * email-mirror filters (hptw_sms_text, hptw_sms_phone) contractually
		 * receive an Email object, which does not exist on this path, so it
		 * gets its own filter instead.
		 *
		 * @hook hptw_channel_sms_text
		 * @param {string} $text SMS text.
		 * @param {object} $notification Notification object.
		 * @return {string} SMS text. Return an empty value to prevent sending.
		 */
		$text = apply_filters( 'hptw_channel_sms_text', $text, $notification );

		if ( ! $text ) {
			return;
		}

		// The cap is consumed last, so a vetoed or undeliverable text never
		// spends part of the member's allowance.
		if ( $this->is_capped( $user_id ) ) {
			return;
		}

		// Deferred to shutdown, mirroring push's fire-and-forget: this path
		// can fire many times inside one request (a batch of favourites,
		// say), and stacking synchronous five-second timeouts would stall
		// the save that triggered it. The queued sends go out after the
		// response, as normal blocking requests with the usual 201 check.
		hivepress()->hptw_twilio->send_message( $phone, $text, false );
	}

	/**
	 * Checks the per-recipient cap, consuming one send when allowed.
	 *
	 * A fixed-window counter per recipient, rolling forward when the window
	 * expires. Both member delivery paths share it, so however the events
	 * arrive, one phone cannot be flooded and one runaway loop cannot run up
	 * the Twilio bill. Administrator-phone recipients never reach this check;
	 * their exemption lives in the callers.
	 *
	 * A slot is spent when this check passes, not when Twilio accepts: on
	 * the mirror path a later hptw_sms_send callback can still veto the
	 * send, and on either path the transport can fail, but the slot stays
	 * consumed. Documented in the readme's Limits FAQ.
	 *
	 * @param int $user_id Recipient user ID.
	 * @return bool True when the recipient is over the cap and the text must be dropped.
	 */
	protected function is_capped( $user_id ) {
		$user_id = absint( $user_id );

		/**
		 * Filters the per-recipient SMS limits for member deliveries.
		 *
		 * @hook hptw_sms_member_limits
		 * @param {array} $limits Limits: 'limit' (texts per window) and 'window' (seconds).
		 * @param {int} $user_id Recipient user ID.
		 * @return {array} Limits.
		 */
		$limits = (array) apply_filters(
			'hptw_sms_member_limits',
			[
				'limit'  => self::MEMBER_SMS_LIMIT,
				'window' => self::MEMBER_SMS_WINDOW,
			],
			$user_id
		);

		$limit  = max( 1, absint( hp\get_array_value( $limits, 'limit', self::MEMBER_SMS_LIMIT ) ) );
		$window = max( 60, absint( hp\get_array_value( $limits, 'window', self::MEMBER_SMS_WINDOW ) ) );

		/*
		 * The transient always stores a non-empty array: get_transient()
		 * returns false for a missing row, so any falsy stored value would be
		 * indistinguishable from "no counter yet" and the count would reset.
		 */
		$name   = 'hptw_sms_cap_' . $user_id;
		$bucket = get_transient( $name );

		if ( ! is_array( $bucket ) || ! isset( $bucket['start'], $bucket['count'] ) || time() - (int) $bucket['start'] >= $window ) {
			$bucket = [
				'start' => time(),
				'count' => 0,
			];
		}

		if ( (int) $bucket['count'] >= $limit ) {

			// One log line per drop; a user ID identifies the recipient
			// without putting a phone number or email address in the log.
			/* translators: %d: user ID. */
			$this->log( sprintf( __( 'SMS cap reached for user #%d, message dropped.', 'twilio-for-hivepress' ), $user_id ) );

			return true;
		}

		$bucket['count'] = (int) $bucket['count'] + 1;

		set_transient( $name, $bucket, $window );

		return false;
	}

	/**
	 * Alters the member preferences form copy.
	 *
	 * A checkboxes field cannot carry per-option captions, so the form-level
	 * description is the only place to say where SMS actually goes - and to
	 * warn a member without a saved number that ticking SMS does nothing yet.
	 *
	 * @param array  $args Form arguments.
	 * @param object $form Form object.
	 * @return array
	 */
	public function alter_preferences_form( $args, $form ) {

		// The filter also fires for any subclassed form; target this one only.
		if ( 'HivePress\Forms\Hpnf_Notification_Update' !== get_class( $form ) || ! $this->is_active() ) {
			return $args;
		}

		$description = (string) hp\get_array_value( $args, 'description' );

		if ( hivepress()->hptw_twilio->get_user_phone_number( get_current_user_id() ) ) {
			$description .= ' ' . esc_html__( 'SMS goes to the phone number saved in your account settings.', 'twilio-for-hivepress' );
		} else {
			$description .= ' ' . sprintf(
				/* translators: %s: account settings page URL. */
				__( 'There is no phone number on your account yet, so ticking SMS will do nothing until you add one in your <a href="%s">account settings</a>.', 'twilio-for-hivepress' ),
				esc_url( hivepress()->router->get_url( 'user_edit_settings_page' ) )
			);
		}

		$args['description'] = trim( $description );

		return $args;
	}

	/**
	 * Adds the Member Preferences settings section.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings( $settings ) {

		/*
		 * Without the notifications plugin there is nothing to link to, so no
		 * section is added at all rather than a section about an absent
		 * plugin. A pre-retrofit install (1.0.x) registers its component as
		 * 'notification' rather than 'hpnf_notification', so that name is
		 * probed too - purely so such an install sees the too-old copy below
		 * instead of nothing; the channel itself only ever registers against
		 * hpnf_notification with get_optin_channels (see is_active()).
		 */
		if ( ! $this->notifications() && ! hivepress()->notification ) {
			return $settings;
		}

		if ( $this->notifications() && method_exists( $this->notifications(), 'get_optin_channels' ) ) {
			$description = esc_html__( 'This links SMS with the Notifications for HivePress extension. With the box below ticked, SMS joins On-site, Email and Push as a choice on each member\'s Notification Settings page. For events enabled under the notification settings, a text only goes to a member who has ticked SMS there. Events you have left disabled there keep today\'s behaviour and go to anyone with a saved message. Everyone starts unticked, so ticking this box stops texts to members until each person opts in; texts to the administrator phone are not affected. The events above still decide which emails can be texted at all and what each text says, and clearing an event\'s message still switches that event off for everyone.', 'twilio-for-hivepress' );
		} else {
			$description = esc_html__( 'This links SMS with the Notifications for HivePress extension. Your copy of that extension is too old for this feature, so the box below has no effect until you update it to version 1.1.0 or later.', 'twilio-for-hivepress' );
		}

		$settings = hp\merge_arrays(
			$settings,
			[
				'sms' => [
					'sections' => [
						'notifications' => [
							'title'       => esc_html__( 'Member Preferences', 'twilio-for-hivepress' ),
							'description' => $description,
							'_order'      => 25,

							'fields'      => [

								/*
								 * Ships off, with no config default: switching
								 * it on gates the existing mirror behind
								 * member opt-in, silently stopping all member
								 * texts until each person opts in, so the
								 * change must be an explicit admin choice made
								 * after reading the copy above. A config
								 * default would also be written to the
								 * database on activation.
								 */
								'twilio_notification_channel' => [
									'label'       => esc_html__( 'Member Opt-in', 'twilio-for-hivepress' ),
									'caption'     => esc_html__( 'Let members choose SMS on their Notification Settings page', 'twilio-for-hivepress' ),
									'description' => esc_html__( 'For events enabled under the notification settings, texts only go to members who have ticked SMS themselves, and their quiet hours are respected, so a text that falls inside them is simply not sent. A few notifications have no email behind them, such as a completed booking or a new favourite; those are texted using the wording from the notification settings and only alongside the on-site notification. Announcements are never texted. Events you have left disabled under the notification settings keep today\'s behaviour and go to anyone with a saved message.', 'twilio-for-hivepress' ),
									'type'        => 'checkbox',
									'_order'      => 10,
								],
							],
						],
					],
				],
			]
		);

		// Explain the deliberately missing SMS box on the notifications
		// plugin's per-role Defaults screen, only while the channel is live.
		if ( $this->is_active() && isset( $settings['notifications']['sections']['defaults']['description'] ) ) {
			$settings['notifications']['sections']['defaults']['description'] .= ' ' . esc_html__( 'SMS has no box here on purpose: each text costs money and goes straight to someone\'s phone, so members opt in themselves on their Notification Settings page and no role ever starts with it.', 'twilio-for-hivepress' );
		}

		return $settings;
	}

	/**
	 * Logs a diagnostic message when logging is enabled.
	 *
	 * A local copy of the Twilio component's gate on the same option: that
	 * method is protected, and the shared public API is deliberately kept to
	 * the send and lookup surface.
	 *
	 * @param string $text Log message, never containing a phone number or a full email address.
	 */
	protected function log( $text ) {
		if ( get_option( hp\prefix( 'twilio_enable_logging' ) ) ) {

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Optional diagnostic logging enabled via the plugin settings.
			error_log( 'Twilio for HivePress: ' . $text );
		}
	}
}
