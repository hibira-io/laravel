# Changelog

## 0.1.0

First release.

- `hibira` broadcast connection for Laravel 5.6 to 13, with no entry
  needed in `config/broadcasting.php`.
- `Hibira` and `HibiraPush` facades; `Pusher\Pusher` and
  `Pusher\PushNotifications\PushNotifications` resolve to clients pointed at
  Hibira.
- `hibira-push` notification channel and `HibiraPushMessage`.
- Optional push user token route and verified webhook route
  (`WebhookReceived` event).
- `hibira:install` and `hibira:check` commands.
