=== Push Notifications for WP ===
Contributors: Jędrzej Czerwiński
Tags: notifications, push, pushover, woocommerce, moderation
Plugin URI: https://github.com/jedrzejcz/push-notifications-for-wp
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends events from your site to the phones of the people who run it, event by event, using Pushover.

== Description ==

Email tells the site what happened; it does not tell anyone right now. This
plugin puts comments awaiting moderation, new accounts and, on a shop, orders,
payments, refunds and stock warnings on the phones of the people who run the
site, with a sound when the event is urgent.

* An application token for the site, set in the admin, in wp-config.php or in an environment variable.
* A Pushover user key on each profile, with a personal mute switch.
* A matrix deciding which events reach which people, and how urgent each one is.
* Delivery in the background, so a slow Pushover never slows down a page.
* Retries for temporary failures, no retries for a rejected key.
* One notification per event and recipient, however many times the event repeats.
* No personal data in the message: what happened, which object, and an admin link.

WooCommerce is optional. When it is active, shop events appear in the matrix
alongside the WordPress ones. Any other plugin can add its own events through a
documented API.

== Installation ==

1. Upload the plugin to wp-content/plugins and activate it.
2. Create an application at pushover.net/apps and copy its API token.
3. Open Push notifications in the admin menu and paste the token.
4. Ask everyone who should get notifications to paste their Pushover user key on their profile.
5. Tick the boxes in the matrix and send a test notification.

Without an application token the plugin stays silent.

== Translations ==

English (source), Polish, Spanish, German and French ship with the plugin. Any
other language can be added by translating the bundled .pot file.

== Frequently Asked Questions ==

= Does the notification contain personal data? =

No. A push notification is visible on a lock screen and Pushover is a third
party, so the message carries what happened, which object it was about and a
link to the admin. No names, no addresses, no comment text.

= Do I need WooCommerce? =

No. WordPress is the only requirement. With WooCommerce active you also get
order, payment and stock events.

= What happens when Pushover is unreachable? =

The site carries on as usual. The notification waits in the queue and is
retried after 1, 5 and 30 minutes. If it still fails, an admin notice says the
channel is down.

= In which language are the notifications sent? =

In the language of the site. The plugin ships English, Polish, Spanish, German
and French; other languages can be added by translating the bundled .pot file.
Notifications are composed in a background job, so they follow the site
language rather than the language of each recipient.

= Can somebody stop notifications while on holiday? =

Yes. Every recipient has a switch on their own profile that silences their
notifications without changing the assignments in the site settings.

== Changelog ==

= 0.1.0 =
* First release.
