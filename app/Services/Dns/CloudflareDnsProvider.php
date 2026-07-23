<?php

namespace Pterodactyl\Services\Dns;

use Illuminate\Support\Str;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Contracts\Dns\DnsProvider;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;

class CloudflareDnsProvider implements DnsProvider
{
    public function __construct(private Factory $http)
    {
    }

    public function validateConfiguration(ManagedDomain $domain, bool $testWriteAccess = false): array
    {
        $zone = $this->request($domain, 'get', "/zones/{$domain->zone_id}");
        $zoneName = strtolower(rtrim((string) ($zone['name'] ?? ''), '.'));
        if ($zoneName !== strtolower(rtrim($domain->domain, '.'))) {
            throw new DnsProviderException('zone_mismatch', 'The configured Cloudflare zone does not match the parent domain.');
        }

        $this->request($domain, 'get', "/zones/{$domain->zone_id}/dns_records", ['per_page' => 1]);

        if ($testWriteAccess) {
            $name = sprintf('_pterodactyl-test-%s.%s', strtolower(Str::random(12)), $domain->domain);
            $created = $this->request($domain, 'post', "/zones/{$domain->zone_id}/dns_records", [
                'type' => 'TXT',
                'name' => $name,
                'content' => 'pterodactyl-managed-dns-permission-test',
                'ttl' => 60,
            ]);

            try {
                $this->request($domain, 'put', "/zones/{$domain->zone_id}/dns_records/{$created['id']}", [
                    'type' => 'TXT',
                    'name' => $name,
                    'content' => 'pterodactyl-managed-dns-update-test',
                    'ttl' => 60,
                ]);
            } finally {
                $this->request($domain, 'delete', "/zones/{$domain->zone_id}/dns_records/{$created['id']}");
            }
        }

        return ['zone_name' => $zoneName, 'can_read' => true, 'can_write' => $testWriteAccess];
    }

    public function listRecords(ManagedDomain $domain, string $name): array
    {
        $result = $this->request($domain, 'get', "/zones/{$domain->zone_id}/dns_records", [
            'name' => $name,
            'per_page' => 100,
        ]);

        return array_values($result);
    }

    public function createRecord(ManagedDomain $domain, array $record): array
    {
        return $this->request(
            $domain,
            'post',
            "/zones/{$domain->zone_id}/dns_records",
            $this->payload($record),
        );
    }

    public function updateRecord(ManagedDomain $domain, string $providerRecordId, array $record): array
    {
        return $this->request(
            $domain,
            'put',
            "/zones/{$domain->zone_id}/dns_records/{$providerRecordId}",
            $this->payload($record),
        );
    }

    public function getRecord(ManagedDomain $domain, string $providerRecordId): array
    {
        return $this->request($domain, 'get', "/zones/{$domain->zone_id}/dns_records/{$providerRecordId}");
    }

    public function deleteRecord(ManagedDomain $domain, string $providerRecordId): void
    {
        try {
            $this->request($domain, 'delete', "/zones/{$domain->zone_id}/dns_records/{$providerRecordId}");
        } catch (DnsProviderException $exception) {
            // Deletion is idempotent: a record already missing from the provider
            // is the desired end state.
            if ($exception->providerErrorCode !== 'provider_record_missing') {
                throw $exception;
            }
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function payload(array $record): array
    {
        $payload = [
            'type' => $record['type'],
            'name' => $record['name'],
            'ttl' => $record['ttl'],
            'proxied' => false,
            'comment' => 'Managed by Pterodactyl Panel',
        ];

        if ($record['type'] === 'SRV') {
            $payload['data'] = [
                'priority' => $record['priority'],
                'weight' => $record['weight'],
                'port' => $record['port'],
                'target' => rtrim($record['target'], '.') . '.',
            ];
        } else {
            $payload['content'] = $record['content'];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(ManagedDomain $domain, string $method, string $path, array $data = []): array
    {
        try {
            $request = $this->http
                ->baseUrl(config('managed-dns.cloudflare.base_url'))
                ->acceptJson()
                ->withToken($domain->api_token)
                ->timeout(config('managed-dns.cloudflare.timeout'));

            $response = $method === 'get'
                ? $request->get($path, $data)
                : $request->{$method}($path, $data);
        } catch (\Throwable $exception) {
            throw new DnsProviderException('provider_unreachable', 'Cloudflare could not be reached. Try again later.', $exception);
        }

        return $this->result($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Response $response): array
    {
        $json = $response->json();
        if (!$response->successful() || !($json['success'] ?? false)) {
            $code = (string) ($json['errors'][0]['code'] ?? $response->status());
            [$safeCode, $message] = match(true) {
                $response->status() === 404 || $code === '81044' => ['provider_record_missing', 'The managed DNS record no longer exists at Cloudflare.'],
                in_array($response->status(), [401, 403], true) => ['provider_credentials_rejected', 'Cloudflare rejected the configured credentials.'],
                $response->status() === 409 => ['provider_record_conflict', 'The requested DNS record conflicts with an existing record.'],
                $response->status() === 429 => ['provider_rate_limited', 'Cloudflare rate-limited the DNS request. It will be retried.'],
                $response->serverError() => ['provider_unavailable', 'Cloudflare is temporarily unavailable. The request will be retried.'],
                default => ["cloudflare_{$code}", 'Cloudflare could not complete the DNS request.'],
            };

            throw new DnsProviderException($safeCode, $message);
        }

        return $json['result'] ?? [];
    }
}
