<?php

namespace Pterodactyl\Services\ReverseProxy;

use GuzzleHttp\Client;
use Pterodactyl\Models\Node;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Foundation\Application;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * HTTP client for a node's reverse-proxy agent control API. Speaks the
 * documented /v1 contract: declarative route sync and a health check.
 */
class ReverseProxyAgentClient
{
    public function __construct(private Application $app, private ReverseProxyKeyService $keyService)
    {
    }

    /**
     * Push the full desired set of routes to the agent. This is declarative:
     * the agent reconciles to exactly this list.
     *
     * @param array<int, array<string, mixed>> $routes
     *
     * @throws DaemonConnectionException
     */
    public function syncRoutes(Node $node, array $routes): array
    {
        try {
            $response = $this->client($node)->put('routes', ['json' => ['routes' => array_values($routes)]]);
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return json_decode($response->getBody()->__toString(), true) ?? [];
    }

    /**
     * @throws DaemonConnectionException
     */
    public function health(Node $node): array
    {
        try {
            $response = $this->client($node)->get('health');
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return json_decode($response->getBody()->__toString(), true) ?? [];
    }

    private function client(Node $node): Client
    {
        return new Client([
            'verify' => $this->app->environment('production'),
            'base_uri' => rtrim($node->reverseProxyControlUrl() ?? '', '/') . '/',
            'timeout' => config('pterodactyl.guzzle.timeout'),
            'connect_timeout' => config('pterodactyl.guzzle.connect_timeout'),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->keyService->apiKey($node),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }
}
