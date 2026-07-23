<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\DnsServiceProfile;

class DnsServiceProfileResolver
{
    /**
     * @return array{profile: ?DnsServiceProfile, source: string}
     */
    public function resolve(Server $server): array
    {
        if ($server->dns_service_profile_id) {
            /** @var DnsServiceProfile|null $profile */
            $profile = $server->dnsServiceProfile()->first();

            return [
                'profile' => $profile,
                'source' => 'administrator_override',
            ];
        }

        $activeSlot = $server->activeGameSlot()->with('egg.dnsServiceProfile')->first();
        if ($activeSlot?->egg?->dnsServiceProfile) {
            /** @var DnsServiceProfile $profile */
            $profile = $activeSlot->egg->dnsServiceProfile;

            return [
                'profile' => $profile,
                'source' => 'game_slot',
            ];
        }

        $egg = $server->egg()->with('dnsServiceProfile')->first();
        if ($egg?->dnsServiceProfile) {
            /** @var DnsServiceProfile $profile */
            $profile = $egg->dnsServiceProfile;

            return [
                'profile' => $profile,
                'source' => 'egg_mapping',
            ];
        }

        return [
            'profile' => DnsServiceProfile::query()->where('slug', 'generic-tcp')->first(),
            'source' => 'generic_fallback',
        ];
    }
}
