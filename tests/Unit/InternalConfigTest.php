<?php

namespace Tests\Unit;

use Core\Messaging\InternalConfig;
use PHPUnit\Framework\TestCase;

class InternalConfigTest extends TestCase
{
    public function testCreatesFromArrayWithDefaults(): void
    {
        $config = InternalConfig::fromArray([
            'service_id' => 'test-service',
        ]);

        $this->assertSame('localhost', $config->host);
        $this->assertSame(5672, $config->port);
        $this->assertSame('guest', $config->login);
        $this->assertSame('guest', $config->password);
        $this->assertSame('/', $config->vhost);
        $this->assertSame('test-service', $config->service_id);
        $this->assertSame('events', $config->exchange_name);
        $this->assertSame('evt', $config->queue_prefix);
        $this->assertFalse($config->with_dlq);
        $this->assertSame(5, $config->max_retries);
        $this->assertSame(10, $config->retry_delay_sec);
        $this->assertSame(86400, $config->dlq_message_ttl_sec);
        $this->assertSame(120, $config->connection_timeout);
        $this->assertSame(60, $config->heartbeat_timeout);
    }

    public function testCreatesFromArrayWithCustomValues(): void
    {
        $config = InternalConfig::fromArray([
            'host' => 'rabbitmq.local',
            'port' => 5673,
            'login' => 'admin',
            'password' => 'secret',
            'vhost' => '/prod',
            'service_id' => 'custom-service',
            'exchange_name' => 'custom_exchange',
            'queue_prefix' => 'custom_prefix',
            'with_dlq' => 'true',
            'max_retries' => 10,
            'retry_delay_sec' => 30,
            'dlq_message_ttl_sec' => 86400,
            'connection_timeout' => 60,
            'heartbeat_timeout' => 30,
        ]);

        $this->assertSame('rabbitmq.local', $config->host);
        $this->assertSame(5673, $config->port);
        $this->assertSame('admin', $config->login);
        $this->assertSame('secret', $config->password);
        $this->assertSame('/prod', $config->vhost);
        $this->assertSame('custom-service', $config->service_id);
        $this->assertSame('custom_exchange', $config->exchange_name);
        $this->assertSame('custom_prefix', $config->queue_prefix);
        $this->assertTrue($config->with_dlq);
        $this->assertSame(10, $config->max_retries);
        $this->assertSame(30, $config->retry_delay_sec);
        $this->assertSame(86400, $config->dlq_message_ttl_sec);
        $this->assertSame(60, $config->connection_timeout);
        $this->assertSame(30, $config->heartbeat_timeout);
    }

    public function testWithDlqBooleanString(): void
    {
        $config = InternalConfig::fromArray([
            'service_id' => 'test',
            'with_dlq' => 'false',
        ]);

        $this->assertFalse($config->with_dlq);

        $config2 = InternalConfig::fromArray([
            'service_id' => 'test',
            'with_dlq' => '1',
        ]);

        $this->assertTrue($config2->with_dlq);
    }
}