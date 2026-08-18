# Push Notifications for WP

Sends events from your WordPress site to the phones of the people who run it,
using [Pushover](https://pushover.net/). One row per event, one column per
person, and a checkbox where they meet.

Email tells the site what happened; it does not tell anyone *now*. On a site
run by two or three people, a comment left at 9pm or an order placed on a
Saturday sits in an inbox until somebody opens it. This plugin puts it on a
phone instead, with a sound when the event is urgent and without one when it
is not.

WordPress is the only requirement. WooCommerce is supported out of the box when
it happens to be installed, and any other plugin can add its own events through
two public functions.

## What it does

- An **application token** for the site, set in the admin, in `wp-config.php`
  or in an environment variable.
- A **Pushover user key** on the profile of everyone who should get
  notifications, plus a "do not send me notifications" switch they control
  themselves.
- A **matrix** on the settings screen: which events go to which people, and how
  urgent each event is.
- **Delivery in the background.** Reporting an event queues a job; the call to
  Pushover happens outside the request. A slow or broken Pushover never slows
  down a page load or a checkout.
- **Retries that make sense.** Temporary failures are retried after 1, 5 and
  30 minutes. A rejected key is not retried, because it will keep being
  rejected; it is flagged on the settings screen instead.
- **One notification per event and recipient.** A payment gateway that repeats
  its callback does not repeat the push.
- **No personal data in the message.** What happened, which object it was
  about, and a link to the admin. No names, no addresses, no comment text.

## Why no personal data

A push notification sits on a lock screen, which other people see, and Pushover
is a third party outside the EEA. The object and a link are enough to know what
happened, and two taps open the rest in the admin. This is not a setting.

## Events

Plain WordPress:

| Key | What it means | Default urgency |
| --- | --- | --- |
| `core.comment_moderation` | A comment is waiting for moderation | normal |
| `core.user_registered` | Somebody registered an account | normal |

With WooCommerce active, the bundled integration adds:

| Key | What it means | Default urgency |
| --- | --- | --- |
| `order.new` | Order placed at checkout | normal |
| `order.paid` | Payment confirmed, or the order moved to processing | normal |
| `order.failed` | Payment failed | urgent |
| `order.cancelled` | Order cancelled | normal |
| `order.refunded` | Refund issued, full or partial | normal |
| `stock.low` | Stock fell to the low stock threshold | normal |
| `stock.out` | Stock reached zero | urgent |

Urgent means Pushover priority 1 with a sound, which cuts through the quiet
hours set in the Pushover app. The urgency of every event can be changed in the
matrix.

A stock warning is sent once. Restocking above the low stock threshold clears
the marker, so the next sell-out is reported again.

## Requirements

- WordPress 6.4 or newer
- PHP 8.1 or newer
- A Pushover account with an application registered in it

Action Scheduler runs the queue when it is available, which on a WooCommerce
site it always is. Without it, WP-Cron does the same job a little more slowly.

## Installing

1. Copy the plugin folder into `wp-content/plugins/` and activate it.
2. Create an application at [pushover.net/apps](https://pushover.net/apps) and
   copy its API token.
3. Open **Push notifications** in the admin menu and paste the token. On a
   production site, prefer `wp-config.php`:

   ```php
   define( 'PUSH_NOTIFY_APP_TOKEN', 'your-application-token' );
   ```

   An environment variable of the same name works too. Either one wins over the
   value stored in the database, and the screen says which is in use.
4. Ask everyone who should receive notifications to install the Pushover app
   and paste their user key on their own WordPress profile.
5. Tick the boxes in the matrix, then use **Send a test notification** to be
   sure the channel works before the first real event arrives.

Without an application token the plugin is silent. It queues nothing, sends
nothing and reports no failures.

## Who can do what

| | Capability | Who has it |
| --- | --- | --- |
| Configure notifications | `manage_options`, or `manage_woocommerce` on a shop | administrators, plus shop managers where WooCommerce is active |
| Be a recipient | `edit_posts` | editors, authors, shop managers |

Subscribers and shop customers are never recipients and never see the profile
fields. Both capabilities can be changed with the
`push_notify_manage_capability` and `push_notify_recipient_capability` filters.

## Adding your own events

Other plugins can register events. They appear in the matrix next to the
built-in ones, with nobody assigned, and stay silent until somebody is.

```php
add_action(
    'init',
    function () {
        if ( ! function_exists( 'push_notify_register_event' ) ) {
            return;
        }

        push_notify_register_event(
            'my_plugin.complaint',
            array(
                'label'    => __( 'New complaint', 'my-plugin' ),
                'group'    => 'other',      // a key from push_notify_event_groups
                'priority' => 'normal',     // normal or urgent
                'throttle' => 0,            // seconds; at most one per window
                'build'    => function ( array $context ): array {
                    return array(
                        'title'   => sprintf( 'Case %s', $context['case'] ),
                        'message' => $context['summary'],
                        // 'url' is optional; object_id in the context produces
                        // an admin link on its own.
                    );
                },
            )
        );
    }
);
```

Report an event from wherever it happens:

```php
if ( function_exists( 'push_notify_send' ) ) {
    push_notify_send(
        'my_plugin.complaint',
        array(
            'case'        => $case_number,
            'summary'     => $summary,
            'object_id'   => $post_id,   // optional: link and once-only marker
            'object_type' => 'post',
        )
    );
}
```

The `function_exists()` guard is the point: a site with this plugin switched
off keeps working, and the call does nothing.

### Filters

| Filter | What it decides |
| --- | --- |
| `push_notify_events` | the whole registry, if you want more than one entry |
| `push_notify_event_groups` | the groups the matrix is divided into |
| `push_notify_manage_capability` | who may configure notifications |
| `push_notify_recipient_capability` | who may be a recipient |
| `push_notify_read_markers`, `push_notify_write_markers` | where once-only markers live for objects outside the posts table |
| `push_notify_object_url` | the admin link for such objects |
| `push_notify_async` | set to false to send in the current request; tests use this |

`push_notify_logged` is an action, fired for every log entry. The WooCommerce
integration uses it to copy anything about an order into that order's notes.

### Context rules

- Plain values only. The context is stored as queue arguments.
- No personal data. It ends up on a lock screen.
- `object_id` with `object_type` (and the shorthands `order_id` and
  `product_id`) are understood: they give the notification its link and carry
  the marker that keeps a repeated event from sending a second notification.
- `throttle` is for events that repeat when something is wrong (a rejected
  webhook, say). One notification per window, and the next one says how many
  were held back.

## When something goes wrong

The settings screen keeps the last fifty deliveries. If the channel gives up on
a notification, an admin notice says so. That warning is never sent as a push:
the channel that would carry it is the one that just failed.

## Development

The test suite runs against a live WordPress install through WP-CLI and never
touches the network:

```sh
wp eval-file tests/run.php             # everything
wp eval-file tests/run.php delivery    # one suite
```

The WooCommerce suite reports itself as skipped on a site without a shop, which
is how the suite proves the plugin does not need one.

### Translations

The plugin ships Polish, Spanish, German and French. Source strings are
English, so any other language is a matter of translating
`languages/push-notifications-for-wp.pot` with Poedit, Loco Translate or WPML
and dropping the result into `wp-content/languages/plugins/`, where it survives
plugin updates.

The bundled translations of the four languages other than English were written
by the author, who is a native speaker of none of them; corrections are the
most welcome kind of pull request.

Regenerate the translation template after changing any string:

```sh
wp i18n make-pot . languages/push-notifications-for-wp.pot --exclude=tests
wp i18n make-mo languages
```

## License

GPL-2.0-or-later. See `LICENSE`.
