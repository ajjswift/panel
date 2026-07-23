<?php

namespace Pterodactyl\Models;

/**
 * Shared constants describing where inactive game-slot data lives inside a
 * server's volume. Kept separate from the model so non-Eloquent code (config
 * structure generation, Wings guards) can reference it without model overhead.
 */
class GameSlotStorage
{
    /**
     * Hidden directory at the server volume root that holds all inactive slot
     * stores. The active slot's files always live directly at the volume root.
     */
    public const STORE_DIRECTORY = '.gameslots';

    /**
     * file_denylist patterns pushed to Wings for slot-enabled servers so the
     * store is blocked from the file manager, SFTP, and backups while the
     * server is in normal operation.
     */
    public const DENYLIST_PATTERNS = [
        self::STORE_DIRECTORY,
        self::STORE_DIRECTORY . '/**',
    ];
}
