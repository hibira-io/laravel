<?php

namespace Hibira\Laravel\Tests;

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
        $this->artisan('hibira:install')->assertSuccessful();

        $env = File::get(base_path('.env.example'));
        $this->assertStringContainsString("BROADCAST_CONNECTION=hibira\n", $env);
        $this->assertStringNotContainsString('BROADCAST_CONNECTION=log', $env);
        $this->assertStringContainsString("HIBIRA_APP_KEY=\n", $env);
        $this->assertStringContainsString('VITE_HIBIRA_APP_KEY="${HIBIRA_APP_KEY}"', $env);
        $this->assertSame(1, preg_match_all('/^HIBIRA_HOST=/m', $env), 'keeps an existing value');
        $this->assertStringContainsString('ws-tyo.hibira.io', $env);
        $this->assertStringContainsString("wsHost: import.meta.env.VITE_HIBIRA_HOST", File::get(resource_path('js/echo.js')));

        // Running it again changes nothing.
        $before = $env;
        $this->artisan('hibira:install')->assertSuccessful();
        $this->assertSame($before, File::get(base_path('.env.example')));
    }

    public function test_keeps_an_existing_echo_file(): void
    {
        File::ensureDirectoryExists(resource_path('js'));
        File::put(resource_path('js/echo.js'), '// mine');

        $this->artisan('hibira:install')->expectsOutputToContain('--force')->assertSuccessful();
        $this->assertSame('// mine', File::get(resource_path('js/echo.js')));
    }
}
