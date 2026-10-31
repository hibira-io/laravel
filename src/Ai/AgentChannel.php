<?php

namespace Hibira\Laravel\Ai;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;

/**
 * Private channels for Laravel AI SDK streams, authorized by this package:
 *
 *   $agent->broadcastOnQueue($prompt, AgentChannel::user($request->user()));
 *
 * user() is the signed-in user's own channel (every stream for them),
 * conversation() a conversation's channel (members are the users the
 * conversation was stored for).
 */
class AgentChannel
{
    const USER = 'hibira-ai.user.';

    const CONVERSATION = 'hibira-ai.conversation.';

    /**
     * @param  \Illuminate\Contracts\Auth\Authenticatable|string|int  $user
     */
    public static function user($user): PrivateChannel
    {
        return new PrivateChannel(self::USER.static::key($user));
    }

    public static function conversation(string $conversationId): PrivateChannel
    {
        return new PrivateChannel(self::CONVERSATION.$conversationId);
    }

    /** Registers the authorization callbacks for both channels. */
    public static function register(Broadcaster $broadcaster): void
    {
        $broadcaster->channel(self::USER.'{id}', function ($user, $id) {
            return (string) static::key($user) === (string) $id;
        });

        $broadcaster->channel(self::CONVERSATION.'{conversationId}', function ($user, $conversationId) {
            return static::ownsConversation($user, (string) $conversationId);
        });
    }

    public static function ownsConversation($user, string $conversationId): bool
    {
        if (! interface_exists(ConversationStore::class) || ! app()->bound(ConversationStore::class)) {
            return false;
        }

        $store = app(ConversationStore::class);
        if (! $store instanceof VerifiesConversationOwnership) {
            return false;
        }

        return $store->conversationBelongsTo(
            $conversationId,
            Conversation::participantType($user),
            Conversation::participantKey($user)
        );
    }

    protected static function key($user)
    {
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            return $user->getAuthIdentifier();
        }

        return $user;
    }
}
