<?php
/**
 * Plugin Name: Twilio for HivePress
 * Description: Send SMS notifications for HivePress events via Twilio.
 * Version: 1.1.0
 * Author: ChrisB
 * Author URI: https://community.hivepress.io/u/chrisb
 * Text Domain: twilio-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * Update URI: https://github.com/irapidchris-del/twilio-sms-for-hivepress
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package HivePress\Twilio
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Register extension directory.
add_filter(
	'hivepress/v1/extensions',
	function( $extensions ) {
		$extensions[] = __DIR__;

		return $extensions;
	}
);

// Load translations.
add_action(
	'init',
	function() {
		load_plugin_textdomain( 'twilio-for-hivepress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// Enable automatic updates from GitHub releases.
add_action(
	'init',
	function() {
		$loader = __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

		if ( ! is_readable( $loader ) ) {
			return;
		}

		require_once $loader;

		$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';

		if ( ! class_exists( $factory ) ) {
			return;
		}

		// Point the checker at the GitHub repository.
		$checker = $factory::buildUpdateChecker(
			'https://github.com/irapidchris-del/twilio-sms-for-hivepress/',
			__FILE__,
			'twilio-for-hivepress'
		);

		// Serve updates from the attached release .zip asset (named twilio-for-hivepress.zip),
		// never GitHub's auto-generated source archive, so the installed plugin folder never changes.
		$api = $checker->getVcsApi();

		if ( method_exists( $api, 'enableReleaseAssets' ) ) {

			// 2 = Api::REQUIRE_RELEASE_ASSETS: only offer an update when the .zip asset is present.
			$api->enableReleaseAssets( '/twilio-for-hivepress\.zip$/i', 2 );
		}
	},
	20
);
