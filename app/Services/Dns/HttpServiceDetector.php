<?php

namespace Pterodactyl\Services\Dns;

use Illuminate\Http\Client\Factory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Detects whether a service is serving HTTP by actually connecting to the port
 * — the equivalent of `curl http://host:port/`. It does not rely on a list of
 * eggs, plugins, or conventional web ports. Several candidate hosts are tried
 * (the allocation's own IP, then the node's public address) so detection works
 * whether the panel reaches the service directly or through the node's edge.
 */
class HttpServiceDetector
{
    private const NO_HTTP = 'none';

    public function __construct(
        private Factory $http,
        private Cache $cache,
        private Config $config,
    ) {
    }

    /**
     * Returns 'http' when any candidate host answers as an HTTP server.
     *
     * @param string[] $hosts
     */
    public function detect(array $hosts, int $port): ?string
    {
        foreach ($hosts as $host) {
            $key = sprintf('managed-dns:http-service:%s', hash('sha256', sprintf('%s:%d', $host, $port)));

            if ($this->cache->has($key)) {
                $cached = $this->cache->get($key);
                if (in_array($cached, ['http', 'https'], true)) {
                    return $cached;
                }

                continue;
            }

            $scheme = $this->probe($host, $port);
            $this->cache->put(
                $key,
                $scheme ?? self::NO_HTTP,
                $scheme
                    ? (int) $this->config->get('managed-dns.http_detection.positive_ttl', 300)
                    : (int) $this->config->get('managed-dns.http_detection.negative_ttl', 30),
            );

            if ($scheme) {
                return $scheme;
            }
        }

        return null;
    }

    private function probe(string $target, int $port): ?string
    {
        $host = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? sprintf('[%s]', $target)
            : $target;

        try {
            // Behave like a plain `curl http://host:port/`: GET, no redirects
            // followed, and only pull the first kilobyte so a large page (a map,
            // a file listing) is never read into panel memory.
            $this->http
                ->withOptions([
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'stream' => true,
                ])
                ->connectTimeout((float) $this->config->get('managed-dns.http_detection.connect_timeout', 1))
                ->timeout((float) $this->config->get('managed-dns.http_detection.timeout', 2))
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                    'Range' => 'bytes=0-1023',
                    'User-Agent' => 'Solstice-HTTP-Service-Detection/1.0',
                ])
                ->get(sprintf('http://%s:%d/', $host, $port));

            // Any HTTP response — including a redirect or an error status —
            // proves something is speaking HTTP on this port.
            return 'http';
        } catch (\Throwable) {
            // A connection or protocol failure just means this candidate is not
            // an HTTP server; the caller moves on to the next host/detector.
        }

        return null;
    }
}
