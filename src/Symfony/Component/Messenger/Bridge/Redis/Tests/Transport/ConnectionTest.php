<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Redis\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * @requires extension redis
 */
class ConnectionTest extends TestCase
{
    public function testFromInvalidDsn()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The given Redis DSN "redis://" is invalid.');

        Connection::fromDsn('redis://');
    }

    public function testFromDsn()
    {
        $this->assertEquals(
            new Connection(['stream' => 'queue'], [
                'host' => 'localhost',
                'port' => 6379,
            ], [], $this->createMock(\Redis::class)),
            Connection::fromDsn('redis://localhost/queue?', [], $this->createMock(\Redis::class))
        );
    }

    public function testFromDsnOnUnixSocket()
    {
        $this->assertEquals(
            new Connection(['stream' => 'queue'], [
                'host' => '/var/run/redis/redis.sock',
                'port' => 0,
            ], [], $redis = $this->createMock(\Redis::class)),
            Connection::fromDsn('redis:///var/run/redis/redis.sock', ['stream' => 'queue'], $redis)
        );
    }

    public function testFromDsnWithOptions()
    {
        $this->assertEquals(
            Connection::fromDsn('redis://localhost', ['stream' => 'queue', 'group' => 'group1', 'consumer' => 'consumer1', 'auto_setup' => false, 'serializer' => 2], $this->createMock(\Redis::class)),
            Connection::fromDsn('redis://localhost/queue/group1/consumer1?serializer=2&auto_setup=0', [], $this->createMock(\Redis::class))
        );
    }

    public function testFromDsnWithOptionsAndTrailingSlash()
    {
        $this->assertEquals(
            Connection::fromDsn('redis://localhost/', ['stream' => 'queue', 'group' => 'group1', 'consumer' => 'consumer1', 'auto_setup' => false, 'serializer' => 2], $this->createMock(\Redis::class)),
            Connection::fromDsn('redis://localhost/queue/group1/consumer1?serializer=2&auto_setup=0', [], $this->createMock(\Redis::class))
        );
    }

    public function testFromDsnWithRedissScheme()
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('connect')
            ->with('tls://127.0.0.1', 6379)
            ->willReturn(true);

        Connection::fromDsn('rediss://127.0.0.1', [], $redis);
    }

    public function testFromDsnWithQueryOptions()
    {
        $this->assertEquals(
            new Connection(['stream' => 'queue', 'group' => 'group1', 'consumer' => 'consumer1'], [
                'host' => 'localhost',
                'port' => 6379,
            ], [
                'serializer' => 2,
            ], $this->createMock(\Redis::class)),
            Connection::fromDsn('redis://localhost/queue/group1/consumer1?serializer=2', [], $this->createMock(\Redis::class))
        );
    }

    public function testFromDsnWithMixDsnQueryOptions()
    {
        $this->assertEquals(
            Connection::fromDsn('redis://localhost/queue/group1?serializer=2', ['consumer' => 'specific-consumer'], $this->createMock(\Redis::class)),
            Connection::fromDsn('redis://localhost/queue/group1/specific-consumer?serializer=2', [], $this->createMock(\Redis::class))
        );

        $this->assertEquals(
            Connection::fromDsn('redis://localhost/queue/group1/consumer1', ['consumer' => 'specific-consumer'], $this->createMock(\Redis::class)),
            Connection::fromDsn('redis://localhost/queue/group1/consumer1', [], $this->createMock(\Redis::class))
        );
    }

    public function testRedisClusterInstanceIsSupported()
    {
        $redis = $this->createMock(\RedisCluster::class);
        $this->assertInstanceOf(Connection::class, new Connection([], [], [], $redis));
    }

    public function testKeepGettingPendingMessages()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(3))->method('xreadgroup')
            ->with('symfony', 'consumer', ['queue' => 0], 1, null)
            ->willReturn(['queue' => [['message' => json_encode(['body' => 'Test', 'headers' => []])]]]);

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $this->assertNotNull($connection->get());
        $this->assertNotNull($connection->get());
        $this->assertNotNull($connection->get());
    }

    /**
     * @dataProvider provideAuthDsn
     */
    public function testAuth(string|array $expected, string $dsn)
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('auth')
            ->with($expected)
            ->willReturn(true);

        Connection::fromDsn($dsn, [], $redis);
    }

    public function provideAuthDsn(): \Generator
    {
        yield 'Password only' => ['password', 'redis://password@localhost/queue'];
        yield 'User and password' => [['user', 'password'], 'redis://user:password@localhost/queue'];
        yield 'User and colon' => ['user', 'redis://user:@localhost/queue'];
        yield 'Colon and password' => ['password', 'redis://:password@localhost/queue'];
        yield 'Colon and falsy password' => ['0', 'redis://:0@localhost/queue'];
    }

    public function testAuthFromOptions()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('auth')
            ->with('password')
            ->willReturn(true);

        Connection::fromDsn('redis://localhost/queue', ['auth' => 'password'], $redis);
    }

    public function testAuthFromOptionsAndDsn()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('auth')
            ->with('password2')
            ->willReturn(true);

        Connection::fromDsn('redis://password1@localhost/queue', ['auth' => 'password2'], $redis);
    }

    public function testNoAuthWithEmptyPassword()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(0))->method('auth')
            ->with('')
            ->willThrowException(new \RuntimeException());

        Connection::fromDsn('redis://@localhost/queue', [], $redis);
    }

    public function testAuthZeroPassword()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('auth')
            ->with('0')
            ->willReturn(true);

        Connection::fromDsn('redis://0@localhost/queue', [], $redis);
    }

    public function testFailedAuth()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis connection ');
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('auth')
            ->with('password')
            ->willReturn(false);

        Connection::fromDsn('redis://password@localhost/queue', [], $redis);
    }

    public function testGetPendingMessageFirst()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('xreadgroup')
            ->with('symfony', 'consumer', ['queue' => '0'], 1, null)
            ->willReturn(['queue' => [['message' => '{"body":"1","headers":[]}']]]);

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $message = $connection->get();

        $this->assertSame([
            'id' => 0,
            'data' => [
                'message' => json_encode([
                    'body' => '1',
                    'headers' => [],
                ]),
            ],
        ], $message);
    }

    public function testClaimAbandonedMessageWithRaceCondition()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(3))->method('xreadgroup')
            ->withConsecutive(
                ['symfony', 'consumer', ['queue' => '0'], 1, null], // first call for pending messages
                ['symfony', 'consumer', ['queue' => '0'], 1, null], // sencond call because of claimed message (redisid-123)
                ['symfony', 'consumer', ['queue' => '>'], 1, null] // third call because of no result (other consumer claimed message redisid-123)
            )
            ->willReturnOnConsecutiveCalls([], [], []);

        $redis->expects($this->once())->method('xpending')->willReturn([[
            0 => 'redisid-123', // message-id
            1 => 'consumer-2', // consumer-name
            2 => 3600001, // idle
        ]]);

        $redis->expects($this->exactly(1))->method('xclaim')
            ->with('queue', 'symfony', 'consumer', 3600000, ['redisid-123'], ['JUSTID'])
            ->willReturn([]);

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $connection->get();
    }

    public function testClaimAbandonedMessage()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(2))->method('xreadgroup')
            ->withConsecutive(
                ['symfony', 'consumer', ['queue' => '0'], 1, null], // first call for pending messages
                ['symfony', 'consumer', ['queue' => '0'], 1, null] // sencond call because of claimed message (redisid-123)
            )
            ->willReturnOnConsecutiveCalls(
                [], // first call returns no result
                ['queue' => [['message' => '{"body":"1","headers":[]}']]] // second call returns clamed message (redisid-123)
            );

        $redis->expects($this->once())->method('xpending')->willReturn([[
            0 => 'redisid-123', // message-id
            1 => 'consumer-2', // consumer-name
            2 => 3600001, // idle
        ]]);

        $redis->expects($this->exactly(1))->method('xclaim')
            ->with('queue', 'symfony', 'consumer', 3600000, ['redisid-123'], ['JUSTID'])
            ->willReturn([]);

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $connection->get();
    }

    public function testUnexpectedRedisError()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Redis error happens');
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('xreadgroup')->willReturn(false);
        $redis->expects($this->once())->method('getLastError')->willReturn('Redis error happens');

        $connection = Connection::fromDsn('redis://localhost/queue', ['auto_setup' => false], $redis);
        $connection->get();
    }

    public function testMaxEntries()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('xadd')
            ->with('queue', '*', ['message' => '{"body":"1","headers":[]}'], 20000, true)
            ->willReturn('1');

        $connection = Connection::fromDsn('redis://localhost/queue?stream_max_entries=20000', [], $redis);
        $connection->add('1', []);
    }

    public function testDeleteAfterAck()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('xack')
            ->with('queue', 'symfony', ['1'])
            ->willReturn(1);
        $redis->expects($this->exactly(1))->method('xdel')
            ->with('queue', ['1'])
            ->willReturn(1);

        $connection = Connection::fromDsn('redis://localhost/queue', [], $redis);
        $connection->ack('1');
    }

    public function testDeleteAfterReject()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->exactly(1))->method('xack')
            ->with('queue', 'symfony', ['1'])
            ->willReturn(1);
        $redis->expects($this->exactly(1))->method('xdel')
            ->with('queue', ['1'])
            ->willReturn(1);

        $connection = Connection::fromDsn('redis://localhost/queue?delete_after_reject=true', [], $redis);
        $connection->reject('1');
    }

    public function testLastErrorGetsCleared()
    {
        $redis = $this->createMock(\Redis::class);

        $redis->expects($this->once())->method('xadd')->willReturn('0');
        $redis->expects($this->once())->method('xack')->willReturn(0);

        $redis->method('getLastError')->willReturnOnConsecutiveCalls('xadd error', 'xack error');
        $redis->expects($this->exactly(2))->method('clearLastError');

        $connection = Connection::fromDsn('redis://localhost/messenger-clearlasterror', ['auto_setup' => false], $redis);

        try {
            $connection->add('message', []);
        } catch (TransportException $e) {
        }

        $this->assertSame('xadd error', $e->getMessage());

        try {
            $connection->ack('1');
        } catch (TransportException $e) {
        }

        $this->assertSame('xack error', $e->getMessage());
    }

    /**
     * @dataProvider provideIdPatterns
     */
    public function testAddReturnId(string $expected, \Redis $redis, int $delay = 0)
    {
        $id = Connection::fromDsn(dsn: 'redis://localhost/queue', redis: $redis)->add('body', [], $delay);

        $this->assertMatchesRegularExpression($expected, $id);
    }

    public function provideIdPatterns(): \Generator
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->atLeastOnce())->method('xadd')->willReturn('THE_MESSAGE_ID');

        yield 'No delay' => ['/^THE_MESSAGE_ID$/', $redis];

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->atLeastOnce())->method('rawCommand')->willReturn('1');
        yield '100ms delay' => ['/^\w+\.\d+$/', $redis, 100];
    }
}
