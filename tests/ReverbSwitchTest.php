<?php

namespace Hibira\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ReverbSwitchTest extends TestCase
{
    /** @var list<string> */
    protected $paths = [
        '.env', '.env.example', 'package.json',
        'resources/js/echo.js', 'resources/js/app.tsx', 'resources/js/app.ts', 'resources/js/app.js',
        'resources/js/hibira-echo.ts', 'resources/js/hibira-echo.js',
    ];

    /** @var array<string, string|null> */
    protected $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->paths as $path) {
            $this->saved[$path] = File::exists(base_path($path)) ? File::get(base_path($path)) : null;
            File::delete(base_path($path));
        }
        File::makeDirectory(resource_path('js'), 0755, true, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $path => $contents) {
            if ($contents === null) {
                File::delete(base_path($path));
            } else {
                File::put(base_path($path), $contents);
            }
        }
        parent::tearDown();
    }

    /** The .env php artisan install:broadcasting --reverb writes. */
    protected function reverbEnv(): string
    {
        return "APP_NAME=Laravel\nBROADCAST_CONNECTION=reverb\n\nREVERB_APP_ID=1\nREVERB_APP_KEY=abc\nREVERB_APP_SECRET=def\nREVERB_HOST=\"localhost\"\nREVERB_PORT=8080\nREVERB_SCHEME=http\n\nVITE_REVERB_APP_KEY=\"\${REVERB_APP_KEY}\"\nVITE_REVERB_HOST=\"\${REVERB_HOST}\"\n";
    }

    public function test_react_starter_kit_from_reverb(): void
    {
        File::put(base_path('.env'), $this->reverbEnv());
        File::put(base_path('package.json'), json_encode(['dependencies' => ['react' => '^19.0.0', '@laravel/echo-react' => '^2.2.0']]));
        // What install:broadcasting injects into the React starter kit.
        File::put(resource_path('js/app.tsx'), "import '../css/app.css';\n\nimport { createInertiaApp } from '@inertiajs/react';\nimport { createRoot } from 'react-dom/client';\nimport { configureEcho } from '@laravel/echo-react';\n\nconfigureEcho({\n    broadcaster: 'reverb',\n});\n\ncreateInertiaApp({});\n");

        $this->assertSame(0, Artisan::call('hibira:install', ['--from-reverb' => true]));
        $output = Artisan::output();

        $app = File::get(resource_path('js/app.tsx'));
        $this->assertStringContainsString("import { configureEcho } from '@laravel/echo-react';\nimport { hibiraEcho } from './hibira-echo';\n", $app);
        $this->assertStringContainsString("\nconfigureEcho(hibiraEcho);\n\ncreateInertiaApp({});", $app);
        $this->assertStringNotContainsString('reverb', $app);
        $this->assertStringContainsString('Pusher: HibiraPusher', File::get(resource_path('js/hibira-echo.ts')));
        $this->assertFalse(File::exists(resource_path('js/echo.js')));

        $env = File::get(base_path('.env'));
        $this->assertStringContainsString("BROADCAST_CONNECTION=hibira\n", $env);
        $this->assertStringContainsString("# REVERB_APP_KEY=abc\n", $env);
        $this->assertStringContainsString("# VITE_REVERB_HOST=", $env);
        $this->assertSame(0, preg_match('/^(VITE_)?REVERB_/m', $env));
        $this->assertStringContainsString('VITE_HIBIRA_APP_KEY="${HIBIRA_APP_KEY}"', $env);
        $this->assertStringContainsString('npm install @laravel/echo-react pusher-js', $output);
        $this->assertStringContainsString('reverb:start', $output);

        // Running it again changes nothing.
        Artisan::call('hibira:install', ['--from-reverb' => true]);
        $this->assertSame($app, File::get(resource_path('js/app.tsx')));
        $this->assertSame($env, File::get(base_path('.env')));
    }

    public function test_vue_app_without_echo_gets_configure_echo(): void
    {
        File::put(base_path('package.json'), json_encode(['dependencies' => ['vue' => '^3.5.0'], 'devDependencies' => ['@laravel/echo-vue' => '^2.2.0']]));
        File::put(resource_path('js/app.ts'), "import '../css/app.css';\nimport { createApp } from 'vue';\n\ncreateApp({}).mount('#app');\n");

        $this->assertSame(0, Artisan::call('hibira:install'));

        $app = File::get(resource_path('js/app.ts'));
        $this->assertStringContainsString("import { createApp } from 'vue';\nimport { configureEcho } from '@laravel/echo-vue';\nimport { hibiraEcho } from './hibira-echo';\n\nconfigureEcho(hibiraEcho);\n", $app);
        $this->assertTrue(File::exists(resource_path('js/hibira-echo.ts')));
        $this->assertStringContainsString('npm install @laravel/echo-vue pusher-js', Artisan::output());
    }

    public function test_javascript_app_gets_the_javascript_client(): void
    {
        File::put(base_path('package.json'), json_encode(['dependencies' => ['react' => '^19.0.0']]));
        File::put(resource_path('js/app.jsx'), "import { createRoot } from 'react-dom/client';\n");

        try {
            $this->assertSame(0, Artisan::call('hibira:install'));
            $this->assertTrue(File::exists(resource_path('js/hibira-echo.js')));
            $this->assertStringNotContainsString(' as const', File::get(resource_path('js/hibira-echo.js')));
            $this->assertStringContainsString("from '@laravel/echo-react';", File::get(resource_path('js/app.jsx')));
        } finally {
            File::delete(resource_path('js/app.jsx'));
        }
    }

    public function test_classic_reverb_echo_js_is_replaced_only_with_the_flag(): void
    {
        $reverb = "window.Echo = new Echo({\n    broadcaster: 'reverb',\n    key: import.meta.env.VITE_REVERB_APP_KEY,\n});\n";
        File::put(base_path('.env'), $this->reverbEnv());
        File::put(resource_path('js/echo.js'), $reverb);

        $this->assertSame(0, Artisan::call('hibira:install'));
        $this->assertSame($reverb, File::get(resource_path('js/echo.js')));
        $this->assertStringContainsString('--from-reverb', Artisan::output());
        $this->assertStringContainsString("\nREVERB_APP_KEY=abc\n", File::get(base_path('.env')), 'kept without the flag');

        $this->assertSame(0, Artisan::call('hibira:install', ['--from-reverb' => true]));
        $this->assertStringContainsString('VITE_HIBIRA_APP_KEY', File::get(resource_path('js/echo.js')));
        $this->assertFalse(File::exists(resource_path('js/hibira-echo.js')));
    }

    public function test_doctor_flags_an_echo_client_still_on_reverb(): void
    {
        File::put(base_path('.env'), "VITE_HIBIRA_APP_KEY=pk_test\n");
        File::put(resource_path('js/app.ts'), "configureEcho({\n    broadcaster: \"reverb\",\n});\n");

        Artisan::call('hibira:doctor', ['--offline' => true]);

        $this->assertStringContainsString('resources/js/app.ts still connects Echo to Reverb', Artisan::output());
    }
}
