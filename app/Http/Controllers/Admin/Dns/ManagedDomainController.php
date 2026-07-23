<?php

namespace Pterodactyl\Http\Controllers\Admin\Dns;

use Ramsey\Uuid\Uuid;
use Illuminate\View\View;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Dns\DnsProviderFactory;
use Pterodactyl\Services\Dns\CloudflareDnsProvider;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;
use Pterodactyl\Http\Requests\Admin\Dns\ManagedDomainFormRequest;
use Pterodactyl\Http\Requests\Admin\Dns\DiscoverManagedDomainRequest;

class ManagedDomainController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private DnsProviderFactory $providerFactory,
        private CloudflareDnsProvider $cloudflare,
    ) {
    }

    public function index(): View
    {
        return view('admin.dns.index', [
            'domains' => ManagedDomain::query()->withCount('managedSubdomains')->orderBy('domain')->paginate(25),
            'profiles' => DnsServiceProfile::query()->orderBy('name')->get(),
            'nodes' => Node::query()->orderBy('name')->get(),
            'eggs' => Egg::query()->orderBy('name')->get(),
        ]);
    }

    public function edit(ManagedDomain $managedDomain): View
    {
        return view('admin.dns.edit', [
            'domain' => $managedDomain,
            'profiles' => DnsServiceProfile::query()->orderBy('name')->get(),
            'nodes' => Node::query()->orderBy('name')->get(),
            'eggs' => Egg::query()->orderBy('name')->get(),
        ]);
    }

    public function store(ManagedDomainFormRequest $request): RedirectResponse
    {
        $domain = new ManagedDomain();
        $domain->forceFill($this->data($request) + [
            'uuid' => Uuid::uuid4()->toString(),
            'provider' => 'cloudflare',
        ])->saveOrFail();

        Activity::event('admin:managed-domain.create')
            ->subject($domain)
            ->property(['domain' => $domain->domain, 'enabled' => $domain->enabled])
            ->log();

        $this->alert->success('The managed parent domain was created. Test its credentials before enabling customer use.')->flash();

        return redirect()->route('admin.managed-dns.edit', $domain);
    }

    public function discover(DiscoverManagedDomainRequest $request): JsonResponse
    {
        $domain = new ManagedDomain();
        $domain->forceFill([
            'domain' => strtolower(rtrim($request->string('domain')->toString(), '.')),
            'api_token' => $request->string('api_token')->toString(),
        ]);

        try {
            $zone = $this->cloudflare->discoverZone($domain);
            $domain->zone_id = $zone['id'];
            $this->cloudflare->validateConfiguration($domain, true);

            Activity::event('admin:managed-domain.configuration-discovered')
                ->property([
                    'domain' => $domain->domain,
                    'zone_id' => $zone['id'],
                    'successful' => true,
                ])
                ->log();

            return new JsonResponse([
                'zone_id' => $zone['id'],
                'zone_name' => $zone['name'],
                'permissions_verified' => true,
            ]);
        } catch (DnsProviderException $exception) {
            Activity::event('admin:managed-domain.configuration-discovered')
                ->property([
                    'domain' => $domain->domain,
                    'successful' => false,
                    'error_code' => $exception->providerErrorCode,
                ])
                ->log();

            return new JsonResponse([
                'message' => $exception->getMessage(),
                'error_code' => $exception->providerErrorCode,
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return new JsonResponse([
                'message' => 'Cloudflare configuration could not be checked unexpectedly. Review the application logs for details.',
                'error_code' => 'provider_discovery_failed',
            ], 500);
        }
    }

    public function update(ManagedDomainFormRequest $request, ManagedDomain $managedDomain): RedirectResponse
    {
        if (
            $request->boolean('enabled')
            && (
                $managedDomain->last_provider_status !== 'healthy'
                || $request->filled('api_token')
                || $request->input('zone_id') !== $managedDomain->zone_id
                || strtolower(rtrim($request->input('domain'), '.')) !== $managedDomain->domain
            )
        ) {
            throw new DisplayException('Test the saved Cloudflare configuration successfully before enabling this parent domain. Disable it first when replacing credentials or zone details.');
        }

        $old = $managedDomain->only(['name', 'domain', 'zone_id', 'enabled']);
        $managedDomain->forceFill($this->data($request))->saveOrFail();

        Activity::event('admin:managed-domain.update')
            ->subject($managedDomain)
            ->property(['old' => $old, 'new' => $managedDomain->only(array_keys($old)), 'credential_replaced' => $request->filled('api_token')])
            ->log();

        $this->alert->success('The managed parent domain was updated.')->flash();

        return redirect()->route('admin.managed-dns.edit', $managedDomain);
    }

    public function test(ManagedDomain $managedDomain): RedirectResponse
    {
        try {
            $this->providerFactory->for($managedDomain)->validateConfiguration($managedDomain, true);
            $managedDomain->forceFill([
                'last_provider_check_at' => now(),
                'last_provider_status' => 'healthy',
                'last_provider_error_code' => null,
            ])->save();

            Activity::event('admin:managed-domain.credentials-tested')
                ->subject($managedDomain)
                ->property(['domain' => $managedDomain->domain, 'successful' => true])
                ->log();
            $this->alert->success('Cloudflare authentication, zone access, and DNS read/create/update/delete permissions were verified.')->flash();
        } catch (\Throwable $exception) {
            $code = $exception instanceof DnsProviderException
                ? $exception->providerErrorCode
                : 'provider_test_failed';
            $managedDomain->forceFill([
                'last_provider_check_at' => now(),
                'last_provider_status' => 'error',
                'last_provider_error_code' => $code,
            ])->save();

            Activity::event('admin:managed-domain.credentials-tested')
                ->subject($managedDomain)
                ->property(['domain' => $managedDomain->domain, 'successful' => false, 'error_code' => $code])
                ->log();
            $message = $exception instanceof DnsProviderException
                ? $exception->getMessage()
                : 'The provider test failed unexpectedly. Review the application logs for details.';
            $this->alert->danger($message)->flash();
        }

        return redirect()->route('admin.managed-dns.edit', $managedDomain);
    }

    public function delete(ManagedDomain $managedDomain): RedirectResponse
    {
        if ($managedDomain->managedSubdomains()->whereNull('deleted_at')->exists()) {
            throw new DisplayException('This parent domain still has managed hostnames. Disable it or clean those records up deliberately before removal.');
        }

        Activity::event('admin:managed-domain.delete')
            ->subject($managedDomain)
            ->property('domain', $managedDomain->domain)
            ->log();
        $managedDomain->delete();
        $this->alert->success('The unused parent domain configuration was removed.')->flash();

        return redirect()->route('admin.managed-dns');
    }

    private function data(ManagedDomainFormRequest $request): array
    {
        $data = $request->validated();
        $data['domain'] = strtolower(rtrim($data['domain'], '.'));
        $data['enabled'] = $request->boolean('enabled');
        $data['supports_srv'] = $request->boolean('supports_srv');
        $data['supports_direct_dns'] = $request->boolean('supports_direct_dns');
        $data['reserved_labels'] = collect(preg_split('/[\s,]+/', $data['reserved_labels'] ?? ''))
            ->filter()
            ->map(fn ($label) => strtolower($label))
            ->unique()
            ->values()
            ->all();

        if (!$request->filled('api_token')) {
            unset($data['api_token']);
        }

        return $data;
    }
}
