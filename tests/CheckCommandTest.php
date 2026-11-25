<?php

namespace Hibira\Laravel\Tests;

use Hibira\Laravel\Support\OriginProbe;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class CheckCommandTest extends TestCase
{
    /** @var FakeOriginProbe */
    protected $probe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hibira.push.instance_id' => null, 'app.url' => 'http://localhost:8000']);
        $this->app->instance('hibira', new FakeChannelsApi());
        $this->probe = new FakeOriginProbe();
        $this->app->instance('hibira.origin-probe', $this->probe);
    }

    protected function check(): array
    {
        $code = Artisan::call('hibira:check');

        return [$code, Artisan::output()];
    }

    public function test_app_url_and_its_loopback_twin_are_checked(): void
    {
        list($code, $output) = $this->check();

        $this->assertSame(0, $code, $output);
        $this->assertSame(['http://localhost:8000', 'http://127.0.0.1:8000'], $this->probe->checked);
        $this->assertStringContainsString('Allowed origins: http://localhost:8000 OK', $output);
        $this->assertStringContainsString('Allowed origins: http://127.0.0.1:8000 OK', $output);
    }

    public function test_a_refused_app_url_fails_with_the_fix(): void
    {
        $this->probe->refuse['http://localhost:8000'] = 4009;

        list($code, $output) = $this->check();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Add http://localhost:8000 (APP_URL) to Allowed origins', $output);
    }

    public function test_a_refused_loopback_twin_only_warns(): void
    {
        $this->probe->refuse['http://127.0.0.1:8000'] = 4009;

        list($code, $output) = $this->check();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('http://127.0.0.1:8000 can\'t connect', $output);
        $this->assertStringContainsString('Browsers treat it as a different site from http://localhost:8000', $output);
    }

    public function test_a_wrong_key_is_named(): void
    {
        $this->probe->refuse['http://localhost:8000'] = 4001;

        list($code, $output) = $this->check();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Check HIBIRA_APP_KEY', $output);
    }

    public function test_an_unreachable_host_only_warns(): void
    {
        $this->probe->down = true;

        list($code, $output) = $this->check();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Allowed origins: not checked (could not connect)', $output);
    }

    public function test_a_public_app_url_has_no_twin(): void
    {
        config(['app.url' => 'https://shop.example.com/']);

        list($code, $output) = $this->check();

        $this->assertSame(0, $code, $output);
        $this->assertSame(['https://shop.example.com'], $this->probe->checked);
    }

    public function test_frames_are_read_and_interpreted(): void
    {
        $established = '{"event":"pusher:connection_established","data":"{\"socket_id\":\"1.2\"}"}';
        $this->assertTrue(OriginProbe::interpret(OriginProbe::readFrame($this->stream("\x81".chr(strlen($established)).$established)))['allowed']);

        $error = json_encode(['event' => 'pusher:error', 'data' => ['code' => 4009, 'message' => 'Origin not allowed']]);
        $long = $error.str_repeat(' ', 200);
        $result = OriginProbe::interpret(OriginProbe::readFrame($this->stream("\x81\x7e".pack('n', strlen($long)).$long)));
        $this->assertSame(['allowed' => false, 'code' => 4009, 'message' => 'Origin not allowed'], $result);

        $result = OriginProbe::interpret(OriginProbe::readFrame($this->stream("\x88\x08".pack('n', 4001).'nope!x')));
        $this->assertSame(4001, $result['code']);
        $this->assertFalse($result['allowed']);
    }

    public function test_origins_and_loopback_twins(): void
    {
        $this->assertSame('http://localhost:8000', OriginProbe::originOf('http://LocalHost:8000/support?x=1'));
        $this->assertSame('https://example.com', OriginProbe::originOf('https://example.com'));
        $this->assertNull(OriginProbe::originOf(''));
        $this->assertSame('http://127.0.0.1:8000', OriginProbe::loopbackTwin('http://localhost:8000'));
        $this->assertSame('http://localhost', OriginProbe::loopbackTwin('http://127.0.0.1'));
        $this->assertNull(OriginProbe::loopbackTwin('https://example.com'));
    }

    /**
     * @return resource
     */
    protected function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}

class FakeChannelsApi
{
    public function get($path)
    {
        return ['status' => 200, 'body' => '{"channels":{}}'];
    }
}

class FakeOriginProbe
{
    /** @var string[] */
    public $checked = [];

    /** @var array<string, int> */
    public $refuse = [];

    /** @var bool */
    public $down = false;

    public function check($origin)
    {
        if ($this->down) {
            throw new RuntimeException('could not connect');
        }
        $this->checked[] = $origin;

        return isset($this->refuse[$origin])
            ? ['allowed' => false, 'code' => $this->refuse[$origin], 'message' => 'refused']
            : ['allowed' => true, 'code' => null, 'message' => 'connected'];
    }
}
