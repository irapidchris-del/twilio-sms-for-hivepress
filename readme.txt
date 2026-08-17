=== Twilio for HivePress ===
Contributors: chrisb
Tags: hivepress, twilio, sms, notifications
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
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
* Optionally asks for the phone number during registration, so the registration SMS can reach new users straight away.
* Authenticates with a Twilio API key (recommended) or the account auth token.
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
4. Enter your Twilio credentials via HivePress > Settings > Integrations. You need the account SID, an API key (or the auth token), and either a Twilio phone number or a messaging service SID.
5. Review the delivery options and per-event messages via HivePress > Settings > SMS.

== Frequently Asked Questions ==

= Does Twilio offer test and live keys? =

Twilio provides one set of live credentials (the account SID and auth token) plus a separate set of test credentials available on the API keys and tokens page of the Twilio console. Requests made with the test credentials return normal API responses but no messages are sent and nothing is charged. When using the test credentials, set the phone number setting to the magic number +15005550006, as it is the only valid sender for test requests. If you have entered a messaging service SID, clear it temporarily while testing, because it takes precedence over the phone number and test credentials cannot access the live account's messaging services.

= Should I use the auth token or an API key? =

An API key. Twilio recommends API keys for production use because a leaked key can be revoked on its own, while a leaked auth token compromises the whole account. Create one on the API keys and tokens page of the Twilio console, then enter the key SID and secret in the plugin settings; when both are set they are used instead of the auth token.

= Why does nothing send from my Twilio trial account? =

New Twilio trial accounts are heavily restricted: they can only text phone numbers you have verified in the Twilio console, and many trials only allow predefined message templates, so the free-text messages this plugin sends are rejected with error 572006. With the logging option enabled these rejections appear in the PHP error log with Twilio's own explanation. For real delivery you generally need an upgraded account, and senders in some countries (including the UK) also need an approved compliance profile in Twilio's Trust Hub before messages go through (error 20003 until then).

= Why do new users not receive the registration SMS? =

The standard HivePress registration form does not ask for a phone number, so at the moment the registration SMS is sent, no number is known yet. Enable "Ask for the phone number during registration" via HivePress > Settings > SMS to add an optional phone field to the registration form; users who fill it in receive the registration SMS and any later notifications straight away.

= Why is a notification not sent as an SMS? =

An SMS is sent only when the Twilio credentials are set, the event has a non-empty message, and the recipient has a valid phone number. Enable the logging option via HivePress > Settings > SMS to record delivery errors in the PHP error log. Log entries may include partially masked recipient details, so make sure the log file is not publicly accessible. Also note that Twilio trial accounts can only send messages to verified phone numbers.

= How do I disable the SMS for a specific event? =

Clear the message for that event via HivePress > Settings > SMS and save the settings. An empty message disables the SMS for that event.

= Are SMS messages still sent if I disable the email notification? =

Yes. If you clear the email content via HivePress > Emails, the email is not sent, but the event still fires, so the SMS is sent as long as its message is set. This lets you replace an email notification with an SMS entirely.

= What happens to my settings if I delete the plugin? =

Nothing, by default. Deactivating loses nothing, and deleting the plugin keeps your Twilio credentials and SMS messages too, so a reinstall brings everything back. WordPress shows a generic warning that deleting a plugin also deletes its data; it does not apply here. If you do want everything removed, tick "Delete all data when this plugin is deleted" under HivePress > Settings > SMS > Removing the Plugin before deleting.

= Can an SMS include the user's password? =

No. HivePress offers a password token on its registration notification, and on the standard registration flow that token holds the real password, so this plugin never sends it: the token is left out of the available-token lists and is removed from a message before sending, even if you type it in yourself. Text messages are not encrypted and would also be stored in your Twilio message logs. If someone needs to set a password, send them the password reset notification instead.

= Which phone number format should users enter? =

Ideally the international E.164 format (e.g. +447700900123). If you set the default country code option, numbers saved in the national format (e.g. 07700 900123) are converted automatically.

= How does the plugin update itself? =

The plugin checks its GitHub repository for new releases and shows available updates on your Plugins screen, so you can update with one click just like a WordPress.org plugin. Updates are downloaded from the official release file, so your plugin folder never changes. The first version you install must be added manually; every version after that can be updated in place.

== Changelog ==

= 1.4.0 =
* Added an option to ask for the phone number during registration, so the registration SMS can reach new users on a standard install.
* Added Twilio API key support as the recommended way to authenticate; the auth token still works.
* The auth token and API key secret are now masked on the settings screen.
* Event descriptions now say who receives each SMS instead of describing the mirrored email notification.
* Documented the Twilio trial account restrictions in the FAQ.

= 1.3.0 =
* Deleting the plugin now keeps your settings and credentials by default; a new "Delete All Data" option under the SMS settings controls the cleanup.
* Fixed message tokens such as %fail_reason% being silently corrupted when saving the settings.
* Fixed backslashes typed into SMS messages being destroyed on save.
* The sending phone number now works when entered without the leading plus sign.
* Fixed the plugin loading nothing at all when its folder is renamed, without triggering a PHP warning on sites without premium HivePress extensions.
* Checking for updates before any release has been published now says so, instead of reporting a connection error.
* Outbound requests to Twilio and GitHub no longer include the site address in the user agent.
* Added a Donate link to the plugin entry on the Plugins screen.
* The SMS settings tab now says when sending is disabled because credentials are missing.
* Shortened the Twilio request timeout so a slow Twilio outage cannot stall the site.

= 1.2.0 =
* Fixed the plugin silently doing nothing when installed into a folder not named `twilio-for-hivepress`, which is what downloading the repository as a ZIP produces.
* Added an admin notice when HivePress is not active, instead of failing silently on WordPress below 6.5.
* The password token is no longer offered or sent. HivePress passes a real plaintext password into it on registration, which should never travel by SMS.
* Prefixed the internal component file and class so another plugin adding a Twilio component cannot silently replace this one.
* Clearing the phone attribute setting now falls back to the default name rather than disabling all user messages.

= 1.1.1 =
* Added a Settings quick link to the plugin entry on the Plugins screen.
* Added proper labels and available-token hints for notification events added by the Marketplace, Requests, Claim Listings, and Import extensions.
* Added descriptions to the Delivery and Twilio settings sections.

= 1.1.0 =
* Added automatic updates from GitHub releases (one-click updates via the Plugins screen).

= 1.0.0 =
* Initial release.
