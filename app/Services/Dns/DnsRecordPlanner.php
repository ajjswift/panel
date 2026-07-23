<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\Results\DnsRecordPlan;

class DnsRecordPlanner
{
    public function build(
        string $fqdn,
        Allocation $allocation,
        ManagedDomain $domain,
        DnsServiceProfile $profile,
        DnsTarget $target,
    ): DnsRecordPlan {
        $records = [[
            'key' => 'address',
            'type' => $target->recordType,
            'name' => $fqdn,
            'content' => $target->value,
            'ttl' => $domain->ttl,
            'proxied' => false,
        ]];

        $useSrv = $profile->supports_srv && $domain->supports_srv;
        if ($useSrv) {
            $records[] = [
                'key' => 'srv',
                'type' => 'SRV',
                'name' => sprintf('%s.%s.%s', $profile->srv_service, $profile->srv_protocol, $fqdn),
                'content' => null,
                'ttl' => $domain->ttl,
                'proxied' => false,
                'priority' => $profile->srv_priority,
                'weight' => $profile->srv_weight,
                'port' => $allocation->port,
                'target' => $fqdn,
            ];
        }

        $portlessOnDefault = $profile->portless_on_default_port
            && $profile->default_port
            && $profile->default_port === $allocation->port;
        $portDiscoverable = $useSrv || $portlessOnDefault;
        $connectionAddress = $portDiscoverable ? $fqdn : sprintf('%s:%d', $fqdn, $allocation->port);

        $explanation = $useSrv
            ? sprintf(
                '%s detected. An SRV record will direct compatible clients from %s to port %d.',
                $profile->name,
                $fqdn,
                $allocation->port,
            )
            : ($portlessOnDefault
                ? sprintf('%s uses its expected default port, so players can connect with %s.', $profile->name, $fqdn)
                : sprintf(
                    'DNS will resolve %s to this server, but it cannot redirect arbitrary ports. Players must connect using %s.',
                    $fqdn,
                    $connectionAddress,
                ));

        return new DnsRecordPlan(
            records: $records,
            connectionAddress: $connectionAddress,
            portDiscoverable: $portDiscoverable,
            explanation: $explanation,
            warnings: $portDiscoverable ? [] : ['Players must include the selected port when connecting.'],
        );
    }
}
