<?php

namespace Hibira\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\File;

class DoctorCommandTest extends TestCase
{
    /** @var array<string, string|null> */
    protected $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['.env', 'package.json', 'resources/js/echo.js', 'app/Ai/Agents/SearchAgent.php'] as $path) {
            $this->saved[$path] = File::exists(base_path($path)) ? File::get(base_path($path)) : null;
        }
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
        File::deleteDirectory(base_path('app/Ai'));
        parent::tearDown();
    }

    protected function doctor(): array
    {
        $code = Artisan::call('hibira:doctor', ['--offline' => true]);

        return [$code, Artisan::output()];
    }

    public function test_a_good_setup_passes(): void
    {
        Broadcast::routes();
        config(['queue.default' => 'redis']);
        File::put(base_path('.env'), "VITE_HIBIRA_APP_KEY=pk_test\n");
        File::makeDirectory(resource_path('js'), 0755, true, true);
        File::copy(__DIR__.'/../stubs/echo.js', resource_path('js/echo.js'));
        File::put(base_path('package.json'), json_encode(['dependencies' => ['@hibira-io/react' => '^0.2.0']]));

        list($code, $output) = $this->doctor();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Broadcast connection: hibira', $output);
        $this->assertStringContainsString('joins chunked events', $output);
        $this->assertStringContainsString('No problems', $output);
    }

    public function test_problems_are_named_with_a_fix(): void
    {
        config(['broadcasting.default' => 'log', 'queue.default' => 'sync']);
        File::put(base_path('.env'), "APP_NAME=Laravel\n");
        File::makeDirectory(resource_path('js'), 0755, true, true);
        File::put(resource_path('js/echo.js'), "window.Echo = new Echo({ broadcaster: 'pusher' });\n");
        File::put(base_path('package.json'), json_encode(['dependencies' => ['@hibira-io/vue' => '^0.1.0']]));
        File::makeDirectory(base_path('app/Ai/Agents'), 0755, true, true);
        File::put(base_path('app/Ai/Agents/SearchAgent.php'), "<?php\n#[WithoutBroadcasting(ToolResult::class)]\nclass SearchAgent {}\n");

        list($code, $output) = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Set BROADCAST_CONNECTION=hibira', $output);
        $this->assertStringContainsString('Private channel auth route: missing', $output);
        $this->assertStringContainsString('Queue connection: sync', $output);
        $this->assertStringContainsString('VITE_HIBIRA_APP_KEY is missing', $output);
        $this->assertStringContainsString('does not join chunked events', $output);
        if (class_exists('Laravel\Ai\Ai')) {
            $this->assertStringContainsString('@hibira-io/vue ^0.1.0 has no useAgentStream()', $output);
            $this->assertStringContainsString('app/Ai/Agents/SearchAgent.php skips broadcasting', $output);
        }
    }
}
