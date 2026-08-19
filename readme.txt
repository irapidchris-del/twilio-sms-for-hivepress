=== Twilio for HivePress ===
Contributors: chrisb
Tags: hivepress, twilio, sms, notifications
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.1
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
* Passwordless sign-in: let users sign in with a six-digit code sent by SMS, switched off by default.
* Authenticates with a Twilio API key, which can be revoked on its own if it is ever exposed; the account auth token is never stored.
* Optional administrator phone number for notifications addressed to the site email address (new listings, reports, vendor registrations).
* Sends via a Twilio phone number or a Twilio Messaging Service.
* Numbers are normalised to the E.164 format, with an optional default country code for numbers saved in the national format.
* Optional error logging for troubleshooting deliveries.
* Optional integration with the Notifications for HivePress extension (version 1.1.0 or later): SMS becomes a choice on each member's own Notification Settings page, strictly opt-in, with quiet hours respected.
* Automatic updates from GitHub: once installed, new releases appear on your Plugins screen for one-click updating, just like a WordPress.org plugin.

**Twilio account requirements.** Live sending needs a properly set-up Twilio account; a free trial cannot deliver this plugin's messages. Expect all four of these steps in the Twilio console before the first SMS arrives:

1. **Fund the account** (upgrade off the free trial; the minimum top-up was 20 GBP at the time of writing). Trials only text verified numbers and commonly reject free-text bodies altogether (error 572006).
2. **Create an API key** on the API keys and tokens page, and enter its SID and secret in the plugin settings.
3. **Buy an SMS-capable phone number**. The monthly fee (roughly 2 GBP for a UK number at the time of writing) is deducted from the account balance, so the initial top-up covers it. A trial number does not always survive the upgrade, and in some countries (including the UK) Twilio requires regulatory documentation before a number can be purchased.
4. **Pass the Trust Hub compliance check** (KYC). Until the profile is approved, every send is rejected with error 20003.

The plugin shows the last delivery error on its settings screen, so each of these states is visible rather than silent.

**SMS as a notification channel.** With the Notifications for HivePress extension (version 1.1.0 or later) active, a Member Opt-in toggle appears via HivePress > Settings > SMS > Member Preferences. The toggle ships off. With it on, SMS joins On-site, Email and Push on each member's Notification Settings page: for events enabled under the notification settings, a text only goes to a member who has ticked SMS there, while events you have left disabled there keep today's behaviour and go to anyone with a saved message. No role default ever grants SMS; every member starts unticked and opts in themselves. Quiet hours are respected on member texts, and a text that falls inside them is dropped, not queued for later. Announcements are never texted. Texts to the administrator phone are unaffected by the toggle, member preferences and quiet hours. Notifications with no email behind them, such as a completed booking or a new favourite, are texted with the wording from the notification settings and only alongside the on-site notification.

**For developers.** The `hptw_sms_send` filter now receives two extra arguments, the recipient's user object (or null) and the recipient email address; callbacks registered with up to four accepted arguments keep working unchanged. Notifications without an email are texted through the new `hptw_channel_sms_text` filter, which receives the text and the notification object and can veto the send by returning an empty value. The public `send_message()` method is for trusted callers only: destinations must be resolved server-side, never taken from request input, and it applies no rate limiting of its own, so it must never be exposed to visitors.

Please note that sending SMS messages usually requires the recipient's consent under regulations such as UK PECR and GDPR. Only collect phone numbers with a clear explanation of how they will be used.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/twilio-for-hivepress` directory, or install the release ZIP file via the Plugins screen. If you install from a manually downloaded archive, make sure the extracted plugin folder is named `twilio-for-hivepress`.
2. Activate the plugin through the Plugins screen. HivePress must be installed and active.
3. Create a user attribute for phone numbers via Users > Attributes. Set the field name to `phone` (or your own name), choose the Phone field type, and make it editable so users can fill it in via their account settings.
4. Enter your Twilio credentials via HivePress > Settings > Integrations. You need the account SID, an API key, and either a Twilio phone number or a messaging service SID.
5. Review the delivery options and per-event messages via HivePress > Settings > SMS.

== Frequently Asked Questions ==

= Why does the plugin not accept my auth token? =

The plugin authenticates with Twilio API keys only. Twilio recommends them for production use because a leaked key can be revoked on its own, while a leaked auth token compromises the whole account, so the token is deliberately never stored. Create a key on the API keys and tokens page of the Twilio console and enter its SID and secret in the plugin settings. If you configured the auth token on an older version of this plugin, sending pauses after the update until a key is entered; the settings screen says so.

= Why does nothing send from my Twilio trial account? =

New Twilio trial accounts are heavily restricted: they can only text phone numbers you have verified in the Twilio console, and many trials only allow predefined message templates, so the free-text messages this plugin sends are rejected with error 572006. With the logging option enabled these rejections appear in the PHP error log with Twilio's own explanation, and the settings screen shows the last delivery error either way. For real delivery you generally need an upgraded account, and senders in some countries (including the UK) also need an approved compliance profile in Twilio's Trust Hub before messages go through (error 20003 until then). Plan to purchase a phone number as well: a trial number does not always survive the upgrade.

= Why do new users not receive the registration SMS? =

