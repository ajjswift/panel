<?php

namespace Pterodactyl\Http\Controllers\Admin\Dns;

use Ramsey\Uuid\Uuid;
use Illuminate\View\View;
use Pterodactyl\Facades\Activity;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\Dns\DnsServiceProfileFormRequest;

class DnsServiceProfileController extends Controller
{
    public function __construct(private AlertsMessageBag $alert)
    {
    }

    public function store(DnsServiceProfileFormRequest $request): RedirectResponse
    {
        $profile = new DnsServiceProfile();
        $profile->forceFill($this->data($request) + [
            'uuid' => Uuid::uuid4()->toString(),
            'is_system' => false,
            'cloudflare_proxy_eligible' => false,
        ])->saveOrFail();

        Activity::event('admin:dns-service-profile.create')->subject($profile)->property('name', $profile->name)->log();
        $this->alert->success('The DNS service profile was created.')->flash();

        return redirect()->route('admin.managed-dns');
    }

    public function edit(DnsServiceProfile $dnsServiceProfile): View
    {
        return view('admin.dns.profile', ['profile' => $dnsServiceProfile]);
    }

    public function update(
        DnsServiceProfileFormRequest $request,
        DnsServiceProfile $dnsServiceProfile,
    ): RedirectResponse {
        $dnsServiceProfile->forceFill($this->data($request))->saveOrFail();
        Activity::event('admin:dns-service-profile.update')->subject($dnsServiceProfile)->property('name', $dnsServiceProfile->name)->log();
        $this->alert->success('The DNS service profile was updated.')->flash();

        return redirect()->route('admin.managed-dns');
    }

    public function delete(DnsServiceProfile $dnsServiceProfile): RedirectResponse
    {
        if ($dnsServiceProfile->is_system || $dnsServiceProfile->eggs()->exists() || $dnsServiceProfile->managedSubdomains()->exists()) {
            throw new DisplayException('This DNS service profile is built in or is still in use and cannot be removed.');
        }

        $dnsServiceProfile->delete();
        $this->alert->success('The unused DNS service profile was removed.')->flash();

        return redirect()->route('admin.managed-dns');
    }

    private function data(DnsServiceProfileFormRequest $request): array
    {
        return $request->validated() + [
            'supports_direct_dns' => $request->boolean('supports_direct_dns'),
            'supports_srv' => $request->boolean('supports_srv'),
            'portless_on_default_port' => $request->boolean('portless_on_default_port'),
            'cloudflare_proxy_eligible' => false,
        ];
    }
}
