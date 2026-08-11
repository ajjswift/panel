<?php

namespace Pterodactyl\Tests\Integration\Services\Dns;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Services\Dns\SubdomainPolicyResolver;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

/**
 * The Domains section is hidden entirely for servers that are deliberately
 * allowed no managed hostnames, rather than showing an empty page behind a
 * "using all 0 of its available managed subdomains" warning.
 */
class SubdomainPolicyVisibilityTest extends IntegrationTestCase
{
    private ManagedDomain $parentDomain;

    public function testHiddenWhenTheServerIsAllowedZeroHostnames(): void
    {
        $server = $this->eligibleServer(limit: 0);

        $policy = $this->resolver()->resolve($server);

        $this->assertFalse($policy->visible);
        $this->assertSame('limit_reached', $policy->disabledReasonCode);
    }

    public function testVisibleWhenTheServerIsAllowedAtLeastOneHostname(): void
    {
        $server = $this->eligibleServer(limit: 1);

        $this->assertTrue($this->resolver()->resolve($server)->visible);
    }

    public function testVisibleWhenTheAllowanceIsUsedUp(): void
    {
        $server = $this->eligibleServer(limit: 1);
        $this->hostname($server);

        $policy = $this->resolver()->resolve($server);

        $this->assertTrue($policy->visible);
        $this->assertSame('limit_reached', $policy->disabledReasonCode);
    }

    /**
     * Reducing an allowance to zero must not hide hostnames that already
     * exist — the owner still needs to see and remove them.
     */
    public function testVisibleWhenTheAllowanceWasReducedBelowExistingHostnames(): void
    {
        $server = $this->eligibleServer(limit: 0);
        $this->hostname($server);

        $policy = $this->resolver()->resolve($server);

        $this->assertTrue($policy->visible);
        $this->assertSame('over_limit', $policy->disabledReasonCode);
    }

    public function testHiddenWhenTheActiveGameDoesNotSupportHostnames(): void
    {
        $server = $this->eligibleServer(limit: 5);
        $server->egg->forceFill(['subdomain_compatibility' => 'incompatible'])->save();

        $policy = $this->resolver()->resolve($server->refresh());

        $this->assertFalse($policy->visible);
        $this->assertSame('egg_incompatible', $policy->disabledReasonCode);
    }

    public function testHiddenWhenTheFeatureIsDisabledForTheServer(): void
    {
        $server = $this->eligibleServer(limit: 5);
        $server->forceFill(['subdomain_policy' => 'disabled'])->save();

        $policy = $this->resolver()->resolve($server->refresh());

        $this->assertFalse($policy->visible);
        $this->assertSame('server_entitlement_disabled', $policy->disabledReasonCode);
    }

    /**
     * A provider outage is not an intentional decision about this server, so
     * the section stays put and explains itself instead of vanishing.
     */
    public function testStaysVisibleWhenNoParentDomainIsCurrentlyHealthy(): void
    {
        $server = $this->eligibleServer(limit: 5);
        ManagedDomain::query()->update(['last_provider_status' => 'unhealthy']);

        $policy = $this->resolver()->resolve($server);

        $this->assertTrue($policy->visible);
        $this->assertSame('no_available_domains', $policy->disabledReasonCode);
    }

    public function testStaysVisibleDuringAGameSwitch(): void
    {
        $server = $this->eligibleServer(limit: 5);
        $server->forceFill(['status' => Server::STATUS_SWITCHING_GAME])->save();

        $policy = $this->resolver()->resolve($server->refresh());

        $this->assertTrue($policy->visible);
        $this->assertSame('game_switch_in_progress', $policy->disabledReasonCode);
    }

    private function resolver(): SubdomainPolicyResolver
    {
        return $this->app->make(SubdomainPolicyResolver::class);
    }

    /**
     * A server whose egg, entitlement, and parent domain all allow managed
     * hostnames, so each test only varies the one thing it is about.
     */
    private function eligibleServer(int $limit): Server
    {
        $server = $this->createServerModel();
        $server->egg->forceFill([
            'subdomain_compatibility' => 'compatible',
            'subdomain_default_policy' => 'enabled',
            'subdomain_server_override_allowed' => true,
            'dns_service_profile_id' => $this->profile()->id,
        ])->save();
        $server->forceFill([
            'subdomain_policy' => 'inherit',
            'subdomain_limit' => $limit,
        ])->save();

        $this->parentDomain = $this->domain();

        return $server->refresh();
    }

    private function profile(): DnsServiceProfile
    {
        return DnsServiceProfile::query()->where('supports_direct_dns', true)->firstOrFail();
    }

    private function domain(): ManagedDomain
    {
        return ManagedDomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => 'Visibility test',
            'domain' => 'vis-' . strtolower(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 12)) . '.example.com',
            'provider' => 'cloudflare',
            'zone_id' => str_repeat('a', 32),
            'api_token' => 'token',
            'enabled' => true,
            'label_pattern' => '^[a-z0-9-]+$',
            'last_provider_status' => 'healthy',
        ]);
    }

    private function hostname(Server $server): ManagedSubdomain
    {
        $domain = $this->parentDomain;
        $label = 'existing-' . strtolower(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 8));
        $fqdn = $label . '.' . $domain->domain;

        return ManagedSubdomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'managed_domain_id' => $domain->id,
            'dns_service_profile_id' => null,
            'label' => $label,
            'fqdn' => $fqdn,
            'routing_mode' => 'direct_dns',
            'detected_service' => 'Generic TCP service',
            'service_detection_source' => 'direct_dns_fallback',
            'status' => 'active',
            'desired_state_version' => 1,
            'public_target_type' => 'A',
            'public_target' => '203.0.113.10',
            'target_port' => $server->allocation->port,
            'connection_address' => $fqdn,
            'desired_record_plan' => ['records' => []],
        ]);
    }
}
