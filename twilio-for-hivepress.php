<?php
/**
 * Plugin Name: Twilio for HivePress
 * Plugin URI: https://github.com/irapidchris-del/twilio-sms-for-hivepress
 * Description: Send SMS notifications for HivePress events via Twilio.
 * Version: 1.4.1
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Text Domain: twilio-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * Update URI: https://github.com/irapidchris-del/twilio-sms-for-hivepress
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package HivePress\Twilio
 */

namespace TwilioForHivePress;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

const VERSION = '1.4.1';

/**
 * Registers the extension with HivePress.
 *
 * HivePress collects extension paths through this filter, then autoloads
 * classes and merges configs from the `includes` directory of every registered
 * path. The registration form is picked at runtime:
 *
 * The bare directory string is used on a normal install, where the folder and
 * the main file share a name. Given a string, core requires exactly
 * `{dirname}/{dirname}.php`, so a renamed install folder would silently
 * disable the whole plugin - not hypothetical here, because the repository is
 * named `twilio-sms-for-hivepress` while the plugin slug is
 * `twilio-for-hivepress`, so a GitHub "Download ZIP" unpacks to a folder the
 * string form can never load from.
 *
 * The array form covers that renamed-folder case, but it cannot be used
 * unconditionally: core's updater probe concatenates every extensions entry
 * (`file_exists( $dir . $path ... )`, class-core.php:249-250), so an array
 * entry makes core log "Array to string conversion" on every request unless
 * an earlier string entry bundles the hivepress-updates package. In the
 * fallback branch the probe is therefore run here first, over string entries
 * only, so core's own loop never reaches the array entry. The filter runs at
 * priority 100 so extensions that bundle hivepress-updates are already listed
 * by the time that probe runs, and it must be added at file scope; core reads
 * it before any `plugins_loaded` callback runs.
 *
 * @param array<string, mixed> $extensions Registered extensions.
 * @return array<string, mixed>
 */
function register_extension( $extensions ) {
	if ( file_exists( __DIR__ . '/' . basename( __DIR__ ) . '.php' ) ) {
		$extensions[] = __DIR__;

		return $extensions;
	}

	if ( ! isset( $extensions['updates'] ) ) {
		$path = '/vendor/hivepress/hivepress-updates';

		foreach ( $extensions as $dir ) {
			if ( is_string( $dir ) && file_exists( $dir . $path . '/hivepress-updates.php' ) ) {
				$extensions['updates'] = $dir . $path;

				break;
			}
		}
	}

	if ( ! isset( $extensions['updates'] ) ) {
		/*
		 * No string entry bundles the updates package, so core's own probe
		 * would run and concatenate the array entry below, logging a PHP
		 * warning. Setting the key to a path with no main file satisfies the
		 * probe's isset() guard, and core silently drops the entry itself
		 * because the file_exists() check in its details loop fails.
		 */
		$extensions['updates'] = __DIR__ . '/vendor/hivepress-updates-absent';
	}

	$extensions['twilio_for_hivepress'] = [
		'name'    => 'Twilio for HivePress',
		'version' => VERSION,
		'path'    => __DIR__,
		'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
	];

	return $extensions;
}

add_filter( 'hivepress/v1/extensions', __NAMESPACE__ . '\\register_extension', 100 );

/**
 * Says so when HivePress is missing.
 *
 * `Requires Plugins` is only enforced from WordPress 6.5; below that the
 * plugin would activate and then do nothing at all, with no settings tab and
 * no SMS, and no indication why.
 *
 * @return void
 */
