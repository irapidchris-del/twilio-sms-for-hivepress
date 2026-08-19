<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's data by default.** Someone who deletes a plugin by
 * accident, or removes it to install a clean copy, gets their Twilio credentials and per-event SMS
 * messages back when they reinstall. Destruction is opt-in, through the "Delete All Data" checkbox
 * in the Removing the Plugin section of the SMS settings tab, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in wp-admin/plugins.php:398-410 is
 * hard-coded with no do_action or apply_filters inside it, so a checkbox cannot be added to that
 * screen; the setting has to live on our own page. Worse, WordPress prints "(will also delete its
 * data)" on that screen whenever an uninstall.php exists at all (wp-admin/plugins.php:376-380),
 * whatever the file actually does, so the setting's own description tells the owner that the core
 * warning does not apply to them unless they ticked the box.
 *
 * The updater's cached release lookup goes either way: it is regenerable runtime junk rather than
 * anything the owner made.
 *
 * @package HivePress\Twilio
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the owner's choice first, before anything is touched.
$hptw_delete_all = (bool) get_option( 'hp_twilio_delete_data' );

/*
 * ---------------------------------------------------------------------------------------------
 * Always cleaned, whichever way the setting is set.
 * ---------------------------------------------------------------------------------------------
 */

// The updater's cached release lookup. A site transient lives under its own prefix, so neither the
// option sweep below nor a plain delete_option() would ever reach it. delete_site_transient() also
// works under a persistent object cache, where transients are not in wp_options at all.
delete_site_transient( 'hptw_github_release' );

// The last-delivery-failure notice is regenerable runtime state, not owner data.
delete_option( 'hp_twilio_last_error' );

// Every transient the plugin sets: the per-member SMS cap counters (hptw_sms_cap_{user_id}) are
// regenerable rate-limit state, not owner data, so they go whichever way the setting points. A
// transient is stored as "_transient_{name}" plus a separate "_transient_timeout_{name}" row, so
// the prefix sweep used for options further down cannot match them.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup while the plugin is deleted.
$hptw_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'hptw_' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'hptw_' ) . '%'
	)
);

foreach ( (array) $hptw_transients as $hptw_transient_name ) {
	delete_option( $hptw_transient_name );
}

// The SMS sign-in state is regenerable runtime data, not owner data, so it goes whichever way the
// setting points: pending sign-in codes and their attempt counters are security state that must
// not outlive the plugin, the two shadow indexes (E.164 numbers, and the trailing digits of the
// ones that cannot be normalised) are derived from the phone attribute and rebuild themselves on
// reinstall, and the backfill cursor merely restarts that rebuild. The phone numbers
// themselves live in the attribute meta (hp_{attribute}), which belongs to HivePress user
// attributes, not to this plugin, and are not touched. The rate buckets and cooldowns are
// transients under the hptw_ prefix, already swept above.
delete_metadata( 'user', 0, 'hp_twilio_otp', '', true );
delete_metadata( 'user', 0, 'hp_twilio_otp_attempts', '', true );
delete_metadata( 'user', 0, 'hp_twilio_phone_e164', '', true );
delete_metadata( 'user', 0, 'hp_twilio_phone_digits', '', true );
delete_option( 'hp_twilio_otp_backfill' );

/*
 * ---------------------------------------------------------------------------------------------
 * Everything below happens only when the owner asked for it.
 * ---------------------------------------------------------------------------------------------
 */

if ( $hptw_delete_all ) {
	/*
	 * Delete the options: the Twilio credentials, the delivery settings, the per-event SMS
	 * messages, the Member Opt-in toggle and the SMS Sign-In toggle. The names are matched on the
	 * plugin's prefix because
	 * most are dynamic - one text option per notification event, including events registered by
	 * other extensions.
	 *
	 * The "delete all data" option itself is excluded here and removed at the very end. If this
	 * run fails part-way through, the flag is still set, so a second attempt finishes the job.
	 * Sweeping it away first would silently flip the site back to "retain" with data left behind.
	 *
	 * The user phone numbers themselves are NOT deleted: they live in the phone attribute's own
	 * meta (hp_{attribute}), which belongs to HivePress user attributes, not to this plugin.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup while the plugin is deleted.
	$hptw_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
			$wpdb->esc_like( 'hp_twilio_' ) . '%',
			'hp_twilio_delete_data'
		)
	);

	foreach ( (array) $hptw_option_names as $hptw_option_name ) {
		delete_option( $hptw_option_name );
	}

	// Last, and only once everything above has succeeded.
	delete_option( 'hp_twilio_delete_data' );
}
