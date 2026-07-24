<?php

namespace Pterodactyl\Services\ReverseProxy;

use Illuminate\Support\Str;
use Pterodactyl\Models\Node;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Generates and stores the credentials a node's reverse-proxy agent uses.
 *
 * Two secrets exist, mirroring the daemon token pattern:
 *  - api_key: the panel presents this to the agent's control API.
 *  - token:   the agent presents "{token_id}.{token}" on status callbacks; the
 *             public token_id is indexed so the callback can be attributed to a
 *             node without decrypting anything.
 *
 * Secrets are only ever returned in full immediately after (re)generation so
 * they can be shown once in the install command; at rest they are encrypted.
 */
class ReverseProxyKeyService
{
    public function __construct(private Encrypter $encrypter)
    {
    }

    /**
     * (Re)generate all agent credentials for a node and persist them encrypted.
     * The plaintext is recovered on demand via apiKey()/callbackToken().
     */
    public function rotate(Node $node): Node
    {
        $node->forceFill([
            'reverse_proxy_api_key' => $this->encrypter->encrypt(Str::random(64)),
            'reverse_proxy_token_id' => Str::random(16),
            'reverse_proxy_token' => $this->encrypter->encrypt(Str::random(64)),
        ])->save();

        return $node;
    }

    /**
     * Ensure credentials exist, generating them on first use.
     */
    public function ensure(Node $node): Node
    {
        if (empty($node->reverse_proxy_api_key) || empty($node->reverse_proxy_token_id)) {
            return $this->rotate($node);
        }

        return $node;
    }

    public function apiKey(Node $node): string
    {
        return $this->encrypter->decrypt($node->reverse_proxy_api_key);
    }

    /**
     * The full callback credential the agent presents: "{token_id}.{token}".
     */
    public function callbackToken(Node $node): string
    {
        return sprintf('%s.%s', $node->reverse_proxy_token_id, $this->encrypter->decrypt($node->reverse_proxy_token));
    }
}