function show_dependency_notice() {
	if ( function_exists( 'hivepress' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__( 'Twilio for HivePress requires the HivePress plugin to be installed and active. Until then, its SMS settings and messages are unavailable.', 'twilio-for-hivepress' ) . '</p></div>';
}

add_action( 'admin_notices', __NAMESPACE__ . '\\show_dependency_notice' );

/*
 * Translations load through WordPress's just-in-time textdomain loading from
 * wp-content/languages/plugins/ (the location Loco Translate calls "System",
 * which survives plugin updates); there is deliberately no
 * load_plugin_textdomain() call, matching HivePress core and every official
 * extension. Because this plugin registers as a HivePress extension, core
 * additionally loads this path's own languages directory at boot
 * (Core::load_textdomains), so a bundled .mo would also work. The shipped
 * .pot is a translation template, not a translation.
 */

/**
 * Gets the support URL.
 *
 * @return string
 */
function get_support_url() {
	return 'https://ko-fi.com/chrisbathivepresscommunity';
}

/**
 * Adds a quiet "Donate" link to this plugin's row meta.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen and joins the
 * items with a pipe, so without the basename test the link would appear on
 * every row on the site.
 *
 * The markup is copied verbatim from the house spec in `releasing.md` rather
 * than composed here: every plugin's row has to look identical. The label is
 * exactly "Donate", which is also the wording WordPress uses in the details
 * popup, and the icon is a Dashicon rather than Font Awesome because Dashicons
 * is the admin's own font and is always loaded there.
 *
 * @param array<string> $meta Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( get_support_url() ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'twilio-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', __NAMESPACE__ . '\\add_row_meta', 10, 2 );

/**
 * Adds show/hide toggles to the masked credential fields.
 *
 * The auth token and API key secret render as password-type inputs, so a
 * toggle is needed to read back what is stored. HivePress ships its own eye
 * button, but its handler lives in the front-end script bundle, which is
 * never loaded in wp-admin - so a dead button would render. This one is
 * self-contained: Dashicons are always available in the admin, and the
 * button flips the input type in place.
 *
 * @return void
 */
function print_secret_toggles() {

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check that changes nothing; the capability test below is the gate.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'hp_settings' !== $page || 'integrations' !== $tab || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<script>
	( function() {
		var labels = {
			show: <?php echo wp_json_encode( __( 'Show', 'twilio-for-hivepress' ) ); ?>,
			hide: <?php echo wp_json_encode( __( 'Hide', 'twilio-for-hivepress' ) ); ?>
		};

		[ 'hp_twilio_auth_token', 'hp_twilio_api_key_secret' ].forEach( function( name ) {
			var input = document.querySelector( 'input[name="' + name + '"]' );

			if ( ! input ) {
				return;
			}

			var button = document.createElement( 'button' );

			// Explicitly not a submit button: it sits inside the settings form.
			button.type = 'button';
			button.className = 'button';
			button.style.marginLeft = '0.5rem';
			button.style.verticalAlign = 'middle';
			button.setAttribute( 'aria-label', labels.show );
			button.title = labels.show;

			var icon = document.createElement( 'span' );

			icon.className = 'dashicons dashicons-visibility';
			icon.style.verticalAlign = 'text-bottom';

			button.appendChild( icon );

			button.addEventListener( 'click', function() {
				var hidden = 'password' === input.type;

				input.type = hidden ? 'text' : 'password';
				icon.className = 'dashicons ' + ( hidden ? 'dashicons-hidden' : 'dashicons-visibility' );
				button.title = hidden ? labels.hide : labels.show;
				button.setAttribute( 'aria-label', hidden ? labels.hide : labels.show );
			} );

			input.insertAdjacentElement( 'afterend', button );
		} );
	} )();
	</script>
	<?php
}

add_action( 'admin_footer', __NAMESPACE__ . '\\print_secret_toggles' );

/*
 * -------------------------------------------------------------------------
 * Updates
 *
 * The plugin is distributed via GitHub releases rather than wp.org, so
 * update checks go through the native `update_plugins_{$hostname}` API
 * introduced in WordPress 5.8, keyed off the Update URI header above.
 * The update package is the release asset named `*.zip`, which must
 * contain a single `twilio-for-hivepress` directory.
 * -------------------------------------------------------------------------
 */

const UPDATE_REPO = 'irapidchris-del/twilio-sms-for-hivepress';

const UPDATE_SLUG = 'twilio-for-hivepress';

const UPDATE_CACHE_KEY = 'hptw_github_release';

/**
 * Gets the installed plugin version.
 *
 * @return string
 */
function get_version() {
	static $version = null;

	if ( null === $version ) {
		$data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );

		$version = $data['Version'];
	}

	return $version;
}

/**
 * Gets the latest GitHub release details, cached for 6 hours.
 *
 * @param bool   $force Bypass the cache.
 * @param string $state Set to 'ok', 'none' (no releases published) or 'error'.
 * @return array<string, string>|null
 */
function get_latest_release( $force = false, &$state = '' ) {
	$release = $force ? false : get_site_transient( UPDATE_CACHE_KEY );

	if ( false === $release || ( ! is_array( $release ) && ! in_array( $release, [ 'none', 'error' ], true ) ) ) {
		$release = fetch_latest_release();

		// Non-success states are cached briefly so the API is not queried
		// repeatedly - unauthenticated GitHub allows 60 requests per hour.
		set_site_transient( UPDATE_CACHE_KEY, $release, is_array( $release ) ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	}

	if ( is_array( $release ) && $release ) {
		$state = 'ok';

		return $release;
	}

	$state = 'none' === $release ? 'none' : 'error';

	return null;
}

/**
 * Fetches the latest release details from the GitHub API.
 *
 * Draft and pre-release entries are excluded by the endpoint itself, so
 * publishing a pre-release never triggers an update notice.
 *
 * A 404 is an answer, not a failure to get one: it means no release has been
 * published yet (or the repository is private), which is reported differently
 * from GitHub being unreachable.
 *
 * @return array<string, string>|string Release details, 'none' or 'error'.
 */
function fetch_latest_release() {
	$response = wp_remote_get(
		'https://api.github.com/repos/' . UPDATE_REPO . '/releases/latest',
		[
			'timeout'    => 10,
			'headers'    => [ 'Accept' => 'application/vnd.github+json' ],

			// Without an explicit user agent WordPress sends its version and
			// the site URL with every request; GitHub only needs something
			// identifying.
			'user-agent' => UPDATE_SLUG . '/' . VERSION,
		]
	);

	if ( is_wp_error( $response ) ) {
		return 'error';
	}

	$code = wp_remote_retrieve_response_code( $response );

	if ( 404 === $code ) {
		return 'none';
	}

	if ( 200 !== $code ) {
		return 'error';
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) ) {
		return 'error';
	}

	// The version is read from the release tag, with or without a "v" prefix.
	$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

	if ( ! $version ) {
		return 'error';
	}

	// The update package is the first release asset named `*.zip`.
	$package = '';

	foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : [] ) as $asset ) {
		$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

		if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
			$package = (string) $asset['browser_download_url'];

			break;
		}
	}

	if ( ! $package ) {
		return 'error';
	}

	return [
		'version'   => $version,
		'package'   => $package,
		'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . UPDATE_REPO ),
		'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
		'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
	];
}

