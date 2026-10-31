<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\HibiraBroadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Mockery;
use Pusher\Pusher;
use ReflectionMethod;

class ChunkingTest extends TestCase
{
    /** @var array */
    protected $triggered = [];

    protected function broadcaster(string $mode = 'ai', int $max = 4096): HibiraBroadcaster
    {
        $result = (new ReflectionMethod(Pusher::class, 'trigger'))->hasReturnType() ? new \stdClass : true;
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')->andReturnUsing(function () use ($result) {
            $this->triggered[] = func_get_args();

            return $result;
        });

        return new HibiraBroadcaster($pusher, $mode, $max);
    }

    /** Broadcasts, skipping on Laravel versions that refuse pusher-php-server 5+ responses. */
    protected function send(HibiraBroadcaster $broadcaster, array $channels, string $event, array $payload): void
    {
        try {
            $broadcaster->broadcast($channels, $event, $payload);
        } catch (\Throwable $e) {
            // A BroadcastException, or an Error reading an object response as an array.
            if ((new ReflectionMethod(Pusher::class, 'trigger'))->hasReturnType()) {
                $this->markTestSkipped('This Laravel version expects pusher-php-server 3 or 4 responses');
            }
            throw $e;
        }
    }

    protected function agentEvent(string $type, array $fields): array
    {
        return array_merge(['id' => 'evt_1', 'invocation_id' => 'inv_1', 'type' => $type], $fields, ['timestamp' => 1]);
    }

    protected function bigResult(): array
    {
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = ['name' => "Row \"$i\" 東京 / \\ ✓", 'url' => "https://example.com/items/$i"];
        }

        return $rows;
    }

    public function test_small_events_are_sent_as_they_are(): void
    {
        $this->send($this->broadcaster(), [new PrivateChannel('hibira-ai.user.1')], 'text_delta', $this->agentEvent('text_delta', ['delta' => 'Hi']));

        $this->assertCount(1, $this->triggered);
        $this->assertSame('text_delta', $this->triggered[0][1]);
    }

    public function test_a_big_agent_event_is_split_into_chunks_that_join_back(): void
    {
        $payload = $this->agentEvent('tool_result', ['tool_id' => 't1', 'result' => $this->bigResult(), 'successful' => true]);

        $this->send($this->broadcaster(), [new PrivateChannel('hibira-ai.user.1')], 'tool_result', $payload + ['socket' => '123.456']);

        $this->assertGreaterThan(5, count($this->triggered));
        $joined = '';
        foreach ($this->triggered as $index => $call) {
            $this->assertSame(['private-hibira-ai.user.1'], array_values((array) $call[0]));
            $this->assertSame(HibiraBroadcaster::CHUNK_EVENT, $call[1]);
            $chunk = $call[2];
            $this->assertLessThanOrEqual(4096, strlen(json_encode($chunk)), 'each chunk fits the Free plan');
            $this->assertSame('tool_result', $chunk['event']);
            $this->assertSame($index, $chunk['index']);
            $this->assertSame(count($this->triggered), $chunk['count']);
            $this->assertSame($this->triggered[0][2]['id'], $chunk['id']);
            $this->assertStringContainsString('123.456', json_encode($call[3]), 'the sender is still excluded');
            $joined .= $chunk['data'];
        }
        $this->assertSame(json_encode($payload), $joined);
        $this->assertSame($payload, json_decode($joined, true));
    }

    public function test_only_agent_events_are_split_by_default(): void
    {
        $payload = ['rows' => $this->bigResult()];

        $this->assertNull($this->broadcaster()->chunks(['orders'], 'OrderShipped', $payload));
        $this->assertNotNull($this->broadcaster('all')->chunks(['orders'], 'OrderShipped', $payload));
        $this->assertNull($this->broadcaster('off')->chunks(['orders'], 'tool_result', $this->agentEvent('tool_result', $payload)));
    }

    public function test_the_limit_follows_the_plan(): void
    {
        $payload = $this->agentEvent('tool_result', ['result' => $this->bigResult()]);

        $free = $this->broadcaster('ai', 4096)->chunks(['c'], 'tool_result', $payload);
        $hobby = $this->broadcaster('ai', 10240)->chunks(['c'], 'tool_result', $payload);

        $this->assertGreaterThan(count($hobby), count($free));
        foreach ($hobby as $chunk) {
            $this->assertLessThanOrEqual(10240, strlen(json_encode($chunk)));
        }
    }

    public function test_encrypted_channels_leave_room_for_the_ciphertext(): void
    {
        $chunks = $this->broadcaster()->chunks(['private-encrypted-ai.1'], 'tool_result', $this->agentEvent('tool_result', ['result' => $this->bigResult()]));

        foreach ($chunks as $chunk) {
            $ciphertext = str_repeat('x', strlen(json_encode($chunk)) + 16); // secretbox adds a 16-byte MAC
            $data = json_encode(['nonce' => base64_encode(random_bytes(24)), 'ciphertext' => base64_encode($ciphertext)]);
            $this->assertLessThanOrEqual(4096, strlen($data));
        }
    }

    public function test_the_hibira_connection_uses_the_chunking_config(): void
    {
        config(['hibira.chunking.events' => 'all', 'hibira.chunking.max_bytes' => 10240]);

        $broadcaster = Broadcast::connection('hibira');
        $this->assertInstanceOf(HibiraBroadcaster::class, $broadcaster);
        $settings = (function () {
            return [$this->chunking, $this->maxBytes];
        })->call($broadcaster);
        $this->assertSame(['all', 10240], $settings);
    }
}
