<?php
/**
 * OTP component.
 *
 * @package HivePress\Components
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Signs users in with a one-time SMS code.
 *
 * Owns every piece of OTP state: the E.164 shadow index that resolves a typed
 * phone number to an account, the hashed code records, the atomic attempt
 * counter, and the request-side rate buckets. The REST orchestration lives in
 * the controller of the same name; it calls into this component so the meta
 * keys, hashing and limits never leave one file.
 *
 * Storage is split on purpose: login codes live in user meta because a
 * persistent object cache (Memcached on the staging host) can evict a
 * transient at any moment, and a nondeterministically vanishing login code is
 * unacceptable, while rate counters ARE transients because losing one merely
 * fails open on a limit.
 */
final class Hptw_Otp extends Component {

	/**
	 * Number of digits in a sign-in code.
	 *
	 * @var int
	 */
	const CODE_LENGTH = 6;

	/**
	 * Lifetime of a sign-in code, in seconds.
	 *
	 * @var int
	 */
	const EXPIRY = 600;

	/**
	 * Maximum verification attempts per code.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Users synced per backfill batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 200;

	/**
	 * Class constructor.
	 *
	 * Registers hooks only: components build in alphabetical order, so
	 * hptw_otp constructs before hptw_twilio and touching
	 * hivepress()->hptw_twilio here would read null. Anything needing the
	 * Twilio component waits for init or a later hook.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// The phone attribute name is an option, so the model-event hook for
		// it can only be resolved once options are readable at init.
		add_action( 'init', [ $this, 'register_sync_hooks' ], 20 );

		// Priority 2: the Twilio component stores the registration phone at
		// priority 1 on the same action, and the shadow row derives from it.
		add_action( 'hivepress/v1/models/user/register', [ $this, 'handle_register' ], 2, 2 );

		// Any completed login kills a pending code: core, Social Login and the
		// email-verify flow all fire wp_login.
		add_action( 'wp_login', [ $this, 'invalidate_code' ], 10, 2 );

		// Entry UX: the link in the login form's actions row and the global
		// guest-only modal. One block filter covers both the login page and
		// the login modal, because both render the same block.
		add_filter( 'hivepress/v1/blocks/user_login_form', [ $this, 'add_login_link' ], 10, 2 );
		add_filter( 'hivepress/v1/templates/site_footer_block', [ $this, 'add_modal' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Core already schedules this event; no plugin-owned scheduled actions.
		add_action( 'hivepress/v1/events/hourly', [ $this, 'run_backfill' ] );

		// Enabling the feature starts a fresh backfill immediately, so most
		// existing numbers resolve before the first hourly run.
		add_action( 'update_option_hp_twilio_otp_login', [ $this, 'handle_enable' ], 10, 2 );
		add_action( 'add_option_hp_twilio_otp_login', [ $this, 'handle_enable_add' ], 10, 2 );

		/*
		 * A changed phone attribute or country code invalidates every derived
		 * E.164 value, so the backfill cursor is dropped and the hourly runs
		 * rebuild the shadow index. The add_option_ pair covers the first-ever
		 * write of each option, which fires add_option_, not update_option_;
		 * the differing argument order between the two hooks is irrelevant
		 * because the callback takes no arguments.
		 */
		add_action( 'update_option_hp_twilio_phone_attribute', [ $this, 'reset_backfill' ] );
		add_action( 'add_option_hp_twilio_phone_attribute', [ $this, 'reset_backfill' ] );
		add_action( 'update_option_hp_twilio_country_code', [ $this, 'reset_backfill' ] );
		add_action( 'add_option_hp_twilio_country_code', [ $this, 'reset_backfill' ] );

