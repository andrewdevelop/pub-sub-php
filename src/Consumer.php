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

    protected bool $forceShutdown = false;

    public function __construct(
        protected readonly InternalConfig $config,
    )
    {
    }

    /**
     * @throws \Throwable
     */
    public function consume(callable $callback, int $timeoutSeconds = 3600): mixed
    {
        if (extension_loaded('pcntl')) {
            $this->registerSignalHandlers($timeoutSeconds);
        }

        $this->connect($this->config);
        $this->setupTopology();

        $handler = function (AMQPMessage $message) use ($callback) {
            if (extension_loaded('pcntl')) {
                pcntl_sigprocmask(SIG_BLOCK, [SIGTERM, SIGINT]);
            }

            $this->handleMessage($message, $callback);

            if (extension_loaded('pcntl')) {
                pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM, SIGINT]);
                pcntl_signal_dispatch();
            }
        };

        $queueName = $this->getMainQueueName();
        $this->logger?->info("starting consumer on queue: {$queueName}" . ($timeoutSeconds ? " (timeout {$timeoutSeconds}s)" : ''));

        $this->channel->basic_consume(
            $queueName,
            $this->generateConsumerTag(),
            false,
            false,
            false,
            false,
            $handler,
            null,
            ['x-cancel-on-ha-failover' => ['t', true]]
        );

        $this->logger?->info("waiting for messages");

        while ($this->channel->is_open() && !$this->forceShutdown) {
            try {
                $this->channel->wait();
            } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException|\PhpAmqpLib\Exception\AMQPIOException $e) {
                $this->logger?->warning("connection lost: " . $e->getMessage());
                break;
            }

            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }
        }

        $this->close();
        $this->logger?->info("consumer stopped");

        exit(0);
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

    private function registerSignalHandlers(int $timeout): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, [$this, 'handleSignals']);
        pcntl_signal(SIGINT, [$this, 'handleSignals']);
        pcntl_signal(SIGQUIT, [$this, 'handleSignals']);
        pcntl_signal(SIGHUP, [$this, 'handleSignals']);

        pcntl_signal(SIGALRM, function () use ($timeout) {
            $this->logger?->info("consumer stopping due to time limit of {$timeout}s.");
            $this->forceShutdown = true;
        });
        pcntl_alarm($timeout);
    }

    public function handleSignals(int $signal): void
    {
        $this->logger?->info("signal $signal received");

        match ($signal) {
            SIGTERM, SIGINT, SIGQUIT => $this->shutdownAndExit($signal, 0),
            SIGHUP => $this->shutdownAndExit($signal, 1),
            default => $this->logger?->error("unknown signal $signal received"),
        };
    }

    private function shutdownAndExit(int $signal, int $exitCode): void
    {
        $this->forceShutdown = true;
        $this->close();

        if (extension_loaded('pcntl')) {
            pcntl_signal($signal, SIG_DFL);
            if (extension_loaded('posix')) {
                posix_kill(getmypid(), $signal);
            }
        }

        $this->logger?->info("waiting {$this->config->restart_timeout}s before restart or close");
        sleep($this->config->restart_timeout);

        exit($exitCode);
    }

    private function generateConsumerTag(): string
    {
        return sprintf(
            'consumer-%s-%s',
            $this->config->service_id,
            bin2hex(random_bytes(4))
        );
    }
}
