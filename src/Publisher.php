<?php

declare(strict_types=1);

namespace Core\Messaging;

use Core\Messaging\Contracts\Publisher as PublisherContract;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class Publisher implements PublisherContract
{
    use HandlesConnectionTrait;
    use HandlesLoggingTrait;
    use HandlesMessageHeadersTrait;

    public function __construct(
        protected readonly InternalConfig $config,
    )
    {
    }

    /**
     * @throws \Throwable
     */
    public function publish(string $message): void
    {
        $this->connect($this->config);
        $this->setupTopology();

        $messageId = $this->generateIdempotencyKey($message);

        $msg = new AMQPMessage(
            $message,
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
                'message_id' => $messageId,
            ]
        );
        
        $this->setHeader($msg, MessageHeaders::SERVICE_ID, $this->config->service_id);
        $this->setHeader($msg, MessageHeaders::MESSAGE_ID, $messageId);

        $this->channel->basic_publish(
            $msg,
            $this->config->exchange_name,
        );

        $this->close();
    }

    private function generateIdempotencyKey(string $data): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $data)->toString();
    }
}