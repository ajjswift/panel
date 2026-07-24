<?php

namespace Pterodactyl\Services\ReverseProxy;

use Pterodactyl\Models\Node;
use Pterodactyl\Exceptions\DisplayException;

/**
 * Produces the one-command install line shown in the admin node screen. Running
 * it on the node downloads the agent installer and configures it with the
 * node's own credentials.
 */
class ReverseProxyInstallService
{
    public function __construct(private ReverseProxyKeyService $keyService)
    {
    }

    /**
     * @throws DisplayException
     */
    public function command(Node $node): string
    {
        $hostname = $node->reverseProxyHostname();
        if (!$hostname) {
            throw new DisplayException('Choose a base domain for this node before generating the install command.');
        }

        $this->keyService->ensure($node);
        $apiKey = $this->keyService->apiKey($node);
        $token = $this->keyService->callbackToken($node);

        $args = [
            '--api-key ' . escapeshellarg($apiKey),
            '--hostname ' . escapeshellarg($hostname),
            '--control-port ' . escapeshellarg((string) $node->reverse_proxy_control_port),
            '--panel-callback-url ' . escapeshellarg(route('api.remote.proxy.status')),
            '--node-token ' . escapeshellarg($token),
        ];

        if ($email = config('reverse-proxy.letsencrypt_email')) {
            $args[] = '--email ' . escapeshellarg($email);
        }

        return sprintf(
            'curl -fsSL %s | sudo bash -s -- %s',
            escapeshellarg(config('reverse-proxy.installer_url')),
            implode(' ', $args),
        );
    }
}
