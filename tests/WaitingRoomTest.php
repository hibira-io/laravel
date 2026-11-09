<?php

namespace Hibira\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Hibira\Laravel\Facades\Hibira;
use Hibira\Laravel\Facades\WaitingRoom;
use Hibira\Laravel\WaitingRooms\Pass;
use Hibira\Laravel\WaitingRooms\WaitingRoomException;
use Hibira\Laravel\WaitingRooms\WaitingRooms;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class WaitingRoomTest extends TestCase
{
    /** @var array */
    protected $history = [];

    /** @var array */
    protected $responses = [];

    /** @var bool */
    protected $faked = false;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.url', 'https://shop.test');

        $app['router']->middleware('hibira.waiting:presale')->group(function ($router) {
            $router->get('/drop', function () {
                return 'number '.request()->attributes->get('hibira_pass')->number;
            });
            $router->post('/checkout', function () {
                return 'paid';
            });
        });
    }

    protected function pass(array $claims = [], string $secret = 'sk_test'): string
    {
        $payload = WaitingRooms::base64url(json_encode($claims + [
            'v' => 1, 'app' => '1001', 'room' => 'presale', 'n' => 42, 'tid' => 'abc', 'iat' => time(), 'exp' => time() + 600,
        ]));

        return $payload.'.'.WaitingRooms::base64url(hash_hmac('sha256', 'hibira-waitroom-pass.'.$payload, $secret, true));
    }

    /**
     * Answers API calls with $responses ([status, body] pairs) and records
     * them as [method, path, query, body], through Http::fake() where
     * Laravel has it and a Guzzle mock otherwise.
     */
    protected function fakeHibira(array $responses): void
    {
        $this->history = [];
        $this->responses = $responses;
        $record = function ($method, $url, $body) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->history[] = [$method, parse_url($url, PHP_URL_PATH), $query, $body];
        };

        if (class_exists(Http::class) && method_exists(PendingRequest::class, 'withBody')) {
            $this->app->forgetInstance(WaitingRooms::class);
            if ($this->faked) {
                return;
            }
            $this->faked = true;
            Http::fake(function ($request) use ($record) {
                $record($request->method(), $request->url(), $request->body());
                [$status, $body] = array_shift($this->responses);

                return Http::response($body, $status);
            });
        } else {
            $handler = function ($request) use ($record) {
                $record($request->getMethod(), (string) $request->getUri(), (string) $request->getBody());
                [$status, $body] = array_shift($this->responses);

                return new \GuzzleHttp\Promise\FulfilledPromise(new Response($status, [], $body));
            };
            $this->app->instance(WaitingRooms::class, new WaitingRooms($this->app['config']['hibira'], new Client(['handler' => HandlerStack::create($handler)])));
        }
        WaitingRoom::clearResolvedInstances();
    }

    protected function room(array $fields = []): array
    {
        return [200, json_encode($fields + [
            'room' => 'presale', 'title' => 'Winter presale', 'state' => 'open', 'admitted' => 10, 'issued' => 50,
            'waiting' => 40, 'admit_per_minute' => 300, 'channel' => 'cache-waitroom-presale', 'return_urls' => ['https://shop.test/'],
        ])];
    }

    public function test_verifies_a_pass_locally()
    {
        $rooms = app(WaitingRooms::class);

        $pass = $rooms->verifyPass($this->pass(), 'presale');
        $this->assertInstanceOf(Pass::class, $pass);
        $this->assertSame(42, $pass->number);
        $this->assertSame('abc', $pass->ticketId);

        $this->assertNull($rooms->verifyPass($this->pass(), 'other'));
        $this->assertNull($rooms->verifyPass($this->pass(['exp' => time() - 1])));
        $this->assertNull($rooms->verifyPass($this->pass(['app' => '999'])));
        $this->assertNull($rooms->verifyPass($this->pass([], 'wrong')));
        $this->assertNull($rooms->verifyPass('garbage'));
        $this->assertNull($rooms->verifyPass(null));
    }

    public function test_a_ticket_is_never_a_pass()
    {
        $payload = WaitingRooms::base64url(json_encode(['v' => 1, 'app' => '1001', 'room' => 'presale', 'n' => 1, 'tid' => 'x', 'iat' => time(), 'exp' => time() + 60]));
        $ticket = $payload.'.'.WaitingRooms::base64url(hash_hmac('sha256', 'hibira-waitroom-ticket.'.$payload, 'sk_test', true));

        $this->assertNull(app(WaitingRooms::class)->verifyPass($ticket));
    }

    public function test_accepts_the_previous_secret_during_a_rotation()
    {
        config(['hibira.waiting_rooms.previous_secret' => 'sk_old']);
        $this->app->forgetInstance(WaitingRooms::class);

        $this->assertNotNull(app(WaitingRooms::class)->verifyPass($this->pass([], 'sk_old')));
    }

    public function test_hosted_page_url()
    {
        $this->assertSame(
            'https://ws.hibira.io/wait/pk_test/presale?return=https%3A%2F%2Fshop.test%2Fdrop&lang=ja',
            Hibira::waitingRoom('presale')->url('https://shop.test/drop', 'ja')
        );

        config(['hibira.scheme' => 'http', 'hibira.host' => '127.0.0.1', 'hibira.port' => 6001]);
        $this->app->forgetInstance(WaitingRooms::class);
        $this->assertSame('http://127.0.0.1:6001/wait/pk_test/presale', WaitingRoom::room('presale')->url());
    }

    public function test_rejects_bad_room_names()
    {
        $this->expectException(WaitingRoomException::class);
        WaitingRoom::room('no spaces');
    }

    public function test_middleware_sends_visitors_without_a_pass_to_the_waiting_page()
    {
        $this->app->setLocale('zh_TW');

        $this->get('/drop?seat=a1')
            ->assertRedirect('https://ws.hibira.io/wait/pk_test/presale?return='.rawurlencode('http://localhost/drop?seat=a1').'&lang=zh-TW');
    }

    public function test_middleware_keeps_the_pass_in_a_cookie_and_cleans_the_address()
    {
        $pass = $this->pass();

        $response = $this->get('/drop?seat=a1&hibira_pass='.$pass);
        $response->assertRedirect('http://localhost/drop?seat=a1');
        $response->assertCookie('hibira_wr_presale', $pass, false);

        $this->call('GET', '/drop', [], ['hibira_wr_presale' => $pass])
            ->assertStatus(200)
            ->assertSee('number 42');
    }

    public function test_middleware_ignores_a_bad_pass()
    {
        $this->get('/drop?hibira_pass='.$this->pass(['room' => 'other']))
            ->assertRedirect('https://ws.hibira.io/wait/pk_test/presale?return='.rawurlencode('http://localhost/drop').'&lang=en');
    }

    public function test_middleware_refuses_other_methods_without_a_pass()
    {
        $this->postJson('/checkout')->assertStatus(403)->assertJson(['error' => 'waiting_room']);

        $this->call('POST', '/checkout', [], ['hibira_wr_presale' => $this->pass()])->assertStatus(200);
    }

    public function test_middleware_can_be_turned_off()
    {
        config(['hibira.waiting_rooms.enabled' => false]);
        $this->app->forgetInstance(WaitingRooms::class);

        $this->post('/checkout')->assertStatus(200);
    }

    public function test_signs_api_calls()
    {
        $this->fakeHibira([$this->room(['state' => 'paused'])]);

        $room = Hibira::waitingRoom('presale')->pause();
        $this->assertSame('paused', $room['state']);

        [$method, $path, $query, $body] = $this->history[0];
        $this->assertSame('POST', $method);
        $this->assertSame('/apps/1001/waiting_rooms/presale', $path);
        $this->assertSame('{"state":"paused"}', $body);

        $signature = $query['auth_signature'];
        unset($query['auth_signature']);
        ksort($query);
        $this->assertSame(md5('{"state":"paused"}'), $query['body_md5']);
        $this->assertSame(
            hash_hmac('sha256', "POST\n/apps/1001/waiting_rooms/presale\n".urldecode(http_build_query($query)), 'sk_test'),
            $signature
        );
    }

    public function test_works_with_a_guzzle_client()
    {
        $seen = [];
        $handler = function ($request) use (&$seen) {
            $seen[] = $request;

            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, [], '{"room":"presale","admitted":7}'));
        };
        $rooms = new WaitingRooms(config('hibira'), new Client(['handler' => HandlerStack::create($handler)]));

        $this->assertSame(7, $rooms->room('presale')->admit(7)['admitted']);
        $this->assertSame('/apps/1001/waiting_rooms/presale/admit', $seen[0]->getUri()->getPath());
        $this->assertSame('{"count":7}', (string) $seen[0]->getBody());
        $this->assertSame('application/json', $seen[0]->getHeaderLine('Content-Type'));
    }

    public function test_room_actions()
    {
        $this->fakeHibira([$this->room(), $this->room(['admitted' => 510]), $this->room(['notice' => 'Restock at 3pm']), [200, '{"deleted":true}']]);

        $room = WaitingRoom::room('presale');
        $room->create(300, ['title' => 'Winter presale', 'starts_at' => null]);
        $this->assertSame(510, $room->admit(500)['admitted']);
        $this->assertSame('Restock at 3pm', $room->notice('Restock at 3pm')['notice']);
        $room->delete();

        $this->assertSame('{"admit_per_minute":300,"title":"Winter presale","starts_at":""}', $this->history[0][3]);
        $this->assertSame('/apps/1001/waiting_rooms/presale/admit', $this->history[1][1]);
        $this->assertSame('{"count":500}', $this->history[1][3]);
        $this->assertSame('{"notice":"Restock at 3pm"}', $this->history[2][3]);
        $this->assertSame('DELETE', $this->history[3][0]);
        $this->assertSame('', $this->history[3][3]);
        $this->assertArrayNotHasKey('body_md5', $this->history[3][2]);
    }

    public function test_lists_rooms_and_falls_back_on_old_servers()
    {
        $this->fakeHibira([
            [200, json_encode(['rooms' => [['room' => 'a'], ['room' => 'b']]])],
            [404, '{"error":"not found"}'],
        ]);

        $this->assertCount(2, WaitingRoom::all());
        $this->assertNull(WaitingRoom::all());
    }

    public function test_errors_carry_the_server_message()
    {
        $this->fakeHibira([[404, '{"error":"waiting room not found"}']]);

        $this->assertNull(WaitingRoom::room('gone')->find());

        $this->fakeHibira([[400, '{"error":"admit_per_minute must be 1 to 600000"}']]);
        try {
            WaitingRoom::room('presale')->rate(0);
            $this->fail('expected an exception');
        } catch (WaitingRoomException $e) {
            $this->assertSame(400, $e->status, $e->getMessage());
            $this->assertSame('admit_per_minute must be 1 to 600000', $e->getMessage());
        }
    }

    public function test_artisan_command()
    {
        $this->fakeHibira([$this->room(), $this->room(['state' => 'closed']), [200, json_encode(['rooms' => [['room' => 'presale', 'state' => 'open', 'waiting' => 40, 'admitted' => 10, 'admit_per_minute' => 300]]])]]);

        $this->assertSame(0, Artisan::call('hibira:waiting-room', ['room' => 'presale', 'action' => 'create', '--rate' => 300, '--title' => 'Winter presale']));
        $output = Artisan::output();
        $this->assertStringContainsString('Waiting room "presale" is ready.', $output);
        $this->assertStringContainsString("Route::middleware('hibira.waiting:presale')", $output);
        $this->assertSame(
            '{"admit_per_minute":300,"title":"Winter presale","return_urls":["https:\\/\\/shop.test\\/"]}',
            $this->history[0][3]
        );

        $this->assertSame(0, Artisan::call('hibira:waiting-room', ['room' => 'presale', 'action' => 'close']));
        $this->assertStringContainsString('"presale" is now closed.', Artisan::output());

        $this->assertSame(0, Artisan::call('hibira:waiting-room'));
        $this->assertStringContainsString('presale', Artisan::output());

        $this->assertSame(1, Artisan::call('hibira:waiting-room', ['room' => 'presale', 'action' => 'create']));
        $this->assertStringContainsString('--rate is needed', Artisan::output());
    }
}
