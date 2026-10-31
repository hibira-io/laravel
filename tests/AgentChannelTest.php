<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\Ai\AgentChannel;
use Hibira\Laravel\HibiraBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Mockery;

class AgentChannelTest extends TestCase
{
    protected function user($id)
    {
        $user = new \stdClass;
        $user->id = $id;

        return $user;
    }

    /** The authorization callback registered for a channel pattern. */
    protected function authCallback(HibiraBroadcaster $broadcaster, string $pattern)
    {
        $channels = (function () {
            return $this->channels;
        })->call($broadcaster);

        return $channels[$pattern] ?? null;
    }

    public function test_channel_names(): void
    {
        $this->assertSame('private-hibira-ai.user.7', AgentChannel::user(7)->name);
        $this->assertSame('private-hibira-ai.conversation.c1', AgentChannel::conversation('c1')->name);
    }

    public function test_the_user_channel_is_only_for_that_user(): void
    {
        $broadcaster = Broadcast::connection('hibira');
        AgentChannel::register($broadcaster);
        $callback = $this->authCallback($broadcaster, 'hibira-ai.user.{id}');

        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(7);
        $this->assertTrue($callback($user, '7'));
        $this->assertFalse($callback($user, '8'));
    }

    public function test_channels_are_registered_when_laravel_ai_is_installed(): void
    {
        if (! class_exists('Laravel\Ai\Ai')) {
            $this->assertNull($this->authCallback(Broadcast::connection('hibira'), 'hibira-ai.user.{id}'));

            return;
        }

        $this->assertNotNull($this->authCallback(Broadcast::connection('hibira'), 'hibira-ai.user.{id}'));
        $this->assertNotNull($this->authCallback(Broadcast::connection('hibira'), 'hibira-ai.conversation.{conversationId}'));
    }

    public function test_the_conversation_channel_asks_the_conversation_store(): void
    {
        if (! interface_exists(VerifiesConversationOwnership::class)) {
            $this->markTestSkipped('laravel/ai is not installed');
        }

        $store = Mockery::mock(ConversationStore::class, VerifiesConversationOwnership::class);
        $store->shouldReceive('conversationBelongsTo')->with('c1', 'stdClass', 7)->andReturn(true);
        $store->shouldReceive('conversationBelongsTo')->andReturn(false);
        $this->app->instance(ConversationStore::class, $store);

        $this->assertTrue(AgentChannel::ownsConversation($this->user(7), 'c1'));
        $this->assertFalse(AgentChannel::ownsConversation($this->user(8), 'c1'));
    }
}