/**
 * Provides the update details to the WordPress update system.
 *
 * WordPress matches the plugin to this filter via the Update URI header
 * hostname and compares the versions itself, filing the result under
 * either the available updates or the up-to-date list.
 *
 * @param array<string, mixed>|false $update Update data.
 * @param array<string, string>      $plugin_data Plugin headers.
 * @param string                     $plugin_file Plugin basename.
 * @return array<string, mixed>|false
 */
function check_for_update( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}

	$release = get_latest_release();

	if ( ! $release ) {
		return $update;
	}

	return [
		'id'      => 'https://github.com/' . UPDATE_REPO,
		'slug'    => UPDATE_SLUG,
		'plugin'  => $plugin_file,
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}

add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\\check_for_update', 10, 3 );

/**
 * Provides the plugin details for the update information popup.
 *
 * Without this the "View version x.x.x details" link on the Plugins
 * screen would open an empty modal, since the plugin is not on wp.org.
 *
 * @param object|array|false $result Result object.
 * @param string             $action API action.
 * @param object             $args API arguments.
 * @return object|array|false
 */
function get_plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! is_object( $args ) || UPDATE_SLUG !== ( isset( $args->slug ) ? $args->slug : '' ) ) {
		return $result;
	}

	$release = get_latest_release();

	if ( ! $release ) {
		return $result;
	}

	$plugin_data = get_file_data(
		__FILE__,
		[
			'Name'        => 'Plugin Name',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		]
	);

	return (object) [
		'name'          => $plugin_data['Name'],
		'slug'          => UPDATE_SLUG,
		'version'       => $release['version'],
		'author'        => '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>',
		'homepage'      => 'https://github.com/' . UPDATE_REPO,
		'donate_link'   => get_support_url(),
		'requires'      => $plugin_data['RequiresWP'],
		'requires_php'  => $plugin_data['RequiresPHP'],
		'last_updated'  => $release['published'],
		'download_link' => $release['package'],
		'sections'      => [
			'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
			'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'twilio-for-hivepress' ) . '</p>',
		],
	];
}

add_filter( 'plugins_api', __NAMESPACE__ . '\\get_plugin_information', 10, 3 );

