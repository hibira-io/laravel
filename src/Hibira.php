<?php

namespace Hibira\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use Hibira\Laravel\Exceptions\MissingCredentials;
use Pusher\Pusher;
use Pusher\PushNotifications\PushNotifications;

/**
 * Builds the Pusher-protocol SDK clients, pointed at Hibira.
 */
class Hibira
{
    public const DEFAULT_HOST = 'ws.hibira.io';

    public const DEFAULT_PUSH_ENDPOINT = 'https://ws.hibira.io';

    /** @var array */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * The client that triggers events and calls the Channels HTTP API.
     *
     * @return Pusher
     */
    public function realtime()
    {
        // Missing credentials don't throw here: the broadcaster is built while
        // the app boots (routes/channels.php), and artisan must keep working
        // before .env is filled in. hibira:check names what is missing.
        $scheme = $this->config['scheme'] ?? 'https';
        $options = array_replace([
            'host' => $this->config['host'] ?? self::DEFAULT_HOST,
            'port' => (int) ($this->config['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'scheme' => $scheme,
            'useTLS' => $scheme === 'https',
            'encrypted' => $scheme === 'https', // pusher-php-server 3.x
        ], $this->config['options'] ?? []);

        $args = [(string) $this->config['key'], (string) $this->config['secret'], (string) $this->config['app_id'], $options];

        // pusher-php-server 5 and later take a Guzzle client.
        if (! empty($this->config['client_options'])) {
            $args[] = new GuzzleClient($this->config['client_options']);
        }

        return new Pusher(...$args);
    }

    /** @return list<string> the config keys of missing realtime credentials */
    public function missingRealtimeCredentials(): array
    {
        return array_values(array_filter(['app_id', 'key', 'secret'], function ($name) {
            return blank($this->config[$name] ?? null);
        }));
    }

    /**
     * The Beams-compatible push notifications client.
     *
     * @return PushNotifications
     */
    public function push()
    {
        $push = $this->config['push'] ?? [];

        foreach (['instance_id', 'secret_key'] as $name) {
            if (blank($push[$name] ?? null)) {
                throw MissingCredentials::push($name);
            }
        }

        return new PushNotifications([
            'instanceId' => (string) $push['instance_id'],
            'secretKey' => (string) $push['secret_key'],
            // Without an endpoint the SDK would talk to pusher.com.
            'endpoint' => rtrim($push['endpoint'] ?? self::DEFAULT_PUSH_ENDPOINT, '/'),
        ]);
    }
}
