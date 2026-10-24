<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\Push\HibiraPushChannel;
use Hibira\Laravel\Push\HibiraPushMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Mockery;
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

    public function test_notify_sends_to_the_notifiable_user(): void
    {
        $push = Mockery::mock(PushNotifications::class);
        $push->shouldReceive('publishToUsers')->once()->with(['42'], Mockery::on(function ($r) {
            return $r['fcm']['notification']['title'] === 'Hello';
        }))->andReturn((object) ['publishId' => 'p1']);
        $this->app->instance('hibira.push', $push);

        (new PushUser(42))->notify(new HelloNotification());
        $this->addToAssertionCount(1);
    }

    public function test_interests_instead_of_the_user(): void
    {
        $push = Mockery::mock(PushNotifications::class);
        $push->shouldReceive('publishToInterests')->once()->with(['news'], Mockery::type('array'));
        $push->shouldNotReceive('publishToUsers');

        (new HibiraPushChannel($push))->send(new PushUser(1), new HelloNotification(['news']));
        $this->addToAssertionCount(1);
    }

    public function test_route_method_and_chunks_of_1000_users(): void
    {
        $push = Mockery::mock(PushNotifications::class);
        $push->shouldReceive('publishToUsers')->twice();

        (new HibiraPushChannel($push))->send(new ManyUsers(), new HelloNotification());
        $this->addToAssertionCount(1);
    }
}

class PushUser
{
    use Notifiable;

    public $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getKey()
    {
        return $this->id;
    }
}

class ManyUsers
{
    use Notifiable;

    public function routeNotificationForHibiraPush()
    {
        return range(1, 1500);
    }
}

class HelloNotification extends Notification
{
    public $interests;

    public function __construct(array $interests = [])
    {
        $this->interests = $interests;
    }

    public function via($notifiable)
    {
        return ['hibira-push'];
    }

    public function toHibiraPush($notifiable)
    {
        return HibiraPushMessage::create('Hello', 'From Hibira')->interests(...$this->interests);
    }
}
