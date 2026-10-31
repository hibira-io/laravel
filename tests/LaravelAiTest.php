<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\Ai\AgentChannel;
use Hibira\Laravel\HibiraBroadcaster;
use Hibira\Laravel\Tests\Fixtures\SupportAgent;
use Illuminate\Support\Facades\Broadcast;
use Mockery;
use Pusher\Pusher;
use ReflectionMethod;

/** broadcastOnQueue() from the official SDK, end to end through the hibira connection. */
class LaravelAiTest extends TestCase
{
    /** @var array */
    protected $triggered = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('Laravel\Ai\Ai')) {
            $this->markTestSkipped('laravel/ai is not installed');
        }

        $result = (new ReflectionMethod(Pusher::class, 'trigger'))->hasReturnType() ? new \stdClass : true;
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')->andReturnUsing(function () use ($result) {
            $this->triggered[] = func_get_args();

            return $result;
        });
        Broadcast::extend('hibira-test', function () use ($pusher) {
            return new HibiraBroadcaster($pusher);
        });
        config(['broadcasting.connections.hibira-test' => ['driver' => 'hibira-test'], 'broadcasting.default' => 'hibira-test', 'queue.default' => 'sync']);
    }

    public function test_broadcast_on_queue_streams_to_the_user_channel_within_the_event_limit(): void
    {
        $long = str_repeat('東京', 1500); // one 9 KB word: one text_delta far over 4 KB
        SupportAgent::fake(["Hello $long bye"]);

        (new SupportAgent)->broadcastOnQueue('Hi', AgentChannel::user(7));

        $names = [];
        $joined = [];
        foreach ($this->triggered as $call) {
            $this->assertSame(['private-hibira-ai.user.7'], array_values((array) $call[0]));
            $this->assertLessThanOrEqual(4096, strlen(json_encode($call[2])));
            $names[] = $call[1];
            if ($call[1] === HibiraBroadcaster::CHUNK_EVENT) {
                $joined[] = $call[2]['data'];
            }
        }

        $this->assertSame('stream_start', $names[0]);
        $this->assertSame('stream_end', end($names));
        $this->assertContains(HibiraBroadcaster::CHUNK_EVENT, $names);
        $delta = json_decode(implode('', $joined), true);
        $this->assertSame('text_delta', $delta['type']);
        $this->assertSame(' '.$long, $delta['delta']);
        $this->assertNotNull($delta['invocation_id']);
    }
}
