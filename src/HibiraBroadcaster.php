<?php

namespace Hibira\Laravel;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;

/**
 * The pusher broadcaster, plus chunking: an event whose data is bigger than
 * the plan's per-event limit (4 KB on Free) is sent as several
 * "hibira:chunk" events that the client joins back together, instead of
 * being refused by the Channels API.
 *
 * Each chunk is {id, event, index, count, data}: data is a slice of the JSON
 * the event would have carried. Join the slices of one id in index order and
 * JSON.parse() the result to get the original payload.
 */
class HibiraBroadcaster extends PusherBroadcaster
{
    const CHUNK_EVENT = 'hibira:chunk';

    /** @var string ai, all or off */
    protected $chunking;

    /** @var int */
    protected $maxBytes;

    public function __construct($pusher, string $chunking = 'ai', int $maxBytes = 4096)
    {
        parent::__construct($pusher);

        $this->chunking = $chunking;
        $this->maxBytes = $maxBytes;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $chunks = $this->chunks($channels, (string) $event, $payload);

        if ($chunks === null) {
            parent::broadcast($channels, $event, $payload);

            return;
        }

        foreach ($chunks as $chunk) {
            parent::broadcast($channels, self::CHUNK_EVENT, $chunk);
        }
    }

    /**
     * The chunk payloads for an event, or null when it is sent as it is.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function chunks(array $channels, string $event, array $payload)
    {
        if ($this->chunking === 'off' || $this->maxBytes <= 0) {
            return null;
        }

        $socket = $payload['socket'] ?? null;
        unset($payload['socket']);

        if ($this->chunking !== 'all' && ! static::isAgentEvent($event, $payload)) {
            return null;
        }

        $budget = $this->budget($channels);

        // Encoded the way pusher-php-server encodes it: default flags give
        // pure ASCII, so slicing by byte never splits a character.
        $json = json_encode($payload);
        if ($json === false || strlen($json) <= $budget) {
            return null;
        }

        $id = bin2hex(random_bytes(8));
        $room = $budget - strlen(json_encode([
            'id' => $id, 'event' => $event, 'index' => 99999, 'count' => 99999, 'data' => '',
        ]));
        if ($room < 64) {
            return null;
        }

        $pieces = [];
        $offset = 0;
        $length = strlen($json);
        while ($offset < $length) {
            $take = min($room, $length - $offset);
            // Quotes, backslashes and slashes are escaped again inside the
            // chunk, so a slice can grow up to twice its length.
            while (($size = strlen(json_encode(substr($json, $offset, $take))) - 2) > $room) {
                $take -= max(1, $size - $room);
            }
            $pieces[] = substr($json, $offset, $take);
            $offset += $take;
        }

        $chunks = [];
        foreach ($pieces as $index => $piece) {
            $chunk = ['id' => $id, 'event' => $event, 'index' => $index, 'count' => count($pieces), 'data' => $piece];
            if ($socket !== null) {
                $chunk['socket'] = $socket;
            }
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Whether a payload is a Laravel AI SDK stream event (text_delta,
     * tool_result, ...), which carry their type and invocation ID.
     */
    public static function isAgentEvent(string $event, array $payload): bool
    {
        return isset($payload['type'], $payload['id'])
            && $payload['type'] === $event
            && array_key_exists('invocation_id', $payload);
    }

    /** The biggest data one event may carry on these channels. */
    protected function budget(array $channels): int
    {
        foreach ($channels as $channel) {
            // Encrypted channels send {"nonce","ciphertext"}: base64 of the
            // data plus a 16-byte MAC, next to a 24-byte nonce.
            if (strpos((string) $channel, 'private-encrypted-') === 0) {
                return intdiv($this->maxBytes - 64, 4) * 3 - 16;
            }
        }

        return $this->maxBytes;
    }
}
