<?php

namespace Hibira\Laravel\Push;

use Illuminate\Container\Container;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Pusher\PushNotifications\PushNotifications;

/**
 * Notification channel: return 'hibira-push' (or this class) from via()
 * and a HibiraPushMessage from toHibiraPush().
 *
 * The message goes to the notifiable's user ID, which is
 * routeNotificationForHibiraPush() when the model defines it, or its key.
 * A message with interests() goes to those interests instead.
 */
class HibiraPushChannel
{
    // Beams limits per publish request.
    public const MAX_USERS = 1000;

    public const MAX_INTERESTS = 100;

    public function __construct(protected ?PushNotifications $push = null) {}

    public function send(object $notifiable, Notification $notification): array
    {
        if (! method_exists($notification, 'toHibiraPush')) {
            throw new InvalidArgumentException(get_class($notification).' must define toHibiraPush($notifiable) to use the hibira-push channel.');
        }

        $message = $notification->toHibiraPush($notifiable);
        if (! $message instanceof HibiraPushMessage) {
            return [];
        }

        $request = $message->toArray();
        if ($request === []) {
            return [];
        }

        $push = $this->push ?? Container::getInstance()->make('hibira.push');
        $responses = [];

        if ($interests = $message->getInterests()) {
            foreach (array_chunk($interests, self::MAX_INTERESTS) as $chunk) {
                $responses[] = $push->publishToInterests($chunk, $request);
            }

            return $responses;
        }

        $users = $this->users($notifiable, $notification);
        foreach (array_chunk($users, self::MAX_USERS) as $chunk) {
            $responses[] = $push->publishToUsers($chunk, $request);
        }

        return $responses;
    }

    /** @return list<string> */
    protected function users(object $notifiable, Notification $notification): array
    {
        $route = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('hibira-push', $notification)
            : null;

        if ($route === null && method_exists($notifiable, 'getKey')) {
            $route = $notifiable->getKey();
        }

        return array_values(array_unique(array_map('strval', array_filter((array) $route, fn ($id) => $id !== null && $id !== ''))));
    }
}
