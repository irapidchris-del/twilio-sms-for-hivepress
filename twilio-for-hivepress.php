<?php
/**
 * Plugin Name: Twilio for HivePress
 * Description: Send SMS notifications for HivePress events via Twilio.
 * Version: 1.0.0
 * Author: ChrisB
 * Author URI: https://community.hivepress.io/u/chrisb
 * Text Domain: twilio-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
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