The standard HivePress registration form does not ask for a phone number, so at the moment the registration SMS is sent, no number is known yet. Enable "Ask for the phone number during registration" via HivePress > Settings > SMS to add an optional phone field to the registration form; users who fill it in receive the registration SMS and any later notifications straight away. Alternatively, HivePress itself shows a phone attribute at registration when the attribute is marked as required; in that case it is a mandatory field, and this plugin leaves it in place rather than adding a second one.

= Why is a notification not sent as an SMS? =

An SMS is sent only when the Twilio credentials are set, the event has a non-empty message, and the recipient has a valid phone number. The settings screen shows the last delivery error reported by Twilio, and the logging option via HivePress > Settings > SMS records delivery errors in the PHP error log. Log entries may include partially masked recipient details, so make sure the log file is not publicly accessible. Also note that Twilio trial accounts can only send messages to verified phone numbers.

= How do I disable the SMS for a specific event? =

Clear the message for that event via HivePress > Settings > SMS and save the settings. An empty message disables the SMS for that event.

= How do I let members choose whether they receive texts? =

Install the Notifications for HivePress extension (version 1.1.0 or later) and tick Member Opt-in via HivePress > Settings > SMS > Member Preferences. SMS then appears on each member's Notification Settings page. Be aware that everyone starts unticked: for events enabled under the notification settings, texts to members stop until each person opts in, so read the description on that settings section before ticking the box. Texts to the administrator phone are not affected, and events left disabled under the notification settings keep today's behaviour.

= Are there limits on how many texts a member can receive? =

Yes. Texts sent through member preferences are capped per recipient, at 10 texts per hour by default, so a runaway loop or a burst of events cannot flood one phone or run up your Twilio bill. Texts over the cap are dropped, with a log line when logging is enabled. Texts to the administrator phone are not capped. A slot is counted when a text is cleared to send, so a text that a later filter callback vetoes or that fails at Twilio still uses up one of that hour's slots. Developers can adjust the cap with the `hptw_sms_member_limits` filter; there is deliberately no settings field for it.

= How do users sign in with an SMS code? =

Enable SMS Sign-In in the Sign-In Codes section of the SMS settings tab. A "Sign in with an SMS code" link then appears on the sign-in form. The user enters the phone number saved on their account, receives a six-digit code, and types it in to sign in. Codes expire after 10 minutes, and requests are rate limited. You can protect the request form with reCAPTCHA via the Protected Forms option on the Integrations tab.

= Are sign-in code requests rate limited? =

Yes. Code requests are limited per phone number and per visitor address, and a site-wide hourly budget caps how many codes the whole site can send, so automated requests cannot run up your Twilio bill. Visitors who share one network address, such as an office or a mobile network gateway, share a visitor allowance, and the site-wide budget is the backstop for such shared addresses. Note that if two accounts save the same phone number, neither can sign in with a code until the duplicate is removed. Developers can adjust the limits with the `hptw_otp_limits` filter; there is deliberately no settings field for them.

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

= 1.7.1 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.7.0 =
* New - SMS can join the Notifications for HivePress extension (version 1.1.0 or later) as a channel on each member's Notification Settings page. Strictly opt-in per member and off by default; no role default ever grants it.
* New - notifications with no email behind them, such as a completed booking or a new favourite, can now be texted alongside the on-site notification, using the wording from the notification settings.
* New - member quiet hours are respected on both delivery paths; a text that falls inside them is dropped, not queued. Announcements are never texted, and the administrator phone is unaffected.
* New - a per-recipient cap (10 texts per hour by default) protects against runaway loops and flooding; adjustable for developers via the hptw_sms_member_limits filter.
* New - users can sign in with a six-digit code sent by SMS. Off by default; enable it in the Sign-In Codes section of the SMS settings tab.
* New - sign-in code requests are rate limited per phone number, per visitor and site-wide, and the request form can be protected with reCAPTCHA. Limits are adjustable for developers via the hptw_otp_limits filter.
* For developers - the hptw_sms_send filter gains two appended arguments (the recipient's user object and email address; existing callbacks keep working), notification texts get their own hptw_channel_sms_text filter, and a small public API (send_message, get_user_phone_number, normalize_phone_number, is_ready, get_phone_attribute) is available for trusted callers.

= 1.6.1 =
* Fixed the API key secret field stretching across the whole settings screen; it now matches the width of the other credential fields at every screen size.

= 1.6.0 =
* The auth token is no longer accepted or stored; the plugin authenticates with Twilio API keys only. Sites configured with a token pause sending after the update until an API key is entered, and the settings screen says so.

= 1.5.0 =
* The SMS settings tab now shows the last delivery error from Twilio, clearing once a message is delivered, so a misconfigured account is no longer silent.
* The auth token is deprecated in favour of API keys; it still works when no key is entered, and will be removed in a future version.
* Fixed a required phone attribute being downgraded to optional on the registration form when the registration field setting was also enabled.
* The show/hide control is now a plain icon inside the field, and revealing a value no longer shifts the layout.
* The registration phone field shows an international format example, and the settings recommend setting the country code alongside it.
* Release notes now render properly in the update details popup.
* Documented the paid Twilio account and Trust Hub requirements.

= 1.4.1 =
* Added a show/hide toggle to the auth token and API key secret fields.

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
