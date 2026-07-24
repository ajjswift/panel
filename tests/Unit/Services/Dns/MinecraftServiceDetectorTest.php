<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use PHPUnit\Framework\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Pterodactyl\Services\Dns\TcpSocketFactory;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\MinecraftServiceDetector;

class MinecraftServiceDetectorTest extends TestCase
{
    public function testDetectsFramedMinecraftStatusResponseAndCachesIt(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $json = json_encode([
            'version' => ['name' => '1.21', 'protocol' => 767],
            'players' => ['max' => 20, 'online' => 1],
            'description' => ['text' => 'Test'],
        ], JSON_THROW_ON_ERROR);
        $payload = "\x00" . $this->varInt(strlen($json)) . $json;
        fwrite($server, $this->varInt(strlen($payload)) . $payload);

        $sockets = $this->createMock(TcpSocketFactory::class);
        $sockets->expects($this->once())
            ->method('connect')
            ->with('1.1.1.1', 25565, 1.0)
            ->willReturn($client);
        $detector = new MinecraftServiceDetector($sockets, new Repository(new ArrayStore()));
        $target = new DnsTarget('A', '1.1.1.1', 'allocation_ip');

        $this->assertTrue($detector->detect($target, 25565));
        $this->assertTrue($detector->detect($target, 25565));

        $handshake = fread($server, 512);
        $this->assertNotSame('', $handshake);
        $this->assertStringContainsString('1.1.1.1', $handshake);
        fclose($server);
    }

    public function testRejectsNonMinecraftTcpResponse(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, "HTTP/1.1 200 OK\r\n\r\n");

        $sockets = $this->createMock(TcpSocketFactory::class);
        $sockets->method('connect')->willReturn($client);
        $detector = new MinecraftServiceDetector($sockets, new Repository(new ArrayStore()));

        $this->assertFalse(
            $detector->detect(new DnsTarget('A', '1.1.1.1', 'allocation_ip'), 25565),
        );
        fclose($server);
    }

    public function testRejectsAStatusResponseWithAnInvalidFrameLength(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $json = '{"version":{"name":"fake"}}';
        $payload = "\x00" . $this->varInt(strlen($json)) . $json;
        fwrite($server, $this->varInt(strlen($payload) - 1) . $payload);

        $sockets = $this->createMock(TcpSocketFactory::class);
        $sockets->method('connect')->willReturn($client);
        $detector = new MinecraftServiceDetector($sockets, new Repository(new ArrayStore()));

        $this->assertFalse(
            $detector->detect(new DnsTarget('A', '1.1.1.1', 'allocation_ip'), 25565),
        );
        fclose($server);
    }

    public function testConnectionFailureIsNotMinecraft(): void
    {
        $sockets = $this->createMock(TcpSocketFactory::class);
        $sockets->expects($this->once())->method('connect')->willReturn(false);
        $detector = new MinecraftServiceDetector($sockets, new Repository(new ArrayStore()));

        $this->assertFalse(
            $detector->detect(new DnsTarget('A', '1.1.1.1', 'allocation_ip'), 27015),
        );
    }

    private function varInt(int $value): string
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
