<?php

namespace Pterodactyl\Services\GameSlots;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Pterodactyl\Models\GameSlotStorage;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Orchestrates the filesystem side of game slots through the Wings file API.
 *
 * All movement between the volume root (the active slot) and the hidden store
 * (inactive slots) is performed with Wings' sandboxed bulk-rename endpoint.
 * Renames happen on the same filesystem, so every entry moves atomically via
 * rename(2) with ownership, permissions, hidden files, and sparse data left
 * untouched — nothing is ever copied. The operations here are idempotent and
 * resumable: they re-list the affected directory and only move what remains,
 * so a crashed or retried switch simply continues where it stopped.
 */
class GameSlotStorageService
{
    private const RENAME_BATCH_SIZE = 100;

    /**
     * Guard against directory listings that keep returning entries (e.g. a
     * process recreating files while the server should be stopped).
     */
    private const MAX_PASSES = 25;

    public function __construct(private DaemonFileRepository $fileRepository)
    {
    }

    /**
     * Ensure the hidden store root and the slot's own store directory exist.
     */
    public function ensureStoreExists(Server $server, GameSlot $slot): void
    {
        $repository = $this->fileRepository->setServer($server);

        $repository->createDirectory(GameSlotStorage::STORE_DIRECTORY, '/');
        $repository->createDirectory($slot->storage_reference, '/' . GameSlotStorage::STORE_DIRECTORY);
    }

    /**
     * Move every entry at the volume root (except the store itself) into the
     * given slot's store directory.
     */
    public function stashActiveFiles(Server $server, GameSlot $slot): void
    {
        $this->ensureStoreExists($server, $slot);

        $this->moveEntries(
            $server,
            '/',
            '/' . $slot->storagePath(),
            except: [GameSlotStorage::STORE_DIRECTORY],
        );
    }

    /**
     * Move every entry from the slot's store directory to the volume root.
     */
    public function restoreSlotFiles(Server $server, GameSlot $slot): void
    {
        $this->ensureStoreExists($server, $slot);

        $this->moveEntries($server, '/' . $slot->storagePath(), '/');
    }

    /**
     * Permanently delete a slot's store directory and everything inside it.
     */
    public function purgeSlotStore(Server $server, GameSlot $slot): void
    {
        $this->fileRepository->setServer($server)->deleteFiles(
            '/' . GameSlotStorage::STORE_DIRECTORY,
            [$slot->storage_reference],
        );
    }

    /**
     * Returns true when the given slot store directory contains no entries.
     */
    public function isStoreEmpty(Server $server, GameSlot $slot): bool
    {
        return count($this->listDirectory($server, '/' . $slot->storagePath())) === 0;
    }

    /**
     * Move all entries from one directory to another in idempotent, re-listed
     * passes of batched atomic renames.
     *
     * @param string[] $except entry names that must never be moved
     */
    private function moveEntries(Server $server, string $from, string $to, array $except = []): void
    {
        $repository = $this->fileRepository->setServer($server);

        for ($pass = 0; $pass < self::MAX_PASSES; ++$pass) {
            $entries = array_values(array_filter(
                $this->listDirectory($server, $from),
                fn (string $name) => !in_array($name, $except, true),
            ));

            if (empty($entries)) {
                return;
            }

            foreach (array_chunk($entries, self::RENAME_BATCH_SIZE) as $chunk) {
                $repository->renameFiles('/', array_map(fn (string $name) => [
                    'from' => ltrim($from . '/' . $name, '/'),
                    'to' => ltrim($to . '/' . $name, '/'),
                ], $chunk));
            }
        }

        throw new \RuntimeException(sprintf('Directory "%s" still contained entries after %d move passes; aborting to avoid looping forever.', $from, self::MAX_PASSES));
    }

    /**
     * @return string[] the names of the entries within the given directory
     */
    private function listDirectory(Server $server, string $path): array
    {
        $listing = $this->fileRepository->setServer($server)->getDirectory($path === '' ? '/' : $path);

        return array_map(fn (array $entry) => $entry['name'], $listing);
    }
}
