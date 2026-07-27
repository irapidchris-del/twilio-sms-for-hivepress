<?php
/**
 * Uninstalls the plugin.
 *
 * @package HivePress\Twilio
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Delete plugin options, including the per-event SMS texts and the Twilio credentials.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup of the plugin options on uninstall.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'hp\_twilio\_%'" );

wp_cache_flush();
