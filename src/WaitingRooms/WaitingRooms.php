<?php

namespace Hibira\Laravel\WaitingRooms;

use GuzzleHttp\Client as GuzzleClient;
use Hibira\Laravel\Exceptions\MissingCredentials;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Hibira waiting rooms: the signed room API, pass checks and the hosted
 * waiting page address. Reached through Hibira::waitingRoom('presale') or
 * the WaitingRoom facade.
 */
class WaitingRooms
{
    public const PASS_PURPOSE = 'hibira-waitroom-pass';

    /** @var array config/hibira.php */
    protected $config;

    /** @var GuzzleClient|null */
    protected $http;

    public function __construct(array $config, ?GuzzleClient $http = null)
    {
        $this->config = $config;
        $this->http = $http;
    }

    /** A handle on one room. Nothing is called until you use it. */
    public function room(string $name): WaitingRoom
    {
        return new WaitingRoom($this, $name);
    }

    /**
     * Every room of the app with its live counters, sorted by name. Returns
     * null when the server is too old to list rooms.
     *
     * @return list<array>|null
     */
    public function all(): ?array
    {
        try {
            $data = $this->request('GET', '/waiting_rooms');
        } catch (WaitingRoomException $e) {
            if ($e->notFound()) {
                return null;
            }
            throw $e;
        }

        return array_values($data['rooms'] ?? []);
    }

    /** Whether the middleware guards routes (HIBIRA_WAITING_ROOMS=false turns it off). */
    public function enabled(): bool
    {
        return filter_var($this->config['waiting_rooms']['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /** https://ws.hibira.io, or the host and port from config/hibira.php. */
    public function baseUrl(): string
    {
        $scheme = $this->config['scheme'] ?? 'https';
        $host = $this->config['host'] ?? 'ws.hibira.io';
        $port = (int) ($this->config['port'] ?? ($scheme === 'https' ? 443 : 80));
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port === $default ? '' : ':'.$port);
    }

    /** The hosted waiting page for a room. */
    public function url(string $room, ?string $returnUrl = null, ?string $lang = null): string
    {
        $base = rtrim($this->config['waiting_rooms']['page_host'] ?? '', '/') ?: $this->baseUrl();
        $query = array_filter(['return' => $returnUrl, 'lang' => $lang], function ($v) {
            return $v !== null && $v !== '';
        });

        return $base.'/wait/'.rawurlencode((string) $this->config['key']).'/'.rawurlencode($room)
            .($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /**
     * Checks a pass locally (HMAC with the app secret, no API call).
     * Returns null when it is missing, forged, expired, or for another
     * room or app.
     */
    public function verifyPass(?string $pass, ?string $room = null, ?int $now = null): ?Pass
    {
        if (! is_string($pass) || $pass === '' || strlen($pass) > 1024) {
            return null;
        }
        $parts = explode('.', $pass);
        if (count($parts) !== 2 || $parts[0] === '') {
            return null;
        }
        [$payload, $signature] = $parts;

        $valid = false;
        foreach ($this->secrets() as $secret) {
            $expected = self::base64url(hash_hmac('sha256', self::PASS_PURPOSE.'.'.$payload, $secret, true));
            if (hash_equals($expected, $signature)) {
                $valid = true;
                break;
            }
        }
        if (! $valid) {
            return null;
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);
        if (! is_array($claims) || ($claims['v'] ?? null) !== 1 || ! is_int($claims['exp'] ?? null)) {
            return null;
        }
        if (($now ?? time()) >= $claims['exp']) {
            return null;
        }
        if ((string) ($claims['app'] ?? '') !== (string) $this->config['app_id']) {
            return null;
        }
        if ($room !== null && ($claims['room'] ?? null) !== $room) {
            return null;
        }

        return new Pass($claims);
    }

    /**
     * Calls the signed waiting room API (signed like POST /apps/ID/events).
     *
     * @throws WaitingRoomException
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        foreach (['app_id', 'key', 'secret'] as $name) {
            if (blank($this->config[$name] ?? null)) {
                throw MissingCredentials::realtime($name);
            }
        }

        $path = '/apps/'.$this->config['app_id'].$path;
        $json = $body === null ? '' : json_encode((object) $body);
        $query = [
            'auth_key' => (string) $this->config['key'],
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
        ];
        if ($json !== '') {
            $query['body_md5'] = md5($json);
        }
        ksort($query);
        $queryString = self::queryString($query);
        $query['auth_signature'] = hash_hmac('sha256', strtoupper($method)."\n".$path."\n".$queryString, (string) $this->config['secret']);

        $url = $this->baseUrl().$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        try {
            [$status, $raw] = $this->send(strtoupper($method), $url, $json);
        } catch (Throwable $e) {
            throw new WaitingRoomException('Hibira did not answer: '.$e->getMessage(), 0, $e);
        }

        $data = json_decode($raw, true);
        if ($status >= 400) {
            $message = is_array($data) ? ($data['error'] ?? $data['message'] ?? null) : null;
            throw new WaitingRoomException($message ?: trim($raw) ?: "HTTP $status", $status);
        }

        return is_array($data) ? $data : [];
    }

    /** @return list<string> the current secret, then any previous one still accepted */
    protected function secrets(): array
    {
        return array_values(array_filter([
            (string) ($this->config['secret'] ?? ''),
            (string) ($this->config['waiting_rooms']['previous_secret'] ?? ''),
        ], 'strlen'));
    }

    /**
     * Laravel's HTTP client when there is one (Laravel 7 and later, so
     * Http::fake() works in your tests), Guzzle otherwise.
     *
     * @return array{0: int, 1: string} status and body
     */
    protected function send(string $method, string $url, string $json): array
    {
        if ($this->http === null && class_exists(HttpFactory::class) && method_exists(PendingRequest::class, 'withBody')) {
            $request = app(HttpFactory::class)
                ->withOptions($this->config['client_options'] ?? [])
                ->timeout(10)
                ->accept('application/json');
            if ($json !== '') {
                $request = $request->withBody($json, 'application/json');
            }
            $response = $request->send($method, $url);

            return [$response->status(), $response->body()];
        }

        if ($this->http === null) {
            $this->http = new GuzzleClient($this->config['client_options'] ?? []);
        }
        $options = ['headers' => ['Accept' => 'application/json'], 'http_errors' => false, 'timeout' => 10];
        if ($json !== '') {
            $options['body'] = $json;
            $options['headers']['Content-Type'] = 'application/json';
        }
        $response = $this->http->request($method, $url, $options);

        return [$response->getStatusCode(), (string) $response->getBody()];
    }

    protected static function queryString(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return implode('&', $pairs);
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
