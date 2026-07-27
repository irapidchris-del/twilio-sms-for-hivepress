=== Twilio for HivePress ===
Contributors: chrisb
Tags: hivepress, twilio, sms, notifications
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send SMS notifications for HivePress events via Twilio.

== Description ==

Twilio for HivePress mirrors HivePress email notifications as SMS messages sent via the Twilio API. Whenever HivePress triggers a notification (a listing is approved, a booking is confirmed, a message is received, and so on), the matching SMS is sent to the recipient's phone number.

Features:

* Works with every HivePress notification automatically, including those added by official extensions such as Bookings, Marketplace, Messages, and Requests. Any notification registered by an extension appears in the settings with its own SMS message.
* Fully customisable SMS text per event, with sensible defaults for the core HivePress notifications.
* Supports the same tokens as HivePress emails, including model tokens such as %user.first_name% and fallback values such as %listing_title | your listing%.
* Phone numbers are read from a user attribute of your choice, so users and vendors manage their own number from their account settings.
* Optional administrator phone number for notifications addressed to the site email address (new listings, reports, vendor registrations).
* Sends via a Twilio phone number or a Twilio Messaging Service.
* Numbers are normalised to the E.164 format, with an optional default country code for numbers saved in the national format.
* Optional error logging for troubleshooting deliveries.
* Automatic updates from GitHub: once installed, new releases appear on your Plugins screen for one-click updating, just like a WordPress.org plugin.

Please note that sending SMS messages usually requires the recipient's consent under regulations such as UK PECR and GDPR. Only collect phone numbers with a clear explanation of how they will be used.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/twilio-for-hivepress` directory, or install the release ZIP file via the Plugins screen. If you install from a manually downloaded archive, make sure the extracted plugin folder is named `twilio-for-hivepress`.
2. Activate the plugin through the Plugins screen. HivePress must be installed and active.
3. Create a user attribute for phone numbers via Users > Attributes. Set the field name to `phone` (or your own name), choose the Phone field type, and make it editable so users can fill it in via their account settings.
4. Enter your Twilio credentials via HivePress > Settings > Integrations. You need the account SID, the auth token, and either a Twilio phone number or a messaging service SID.
5. Review the delivery options and per-event messages via HivePress > Settings > SMS.

== Frequently Asked Questions ==

= Does Twilio offer test and live keys? =

Twilio provides one set of live credentials (the account SID and auth token) plus a separate set of test credentials available on the API keys and tokens page of the Twilio console. Requests made with the test credentials return normal API responses but no messages are sent and nothing is charged. When using the test credentials, set the phone number setting to the magic number +15005550006, as it is the only valid sender for test requests. If you have entered a messaging service SID, clear it temporarily while testing, because it takes precedence over the phone number and test credentials cannot access the live account's messaging services.

= Why is a notification not sent as an SMS? =

An SMS is sent only when the Twilio credentials are set, the event has a non-empty message, and the recipient has a valid phone number. Enable the logging option via HivePress > Settings > SMS to record delivery errors in the PHP error log. Log entries may include partially masked recipient details, so make sure the log file is not publicly accessible. Also note that Twilio trial accounts can only send messages to verified phone numbers.

= How do I disable the SMS for a specific event? =

Clear the message for that event via HivePress > Settings > SMS and save the settings. An empty message disables the SMS for that event.

= Are SMS messages still sent if I disable the email notification? =

Yes. If you clear the email content via HivePress > Emails, the email is not sent, but the event still fires, so the SMS is sent as long as its message is set. This lets you replace an email notification with an SMS entirely.

= Which phone number format should users enter? =

Ideally the international E.164 format (e.g. +447700900123). If you set the default country code option, numbers saved in the national format (e.g. 07700 900123) are converted automatically.

= How does the plugin update itself? =

The plugin checks its GitHub repository for new releases and shows available updates on your Plugins screen, so you can update with one click just like a WordPress.org plugin. Updates are downloaded from the official release file, so your plugin folder never changes. The first version you install must be added manually; every version after that can be updated in place.

== Changelog ==

= 1.1.0 =
* Added automatic updates from GitHub releases (one-click updates via the Plugins screen).

= 1.0.0 =
* Initial release.
