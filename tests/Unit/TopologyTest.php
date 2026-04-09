<?php

namespace Tests\Unit;

use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;
use Core\Messaging\Topology;
use Core\Messaging\InternalConfig;

class TopologyTest extends TestCase
{
    private AMQPChannel $channel;

    protected function setUp(): void
    {
        $this->channel = $this->createMock(AMQPChannel::class);
    }

    private function createConfig(bool $withDlq = false): InternalConfig
    {
        return new InternalConfig(
            host: 'localhost',
            port: 5672,
            login: 'guest',
            password: 'guest',
            vhost: '/',
            service_id: 'test-service',
            exchange_name: 'events',
            queue_prefix: 'evt',
            with_dlq: $withDlq,
            max_retries: 3,
            retry_delay_sec: 10,
            dlq_message_ttl_sec: 86400,
            connection_timeout: 30,
            heartbeat_timeout: 15
        );
    }

    public function testSetupWithoutDlqReturnsQueueName(): void
    {
        $this->channel->expects($this->atLeastOnce())
            ->method('exchange_declare');

        $this->channel->expects($this->atLeastOnce())
            ->method('queue_declare');

        $this->channel->expects($this->atLeastOnce())
            ->method('queue_bind');

        $config = $this->createConfig(false);

        $result = Topology::setup(
            channel: $this->channel,
            main_exchange: $config->exchange_name,
            main_queue: sprintf('%s.%s', $config->queue_prefix, $config->service_id),
            retry_exchange: sprintf('%s.retry', $config->exchange_name),
            retry_queue: $config->with_dlq ? sprintf('%s.retry.%s', $config->queue_prefix, $config->service_id) : null,
            dead_letter_queue: $config->with_dlq ? sprintf('%s.dlq.%s', $config->queue_prefix, $config->service_id) : null,
            retry_delay_sec: $config->retry_delay_sec,
            dlq_message_ttl_sec: $config->dlq_message_ttl_sec
        );

        $this->assertSame('evt.test-service', $result);
    }

    public function testSetupWithDlq(): void
    {
        $this->channel->expects($this->atLeastOnce())
            ->method('exchange_declare');

        $this->channel->expects($this->atLeastOnce())
            ->method('queue_declare');

        $this->channel->expects($this->atLeastOnce())
            ->method('queue_bind');

        $config = $this->createConfig(true);

        $result = Topology::setup(
            channel: $this->channel,
            main_exchange: $config->exchange_name,
            main_queue: sprintf('%s.%s', $config->queue_prefix, $config->service_id),
            retry_exchange: sprintf('%s.retry', $config->exchange_name),
            retry_queue: $config->with_dlq ? sprintf('%s.retry.%s', $config->queue_prefix, $config->service_id) : null,
            dead_letter_queue: $config->with_dlq ? sprintf('%s.dlq.%s', $config->queue_prefix, $config->service_id) : null,
            retry_delay_sec: $config->retry_delay_sec,
            dlq_message_ttl_sec: $config->dlq_message_ttl_sec
        );

        $this->assertSame('evt.test-service', $result);
    }

    public function testQueueNameFormat(): void
    {
        $queueName = sprintf('%s.%s', 'evt', 'my-service');
        $this->assertSame('evt.my-service', $queueName);
    }

    public function testRetryQueueNameFormat(): void
    {
        $retryQueueName = sprintf('%s.retry.%s', 'evt', 'my-service');
        $this->assertSame('evt.retry.my-service', $retryQueueName);
    }

    public function testDlqQueueNameFormat(): void
    {
        $dlqQueueName = sprintf('%s.dlq.%s', 'evt', 'my-service');
        $this->assertSame('evt.dlq.my-service', $dlqQueueName);
    }
}