		parent::__construct( $args );
	}

	/**
	 * Checks whether SMS sign-in is switched on and able to send.
	 *
	 * A plain unchecked checkbox stores '', and both '' and an absent option
	 * are correctly "off" for a boolean gate, so no stored-'' branching is
	 * needed here.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return get_option( hp\prefix( 'twilio_otp_login' ) ) && hivepress()->hptw_twilio->is_ready();
	}

	/**
	 * Registers the phone-attribute sync hook.
	 *
	 * The model event fires from the added/updated/deleted user-meta hooks
	 * directly, so account-settings edits, wp-admin profile saves and deletes
	 * are all covered, including edits that bypass the HivePress model.
	 */
	public function register_sync_hooks() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'hivepress/v1/models/user/update_' . hivepress()->hptw_twilio->get_phone_attribute(), [ $this, 'handle_phone_update' ], 10, 2 );
	}

	/**
	 * Handles a phone attribute change.
	 *
	 * The passed value is ignored on purpose: the sync re-derives from the
	 * source of truth, so a delete (null) and an update take the same path.
	 *
	 * @param int   $user_id User ID.
	 * @param mixed $value New meta value, null on delete.
	 */
	public function handle_phone_update( $user_id, $value = null ) {
		$this->sync_user( $user_id );
	}

	/**
	 * Syncs the shadow row for a newly registered user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $values Form values.
	 */
	public function handle_register( $user_id, $values ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->sync_user( $user_id );
	}

	/**
	 * Deletes any pending code once a user logs in.
	 *
	 * Deliberately not gated on is_enabled(): a code issued before the
	 * feature was switched off must still die on the next login, whichever
	 * way the user signed in.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user User object.
	 */
	public function invalidate_code( $user_login, $user ) {
		if ( $user instanceof \WP_User ) {
			$this->delete_code( $user->ID );
		}
	}

	/**
	 * Adds the sign-in code link to the login form actions.
	 *
	 * The subclass merges its footer defaults before the parent applies this
	 * filter, so the form_actions container is guaranteed to exist. Order 15
	 * places the link between "Register" (10) and "Forgot password?" (20).
	 *
	 * @param array  $args Block arguments.
	 * @param object $block Block object.
	 * @return array
	 */
	public function add_login_link( $args, $block ) {
		if ( $this->is_enabled() ) {
			$args['footer']['form_actions']['blocks']['hptw_otp_login_link'] = [
				'type'   => 'part',
				'path'   => 'hptw-otp/login-link',
				'_order' => 15,
			];
		}

		return $args;
	}

	/**
	 * Adds the sign-in code modal to the site footer.
	 *
	 * Mirrors core's own login modal injection into the same template. The
	 * template has no label meta and is not exposed to the visual editor, so
	 * on stock core the modal renders unconditionally. The login capability
	 * renders it for guests only, and core JS binds any link pointing at the
	 * modal ID, closing the current fancybox first, exactly as the
	 * "Forgot password?" link hops between modals.
	 *
	 * @param array $template Template arguments.
	 * @return array
	 */
	public function add_modal( $template ) {
		if ( ! $this->is_enabled() ) {
			return $template;
		}

		return hivepress()->template->merge_blocks(
			$template,
			[
				'modals' => [
					'blocks' => [
						'hptw_otp_login_modal' => [
							'type'        => 'modal',
							'title'       => esc_html__( 'Sign In with a Code', 'twilio-for-hivepress' ),
							'_capability' => 'login',

							'blocks'      => [
								'hptw_otp_request_form'  => [
									'type'       => 'form',
									'form'       => 'hptw_otp_request',
									'_order'     => 10,

									'attributes' => [
										'class' => [ 'hp-form--narrow' ],
									],
								],

								'hptw_otp_verify_form'   => [
									'type'       => 'form',
									'form'       => 'hptw_otp_verify',
									'_order'     => 20,

									'attributes' => [
										'class' => [ 'hp-form--narrow' ],
									],
								],

								'hptw_otp_password_link' => [
									'type'   => 'part',
									'path'   => 'hptw-otp/password-link',
									'_order' => 30,
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Enqueues the front-end assets.
	 *
	 * Guests only: the modal itself is capability-gated to guests, so for a
	 * logged-in visitor the script and style would dress nothing.
	 */
	public function enqueue_assets() {
		if ( is_user_logged_in() || ! $this->is_enabled() ) {
			return;
		}

		/*
		 * The file's own timestamp rides along in the version string. WordPress cache-busts with
		 * "?ver=", so a version that only changes when the plugin does serves stale CSS and JS to
		 * everyone who already has a copy - and an optimiser makes it worse: staging's FlyingPress
		 * was still serving a 170-byte combined copy of a 1,734-byte stylesheet, with the ?ver=
		 * stripped entirely, so two rounds of CSS edits were invisible there (2026-08-18). Editing
		 * an asset without touching the plugin version is normal during a fix round, which is
		 * exactly when this bites.
		 */
		$path = hivepress()->get_path( 'twilio_for_hivepress' );
		$url  = hivepress()->get_url( 'twilio_for_hivepress' );

		wp_enqueue_style( 'hptw-otp', $url . '/assets/css/hptw-otp.css', [], \TwilioForHivePress\VERSION . '.' . (int) filemtime( $path . '/assets/css/hptw-otp.css' ) );

		wp_enqueue_script( 'hptw-otp', $url . '/assets/js/hptw-otp.js', [ 'jquery' ], \TwilioForHivePress\VERSION . '.' . (int) filemtime( $path . '/assets/js/hptw-otp.js' ), true );

		// A flat object of strings only, so the scalar cast applied by
		// wp_localize_script changes nothing.
		wp_localize_script(
			'hptw-otp',
			'hptwOtpData',
			[
				'requestUrl' => hivepress()->router->get_url( 'hptw_otp_request_action' ),
			]
		);
	}

	/**
	 * Runs one hourly backfill batch.
	 *
	 * Keyset pagination over user IDs: users created after a batch are synced
	 * by the registration hook anyway, so cursor drift is a non-issue. The
	 * state option always stores a non-empty array, never ''/false, so its
	 * absence is unambiguous.
	 */
	public function run_backfill() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$state = get_option( hp\prefix( 'twilio_otp_backfill' ) );

		if ( is_array( $state ) && ! empty( $state['done'] ) ) {
			return;
		}

		$last_id = is_array( $state ) ? absint( hp\get_array_value( $state, 'last_id' ) ) : 0;

		global $wpdb;

		/*
		 * Keyset pagination on the indexed user_id column with a DISTINCT
		 * collapse, which WP_User_Query cannot express; the batch is bounded
		 * and each run touches at most BATCH_SIZE users.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset batch unavailable through WP_User_Query; results are consumed once.
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d",
				hp\prefix( hivepress()->hptw_twilio->get_phone_attribute() ),
				$last_id,
				self::BATCH_SIZE
			)
		);

		$user_ids = array_map( 'absint', (array) $user_ids );

		foreach ( $user_ids as $user_id ) {
			$this->sync_user( $user_id );
		}

		if ( count( $user_ids ) < self::BATCH_SIZE ) {
			update_option( hp\prefix( 'twilio_otp_backfill' ), [ 'done' => 1 ], false );
		} else {
			update_option( hp\prefix( 'twilio_otp_backfill' ), [ 'last_id' => (int) end( $user_ids ) ], false );
		}
	}

	/**
	 * Handles the SMS sign-in option being updated.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value New value.
	 */
	public function handle_enable( $old_value, $value ) {
		if ( $value ) {
			$this->reset_backfill();
			$this->run_backfill();
		}
	}

	/**
	 * Handles the SMS sign-in option being created.
	 *
	 * The first-ever save of an option fires add_option_, not update_option_,
	 * with the option name as the first argument.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value New value.
	 */
	public function handle_enable_add( $option, $value ) {
		if ( $value ) {
			$this->reset_backfill();
			$this->run_backfill();
		}
	}

	/**
	 * Resets the backfill cursor so the shadow index is rebuilt.
	 */
	public function reset_backfill() {
		delete_option( hp\prefix( 'twilio_otp_backfill' ) );
	}

	/**
	 * Syncs one user's E.164 shadow row from the source of truth.
	 *
	 * The shadow meta names match no HivePress user field, so the Hook
	 * component resolves no field name for them and fires no model events -
	 * writing them here cannot recurse into the sync hook.
	 *
	 * Two rows, not one. The E.164 row is the index the sign-in path resolves
	 * against, and it exists only for numbers that normalise. The digits row
	 * is the opposite: it exists only for numbers that do NOT normalise, which
	 * on a site with no Country Code setting means every number saved in the
	 * national format. Those numbers can never be texted, but they can still
	 * be the same real number as one that can, and without a row for them the
	 * duplicate test never sees them.
	 *
	 * @param int $user_id User ID.
	 */
	public function sync_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		$twilio = hivepress()->hptw_twilio;

		$e164 = $twilio->get_user_phone_number( $user_id );

		if ( $e164 ) {
			update_user_meta( $user_id, hp\prefix( 'twilio_phone_e164' ), $e164 );
		} else {
			delete_user_meta( $user_id, hp\prefix( 'twilio_phone_e164' ) );
		}

		$digits = $twilio->get_user_match_key( $user_id );

		if ( $digits ) {
			update_user_meta( $user_id, hp\prefix( 'twilio_phone_digits' ), $digits );
		} else {
			delete_user_meta( $user_id, hp\prefix( 'twilio_phone_digits' ) );
		}
	}

	/**
	 * Resolves a phone number to a single user ID.
	 *
	 * The shadow rows are an index, never an authority: every candidate they
	 * return is re-verified against the stored attribute and re-synced, which
	 * purges orphaned rows as a side effect, so the duplicate refusal below
	 * only ever fires for two accounts genuinely sharing one number - the
	 * case the settings copy describes.
	 *
	 * A missed duplicate is not a cosmetic miss. The refusal exists because
	 * nothing can tell which of two accounts sharing a number the requester
	 * meant, so failing to see the second one does not merely skip a warning:
	 * it texts a code to that handset and signs whoever holds it into the
	 * other account. Staging found the case on 2026-08-18 - five accounts
	 * held +4475...3514 and a sixth held the same number as 075...3514, which
	 * no E.164 row could ever match - and it is why the sweep below runs
	 * unconditionally and compares digits as well as normalised numbers.
	 *
	 * @param string $e164 Phone number in the E.164 format.
	 * @param string $raw Sanitised submitted value, for the attribute lookup.
	 * @return int User ID, or 0 when unknown or ambiguous.
	 */
	public function get_user_id_by_phone( $e164, $raw ) {
		if ( ! $e164 ) {
			return 0;
		}

		$twilio = hivepress()->hptw_twilio;

		$match_key = $twilio->get_match_key( $e164 );

		// Query the shadow index.
		$candidates = get_users(
			[
				'number'     => 5,
				'fields'     => 'ID',
				'meta_key'   => hp\prefix( 'twilio_phone_e164' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact match on the plugin's own shadow index, bounded to five rows.
				'meta_value' => $e164, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			]
		);

		/*
		 * Source-of-truth sweep, run for every request and not merely when the
		 * index came back empty. Skipping it whenever the index already
		 * answered is exactly how a second holder of one number stays unseen:
		 * an account the hourly backfill has not reached has no shadow row at
		 * all, and one saved in the national format on a site with no Country
		 * Code setting has no E.164 row it could ever have. Both clauses
		 * compare literals against an indexed meta key and the result is
		 * bounded, as the index query is.
		 */
		$clauses = [
			'relation' => 'OR',

			[
				'key'     => hp\prefix( $twilio->get_phone_attribute() ),
				'value'   => $this->get_phone_variants( $e164, $raw ),
				'compare' => 'IN',
			],
		];

		if ( $match_key ) {
			$clauses[] = [
				'key'   => hp\prefix( 'twilio_phone_digits' ),
				'value' => $match_key,
			];
		}

		$candidates = array_merge(
			(array) $candidates,
			(array) get_users(
				[
					'number'     => 5,
					'fields'     => 'ID',

					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded five-row lookup on two of the plugin's own indexed keys.
					'meta_query' => $clauses,
				]
			)
		);

		$candidates = array_unique( array_map( 'absint', $candidates ) );

		/*
		 * Two sets, because they answer two different questions. A survivor is
		 * an account whose stored number IS this number and may therefore be
		 * signed in. A holder is any account holding the same real number in
		 * any writing, which is what the duplicate test counts. The loose
		 * digit comparison only ever adds holders: it is coarse enough that
		 * acting on one could sign a member into a stranger's account, while
		 * the worst a coincidence can do here is refuse a code silently.
		 */
		$survivors = [];
		$holders   = [];

		foreach ( $candidates as $candidate_id ) {
			if ( $twilio->get_user_phone_number( $candidate_id ) === $e164 ) {
				$survivors[] = $candidate_id;
				$holders[]   = $candidate_id;
			} elseif ( $match_key && $twilio->get_user_match_key( $candidate_id ) === $match_key ) {
				$holders[] = $candidate_id;
			}

			// Re-sync every candidate, keeper or not, so a stale shadow row is
			// corrected the first time it is ever queried.
			$this->sync_user( $candidate_id );
		}

		if ( count( $holders ) > 1 ) {

			// Refused silently: the callers answer with their uniform
			// response, so the refusal reveals nothing to the requester.
			/* translators: %s: masked phone number. */
			$this->log( sprintf( __( 'Sign-in code refused for %s (duplicate phone number).', 'twilio-for-hivepress' ), $this->mask_phone( $e164 ) ) );

			return 0;
		}

		if ( ! $survivors ) {

			/*
			 * Logged for the same reason the two rate-limit refusals are: with duplicate and
			 * cooldown pressure both visible and this one silent, an admin could see every kind
			 * of failure except somebody working through a list of numbers, which is the one that
			 * actually means an attack. Nothing changes for the requester - the caller still
			 * answers with the uniform response, so the difference exists only in a log the
			 * admin has opted into.
			 */
			/* translators: %s: masked phone number. */
			$this->log( sprintf( __( 'Sign-in code not sent for %s (no account holds that number).', 'twilio-for-hivepress' ), $this->mask_phone( $e164 ) ) );

			return 0;
		}

		return (int) reset( $survivors );
	}

	/**
	 * Gets the plainly written forms of a number for the attribute lookup.
	 *
	 * Exact strings rather than a LIKE pattern, so the sweep stays an indexed
	 * meta comparison instead of a full scan of every user's phone number. The
	 * country code cannot be assumed here - the setting is often empty, which
	 * is the whole reason a national-format number reaches this code unindexed
	 * - so a national form is generated for every plausible one to three digit
	 * code. Generosity costs nothing: each candidate is re-verified against
	 * the stored number afterwards, and the numbers themselves are the only
	 * thing this list can ever match.
	 *
	 * @param string $e164 Phone number in the E.164 format.
	 * @param string $raw Sanitised submitted value.
	 * @return array
	 */
	protected function get_phone_variants( $e164, $raw ) {
		$digits = substr( (string) $e164, 1 );

		$variants = [ (string) $raw, (string) $e164, $digits, '00' . $digits ];

		for ( $length = 1; $length <= 3; $length++ ) {
			$national = substr( $digits, $length );

			// Nothing shorter than this is a phone number the normaliser would
			// have accepted, so a shorter tail can only add noise.
			if ( strlen( $national ) < 7 ) {
				break;
			}

			$variants[] = $national;
			$variants[] = '0' . $national;
		}

		return array_values( array_unique( array_filter( $variants ) ) );
	}

	/**
	 * Checks and consumes the request-side rate limits.
	 *
	 * Runs before and regardless of account resolution, so the limiter cannot
	 * become an enumeration oracle: unknown and known numbers consume the
	 * identical allowances. The window slot lives in the bucket KEY, so
	 * incrementing never slides the window, counters start at 1 (a stored
	 * falsy value would be indistinguishable from an absent transient), and
	 * the TTL matches the window so stale buckets expire on their own. Under
	 * an external object cache an evicted counter fails open, and concurrent
	 * requests can overshoot a cap slightly (unlocked read-then-write); both
	 * are accepted for limits that only shape traffic.
	 *
	 * @param string $e164 Phone number in the E.164 format.
	 * @return bool True when the request must be refused.
	 */
	public function is_request_limited( $e164 ) {
		$limits = $this->get_limits();

		$cooldown  = absint( hp\get_array_value( $limits, 'resend_cooldown', 60 ) );
		$phone_cap = max( 1, absint( hp\get_array_value( $limits, 'phone_hourly', 5 ) ) );
		$ip_cap    = max( 1, absint( hp\get_array_value( $limits, 'ip_hourly', 10 ) ) );

		$slot = (int) floor( time() / HOUR_IN_SECONDS );

		$cooldown_key = 'hptw_otp_cd_' . md5( $e164 );
		$phone_key    = 'hptw_otp_ph_' . md5( $e164 . '|' . $slot );
		$ip_key       = 'hptw_otp_ip_' . md5( $this->get_ip() . '|' . $slot );

		/*
		 * Refusals are logged, masked, for the same reason duplicate refusals are: staging watched
		 * a run where the only visible trace of rate-limit pressure was its absence, because a
		 * cooldown refusal wrote nothing at all (2026-08-18). Collision pressure was legible and
		 * abuse pressure was not, which is the wrong way round - hourly caps being hit is the
		 * signal that someone is pumping the endpoint. The two branches are logged separately so
		 * a member tapping "Send a new code" too quickly cannot be mistaken for that.
		 */
		if ( $cooldown && get_transient( $cooldown_key ) ) {
			$this->log( sprintf( __( 'Sign-in code refused for %s (still inside the resend cooldown).', 'twilio-for-hivepress' ), $this->mask_phone( $e164 ) ) );

			return true;
		}

		$phone_count = (int) get_transient( $phone_key );
		$ip_count    = (int) get_transient( $ip_key );

		if ( $phone_count >= $phone_cap || $ip_count >= $ip_cap ) {
			$this->log( sprintf( __( 'Sign-in code refused for %s (hourly request limit reached).', 'twilio-for-hivepress' ), $this->mask_phone( $e164 ) ) );

			return true;
		}

		// Passed: consume, for every accepted request, known number or not.
		if ( $cooldown ) {
			set_transient( $cooldown_key, 1, $cooldown );
		}

		set_transient( $phone_key, $phone_count + 1, HOUR_IN_SECONDS );
		set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * Checks and consumes the per-IP verification limit.
	 *
	 * The counter increments on every verify request, allowed or not; the
	 * slot in the key stops over-limit hits from extending the window.
	 *
	 * @return bool True when the request must be refused.
	 */
	public function is_verify_limited() {
		$limits = $this->get_limits();

		$cap = max( 1, absint( hp\get_array_value( $limits, 'verify_window', 20 ) ) );

		$slot = (int) floor( time() / ( 10 * MINUTE_IN_SECONDS ) );
		$name = 'hptw_otp_vf_' . md5( $this->get_ip() . '|' . $slot );

		$count = (int) get_transient( $name );

		set_transient( $name, $count + 1, 10 * MINUTE_IN_SECONDS );

		return $count >= $cap;
	}

	/**
	 * Consumes one unit of the site-wide send budget.
	 *
	 * The budget counts codes actually about to be sent, not requests, so
	 * enumeration attempts against unknown numbers cannot exhaust it and
	 * block real sign-ins. It is the backstop for deployments where many
	 * visitors share one REMOTE_ADDR and the per-IP cap groups them together.
	 *
	 * @param string $e164 Phone number in the E.164 format, for the log line.
	 * @return bool True when a send is allowed; false when the budget is spent.
	 */
	public function consume_send_budget( $e164 ) {
		$limits = $this->get_limits();

		$cap = max( 1, absint( hp\get_array_value( $limits, 'site_hourly', 100 ) ) );

		$slot = (int) floor( time() / HOUR_IN_SECONDS );
		$name = 'hptw_otp_sw_' . md5( 'site|' . $slot );

		$count = (int) get_transient( $name );

		if ( $count >= $cap ) {
			/* translators: %s: masked phone number. */
			$this->log( sprintf( __( 'Sign-in code for %s not sent (site-wide hourly limit reached).', 'twilio-for-hivepress' ), $this->mask_phone( $e164 ) ) );

			return false;
		}

		set_transient( $name, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Issues a sign-in code: stores the record and texts the code.
	 *
	 * The plaintext code exists only inside this method; it is hashed for
	 * storage and handed to the Twilio request, never logged, echoed, stored,
	 * filtered or returned. The send is deferred until the shutdown action
	 * (see Hptw_Twilio::send_message), because a synchronous Twilio call
	 * would take hundreds of milliseconds only for numbers that matched an
	 * account, turning response timing into an account oracle. On FastCGI
	 * hosts the response is flushed before the deferred send, removing that
	 * signal entirely; elsewhere it is only reduced, and the uniform response
	 * copy and the hourly caps remain the primary enumeration defences. The
	 * deferred dispatch is a normal blocking request, so delivery failures
	 * still reach the settings-screen notice and the log; only this method
	 * cannot branch on the outcome, and the masked line below records each
	 * attempt regardless.
	 *
	 * @param int    $user_id User ID.
	 * @param string $e164 Phone number in the E.164 format.
	 */
	public function issue_code( $user_id, $e164 ) {
		$user_id = absint( $user_id );

		// The code space derives from CODE_LENGTH so the length constant, the
		// generated value and the verify form's constraints can never drift.
		$code = str_pad( (string) random_int( 0, ( 10 ** self::CODE_LENGTH ) - 1 ), self::CODE_LENGTH, '0', STR_PAD_LEFT );

		/*
		 * The keyed HMAC makes a database-only leak (SQL export, backup)
		 * useless without the salt from wp-config.php, and compares in
		 * constant time via hash_equals without bcrypt's per-request cost -
		 * a six-digit space is offline-brute-forceable whatever the hash, so
		 * the real defences are the attempt counter and the expiry. The bound
		 * phone stops a number changed mid-flow from redeeming the code.
		 * Overwriting on each request keeps at most one live code per user.
		 */
		update_user_meta(
			$user_id,
			hp\prefix( 'twilio_otp' ),
			[
				'hash'    => hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ),
				'expires' => time() + self::EXPIRY,
				'phone'   => $e164,
			]
		);

		// The attempt counter lives in its own plain-integer row so it can be
		// incremented atomically in SQL; an array element cannot.
		update_user_meta( $user_id, hp\prefix( 'twilio_otp_attempts' ), 0 );

		$text = sprintf(
			/* translators: 1: site name, 2: six-digit code. */
			__( 'Your %1$s sign-in code is %2$s. It expires in 10 minutes.', 'twilio-for-hivepress' ),
			// get_bloginfo() returns HTML-escaped text, wrong for an SMS body.
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$code
		);

		/*
		 * No URL in the message, deliberately: the user already has the page
		 * open, links in security texts train phishing habits and trigger
		 * carrier filtering, and omitting one keeps the message in a single
		 * GSM-7 segment. The text bypasses the hptw_sms_* filters entirely so
		 * no third-party callback can observe or veto a code. Deferred send:
		 * nothing on this path awaits Twilio before the response goes out.
		 */
		hivepress()->hptw_twilio->send_message( $e164, $text, false );

		/* translators: %s: masked phone number. */
		$this->log( sprintf( __( 'Sign-in code sent to %s.', 'twilio-for-hivepress' ), $this->mask_phone( $e164 ) ) );
	}

	/**
	 * Gets the stored code record for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array|null Record with hash, expires and phone keys, or null.
	 */
	public function get_record( $user_id ) {
		$record = get_user_meta( absint( $user_id ), hp\prefix( 'twilio_otp' ), true );

		if ( ! is_array( $record ) || ! isset( $record['hash'], $record['expires'], $record['phone'] ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * Burns one verification attempt, before any code comparison.
	 *
	 * A read-modify-write through the meta API is a race: two parallel verify
	 * requests read the same count and both store count+1, buying an attacker
	 * extra guesses at a six-digit code. The single SQL increment is atomic,
	 * so every concurrent request observes a distinct post-increment value
	 * and at most MAX_ATTEMPTS comparisons can ever run per code. The
	 * read-back goes straight to the table because the user-meta cache may
	 * still hold the pre-increment value on this request.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when this attempt may proceed to the comparison.
	 */
	public function consume_attempt( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The increment must be atomic in SQL; get_user_meta/update_user_meta is a read-modify-write race that would grant extra code guesses.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = meta_value + 1 WHERE user_id = %d AND meta_key = %s",
				$user_id,
				hp\prefix( 'twilio_otp_attempts' )
			)
		);

		wp_cache_delete( $user_id, 'user_meta' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The post-increment value must be read from the table, not a cache that may predate the increment.
		$attempts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				$user_id,
				hp\prefix( 'twilio_otp_attempts' )
			)
		);

		if ( null === $attempts || (int) $attempts > self::MAX_ATTEMPTS ) {

			// A missing counter row means the record predates it or was
			// tampered with; either way the code is unusable and is burnt
			// together with an exhausted one.
			$this->delete_code( $user_id );

			return false;
		}

		return true;
	}

	/**
	 * Checks a submitted code against a stored record.
	 *
	 * @param array  $record Stored code record.
	 * @param string $code Submitted code.
	 * @param string $e164 Phone number the request resolved, in E.164 format.
	 * @return bool
	 */
	public function is_code_valid( $record, $code, $e164 ) {
		if ( ! is_array( $record ) || ! isset( $record['hash'], $record['phone'] ) ) {
			return false;
		}

		return hash_equals( (string) $record['hash'], (string) hash_hmac( 'sha256', (string) $code, wp_salt( 'auth' ) ) ) && $e164 && $record['phone'] === $e164;
	}

	/**
	 * Deletes a user's code record and attempt counter.
	 *
	 * @param int $user_id User ID.
	 */
	public function delete_code( $user_id ) {
		$user_id = absint( $user_id );

		delete_user_meta( $user_id, hp\prefix( 'twilio_otp' ) );
		delete_user_meta( $user_id, hp\prefix( 'twilio_otp_attempts' ) );
	}

	/**
	 * Gets the fixed rate limits.
	 *
	 * Constants behind one filter rather than settings knobs: conservative
	 * defaults keep the settings section to a single consent checkbox, and
	 * the filter is the tuning escape hatch.
	 *
	 * @return array
	 */
	protected function get_limits() {

		/**
		 * Filters the fixed limits applied to SMS sign-in codes.
		 *
		 * @hook hptw_otp_limits
		 * @param {array} $limits Limits: 'resend_cooldown' (seconds between requests per number), 'phone_hourly' (requests per number per hour), 'ip_hourly' (requests per visitor address per hour), 'verify_window' (verification attempts per visitor address per ten minutes), 'site_hourly' (codes sent across the whole site per hour).
		 * @return {array} Limits.
		 */
		return (array) apply_filters(
			'hptw_otp_limits',
			[
				'resend_cooldown' => 60,
				'phone_hourly'    => 5,
				'ip_hourly'       => 10,
				'verify_window'   => 20,
				'site_hourly'     => 100,
			]
		);
	}

	/**
	 * Gets the visitor address for rate bucketing.
	 *
	 * REMOTE_ADDR only, deliberately: forwarded headers are caller-controlled
	 * and would let an attacker mint fresh buckets per request. Deployments
	 * where many visitors share one edge address share an allowance instead,
	 * with the site-wide budget as the backstop.
	 *
	 * @return string
	 */
	protected function get_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Masks a phone number for logging.
	 *
	 * Same masking shape as the recorded Twilio errors: the last two digits
	 * identify a number to its owner without identifying it to anyone else.
	 *
	 * @param string $phone Phone number.
	 * @return string
	 */
	protected function mask_phone( $phone ) {
		return '+***' . substr( (string) $phone, -2 );
	}

	/**
	 * Logs a diagnostic message when logging is enabled.
	 *
	 * A local copy of the Twilio component's gate on the same option: that
	 * method is protected, and the shared public API is deliberately kept to
	 * the send and lookup surface.
	 *
	 * @param string $text Log message, never containing a full phone number.
	 */
	protected function log( $text ) {
		if ( get_option( hp\prefix( 'twilio_enable_logging' ) ) ) {

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Optional diagnostic logging enabled via the plugin settings.
			error_log( 'Twilio for HivePress: ' . $text );
		}
	}
}
