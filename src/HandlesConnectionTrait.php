<?php

declare(strict_types=1);

namespace Core\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Trait for handling AMQP connections
 */
trait HandlesConnectionTrait
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    protected function getCid(): string
    {
        $cid = get_called_class();
        $cid = substr($cid, strrpos($cid, '\\') + 1);
        return strtolower($cid);
    }

    public function connect(InternalConfig $config): void
    {
        $this->connection = ConnectionFactory::create($config, $this->getCid());
        $this->channel = $this->connection->channel();
    }

    /**
     * @throws \Throwable
     */
    protected function setupTopology(): void
    {
        Topology::setup(
            channel: $this->channel,
            main_exchange: $this->getMainExchangeName(),
            main_queue: $this->getMainQueueName(),
            retry_exchange: $this->getRetryExchangeName(),
            retry_queue: $this->getRetryQueueName(),
            dead_letter_queue: $this->getDlqQueueName(),
            retry_delay_sec: $this->config->retry_delay_sec,
            dlq_message_ttl_sec: $this->config->dlq_message_ttl_sec
        );
    }

    public function close(): void
    {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (\Throwable) {
            }
        }

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (\Throwable) {
            }
        }

        $this->channel = null;
        $this->connection = null;

        $this->logger?->info("connection closed");
    }

    public function getChannel(): ?AMQPChannel
    {
        return $this->channel;
    }

    public function getConnection(): ?AMQPStreamConnection
    {
        return $this->connection;
    }

    protected function getMainExchangeName(): string
    {
        return $this->config->exchange_name;
    }

    protected function getMainQueueName(): string
    {
        return sprintf('%s.%s', $this->config->queue_prefix, $this->config->service_id);
    }

    protected function getRetryQueueName(): string
    {
        return sprintf('%s.retry.%s.%s', $this->config->queue_prefix, $this->config->queue_prefix, $this->config->service_id);
    }

    protected function getDlqQueueName(): string
    {
        return sprintf('%s.dlq.%s.%s', $this->config->queue_prefix, $this->config->queue_prefix, $this->config->service_id);
    }

    protected function getRetryExchangeName(): string
    {
        return sprintf('%s.retry', $this->config->exchange_name);
    }

    public function __destruct()
    {
        $this->close();
    }
}