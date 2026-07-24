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
    public function detect(DnsTarget $target, int $port, string $hostname): ?string
    {
        $key = sprintf(
            'managed-dns:http-service:%s',
            hash('sha256', sprintf('%s:%s:%d', $target->recordType, $target->value, $port)),
        );

        if ($this->cache->has($key)) {
            $cached = $this->cache->get($key);

            return in_array($cached, ['http', 'https'], true) ? $cached : null;
        }

        $scheme = $this->probe($target->value, $port, $hostname);
        $ttl = $scheme
            ? (int) config('managed-dns.http_detection.positive_ttl', 300)
            : (int) config('managed-dns.http_detection.negative_ttl', 30);

        $this->cache->put($key, $scheme ?? self::NO_HTTP, $ttl);

        return $scheme;
    }

    private function probe(string $target, int $port, string $hostname): ?string
    {
        $host = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? sprintf('[%s]', $target)
            : $target;

        foreach (['http', 'https'] as $scheme) {
            try {
                // A response with any status code proves that the port speaks
                // HTTP. Redirects are deliberately not followed because the
                // origin response is all that matters.
                $this->http
                    ->withOptions([
                        'allow_redirects' => false,
                        'http_errors' => false,
                        // Origin services commonly use a self-signed
                        // certificate; the node proxy will terminate public TLS.
                        'verify' => false,
                    ])
                    ->connectTimeout((float) config('managed-dns.http_detection.connect_timeout', 1))
                    ->timeout((float) config('managed-dns.http_detection.timeout', 2))
                    ->withHeaders([
                        'Host' => $hostname,
                        'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                        'User-Agent' => 'Solstice-HTTP-Service-Detection/1.0',
                    ])
                    ->head(sprintf('%s://%s:%d/', $scheme, $host, $port));

                return $scheme;
            } catch (\Throwable) {
                // A connection or protocol failure means this scheme did not
                // produce an HTTP response. Try the other scheme before giving
                // up and leaving the allocation on direct DNS.
            }
        }

        return null;
    }
}
