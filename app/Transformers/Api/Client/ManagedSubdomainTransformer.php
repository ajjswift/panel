<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\ManagedSubdomain;

class ManagedSubdomainTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return 'managed_subdomain';
    }

    public function transform(ManagedSubdomain $model): array
    {
        $model->loadMissing(['allocation', 'domain', 'serviceProfile', 'records']);

        return [
            'uuid' => $model->uuid,
            'fqdn' => $model->fqdn,
            'label' => $model->label,
            'routing_mode' => $model->routing_mode->value,
            'status' => $model->status->value,
            'allocation' => $model->allocation ? [
                'id' => $model->allocation->id,
                'ip' => $model->allocation->ip,
                'alias' => $model->allocation->ip_alias,
                'port' => $model->allocation->port,
            ] : null,
            'domain' => [
                'uuid' => $model->domain->uuid,
                'name' => $model->domain->name,
                'domain' => $model->domain->domain,
            ],
            'detected_service' => $model->detected_service,
            'service_detection_source' => $model->service_detection_source,
            'connection_address' => $model->connection_address,
            'public_target' => [
                'type' => $model->public_target_type,
                'value' => $model->public_target,
            ],
            'target_port' => $model->target_port,
            'record_plan' => $model->desired_record_plan,
            'record_count' => $model->records->where('sync_status', 'active')->count(),
            'last_synchronized_at' => $model->last_synchronized_at?->toAtomString(),
            'last_error_code' => $model->last_error_code,
            'error_message' => $model->sanitized_error_message,
            'drift_detected_at' => $model->provider_drift_detected_at?->toAtomString(),
            'created_at' => $model->created_at?->toAtomString(),
            'updated_at' => $model->updated_at?->toAtomString(),
        ];
    }
}
