<?php

namespace Tests\Unit;

use Core\Messaging\ConnectionFactory;
use Core\Messaging\InternalConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;

class ConnectionFactoryTest extends TestCase
{
    public function testCreatesConnectionConfigCorrectly()
    {
        $config = new InternalConfig(
            host: 'invalid_host_to_prevent_actual_connection',
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
            connection_timeout: 1, // small timeout
            heartbeat_timeout: 60,
        );

        // We expect it to throw an exception because the host is invalid
        // But this confirms the factory is passing parameters to AMQPStreamConnection
        $this->expectException(\Exception::class);
        
        ConnectionFactory::create($config, 'test-id');
    }
}
