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

    /** @var string */
    public $name;

    /** @var string|null */
    public $channel;

    /** @var array */
    public $payload;

    /** @var int */
    public $timeMs;

    public function __construct(string $name, ?string $channel, array $payload, int $timeMs)
    {
        $this->name = $name;
        $this->channel = $channel;
        $this->payload = $payload;
        $this->timeMs = $timeMs;
    }

    public function userId(): ?string
    {
        return $this->payload['user_id'] ?? null;
    }
}
