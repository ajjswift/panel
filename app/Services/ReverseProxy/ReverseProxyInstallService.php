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

        $email = config('reverse-proxy.letsencrypt_email');
        if (!$email) {
            throw new DisplayException('Set REVERSE_PROXY_LETSENCRYPT_EMAIL in the panel environment before generating the install command; the agent needs it to request certificates.');
        }

        $this->keyService->ensure($node);

        // The installer reads its inputs from the environment, and resolves the
        // correct binary for the node's CPU from the release itself, so we only
        // supply credentials/config here.
        $env = [
            'API_KEY' => $this->keyService->apiKey($node),
            'HOSTNAME' => $hostname,
            'CONTROL_PORT' => (string) $node->reverse_proxy_control_port,
            'PANEL_CALLBACK_URL' => route('api.remote.proxy.status'),
            'NODE_TOKEN' => $this->keyService->callbackToken($node),
            'LETSENCRYPT_EMAIL' => $email,
        ];

        $assignments = [];
        foreach ($env as $key => $value) {
            $assignments[] = $key . '=' . escapeshellarg($value);
        }

        return sprintf(
            'curl -fsSL %s | sudo env %s bash',
            escapeshellarg(config('reverse-proxy.installer_url')),
            implode(' ', $assignments),
        );
    }
}
