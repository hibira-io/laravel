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

    /** @var string|null */
    protected $title;

    /** @var string|null */
    protected $body;

    /** @var string|null */
    protected $link;

    /** @var string|null */
    protected $icon;

    /** @var string|null */
    protected $sound;

    /** @var int|null */
    protected $badge;

    /** @var array */
    protected $data = [];

    /** @var list<string> */
    protected $platforms = self::PLATFORMS;

    /** @var list<string> */
    protected $interests = [];

    /** @var array<string, array> */
    protected $overrides = ['apns' => [], 'fcm' => [], 'web' => []];

    public function __construct(?string $title = null, ?string $body = null)
    {
        $this->title = $title;
        $this->body = $body;
    }

    /** @return static */
    public static function create(?string $title = null, ?string $body = null)
    {
        return new static($title, $body);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /** The page a browser opens when the notification is clicked (Web Push deep_link). */
    public function link(string $url): self
    {
        $this->link = $url;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function sound(string $sound = 'default'): self
    {
        $this->sound = $sound;

        return $this;
    }

    public function badge(int $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    /** Custom data for your app. FCM receives the values as strings. */
    public function data(array $data): self
    {
        $this->data = array_replace($this->data, $data);

        return $this;
    }

    /** Send to these platforms only. */
    public function only(string ...$platforms): self
    {
        $this->platforms = array_values(array_intersect(self::PLATFORMS, $platforms));

        return $this;
    }

    /** Send to every device subscribed to these interests, instead of to the notifiable user. */
    public function interests(string ...$interests): self
    {
        $this->interests = array_values(array_unique(array_merge($this->interests, $interests)));

        return $this;
    }

    public function apns(array $fields): self
    {
        return $this->override('apns', $fields);
    }

    public function fcm(array $fields): self
    {
        return $this->override('fcm', $fields);
    }

    public function web(array $fields): self
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

    protected function override(string $platform, array $fields): self
    {
        $this->overrides[$platform] = array_replace_recursive($this->overrides[$platform], $fields);

        return $this;
    }

    protected function buildApns(): array
    {
        $aps = array_filter([
            'alert' => array_filter(['title' => $this->title, 'body' => $this->body], [$this, 'notNull']),
            'sound' => $this->sound,
            'badge' => $this->badge,
        ], function ($v) { return $v !== null && $v !== []; });

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
        ], [$this, 'notNull']);

        if ($notification !== []) {
            $payload['notification'] = $notification;
        }
        if ($this->data !== []) {
            $payload['data'] = array_map(
                function ($v) {
                    return is_scalar($v) || $v === null ? (string) $v : json_encode($v);
                },
                $this->data
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
        ], [$this, 'notNull']);

        if ($notification !== []) {
            $payload['notification'] = $notification;
        }
        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }

    protected function notNull($value): bool
    {
        return $value !== null;
    }
}
