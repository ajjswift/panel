<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Pterodactyl\Services\Dns\HttpServiceDetector;

class HttpServiceDetectorTest extends TestCase
{
    private function detector(Factory $http): HttpServiceDetector
    {
        return new HttpServiceDetector($http, new Repository(new ArrayStore()), config());
    }

    public function testDetectsGetResponseOnArbitraryHttpPortAndCachesIt(): void
    {
        $http = new Factory();
        $http->fake([
            'http://1.1.1.1:8123/' => Factory::response('', 404),
        ]);
        $detector = $this->detector($http);

        $this->assertSame('http', $detector->detect(['1.1.1.1'], 8123));
        $this->assertSame('http', $detector->detect(['1.1.1.1'], 8123));

        $http->assertSentCount(1);
        $http->assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'http://1.1.1.1:8123/'
            && $request->hasHeader('Range', 'bytes=0-1023'));
    }

    public function testReturnsNullWhenNoCandidateAnswers(): void
    {
        $http = new Factory();
        $http->fake(fn () => Factory::failedConnection());
        $detector = $this->detector($http);

        $this->assertNull($detector->detect(['1.1.1.1'], 25572));
        $http->assertSentCount(1);
    }

    public function testTriesEachCandidateUntilOneAnswers(): void
    {
        $http = new Factory();
        $http->fake([
            'http://10.0.0.5:8080/' => Factory::failedConnection(),
            'http://203.0.113.10:8080/' => Factory::response('', 200),
        ]);
        $detector = $this->detector($http);

        // The private allocation IP is unreachable, so it should fall through to
        // the node's public address and detect the website there.
        $this->assertSame('http', $detector->detect(['10.0.0.5', '203.0.113.10'], 8080));
        $http->assertSentCount(2);
    }

    public function testBracketsIpv6TargetInRequestUrl(): void
    {
        $http = new Factory();
        $http->fake(['*' => Factory::response('', 200)]);
        $detector = $this->detector($http);

        $this->assertSame('http', $detector->detect(['2001:4860:4860::8888'], 8080));
        $http->assertSent(fn (Request $request) => $request->url() === 'http://[2001:4860:4860::8888]:8080/');
    }
}
