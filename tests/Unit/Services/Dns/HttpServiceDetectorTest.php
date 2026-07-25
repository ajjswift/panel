<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\HttpServiceDetector;

class HttpServiceDetectorTest extends TestCase
{
    public function testDetectsGetResponseOnArbitraryHttpPortAndCachesIt(): void
    {
        $http = new Factory();
        $http->fake([
            'http://1.1.1.1:8123/' => Factory::response('', 404),
        ]);
        $detector = new HttpServiceDetector($http, new Repository(new ArrayStore()));
        $target = new DnsTarget('A', '1.1.1.1', 'allocation_ip');

        $this->assertSame('http', $detector->detect($target, 8123));
        $this->assertSame('http', $detector->detect($target, 8123));

        $http->assertSentCount(1);
        $http->assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'http://1.1.1.1:8123/'
            && $request->hasHeader('Range', 'bytes=0-1023'));
    }

    public function testReturnsNullAfterOneFailedHttpRequest(): void
    {
        $http = new Factory();
        $http->fake(fn () => Factory::failedConnection());
        $detector = new HttpServiceDetector($http, new Repository(new ArrayStore()));

        $this->assertNull(
            $detector->detect(new DnsTarget('A', '1.1.1.1', 'allocation_ip'), 25572),
        );
        $http->assertSentCount(1);
    }

    public function testBracketsIpv6TargetInRequestUrl(): void
    {
        $http = new Factory();
        $http->fake(['*' => Factory::response('', 200)]);
        $detector = new HttpServiceDetector($http, new Repository(new ArrayStore()));

        $this->assertSame(
            'http',
            $detector->detect(new DnsTarget('AAAA', '2001:4860:4860::8888', 'node_ipv6'), 8080),
        );
        $http->assertSent(fn (Request $request) => $request->url() === 'http://[2001:4860:4860::8888]:8080/');
    }
}
