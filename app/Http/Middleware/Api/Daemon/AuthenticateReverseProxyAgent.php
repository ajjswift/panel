<?php

namespace Pterodactyl\Http\Middleware\Api\Daemon;

use Illuminate\Http\Request;
use Pterodactyl\Models\Node;
use Illuminate\Contracts\Encryption\Encrypter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authenticates a reverse-proxy agent status callback. The agent presents
 * "{token_id}.{token}"; the public token_id is looked up and the secret is
 * compared in constant time. Mirrors DaemonAuthenticate but uses the node's
 * dedicated reverse-proxy credentials.
 */
class AuthenticateReverseProxyAgent
{
    public function __construct(private Encrypter $encrypter)
    {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        if (is_null($bearer = $request->bearerToken())) {
            throw new HttpException(401, 'Access to this endpoint must include an Authorization header.', null, ['WWW-Authenticate' => 'Bearer']);
        }

        $parts = explode('.', $bearer, 2);
        if (count($parts) !== 2 || empty($parts[0]) || empty($parts[1])) {
            throw new BadRequestHttpException('The Authorization header provided was not in a valid format.');
        }

        /** @var Node|null $node */
        $node = Node::query()->where('reverse_proxy_token_id', $parts[0])->first();

        if ($node && $node->reverse_proxy_token && hash_equals((string) $this->encrypter->decrypt($node->reverse_proxy_token), $parts[1])) {
            $request->attributes->set('node', $node);

            return $next($request);
        }

        throw new AccessDeniedHttpException('You are not authorized to access this resource.');
    }
}
