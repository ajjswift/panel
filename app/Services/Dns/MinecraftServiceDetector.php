<?php

namespace Pterodactyl\Services\Dns;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Detects a Minecraft Java server by performing the status-protocol handshake
 * against the port. Several candidate hosts are tried (the allocation's own IP,
 * then the node's public address) so it works whether the panel reaches the
 * server directly or through the node's edge.
 */
class MinecraftServiceDetector
{
    public function __construct(
        private TcpSocketFactory $sockets,
        private Cache $cache,
        private Config $config,
    ) {
    }

    /**
     * @param string[] $hosts
     */
    public function detect(array $hosts, int $port): bool
    {
        foreach ($hosts as $host) {
            $key = sprintf('managed-dns:minecraft-service:%s', hash('sha256', sprintf('%s:%d', $host, $port)));

            if ($this->cache->has($key)) {
                if ($this->cache->get($key) === true) {
                    return true;
                }

                continue;
            }

            $detected = $this->probe($host, $port);
            $this->cache->put(
                $key,
                $detected,
                $detected
                    ? (int) $this->config->get('managed-dns.minecraft_detection.positive_ttl', 300)
                    : (int) $this->config->get('managed-dns.minecraft_detection.negative_ttl', 30),
            );

            if ($detected) {
                return true;
            }
        }

        return false;
    }

    private function probe(string $host, int $port): bool
    {
        $socket = $this->sockets->connect(
            $host,
            $port,
            (float) $this->config->get('managed-dns.minecraft_detection.connect_timeout', 1),
        );
        if (!is_resource($socket)) {
            return false;
        }

        try {
            stream_set_timeout($socket, (int) $this->config->get('managed-dns.minecraft_detection.timeout', 2));

            // Handshake into status mode followed by an empty status request.
            $handshake = "\x00"
                . $this->encodeVarInt(47)
                . $this->encodeVarInt(strlen($host))
                . $host
                . pack('n', $port)
                . "\x01";
            if (!$this->writeAll($socket, $this->encodeVarInt(strlen($handshake)) . $handshake . "\x01\x00")) {
                return false;
            }

            $packetLength = $this->readVarInt($socket);
            $packetId = $this->readVarInt($socket);
            $jsonLength = $this->readVarInt($socket);
            if ($packetLength === null || $packetId !== 0 || $jsonLength === null || $jsonLength < 2 || $jsonLength > 1048576) {
                return false;
            }
            $expectedPacketLength = strlen($this->encodeVarInt($packetId))
                + strlen($this->encodeVarInt($jsonLength))
                + $jsonLength;
            if ($packetLength !== $expectedPacketLength) {
                return false;
            }

            $json = $this->readBytes($socket, $jsonLength);
            $status = $json === null ? null : json_decode($json, true);

            return is_array($status)
                && (isset($status['version']) || isset($status['players']) || array_key_exists('description', $status));
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $payload): bool
    {
        $written = 0;
        $length = strlen($payload);
        while ($written < $length) {
            $bytes = fwrite($socket, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                return false;
            }
            $written += $bytes;
        }

        return true;
    }

    /**
     * @param resource $socket
     */
    private function readVarInt($socket): ?int
    {
        $value = 0;
        for ($position = 0; $position < 5; ++$position) {
            $byte = fread($socket, 1);
            if ($byte === false || $byte === '') {
                return null;
            }

            $current = ord($byte);
            $value |= ($current & 0x7F) << (7 * $position);
            if (($current & 0x80) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param resource $socket
     */
    private function readBytes($socket, int $length): ?string
    {
        $payload = '';
        while (strlen($payload) < $length) {
            $chunk = fread($socket, $length - strlen($payload));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $payload .= $chunk;
        }

        return $payload;
    }

    private function encodeVarInt(int $value): string
    {
        $encoded = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $encoded .= chr($byte);
        } while ($value !== 0);

        return $encoded;
    }
}
