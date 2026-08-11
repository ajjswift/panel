<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Collection;
use Pterodactyl\Models\Permission;
use Pterodactyl\Enum\SubdomainPolicy;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Enum\SubdomainCompatibility;
use Pterodactyl\Services\Dns\Results\SubdomainPolicyResult;

class SubdomainPolicyResolver
{
    public function __construct(private DnsServiceProfileResolver $profileResolver)
    {
    }

    public function resolve(Server $server, ?User $user = null, bool $forExisting = false): SubdomainPolicyResult
    {
        $server->loadMissing(['egg', 'node']);
        $activeEgg = $server->activeGameSlot()->with('egg')->first()?->egg ?: $server->egg;
        $used = $server->managedSubdomains()->whereNull('deleted_at')->count();
        $limit = $server->subdomain_limit;
        ['profile' => $profile] = $this->profileResolver->resolve($server);

        $canView = !$user || $user->can(Permission::ACTION_SUBDOMAIN_READ, $server);
        $canUpdate = !$user || $user->can(Permission::ACTION_SUBDOMAIN_UPDATE, $server);
        $canDelete = !$user || $user->can(Permission::ACTION_SUBDOMAIN_DELETE, $server);
        $canRepair = !$user || $user->can(Permission::ACTION_SUBDOMAIN_REPAIR, $server);

        $domains = $this->eligibleDomains($server, $profile?->id, $activeEgg->id, $forExisting);
        [$entitled, $source] = $this->entitlement($server, $activeEgg);
        [$reasonCode, $reason] = $this->disabledReason($server, $activeEgg, $profile, $domains, $entitled, $forExisting);
        $enabled = is_null($reasonCode);
        if (!$forExisting && $enabled && $used > $limit) {
            [$reasonCode, $reason] = ['over_limit', sprintf('%d hostnames exist, but this server is currently allowed %d.', $used, $limit)];
        } elseif ($enabled && ($limit === 0 || $used >= $limit)) {
            [$reasonCode, $reason] = ['limit_reached', sprintf('This server is using all %d of its available managed subdomains.', $limit)];
        }
        // The server's own entitlement, before the viewer's permissions are
        // considered — a subuser who merely lacks the create permission must
        // not make the section look unavailable for the whole server.
        $allowsCreate = $enabled && is_null($reasonCode);
        $canCreate = $allowsCreate
            && (!$user || $user->can(Permission::ACTION_SUBDOMAIN_CREATE, $server));

        $warnings = [];
        if ($used > $limit) {
            $warnings[] = sprintf('%d managed hostnames exist, but this server is currently allowed %d.', $used, $limit);
        }

        if ($server->status === Server::STATUS_SWITCHING_GAME) {
            $warnings[] = 'DNS changes will be evaluated after the game switch completes.';
        }

        return new SubdomainPolicyResult(
            enabled: $enabled,
            visible: $this->visible($allowsCreate, $used, $reasonCode),
            canView: $canView,
            canCreate: $canCreate,
            canUpdate: $canUpdate,
            canDelete: $canDelete,
            canRepair: $canRepair,
            limit: $limit,
            used: $used,
            policySource: $source,
            serviceProfile: $profile,
            eligibleDomains: $domains,
            disabledReason: $reason,
            disabledReasonCode: $reasonCode,
            warnings: $warnings,
        );
    }

    /**
     * Reasons that describe a temporary condition rather than a deliberate
     * decision about this server. The Domains area stays visible for these so
     * the user can see why it is unavailable and watch it come back.
     */
    private const TRANSIENT_REASONS = ['no_available_domains', 'game_switch_in_progress'];

    /**
     * Whether the Domains area should be offered at all. A server that is
     * deliberately allowed no managed hostnames — limit of zero, an
     * incompatible game, or the feature switched off for it — has nothing to
     * show and nothing to do there, so the client hides the section entirely
     * instead of presenting a dead end. Existing hostnames always keep it
     * visible, even if the allowance was later reduced to zero, so records can
     * still be inspected and removed.
     */
    private function visible(bool $allowsCreate, int $used, ?string $reasonCode): bool
    {
        return $used > 0
            || $allowsCreate
            || in_array($reasonCode, self::TRANSIENT_REASONS, true);
    }

    /**
     * @return Collection<int, ManagedDomain>
     */
    private function eligibleDomains(Server $server, ?int $profileId, int $activeEggId, bool $forExisting): Collection
    {
        $restrictions = $server->subdomain_domain_restrictions ?? [];

        return ManagedDomain::query()
            ->where('enabled', true)
            ->get()
            ->filter(function (ManagedDomain $domain) use ($server, $profileId, $activeEggId, $restrictions, $forExisting) {
                if (!$forExisting && $domain->last_provider_status !== 'healthy') {
                    return false;
                }

                if ($restrictions && !in_array($domain->id, $restrictions, true)) {
                    return false;
                }

                if ($domain->allowed_node_ids && !in_array($server->node_id, $domain->allowed_node_ids, true)) {
                    return false;
                }

                if ($domain->allowed_egg_ids && !in_array($activeEggId, $domain->allowed_egg_ids, true)) {
                    return false;
                }

                if ($domain->allowed_service_profile_ids && !in_array($profileId, $domain->allowed_service_profile_ids, true)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @return array{bool, string}
     */
    private function entitlement(Server $server, Egg $activeEgg): array
    {
        if (
            !$activeEgg->subdomain_server_override_allowed
            && $server->subdomain_policy === SubdomainPolicy::Enabled->value
        ) {
            return [
                $activeEgg->subdomain_default_policy === SubdomainPolicy::Enabled->value,
                'egg_default_override_locked',
            ];
        }

        return match($server->subdomain_policy) {
            SubdomainPolicy::Enabled->value => [true, 'server_override'],
            SubdomainPolicy::Disabled->value => [false, 'server_override'],
            default => [
                $activeEgg->subdomain_default_policy === SubdomainPolicy::Enabled->value,
                'egg_default',
            ],
        };
    }

    /**
     * @return array{?string, ?string}
     */
    private function disabledReason(
        Server $server,
        Egg $activeEgg,
        $profile,
        Collection $domains,
        bool $entitled,
        bool $forExisting,
    ): array {
        if (!config('managed-dns.enabled')) {
            return ['globally_disabled', 'Managed subdomains are currently disabled by an administrator.'];
        }

        if ($server->status === Server::STATUS_SWITCHING_GAME) {
            return ['game_switch_in_progress', 'DNS changes are paused while this server switches games.'];
        }

        if ($activeEgg->subdomain_compatibility !== SubdomainCompatibility::Compatible->value) {
            return ['egg_incompatible', 'The active game configuration does not currently support managed subdomains.'];
        }

        if (!$profile || !$profile->supports_direct_dns) {
            return ['service_not_supported', 'The active service does not support managed direct DNS.'];
        }

        if (!$entitled) {
            return ['server_entitlement_disabled', 'Managed subdomains are not enabled for this server.'];
        }

        if (!$forExisting && $domains->isEmpty()) {
            return ['no_available_domains', 'No approved parent domains are currently available for this server.'];
        }

        return [null, null];
    }
}
