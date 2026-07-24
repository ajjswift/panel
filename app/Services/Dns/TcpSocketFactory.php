<?php

namespace Pterodactyl\Services\Dns;

class TcpSocketFactory
{
    /**
     * @return resource|false
     */
    public function connect(string $host, int $port, float $timeout)
    {
        $host = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? sprintf('[%s]', $host)
            : $host;

        return @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );
    }
}
