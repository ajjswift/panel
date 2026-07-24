<?php

namespace Pterodactyl\Services\Dns;

use Illuminate\Http\Client\Factory;
use Illuminate\Contracts\Cache\Repository;
use Pterodactyl\Services\Dns\Results\DnsTarget;

/**
 * Detects whether an allocation is serving HTTP without relying on a list of
 * eggs, plugins, or conventional web ports.
 */
class HttpServiceDetector
{
    private const NO_HTTP = 'none';

    public function __construct(
        private Factory $http,
        private Repository $cache,
    ) {
    }

    /**
     * Returns the origin scheme when the target responds as an HTTP server.
     */
    public function detect(DnsTarget $target, int $port): ?string
    {
        $key = sprintf(
            'managed-dns:http-service:%s',
            hash('sha256', sprintf('%s:%s:%d', $target->recordType, $target->value, $port)),
        );

        if ($this->cache->has($key)) {
            $cached = $this->cache->get($key);

            return in_array($cached, ['http', 'https'], true) ? $cached : null;
        }

        $scheme = $this->probe($target->value, $port);
        $ttl = $scheme
            ? (int) config('managed-dns.http_detection.positive_ttl', 300)
            : (int) config('managed-dns.http_detection.negative_ttl', 30);

        $this->cache->put($key, $scheme ?? self::NO_HTTP, $ttl);

        return $scheme;
    }

    private function probe(string $target, int $port): ?string
    {
        $host = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? sprintf('[%s]', $target)
            : $target;

        try {
            // Match a literal `curl http://host:port/`: use GET, let the target
            // provide its normal Host handling, and stream the body so a map or
            // other large page is not downloaded into panel memory.
            $this->http
                ->withOptions([
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'stream' => true,
                ])
                ->connectTimeout((float) config('managed-dns.http_detection.connect_timeout', 1))
                ->timeout((float) config('managed-dns.http_detection.timeout', 2))
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                    'Range' => 'bytes=0-1023',
                    'User-Agent' => 'Solstice-HTTP-Service-Detection/1.0',
                ])
                ->get(sprintf('http://%s:%d/', $host, $port));

            // A response with any HTTP status proves that this is an HTTP
            // service; redirects and errors are still valid web responses.
            return 'http';
        } catch (\Throwable) {
            // A connection or protocol failure lets the next detector try the
            // port before record planning falls back to direct DNS.
        }

        return null;
    }
}
