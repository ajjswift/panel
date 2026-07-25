<?php

namespace Pterodactyl\Services\Dns\Results;

final readonly class DetectedAllocationService
{
    public const HTTP = 'http';
    public const MINECRAFT_JAVA = 'minecraft_java';
    public const UNKNOWN = 'unknown';

    public function __construct(public string $type)
    {
    }

    public function isHttp(): bool
    {
        return $this->type === self::HTTP;
    }

    public function isMinecraftJava(): bool
    {
        return $this->type === self::MINECRAFT_JAVA;
    }
}
