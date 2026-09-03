<?php
/**
 * Paid access component.
 *
 * @package HivePress\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sells SMS through HivePress Memberships plans.
 *
 * Twilio bills the site owner for every text, so without a way to charge for
 * them SMS is a cost the owner carries on behalf of every member. This puts a
 * single tick box, "Allow SMS notifications", on each membership plan's
 * Settings box, the same way Additional Gallery for HivePress sells gallery
 * access. Leave it unticked on every plan and nothing changes: every member
 * with a saved number can receive texts, exactly as before this existed. Tick
 * it on one plan or more and member-addressed texts only go to a user holding
 * an active membership on one of those plans; everyone else loses the SMS
 * choice on their Notification Settings page and sees a short sentence with a
 * link to the plans instead.
 *
 * Two kinds of text are deliberately never gated. Texts to the administrator
 * phone are the owner's own alerts, not a member benefit. Sign-in codes are a
 * way into the account rather than a notification, and a member locked out of
 * a sign-in method they were offered yesterday is a support ticket, not a
 * sale.
 *
 * The gate itself is a persisted flag (hp_twilio_sms_gated) recomputed
 * whenever a plan is saved, trashed or deleted, so it fails CLOSED: if the
 * Memberships extension is switched off while plans still tick the box, the
 * flag stays set, no membership can be found, and no member is texted for
 * free by accident. Same reasoning as the gallery's hp_gallery_access_gated.
 */
final class Hptw_Access extends Component {

	/**
	 * Plan meta key behind the "Allow SMS notifications" tick box.
	 *
	 * @var string
	 */
	const PLAN_META = 'hp_twilio_sms_access';

	/**
	 * Option holding the fail-closed gate flag.
	 *
	 * @var string
	 */
	const GATE_OPTION = 'hp_twilio_sms_gated';

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// The tick box on each membership plan, stored as plan meta.
		add_filter( 'hivepress/v1/models/membership_plan', [ $this, 'add_plan_field' ] );
		add_filter( 'hivepress/v1/meta_boxes/membership_plan_settings', [ $this, 'add_plan_setting' ] );

		// Keep the fail-closed flag in step with the plans. The model action fires after the
		// plan meta is saved; the late save_post is the fallback, and both are idempotent.
		add_action( 'hivepress/v1/models/membership_plan/update', [ $this, 'refresh_gate' ] );
		add_action( 'save_post', [ $this, 'refresh_gate_for_post' ], 999, 2 );
		add_action( 'deleted_post', [ $this, 'refresh_gate_for_post' ], 10, 2 );
		add_action( 'trashed_post', [ $this, 'refresh_gate_for_post' ] );
		add_action( 'untrashed_post', [ $this, 'refresh_gate_for_post' ] );

		/*
		 * Delivery. Priority 5 on the mirror filter, ahead of the notification bridge at 10, so
		 * a member without access is refused before their preferences are even consulted. The
		 * on-site path has no send flag of its own; its text filter is the documented veto
		 * (return an empty value), so that is what is used.
		 */
		add_filter( 'hptw_sms_send', [ $this, 'gate_mirror_send' ], 5, 6 );
		add_filter( 'hptw_channel_sms_text', [ $this, 'gate_channel_text' ], 5, 2 );

		// The member's own Notification Settings page, after the channel bridge (100) has
		// written its sentence about where SMS goes.
		add_filter( 'hivepress/v1/forms/hpnf_notification_update', [ $this, 'alter_preferences_form' ], 110, 2 );

