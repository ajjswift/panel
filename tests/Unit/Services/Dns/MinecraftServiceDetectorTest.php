<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Pterodactyl\Services\Dns\TcpSocketFactory;
use Pterodactyl\Services\Dns\MinecraftServiceDetector;

class MinecraftServiceDetectorTest extends TestCase
{
    private function detector(TcpSocketFactory $sockets): MinecraftServiceDetector
    {
        return new MinecraftServiceDetector($sockets, new Repository(new ArrayStore()), config());
    }

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
        $detector = $this->detector($sockets);

        $this->assertTrue($detector->detect(['1.1.1.1'], 25565));
        $this->assertTrue($detector->detect(['1.1.1.1'], 25565));

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

        $this->assertFalse($this->detector($sockets)->detect(['1.1.1.1'], 25565));
        fclose($server);
    }

    public function testTriesEachCandidateUntilOneAnswers(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $json = '{"version":{"name":"1.21"},"players":{"max":1,"online":0},"description":"x"}';
        $payload = "\x00" . $this->varInt(strlen($json)) . $json;
        fwrite($server, $this->varInt(strlen($payload)) . $payload);

        $sockets = $this->createMock(TcpSocketFactory::class);
        // First candidate is unreachable, second answers.
        $sockets->method('connect')->willReturnOnConsecutiveCalls(false, $client);

        $this->assertTrue($this->detector($sockets)->detect(['10.0.0.5', '203.0.113.10'], 25565));
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

        $this->assertFalse($this->detector($sockets)->detect(['1.1.1.1'], 25565));
        fclose($server);
    }

    public function testConnectionFailureIsNotMinecraft(): void
    {
        $sockets = $this->createMock(TcpSocketFactory::class);
        $sockets->expects($this->once())->method('connect')->willReturn(false);

        $this->assertFalse($this->detector($sockets)->detect(['1.1.1.1'], 27015));
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
