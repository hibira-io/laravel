<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\HibiraServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HibiraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('broadcasting.default', 'hibira');
        $app['config']->set('hibira.app_id', '1001');
        $app['config']->set('hibira.key', 'pk_test');
        $app['config']->set('hibira.secret', 'sk_test');
        $app['config']->set('hibira.push.instance_id', 'inst-test');
        $app['config']->set('hibira.push.secret_key', str_repeat('A1', 32));
    }
}
