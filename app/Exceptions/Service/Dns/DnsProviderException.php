<?php

namespace Pterodactyl\Exceptions\Service\Dns;

class DnsProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $providerErrorCode,
        string $message = 'The DNS provider could not complete the request.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