		// Admin settings, after the Twilio component (100) and the channel bridge (200).
		add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ], 210 );

		parent::__construct( $args );
	}

	/**
	 * Checks whether the HivePress Memberships extension is active.
	 *
	 * The post type is the test rather than the version, because it is the
	 * post type this component queries.
	 *
	 * @return bool
	 */
	public function is_memberships_active() {
		return post_type_exists( 'hp_membership' );
	}

	/**
	 * Gets the membership plan post type.
	 *
	 * Memberships is a premium (closed-source) extension, so the name is not
	 * hard-coded blindly: the conventional name is tried first, then the parent
	 * of an existing membership record (memberships store their plan as
	 * post_parent). Mirrors Additional Gallery, which has run this way since
	 * its 1.3.0.
	 *
	 * @return string|null
	 */
	public function get_plan_post_type() {
		static $post_type;

		if ( ! isset( $post_type ) ) {
			$post_type = null;

			if ( post_type_exists( 'hp_membership_plan' ) ) {
				$post_type = 'hp_membership_plan';
			} elseif ( post_type_exists( 'hp_membership' ) ) {
				$membership_ids = get_posts(
					[
						'post_type'   => 'hp_membership',
						'post_status' => 'any',
						'numberposts' => 5,
						'fields'      => 'ids',
					]
				);

				foreach ( $membership_ids as $membership_id ) {
					$parent_id = wp_get_post_parent_id( $membership_id );

					if ( $parent_id && get_post_type( $parent_id ) ) {
						$post_type = get_post_type( $parent_id );

						break;
					}
				}
			}
		}

		return $post_type;
	}

	/**
	 * Adds the access field to the Membership Plan model.
	 *
	 * @param array $model Model arguments.
	 * @return array
	 */
	public function add_plan_field( $model ) {
		$model['fields']['twilio_sms_access'] = [
			'type'      => 'checkbox',
			'_external' => true,
		];

		return $model;
	}

	/**
	 * Adds the tick box to the Membership Plan settings meta box.
	 *
	 * @param array $meta_box Meta box arguments.
	 * @return array
	 */
	public function add_plan_setting( $meta_box ) {
		$meta_box['fields']['twilio_sms_access'] = [
			'label'       => esc_html__( 'SMS', 'twilio-for-hivepress' ),
			'caption'     => esc_html__( 'Allow SMS notifications', 'twilio-for-hivepress' ),
			'description' => esc_html__( 'Members on this plan can receive HivePress notifications by text message. Leave this unticked on every plan to keep SMS open to every member with a phone number; tick it on one plan or more and texts only go to members of those plans. Texts to the administrator phone and sign-in codes are never limited.', 'twilio-for-hivepress' ),
			'type'        => 'checkbox',
			'_order'      => 215,
		];

		return $meta_box;
	}

	/**
	 * Gets the IDs of published plans that allow SMS.
	 *
	 * @return array
	 */
	public function get_access_plan_ids() {
		static $plan_ids;

		if ( ! isset( $plan_ids ) ) {
			$plan_ids = $this->query_plan_ids();
		}

		return $plan_ids;
	}

	/**
	 * Queries published plans carrying the access flag.
	 *
	 * @return array Plan IDs.
	 */
	protected function query_plan_ids() {
		$post_type = $this->get_plan_post_type();

		if ( ! $post_type ) {
			return [];
		}

		return array_map(
			'absint',
			get_posts(
				[
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',

					'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-defined plan set read behind a persisted gating flag.
						[
							'key'     => self::PLAN_META,
							'value'   => '1',
							'compare' => '=',
						],
					],
				]
			)
		);
	}

	/**
	 * Whether SMS is currently limited to members of chosen plans.
	 *
	 * Reads the persisted flag rather than querying plans, so the answer holds
	 * even when Memberships has been switched off (fail closed).
	 *
	 * @return bool
	 */
	public function is_gated() {
		return (bool) get_option( self::GATE_OPTION );
	}

	/**
	 * Recomputes and stores the gate flag.
	 */
	public function refresh_gate() {
		update_option( self::GATE_OPTION, $this->query_plan_ids() ? '1' : '' );
	}

	/**
	 * Refreshes the gate flag when a plan is saved, deleted, trashed or restored.
	 *
	 * Reads the type from the passed post so it still works from deleted_post,
	 * after the row is gone.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post Post object, when the hook provides one.
	 */
	public function refresh_gate_for_post( $post_id, $post = null ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$type       = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		$plan_types = array_filter( [ $this->get_plan_post_type(), 'hp_membership_plan' ] );

		if ( in_array( $type, $plan_types, true ) ) {
			$this->refresh_gate();
		}
	}

	/**
	 * Checks whether a user holds an active membership on any of the given plans.
	 *
	 * A membership is an hp_membership post whose author is the user and whose
	 * parent is the plan; it is `publish` while active and `draft` once it has
	 * lapsed (Memberships' own model, models/class-membership.php).
	 *
	 * @param int   $user_id User ID.
	 * @param array $plan_ids Plan IDs.
	 * @return bool
	 */
	public function user_has_active_membership( $user_id, $plan_ids ) {
		static $cache = [];

		$user_id  = absint( $user_id );
		$plan_ids = array_filter( array_map( 'absint', (array) $plan_ids ) );

		if ( ! $user_id || ! $plan_ids || ! $this->is_memberships_active() ) {
			return false;
		}

		$cache_key = $user_id . '_' . implode( '_', $plan_ids );

		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$membership_ids = get_posts(
			[
				'post_type'       => 'hp_membership',
				'post_status'     => 'publish',
				'author'          => $user_id,
				'post_parent__in' => $plan_ids,
				'fields'          => 'ids',
				'numberposts'     => 1,
			]
		);

		$cache[ $cache_key ] = ! empty( $membership_ids );

		return $cache[ $cache_key ];
	}

	/**
	 * Whether a member may receive SMS notifications.
	 *
	 * True for everyone while no plan limits SMS. Once one does, only a user
	 * with an active membership on one of the ticked plans passes.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function user_can_receive_sms( $user_id ) {
		$user_id = absint( $user_id );
		$can     = true;

		if ( $this->is_gated() ) {
			$can = $user_id && $this->user_has_active_membership( $user_id, $this->get_access_plan_ids() );
		}

		/**
		 * Filters whether a member may receive SMS notifications, so a site can
		 * grant access from its own membership or purchase system.
		 *
		 * @hook hptw_sms_access
		 * @param {bool} $can Whether the member has access.
		 * @param {int} $user_id User ID.
		 * @return {bool} Whether the member has access.
		 */
		return (bool) apply_filters( 'hptw_sms_access', $can, $user_id );
	}

	/**
	 * Refuses the email-mirror text for a member without access.
	 *
	 * The administrator recipient is untouched whichever way their number was
	 * found, and so is a recipient with no account: the gate is about members.
	 *
	 * @param bool          $send Send flag.
	 * @param string        $phone Phone number.
	 * @param string        $text SMS text.
	 * @param object        $email Email object.
	 * @param \WP_User|null $user Recipient account, or null when the address matches no user.
	 * @param string        $address Recipient email address.
	 * @return bool
	 */
	public function gate_mirror_send( $send, $phone, $text, $email, $user = null, $address = '' ) {
		if ( ! $send || ! $this->is_gated() ) {
			return $send;
		}

		if ( $address && 0 === strcasecmp( (string) get_option( 'admin_email' ), (string) $address ) ) {
			return $send;
		}

		if ( ! $user instanceof \WP_User ) {
			return $send;
		}

		if ( ! $this->user_can_receive_sms( $user->ID ) ) {
			$this->log( sprintf( 'SMS to user #%d dropped: their membership does not include SMS.', $user->ID ) );

			return false;
		}

		return $send;
	}

	/**
	 * Vetoes the on-site notification text for a member without access.
	 *
	 * @param string $text SMS text.
	 * @param object $notification Notification object.
	 * @return string
	 */
	public function gate_channel_text( $text, $notification ) {
		if ( ! $text || ! $this->is_gated() || ! is_object( $notification ) || ! method_exists( $notification, 'get_user__id' ) ) {
			return $text;
		}

		$user_id = (int) $notification->get_user__id();

		if ( ! $this->user_can_receive_sms( $user_id ) ) {
			$this->log( sprintf( 'SMS to user #%d dropped: their membership does not include SMS.', $user_id ) );

			return '';
		}

		return $text;
	}

	/**
	 * Removes the SMS choice from the preferences form of a member without access.
	 *
	 * The tick boxes are per group, with the channels as options; taking the
	 * option away is what stops the member ticking a box that could never do
	 * anything. The sentence that replaces it says why, with a link to the
	 * plans when the site has a plans page.
	 *
	 * @param array  $args Form arguments.
	 * @param object $form Form object.
	 * @return array
	 */
	public function alter_preferences_form( $args, $form ) {
		if ( 'HivePress\Forms\Hpnf_Notification_Update' !== get_class( $form ) || ! $this->is_gated() ) {
			return $args;
		}

		if ( $this->user_can_receive_sms( get_current_user_id() ) ) {
			return $args;
		}

		$removed = false;

		foreach ( (array) hp\get_array_value( $args, 'fields', [] ) as $name => $field ) {
			if ( isset( $field['options']['sms'] ) ) {
				unset( $args['fields'][ $name ]['options']['sms'] );

				if ( isset( $field['default'] ) && is_array( $field['default'] ) ) {
					$args['fields'][ $name ]['default'] = array_values( array_diff( $field['default'], [ 'sms' ] ) );
				}

				$removed = true;
			}
		}

		if ( ! $removed ) {
			return $args;
		}

		// The channel bridge's "SMS goes to the phone number saved in your account" sentence
		// has just become untrue for this member; replace the description rather than add to it.
		$args['description'] = $this->get_access_message();

		return $args;
	}

	/**
	 * Gets the sentence shown to a member whose membership does not include SMS.
	 *
	 * @return string HTML, safe for the form description.
	 */
	public function get_access_message() {
		$message = (string) get_option( 'hp_twilio_access_message' );

		if ( '' === trim( $message ) ) {
			$message = $this->get_default_access_message();
		}

		$message = esc_html( $message );
		$url     = $this->get_upgrade_url();

		if ( $url ) {
			$message .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'See the membership plans', 'twilio-for-hivepress' ) . '</a>';
		}

		return $message;
	}

	/**
	 * Gets the default wording for the member-facing sentence.
	 *
	 * @return string
	 */
	public function get_default_access_message() {
		return __( 'Text message notifications are included with some membership plans; your current membership does not include them.', 'twilio-for-hivepress' );
	}

	/**
	 * Gets the URL of the membership plans page, if the site has one.
	 *
	 * @return string|null
	 */
	public function get_upgrade_url() {
		if ( hivepress()->router->get_route( 'membership_plans_view_page' ) ) {
			return hivepress()->router->get_url( 'membership_plans_view_page' );
		}

		return null;
	}

	/**
	 * Adds the Paid Access settings section.
	 *
	 * The section says what the gate is doing right now, because the switch
	 * itself lives on the plans rather than here: an owner reading this screen
	 * to find out why a member is or is not being texted gets the answer in one
	 * sentence, with a link to the plans.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings( $settings ) {
		if ( ! $this->is_memberships_active() ) {
			$description = esc_html__( 'Selling SMS access needs the HivePress Memberships extension. With it active, each membership plan gets an "Allow SMS notifications" tick box, and texts only go to members whose plan includes them.', 'twilio-for-hivepress' );
		} else {
			$plans_url = admin_url( 'edit.php?post_type=' . ( $this->get_plan_post_type() ? $this->get_plan_post_type() : 'hp_membership_plan' ) );
			$plan_ids  = $this->get_access_plan_ids();

			if ( $plan_ids ) {
				$titles = array_map( 'get_the_title', $plan_ids );

				$description = sprintf(
					/* translators: 1: plan names, 2: plans screen URL. */
					__( 'SMS notifications currently go only to members of these plans: %1$s. Tick or untick "Allow SMS notifications" on a plan under <a href="%2$s">Memberships</a> to change that; untick it everywhere to open SMS to every member again. Texts to the administrator phone and sign-in codes are never limited.', 'twilio-for-hivepress' ),
					esc_html( implode( ', ', array_filter( array_map( 'strval', $titles ) ) ) ),
					esc_url( $plans_url )
				);
			} else {
				$description = sprintf(
					/* translators: %s: plans screen URL. */
					__( 'No plan limits SMS at the moment, so every member with a saved phone number can receive texts. To sell SMS access, tick "Allow SMS notifications" on one plan or more under <a href="%s">Memberships</a>; texts then go only to members of those plans, and everyone else sees the sentence below on their Notification Settings page. Texts to the administrator phone and sign-in codes are never limited.', 'twilio-for-hivepress' ),
					esc_url( $plans_url )
				);
			}
		}

		return hp\merge_arrays(
			$settings,
			[
				'sms' => [
					'sections' => [
						'access' => [
							'title'       => esc_html__( 'Paid Access', 'twilio-for-hivepress' ),
							'description' => $description,
							'_order'      => 27,

							'fields'      => [
								'twilio_access_message' => [
									'label'       => esc_html__( 'Members Without SMS', 'twilio-for-hivepress' ),
									'description' => esc_html__( 'The sentence shown on the Notification Settings page of a member whose membership does not include SMS, in place of the SMS tick boxes. A link to your membership plans page is added after it. Leave it empty for the standard wording.', 'twilio-for-hivepress' ),
									'placeholder' => $this->get_default_access_message(),
									'type'        => 'textarea',
									'max_length'  => 400,
									'_order'      => 10,
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Logs a diagnostic message when logging is enabled.
	 *
	 * A user ID identifies the member without putting a phone number or an
	 * email address in the log.
	 *
	 * @param string $text Log message.
	 */
	protected function log( $text ) {
		if ( get_option( hp\prefix( 'twilio_enable_logging' ) ) ) {

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Optional diagnostic logging enabled via the plugin settings.
			error_log( 'Twilio for HivePress: ' . $text );
		}
	}
}
