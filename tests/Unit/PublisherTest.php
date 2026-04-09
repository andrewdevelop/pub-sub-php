<?php

namespace Tests\Unit;

use Core\Messaging\InternalConfig;
use Core\Messaging\Publisher;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class PublisherTest extends TestCase
{
    private InternalConfig $config;

    protected function setUp(): void
    {
        $this->config = new InternalConfig(
            host: 'localhost',
            port: 5672,
            login: 'guest',
            password: 'guest',
            vhost: '/',
            service_id: 'test-service',
            exchange_name: 'events',
            queue_prefix: 'evt',
            with_dlq: true,
            max_retries: 5,
            retry_delay_sec: 10,
            dlq_message_ttl_sec: 86400,
            connection_timeout: 120,
            heartbeat_timeout: 60,
        );
    }

    public function testGeneratesUuid5IdempotencyKey(): void
    {
        $publisher = new Publisher($this->config);

        $data1 = ['event' => 'user.created', 'user_id' => 123];
        $data2 = ['event' => 'user.created', 'user_id' => 123];

        $reflection = new \ReflectionClass($publisher);
        $method = $reflection->getMethod('generateIdempotencyKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($publisher, json_encode($data1));
        $key2 = $method->invoke($publisher, json_encode($data2));

        $this->assertSame($key1, $key2);
        $this->assertTrue(Uuid::isValid($key1));
    }

    public function testIdempotencyKeyDifferentForDifferentPayload(): void
    {
        $publisher = new Publisher($this->config);

        $data1 = ['event' => 'user.created', 'user_id' => 123];
        $data2 = ['event' => 'user.updated', 'user_id' => 123];

        $reflection = new \ReflectionClass($publisher);
        $method = $reflection->getMethod('generateIdempotencyKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($publisher, json_encode($data1));
        $key2 = $method->invoke($publisher, json_encode($data2));

        $this->assertNotSame($key1, $key2);
    }

    public function testIdempotencyKeyIsValidUuid(): void
    {
        $publisher = new Publisher($this->config);

        $data = ['test' => 'data'];

        $reflection = new \ReflectionClass($publisher);
        $method = $reflection->getMethod('generateIdempotencyKey');
        $method->setAccessible(true);

        $key = $method->invoke($publisher, json_encode($data));

        $this->assertTrue(Uuid::isValid($key));
    }

    public function testIdempotencyKeyDeterministic(): void
    {
        $publisher = new Publisher($this->config);

        $data = ['event' => 'user.created', 'user_id' => 123, 'timestamp' => '2024-01-01'];

        $reflection = new \ReflectionClass($publisher);
        $method = $reflection->getMethod('generateIdempotencyKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($publisher, json_encode($data));
        $key2 = $method->invoke($publisher, json_encode($data));

        $this->assertSame($key1, $key2);
    }

    public function testIdempotencyKeyWithNestedArray(): void
    {
        $publisher = new Publisher($this->config);

        $data1 = ['event' => 'order.created', 'items' => ['a', 'b']];
        $data2 = ['event' => 'order.created', 'items' => ['a', 'b']];

        $reflection = new \ReflectionClass($publisher);
        $method = $reflection->getMethod('generateIdempotencyKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($publisher, json_encode($data1));
        $key2 = $method->invoke($publisher, json_encode($data2));

        $this->assertSame($key1, $key2);
    }

    public function testIdempotencyKeyWithNullValues(): void
    {
        $publisher = new Publisher($this->config);

        $data1 = ['event' => 'test', 'optional' => null];
        $data2 = ['event' => 'test', 'optional' => null];

        $reflection = new \ReflectionClass($publisher);
        $method = $reflection->getMethod('generateIdempotencyKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($publisher, json_encode($data1));
        $key2 = $method->invoke($publisher, json_encode($data2));

        $this->assertSame($key1, $key2);
    }
}