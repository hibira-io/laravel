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

    public function __construct(protected array $config) {}

    /** The client that triggers events and calls the Channels HTTP API. */
    public function realtime(): Pusher
    {
        foreach (['key', 'secret', 'app_id'] as $name) {
            if (blank($this->config[$name] ?? null)) {
                throw MissingCredentials::realtime($name);
            }
        }

        $scheme = $this->config['scheme'] ?? 'https';
        $options = array_replace([
            'host' => $this->config['host'] ?? self::DEFAULT_HOST,
            'port' => (int) ($this->config['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'scheme' => $scheme,
            'useTLS' => $scheme === 'https',
        ], $this->config['options'] ?? []);

        $client = empty($this->config['client_options'])
            ? null
            : new GuzzleClient($this->config['client_options']);

        return new Pusher(
            (string) $this->config['key'],
            (string) $this->config['secret'],
            (string) $this->config['app_id'],
            $options,
            $client,
        );
    }

    /** The Beams-compatible push notifications client. */
    public function push(): PushNotifications
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
