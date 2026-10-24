<?php

namespace Hibira\Laravel\Push;

/**
 * One notification for iOS (APNs), Android (FCM) and browsers (Web Push).
 *
 * The fluent setters fill all three platforms; apns(), fcm() and web()
 * merge platform-specific fields on top.
 */
class HibiraPushMessage
{
    public const PLATFORMS = ['apns', 'fcm', 'web'];

    protected ?string $title = null;

    protected ?string $body = null;

    protected ?string $link = null;

    protected ?string $icon = null;

    protected ?string $sound = null;

    protected ?int $badge = null;

    protected array $data = [];

    protected array $platforms = self::PLATFORMS;

    protected array $interests = [];

    protected array $overrides = ['apns' => [], 'fcm' => [], 'web' => []];

    public function __construct(?string $title = null, ?string $body = null)
    {
        $this->title = $title;
        $this->body = $body;
    }

    public static function create(?string $title = null, ?string $body = null): static
    {
        return new static($title, $body);
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /** The page a browser opens when the notification is clicked (Web Push deep_link). */
    public function link(string $url): static
    {
        $this->link = $url;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function sound(string $sound = 'default'): static
    {
        $this->sound = $sound;

        return $this;
    }

    public function badge(int $badge): static
    {
        $this->badge = $badge;

        return $this;
    }

    /** Custom data for your app. FCM receives the values as strings. */
    public function data(array $data): static
    {
        $this->data = array_replace($this->data, $data);

        return $this;
    }

    /** Send to these platforms only. */
    public function only(string ...$platforms): static
    {
        $this->platforms = array_values(array_intersect(self::PLATFORMS, $platforms));

        return $this;
    }

    /** Send to every device subscribed to these interests, instead of to the notifiable user. */
    public function interests(string ...$interests): static
    {
        $this->interests = array_values(array_unique([...$this->interests, ...$interests]));

        return $this;
    }

    public function apns(array $fields): static
    {
        return $this->override('apns', $fields);
    }

    public function fcm(array $fields): static
    {
        return $this->override('fcm', $fields);
    }

    public function web(array $fields): static
    {
        return $this->override('web', $fields);
    }

    public function getInterests(): array
    {
        return $this->interests;
    }

    /** The Beams publish request body. */
    public function toArray(): array
    {
        $request = [];

        foreach ($this->platforms as $platform) {
            $request[$platform] = array_replace_recursive($this->{'build'.ucfirst($platform)}(), $this->overrides[$platform]);
        }

        return $request;
    }

    protected function override(string $platform, array $fields): static
    {
        $this->overrides[$platform] = array_replace_recursive($this->overrides[$platform], $fields);

        return $this;
    }

    protected function buildApns(): array
    {
        $aps = array_filter([
            'alert' => array_filter(['title' => $this->title, 'body' => $this->body], fn ($v) => $v !== null),
            'sound' => $this->sound,
            'badge' => $this->badge,
        ], fn ($v) => $v !== null && $v !== []);

        $payload = ['aps' => $aps];
        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }

    protected function buildFcm(): array
    {
        $payload = [];
        $notification = array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'sound' => $this->sound,
        ], fn ($v) => $v !== null);

        if ($notification !== []) {
            $payload['notification'] = $notification;
        }
        if ($this->data !== []) {
            $payload['data'] = array_map(
                fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v),
                $this->data,
            );
        }

        return $payload;
    }

    protected function buildWeb(): array
    {
        $payload = [];
        $notification = array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'deep_link' => $this->link,
        ], fn ($v) => $v !== null);

        if ($notification !== []) {
            $payload['notification'] = $notification;
        }
        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }
}
