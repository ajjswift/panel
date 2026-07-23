<?php

namespace Pterodactyl\Contracts\Dns;

use Pterodactyl\Models\ManagedDomain;

interface DnsProvider
{
    /**
     * @return array{zone_name: string, can_read: bool, can_write: bool}
     */
    public function validateConfiguration(ManagedDomain $domain, bool $testWriteAccess = false): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecords(ManagedDomain $domain, string $name): array;

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function createRecord(ManagedDomain $domain, array $record): array;

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function updateRecord(ManagedDomain $domain, string $providerRecordId, array $record): array;

    /**
     * @return array<string, mixed>
     */
    public function getRecord(ManagedDomain $domain, string $providerRecordId): array;

    public function deleteRecord(ManagedDomain $domain, string $providerRecordId): void;
}
