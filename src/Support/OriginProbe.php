<?php

namespace Hibira\Laravel\Support;

use RuntimeException;

/**
 * Opens a WebSocket to the Hibira host the way a browser page on a given
 * origin would, and reports whether the app's Allowed origins accept it.
 */
class OriginProbe
{
    /** @var string */
    protected $host;

    /** @var int */
    protected $port;

    /** @var string */
    protected $scheme;

    /** @var string */
    protected $key;

    /** @var float */
    protected $timeout;

    public function __construct($host, $port, $scheme, $key, $timeout = 5.0)
    {
        $this->host = (string) $host;
        $this->port = (int) $port;
        $this->scheme = (string) $scheme;
        $this->key = (string) $key;
        $this->timeout = (float) $timeout;
    }

    /**
     * @param  string  $origin  such as http://localhost:8000
     * @return array{allowed: bool, code: int|null, message: string}
     *
     * @throws RuntimeException when the host can't be reached or doesn't speak WebSocket
     */
    public function check($origin)
    {
        $transport = $this->scheme === 'https' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $this->host]]);
        $socket = @stream_socket_client(
            sprintf('%s://%s:%d', $transport, $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException(sprintf('could not connect to %s:%d (%s)', $this->host, $this->port, $errstr ?: 'error '.$errno));
        }
        stream_set_timeout($socket, (int) ceil($this->timeout));

        try {
            $default = $this->scheme === 'https' ? 443 : 80;
            $host = $this->port === $default ? $this->host : $this->host.':'.$this->port;
            fwrite($socket, implode("\r\n", [
                sprintf('GET /app/%s?protocol=7&client=hibira-laravel-check&version=1 HTTP/1.1', rawurlencode($this->key)),
                'Host: '.$host,
                'Upgrade: websocket',
                'Connection: Upgrade',
                'Sec-WebSocket-Key: '.base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version: 13',
                'Origin: '.$origin,
                '',
                '',
            ]));

            $status = (string) fgets($socket);
            if (! preg_match('#^HTTP/1\.[01] 101\b#', $status)) {
                throw new RuntimeException('the host did not open a WebSocket ('.(trim($status) ?: 'no answer').')');
            }
            while (($line = fgets($socket)) !== false && rtrim($line, "\r\n") !== '') {
                // Skip the response headers.
            }

            return static::interpret(static::readFrame($socket));
        } finally {
            fclose($socket);
        }
    }

    /**
     * Reads one server frame (server frames are not masked).
     *
     * @param  resource  $stream
     * @return array{0: int, 1: string} the opcode and the payload
     */
    public static function readFrame($stream)
    {
        $head = static::read($stream, 2);
        $opcode = ord($head[0]) & 0x0f;
        $length = ord($head[1]) & 0x7f;
        if ($length === 126) {
            $length = unpack('n', static::read($stream, 2))[1];
        } elseif ($length === 127) {
            $length = unpack('J', static::read($stream, 8))[1];
        }
        $mask = (ord($head[1]) & 0x80) ? static::read($stream, 4) : null;
        $payload = $length > 0 ? static::read($stream, $length) : '';
        if ($mask !== null) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        return [$opcode, $payload];
    }

    /**
     * @param  array{0: int, 1: string}  $frame
     * @return array{allowed: bool, code: int|null, message: string}
     */
    public static function interpret(array $frame)
    {
        list($opcode, $payload) = $frame;
        if ($opcode === 0x8) {
            $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : null;

            return ['allowed' => false, 'code' => $code, 'message' => (string) substr($payload, 2) ?: 'connection closed'];
        }

        $message = json_decode($payload, true);
        $event = is_array($message) ? ($message['event'] ?? null) : null;
        if ($event === 'pusher:connection_established') {
            return ['allowed' => true, 'code' => null, 'message' => 'connected'];
        }
        if ($event === 'pusher:error') {
            $data = $message['data'] ?? [];
            if (is_string($data)) {
                $data = json_decode($data, true) ?: ['message' => $data];
            }

            return [
                'allowed' => false,
                'code' => isset($data['code']) ? (int) $data['code'] : null,
                'message' => isset($data['message']) ? (string) $data['message'] : 'refused',
            ];
        }

        throw new RuntimeException('unexpected first message from the host: '.substr($payload, 0, 200));
    }

    /**
     * The origin (scheme://host[:port]) of a URL, or null.
     *
     * @param  string|null  $url
     * @return string|null
     */
    public static function originOf($url)
    {
        $parts = parse_url((string) $url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']);

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    /**
     * The same address spelled with the other loopback name (localhost and
     * 127.0.0.1 are different origins to a browser), or null.
     *
     * @param  string  $origin
     * @return string|null
     */
    public static function loopbackTwin($origin)
    {
        if (preg_match('#^(https?://)localhost(:\d+)?$#', $origin, $m)) {
            return $m[1].'127.0.0.1'.($m[2] ?? '');
        }
        if (preg_match('#^(https?://)127\.0\.0\.1(:\d+)?$#', $origin, $m)) {
            return $m[1].'localhost'.($m[2] ?? '');
        }

        return null;
    }

    /**
     * @param  resource  $stream
     * @param  int  $length
     * @return string
     */
    protected static function read($stream, $length)
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                throw new RuntimeException(! empty($meta['timed_out']) ? 'the host did not answer in time' : 'the host closed the connection');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
