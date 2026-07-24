<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\Allocation;

class AllocationTransformer extends BaseClientTransformer
{
    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return 'allocation';
    }

    public function transform(Allocation $model): array
    {
        return [
            'id' => $model->id,
            'ip' => $model->ip,
            'ip_alias' => $model->ip_alias,
            'port' => $model->port,
            'notes' => $model->notes,
            'is_default' => $model->server->allocation_id === $model->id,
            'managed_hostname_count' => (int) ($model->getAttribute('managed_hostname_count') ?? 0),
            // The friendly address to show in place of ip:port, when this
            // allocation has an active managed address served over direct DNS.
            // Reverse-proxied addresses are intentionally excluded — their raw
            // ip:port is still what a direct client connects to.
            'connection_address' => $this->activeConnectionAddress($model),
        ];
    }

    private function activeConnectionAddress(Allocation $model): ?string
    {
        $subdomain = $model->managedSubdomains()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('routing_mode', 'direct_dns')
            ->orderBy('id')
            ->first();

        return $subdomain?->connection_address;
    }
}
