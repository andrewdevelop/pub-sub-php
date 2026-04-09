<?php

namespace Tests\Integration;

use Core\Messaging\InternalConfig;
use Core\Messaging\ConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected string $host = '127.0.0.1';
    protected int $port = 5672;
    protected string $user = 'root';
    protected string $pass = 'secret';
    protected string $vhost = 'test';
    protected string $exchange = 'real_test_events';
    protected string $queuePrefix = 'real_test_evt';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupRabbitMQ();
    }

    protected function tearDown(): void
    {
        $this->cleanupRabbitMQ();
        parent::tearDown();
    }

    protected function createConfig(string $serviceId): InternalConfig
    {
        return new InternalConfig(
            host: $this->host,
            port: $this->port,
            login: $this->user,
            password: $this->pass,
            vhost: $this->vhost,
            service_id: $serviceId,
            exchange_name: $this->exchange,
            queue_prefix: $this->queuePrefix,
            with_dlq: true,
            max_retries: 3,
            retry_delay_sec: 2,
            dlq_message_ttl_sec: 86400,
            connection_timeout: 30,
            heartbeat_timeout: 15,
        );
    }

    private function cleanupRabbitMQ(): void
    {
        $connection = null;
        try {
            $connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->pass,
                $this->vhost,
            );
            $channel = $connection->channel();

            $channel->exchange_delete($this->exchange);
            $channel->queue_delete("{$this->queuePrefix}.service-a");
            $channel->queue_delete("{$this->queuePrefix}.service-b");
            $channel->queue_delete("{$this->queuePrefix}.retry.{$this->queuePrefix}.service-a");
            $channel->queue_delete("{$this->queuePrefix}.retry.{$this->queuePrefix}.service-b");
            $channel->queue_delete("{$this->queuePrefix}.dlq.{$this->queuePrefix}.service-a");
            $channel->queue_delete("{$this->queuePrefix}.dlq.{$this->queuePrefix}.service-b");

            $channel->close();
            $connection->close();
        } catch (\Throwable $e) {
            if ($connection) {
                try {
                    $connection->close();
                } catch (\Throwable) {
                }
            }
            echo "Cleanup warning: {$e->getMessage()}\n";
        }
    }
}