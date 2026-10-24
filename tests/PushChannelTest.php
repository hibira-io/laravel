<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\Push\HibiraPushChannel;
use Hibira\Laravel\Push\HibiraPushMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Pusher\PushNotifications\PushNotifications;

class PushChannelTest extends TestCase
{
    public function test_message_fills_every_platform(): void
    {
        $request = HibiraPushMessage::create('Order shipped', 'Order #7 is on its way.')
            ->link('https://shop.test/orders/7')
            ->sound()
            ->data(['order_id' => 7, 'tags' => ['a']])
            ->toArray();

        $this->assertSame(['title' => 'Order shipped', 'body' => 'Order #7 is on its way.'], $request['apns']['aps']['alert']);
        $this->assertSame('default', $request['apns']['aps']['sound']);
        $this->assertSame(['order_id' => 7, 'tags' => ['a']], $request['apns']['data']);
        $this->assertSame(['order_id' => '7', 'tags' => '["a"]'], $request['fcm']['data']);
        $this->assertSame('https://shop.test/orders/7', $request['web']['notification']['deep_link']);
    }

    public function test_only_and_overrides(): void
    {
        $request = HibiraPushMessage::create('Hi')
            ->only('apns', 'web')
            ->apns(['aps' => ['mutable-content' => 1]])
            ->toArray();

        $this->assertSame(['apns', 'web'], array_keys($request));
        $this->assertSame(1, $request['apns']['aps']['mutable-content']);
        $this->assertSame('Hi', $request['apns']['aps']['alert']['title']);
    }

    public function test_sends_to_the_notifiable_user(): void
    {
        $push = $this->mock(PushNotifications::class);
        $push->shouldReceive('publishToUsers')->once()->with(['42'], \Mockery::on(fn ($r) => $r['fcm']['notification']['title'] === 'Hello'))->andReturn((object) ['publishId' => 'p1']);
        $this->app->instance('hibira.push', $push);

        (new PushUser(42))->notify(new HelloNotification());
    }

    public function test_route_method_and_interests(): void
    {
        $push = $this->mock(PushNotifications::class);
        $push->shouldReceive('publishToInterests')->once()->with(['news'], \Mockery::type('array'));
        $push->shouldNotReceive('publishToUsers');

        (new HibiraPushChannel($push))->send(new PushUser(1), new HelloNotification(['news']));
    }

    public function test_users_are_chunked_by_1000(): void
    {
        $push = $this->mock(PushNotifications::class);
        $push->shouldReceive('publishToUsers')->twice();

        $users = new class { public function routeNotificationFor($channel) { return range(1, 1500); } };
        (new HibiraPushChannel($push))->send($users, new HelloNotification());
    }

    public function test_channel_name_is_registered(): void
    {
        NotificationFacade::fake();
        (new PushUser(1))->notify(new HelloNotification());
        NotificationFacade::assertSentTo(new PushUser(1), HelloNotification::class, fn ($n, $channels) => $channels === ['hibira-push']);
    }
}

class PushUser
{
    use Notifiable;

    public function __construct(public int $id) {}

    public function getKey(): int
    {
        return $this->id;
    }
}

class HelloNotification extends Notification
{
    public function __construct(public array $interests = []) {}

    public function via($notifiable): array
    {
        return ['hibira-push'];
    }

    public function toHibiraPush($notifiable): HibiraPushMessage
    {
        return HibiraPushMessage::create('Hello', 'From Hibira')->interests(...$this->interests);
    }
}
