<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\Facades\Hibira;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Pusher\Pusher;
use Pusher\PushNotifications\PushNotifications;

class BroadcastingTest extends TestCase
{
    public function test_hibira_connection_is_registered_without_editing_broadcasting_config(): void
    {
        $this->assertSame(['driver' => 'hibira'], config('broadcasting.connections.hibira'));
        $this->assertInstanceOf(PusherBroadcaster::class, Broadcast::connection('hibira'));
    }

    public function test_realtime_client_points_at_hibira(): void
    {
        $settings = app('hibira')->getSettings();

        $this->assertSame('ws.hibira.io', $settings['host']);
        $this->assertSame(443, (int) $settings['port']);
        $this->assertSame('https', $settings['scheme']);
        $this->assertSame('1001', (string) $settings['app_id']);
        $this->assertSame('pk_test', $settings['auth_key']);
        $this->assertSame(app('hibira'), app(Pusher::class));
        $this->assertSame(app('hibira'), Hibira::getFacadeRoot());
    }

    public function test_another_cluster_host_comes_from_config(): void
    {
        config(['hibira.host' => 'ws-tyo.hibira.io']);

        $this->assertSame('ws-tyo.hibira.io', app('hibira')->getSettings()['host']);
    }

    public function test_connection_can_override_credentials(): void
    {
        config(['broadcasting.connections.other' => ['driver' => 'hibira', 'key' => 'pk_other', 'host' => 'ws-sin.hibira.io']]);

        $broadcaster = Broadcast::connection('other');
        $pusher = (function () {
            return $this->pusher;
        })->call($broadcaster);

        $this->assertSame('pk_other', $pusher->getSettings()['auth_key']);
        $this->assertSame('ws-sin.hibira.io', $pusher->getSettings()['host']);
    }

    public function test_private_channel_auth_is_signed_with_the_app_secret(): void
    {
        $auth = json_decode(app('hibira')->socket_auth('private-orders.1', '123.456'), true)['auth'];

        $this->assertSame('pk_test:'.hash_hmac('sha256', '123.456:private-orders.1', 'sk_test'), $auth);
    }

    public function test_push_client_uses_the_hibira_endpoint(): void
    {
        $push = app('hibira.push');

        $this->assertInstanceOf(PushNotifications::class, $push);
        $this->assertSame($push, app(PushNotifications::class));
        $property = new \ReflectionProperty($push, 'options');
        $property->setAccessible(true);
        $this->assertSame('https://ws.hibira.io', $property->getValue($push)['endpoint']);
    }

    public function test_missing_credentials_do_not_break_booting(): void
    {
        config(['hibira.secret' => null]);

        $this->assertInstanceOf(PusherBroadcaster::class, Broadcast::connection('hibira'));
        $this->assertSame(['secret'], app(\Hibira\Laravel\Hibira::class)->missingRealtimeCredentials());
    }

    public function test_check_names_the_missing_env_variable(): void
    {
        config(['hibira.secret' => '']);

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('hibira:check'));
        $this->assertStringContainsString('HIBIRA_APP_SECRET', \Illuminate\Support\Facades\Artisan::output());
    }
}
