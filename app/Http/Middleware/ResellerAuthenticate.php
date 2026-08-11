<?php

namespace Pterodactyl\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ResellerAuthenticate
{
    /**
     * Gate the /reseller area. Access requires ownership of an *enabled*
     * reseller — a disabled reseller keeps its data but loses the panel.
     *
     * Note this deliberately does not let root admins through: superadmins
     * manage resellers from /admin, and letting them fall into the reseller
     * area would leave ResellerContext with no reseller to scope against.
     *
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!$request->user() || !$request->user()->isReseller()) {
            throw new AccessDeniedHttpException();
        }

        return $next($request);
    }
}
