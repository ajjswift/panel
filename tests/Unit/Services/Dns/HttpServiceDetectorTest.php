<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use PHPUnit\Framework\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\HttpServiceDetector;

class HttpServiceDetectorTest extends TestCase
{
    public function testDetectsHttpResponseOnArbitraryPortAndCachesIt(): void
    {
        $http = new Factory();
        $http->fake([
            'http://1.1.1.1:8123/' => Factory::response('', 405),
        ]);
        $detector = new HttpServiceDetector($http, new Repository(new ArrayStore()));
        $target = new DnsTarget('A', '1.1.1.1', 'allocation_ip');

        $this->assertSame('http', $detector->detect($target, 8123, 'dynmap.example.com'));
        $this->assertSame('http', $detector->detect($target, 8123, 'dynmap.example.com'));

        $http->assertSentCount(1);
        $http->assertSent(fn (Request $request) => $request->method() === 'HEAD'
            && $request->hasHeader('Host', 'dynmap.example.com'));
    }

    public function testFallsBackToHttpsWhenPlainHttpIsNotSpoken(): void
    {
        $http = new Factory();
        $http->fake(function (Request $request) {
            return str_starts_with($request->url(), 'http://')
                ? Factory::failedConnection()
                : Factory::response('', 200);
        });
        $detector = new HttpServiceDetector($http, new Repository(new ArrayStore()));

        $this->assertSame(
            'https',
            $detector->detect(new DnsTarget('A', '1.1.1.1', 'allocation_ip'), 8443, 'map.example.com'),
        );
        $http->assertSentCount(2);
    }

    public function testReturnsNullWhenNeitherHttpSchemeResponds(): void
    {
        $http = new Factory();
        $http->fake(fn () => Factory::failedConnection());
        $detector = new HttpServiceDetector($http, new Repository(new ArrayStore()));

        $this->assertNull(
            $detector->detect(new DnsTarget('A', '1.1.1.1', 'allocation_ip'), 25572, 'play.example.com'),
        );
        $http->assertSentCount(2);
    }
}
