<?php

namespace Hibira\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One webhook event, after its request's signature was checked.
 *
 * $name is channel_occupied, channel_vacated, member_added,
 * member_removed, client_event, cache_miss or subscription_count, and
 * $payload holds the event's fields (channel, user_id, event, data, ...).
 */
class WebhookReceived
{
    use Dispatchable;

    public function __construct(
        public string $name,
        public ?string $channel,
        public array $payload,
        public int $timeMs,
    ) {}

    public function userId(): ?string
    {
        return $this->payload['user_id'] ?? null;
    }
}
