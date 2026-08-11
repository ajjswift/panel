<?php

namespace Pterodactyl\Services\Resellers;

use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Reseller;
use Pterodactyl\Models\ResellerDomain;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Decides whose branding a request should render with.
 *
 * Resolution order matters: the request Host is checked *first* so that a
 * visitor who is not logged in — sitting on the login page of a reseller's own
 * domain — still sees that reseller's identity.
 */
class ResellerBrandingResolver
{
    private const CACHE_TTL = 300;

    private ?ResellerBranding $resolved = null;

    public function __construct(
        private CacheRepository $cache,
        private ColorRampGenerator $generator,
    ) {
    }

    public function resolve(Request $request): ResellerBranding
    {
        return $this->resolved ??= $this->determine($request);
    }

    private function determine(Request $request): ResellerBranding
    {
        $reseller = $this->fromHost($request->getHost()) ?? $this->fromUser($request->user());

        if (is_null($reseller) || !$reseller->enabled || !$reseller->hasBranding()) {
            return ResellerBranding::none();
        }

        return ResellerBranding::make($reseller, $this->generator);
    }

    /**
     * Only *verified* domains are honoured. Without that check, adding someone
     * else's hostname would be enough to hijack their branding.
     */
    private function fromHost(string $host): ?Reseller
    {
        $id = $this->cache->remember(
            'reseller.branding.host.' . strtolower($host),
            self::CACHE_TTL,
            fn () => ResellerDomain::query()
                ->whereRaw('LOWER(hostname) = ?', [strtolower($host)])
                ->whereNotNull('verified_at')
                ->value('reseller_id') ?? 0
        );

        return $id ? Reseller::query()->find($id) : null;
    }

    private function fromUser(?User $user): ?Reseller
    {
        if (is_null($user)) {
            return null;
        }

        // A reseller sees its own branding; a tenant sees the branding of the
        // reseller that manages it.
        return $user->reseller ?? $user->parentReseller;
    }

    /**
     * Drop the host lookup for a reseller's domains. Called whenever a domain is
     * added, verified or removed.
     */
    public function forgetHost(string $host): void
    {
        $this->cache->forget('reseller.branding.host.' . strtolower($host));
    }
}
