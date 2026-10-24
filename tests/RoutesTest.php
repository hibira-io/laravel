<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\Events\WebhookReceived;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;

class RoutesTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('hibira.push.token_route', 'push/user-token');
        $app['config']->set('hibira.push.token_middleware', ['web']);
        $app['config']->set('hibira.webhooks.path', 'hibira/webhook');
    }

    protected function webhook(string $body, array $headers = [])
    {
        return $this->call('POST', '/hibira/webhook', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PUSHER_KEY' => 'pk_test',
        ], $headers), $body);
    }

    public function test_webhook_dispatches_each_event(): void
    {
        Event::fake([WebhookReceived::class]);
        $body = json_encode(['time_ms' => 1792458481958, 'events' => [
            ['name' => 'channel_occupied', 'channel' => 'orders'],
            ['name' => 'member_added', 'channel' => 'presence-room.1', 'user_id' => '7'],
        ]]);

        $this->webhook($body, ['HTTP_X_PUSHER_SIGNATURE' => hash_hmac('sha256', $body, 'sk_test')])->assertStatus(200);

        $events = [];
        Event::assertDispatched(WebhookReceived::class, function ($e) use (&$events) {
            $events[] = $e;

            return true;
        });
        $this->assertCount(2, $events);
        $this->assertSame('channel_occupied', $events[0]->name);
        $this->assertSame('orders', $events[0]->channel);
        $this->assertSame('7', $events[1]->userId());
        $this->assertSame(1792458481958, $events[1]->timeMs);
    }

    public function test_webhook_rejects_a_bad_signature_or_key(): void
    {
        Event::fake([WebhookReceived::class]);
        $body = '{"time_ms":1,"events":[]}';

        $this->webhook($body, ['HTTP_X_PUSHER_SIGNATURE' => hash_hmac('sha256', $body, 'wrong')])->assertStatus(401);
        $this->webhook($body, ['HTTP_X_PUSHER_KEY' => 'pk_other', 'HTTP_X_PUSHER_SIGNATURE' => hash_hmac('sha256', $body, 'sk_test')])->assertStatus(401);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_webhook_accepts_the_previous_secret_signature(): void
    {
        // The app still has the old secret; Hibira signs the extra header with it.
        $body = '{"time_ms":1,"events":[]}';

        $this->webhook($body, [
            'HTTP_X_PUSHER_SIGNATURE' => hash_hmac('sha256', $body, 'sk_new'),
            'HTTP_X_HIBIRA_PREVIOUS_SIGNATURE' => hash_hmac('sha256', $body, 'sk_test'),
        ])->assertStatus(200);
    }

    public function test_push_token_for_the_signed_in_user_only(): void
    {
        $user = new User();
        $user->forceFill(['id' => 42]);

        $this->get('/push/user-token?user_id=42')->assertStatus(401);
        $this->actingAs($user)->get('/push/user-token?user_id=7')->assertStatus(401);

        $response = $this->actingAs($user)->get('/push/user-token?user_id=42');
        $response->assertStatus(200);
        $token = json_decode($response->getContent(), true)['token'];
        $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $this->assertSame('42', $claims['sub']);
    }
}