/**
 * Adds the settings link to the plugin row.
 *
 * The link points at a HivePress admin page, so it is only useful once
 * HivePress has actually loaded the extension.
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function add_settings_link( $links ) {
	if ( ! function_exists( 'hivepress' ) ) {
		return $links;
	}

	return array_merge(
		[
			'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=sms' ) ) . '">' . esc_html__( 'Settings', 'twilio-for-hivepress' ) . '</a>',
		],
		$links
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\add_settings_link' );

/**
 * Adds the manual update check link to the plugin row.
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function add_update_check_link( $links ) {
	if ( current_user_can( 'update_plugins' ) ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?hptw_check_updates=1' ), 'hptw_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'twilio-for-hivepress' ) . '</a>';
	}

	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\add_update_check_link' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\add_update_check_link' );

/**
 * Handles the manual update check.
 *
 * Refreshes the cached release, re-runs the update check and redirects
 * back to the Plugins screen with the result.
 *
 * @return void
 */
function handle_update_check() {
	if ( ! isset( $_GET['hptw_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	check_admin_referer( 'hptw_check_updates' );

	$state   = '';
	$release = get_latest_release( true, $state );

	wp_clean_plugins_cache();
	wp_update_plugins();

	$status = 'none';

	if ( ! $release ) {

		// A 404 means no release has been published yet - an answer, not a
		// connectivity failure, so it gets its own message.
		$status = 'none' === $state ? 'unreleased' : 'error';
	} elseif ( version_compare( $release['version'], get_version(), '>' ) ) {
		$status = 'available';
	}

	wp_safe_redirect( add_query_arg( 'hptw_checked', $status, self_admin_url( 'plugins.php' ) ) );

	exit;
}

add_action( 'admin_init', __NAMESPACE__ . '\\handle_update_check' );

/**
 * Shows the manual update check result.
 *
 * @return void
 */
function show_update_check_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag set by the nonce-verified redirect in handle_update_check().
	if ( ! isset( $_GET['hptw_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag set by the nonce-verified redirect in handle_update_check().
	$status = sanitize_key( wp_unslash( $_GET['hptw_checked'] ) );

	if ( 'available' === $status ) {
		$release = get_latest_release();

		/* translators: %s: new version number. */
		$message = sprintf( __( 'A new version of Twilio for HivePress (%s) is available.', 'twilio-for-hivepress' ), $release ? $release['version'] : '' );
		$class   = 'notice-success';
	} elseif ( 'none' === $status ) {
		$message = __( 'Twilio for HivePress is up to date.', 'twilio-for-hivepress' );
		$class   = 'notice-success';
	} elseif ( 'unreleased' === $status ) {
		$message = __( 'No releases have been published on GitHub yet, so there is nothing to update to.', 'twilio-for-hivepress' );
		$class   = 'notice-info';
	} elseif ( 'error' === $status ) {
		$message = __( 'Could not reach GitHub to check for updates. Please try again later.', 'twilio-for-hivepress' );
		$class   = 'notice-error';
	} else {
		return;
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

add_action( 'admin_notices', __NAMESPACE__ . '\\show_update_check_notice' );
add_action( 'network_admin_notices', __NAMESPACE__ . '\\show_update_check_notice' );

/**
 * Keeps updates installing into the current plugin directory.
 *
 * The extracted release folder is renamed to match the directory the
 * plugin is installed in, so an update can never end up in a differently
 * named folder even if the release zip is packaged unexpectedly.
 *
 * @param string               $source Extracted update source.
 * @param string               $remote_source Remote source directory.
 * @param object               $upgrader Upgrader instance.
 * @param array<string, mixed> $hook_extra Extra hook arguments.
 * @return string|\WP_Error
 */
function fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = [] ) {
	global $wp_filesystem;

	if ( plugin_basename( __FILE__ ) !== ( isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '' ) || ! $wp_filesystem ) {
		return $source;
	}

	$directory = dirname( plugin_basename( __FILE__ ) );

	if ( '.' === $directory ) {
		return $source;
	}

	$target = trailingslashit( $remote_source ) . $directory . '/';

	if ( trailingslashit( $source ) === $target ) {
		return $source;
	}

	if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
		return new \WP_Error( 'hptw_rename_failed', __( 'Could not rename the update directory.', 'twilio-for-hivepress' ) );
	}

	return $target;
}

add_filter( 'upgrader_source_selection', __NAMESPACE__ . '\\fix_update_directory', 10, 4 );
