# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-18

First release.

### Added

- Settings screen of its own in the admin menu: the application token, the
  recipient matrix, a delivery log and a test send.
- Pushover user key and a personal mute switch on the profile of every account
  allowed to edit content.
- Core WordPress events: `core.comment_moderation`, `core.user_registered`.
- Bundled WooCommerce integration, loaded only when WooCommerce is active:
  `order.new`, `order.paid`, `order.failed`, `order.cancelled`,
  `order.refunded`, `stock.low`, `stock.out`.
- Two urgency levels per event: normal, and urgent with a sound that cuts
  through quiet hours.
- Background delivery through Action Scheduler where it exists and WP-Cron
  where it does not, with retries after 1, 5 and 30 minutes for temporary
  failures and no retries for rejected keys.
- One notification per event and recipient, and an optional throttling window
  for events that repeat when something is wrong.
- Public extension API: `push_notify_register_event()`, `push_notify_send()`
  and the `push_notify_events` filter, plus filters for capabilities, marker
  storage and admin links.
- Translations: Polish, Spanish, German and French.
