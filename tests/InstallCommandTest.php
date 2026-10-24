<?php

namespace Hibira\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class InstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        File::put(base_path('.env.example'), "APP_NAME=Laravel\nBROADCAST_CONNECTION=log\nHIBIRA_HOST=ws-tyo.hibira.io\n");
        File::delete(resource_path('js/echo.js'));
    }

    protected function tearDown(): void
    {
        File::delete(base_path('.env.example'));
        File::delete(resource_path('js/echo.js'));
        parent::tearDown();
    }

    public function test_writes_env_and_echo(): void
    {
        $this->assertSame(0, Artisan::call('hibira:install'));

        $env = File::get(base_path('.env.example'));
        $this->assertStringContainsString("BROADCAST_CONNECTION=hibira\n", $env);
        $this->assertStringNotContainsString('BROADCAST_CONNECTION=log', $env);
        $this->assertStringContainsString("HIBIRA_APP_KEY=\n", $env);
        $this->assertStringContainsString('VITE_HIBIRA_APP_KEY="${HIBIRA_APP_KEY}"', $env);
        $this->assertSame(1, preg_match_all('/^HIBIRA_HOST=/m', $env), 'keeps an existing value');
        $this->assertStringContainsString('ws-tyo.hibira.io', $env);
        $this->assertStringContainsString('wsHost: import.meta.env.VITE_HIBIRA_HOST', File::get(resource_path('js/echo.js')));

        // Running it again changes nothing.
        Artisan::call('hibira:install');
        $this->assertSame($env, File::get(base_path('.env.example')));
    }

    public function test_mix_apps_get_mix_variables(): void
    {
        File::put(base_path('webpack.mix.js'), '');
        File::delete(base_path('.env.example'));
        File::put(base_path('.env.example'), "APP_NAME=Laravel\n");

        try {
            $this->assertSame(0, Artisan::call('hibira:install'));
        } finally {
            File::delete(base_path('webpack.mix.js'));
        }

        $env = File::get(base_path('.env.example'));
        $key = version_compare($this->app->version(), '11.0.0', '>=') ? 'BROADCAST_CONNECTION' : 'BROADCAST_DRIVER';
        $this->assertStringContainsString("$key=hibira\n", $env);
        $this->assertStringContainsString('MIX_HIBIRA_APP_KEY="${HIBIRA_APP_KEY}"', $env);
        $this->assertStringNotContainsString('VITE_', $env);
        $this->assertStringContainsString('process.env.MIX_HIBIRA_HOST', File::get(resource_path('js/echo.js')));
    }

    public function test_keeps_an_existing_echo_file(): void
    {
        if (! File::isDirectory(resource_path('js'))) {
            File::makeDirectory(resource_path('js'), 0755, true);
        }
        File::put(resource_path('js/echo.js'), '// mine');

        $this->assertSame(0, Artisan::call('hibira:install'));
        $this->assertStringContainsString('--force', Artisan::output());
        $this->assertSame('// mine', File::get(resource_path('js/echo.js')));
    }
}
