<?php

declare(strict_types=1);

namespace Core\Messaging;

use Core\Messaging\Contracts\Consumer as ConsumerContract;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class Consumer implements ConsumerContract
{
    use HandlesConnectionTrait;
    use HandlesLoggingTrait;
    use HandlesMessageHeadersTrait;

    protected bool $shouldQuit = false;
    protected bool $shouldRestart = false;
    protected ?string $consumerTag = null;

    public function __construct(
        protected readonly InternalConfig $config,
    )
    {
    }

    public function consume(callable $callback, int $timeoutSeconds = 0): mixed
    {
        $this->setupSignals();

        $startTime = time();
        $this->connect($this->config);
        $this->setupTopology($this->config);

        do {
            $queueName = $this->getMainQueueName();

            if ($timeoutSeconds > 0) {
                $remaining = $timeoutSeconds - (time() - $startTime);
                if ($remaining <= 0) {
                    break;
                }
            }

            $this->logger?->info("starting consumer on queue: {$queueName}");

            $this->channel->basic_qos(null, 1, false);
            $this->channel->basic_consume(
                $queueName,
                $this->generateConsumerTag(),
                false,
                false,
                false,
                false,
                function (AMQPMessage $message) use ($callback) {
                    $this->handleMessage($message, $callback);
                },
                null,
                ['x-cancel-on-ha-failover' => ['t', true]]
            );

            while ($this->channel->is_consuming() && !$this->shouldQuit) {
                if (extension_loaded('pcntl')) {
                    pcntl_signal_dispatch();
                }

                if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                    break;
                }

                try {
                    $waitTimeout = $timeoutSeconds > 0 ? min($timeoutSeconds - (time() - $startTime), $this->config->connection_timeout) : $this->config->connection_timeout;
                    $this->channel->wait(null, false, (float)$waitTimeout);
                } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException|\PhpAmqpLib\Exception\AMQPIOException $e) {
                    $this->logger?->warning("connection lost, attempting to reconnect: " . $e->getMessage());
                    $this->reconnect();
                    break;
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                        break;
                    }
                }
            }

            if ($this->shouldQuit) {
                $this->close();
            }
        } while (!$this->shouldQuit && $this->shouldRestart && ($timeoutSeconds <= 0 || (time() - $startTime) < $timeoutSeconds));

        return null;
    }

    private function handleMessage(AMQPMessage $message, callable $callback): void
    {
        $publisherServiceId = $this->getHeader($message, MessageHeaders::SERVICE_ID);

        if ($publisherServiceId !== null && (string)$publisherServiceId === (string)$this->config->service_id) {
            $isRetry = (int)$this->getHeader($message, MessageHeaders::RETRY_COUNT, 0) > 0;
            if (!$isRetry) {
                $this->logger?->info("Ignoring message from same service (loopback prevention)");
                $message->ack();
                return;
            }
        }

        try {
            $callback($message);
            $message->ack();
        } catch (\Throwable $e) {
            $this->handleFailure($message, $e);
        }
    }

    private function handleFailure(AMQPMessage $message, \Throwable $e): void
    {
        $this->logger?->error("Message processing failed: " . $e->getMessage());

        $retryCount = (int)$this->getHeader($message, MessageHeaders::RETRY_COUNT, 0);
        $retryCount++;

        if ($retryCount >= $this->config->max_retries) {
            $this->logger?->info("Sending to DLQ. Retry count: " . $retryCount);
            $this->sendToDlq($message, $retryCount, $this->getHeaders($message));
            return;
        }

        $this->logger?->info("Sending to Retry. Retry count: " . $retryCount);
        $this->sendToRetry($message, $retryCount, $this->getHeaders($message));
    }

    private function sendToRetry(AMQPMessage $message, int $retryCount, array $headers): void
    {
        $headers[MessageHeaders::RETRY_COUNT] = $retryCount;
        
        $requeue = new AMQPMessage(
            $message->getBody(),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => $message->get('content_type'),
                'message_id' => $message->get('message_id'),
                'application_headers' => new AMQPTable($headers),
            ]
        );

        $retryExchange = $this->getRetryExchangeName();
        $mainQueue = $this->getMainQueueName();

        // Publish to retry exchange with routing key = main queue
        // This goes to retry queue briefly, then DLX routes to main queue after TTL
        $this->channel->basic_publish(
            $requeue,
            $retryExchange,
            $mainQueue,
        );
        $this->logger?->info("Published to retry exchange $retryExchange with routing key $mainQueue");

        $message->ack();
    }

    private function sendToDlq(AMQPMessage $message, int $retryCount, array $headers): void
    {
        $headers[MessageHeaders::RETRY_COUNT] = $retryCount;

        $dlqMessage = new AMQPMessage(
            $message->getBody(),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => $message->get('content_type'),
                'message_id' => $message->get('message_id'),
                'expiration' => $this->config->dlq_message_ttl_sec > 0
                    ? (string)($this->config->dlq_message_ttl_sec * 1000)
                    : null,
                'application_headers' => new AMQPTable($headers),
            ]
        );

        $dlqQueue = $this->getDlqQueueName();

        $this->channel->basic_publish(
            $dlqMessage,
            '',
            $dlqQueue,
        );

        $message->ack();
    }

    private function reconnect(): void
    {
        $this->close();
        $this->connect($this->config);
        $this->shouldRestart = true;
    }

    private function setupSignals(): void
    {
        if (!extension_loaded('pcntl')) return;

        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        pcntl_signal(SIGINT, [$this, 'signalHandler']);
        pcntl_signal(SIGQUIT, [$this, 'signalHandler']);
        pcntl_signal(SIGHUP, [$this, 'signalHandler']);
    }

    public function signalHandler(int $signalNumber): void
    {
        match ($signalNumber) {
            SIGTERM, SIGQUIT => $this->shouldQuit = true,
            SIGINT => $this->shouldQuit = true,
            SIGHUP => $this->shouldRestart = true,
            default => null,
        };
    }

    private function generateConsumerTag(): string
    {
        return $this->consumerTag ??= sprintf(
            'consumer-%s-%s',
            $this->config->service_id,
            bin2hex(random_bytes(4))
        );
    }
}