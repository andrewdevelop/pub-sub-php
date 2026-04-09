<?php

declare(strict_types=1);

namespace Core\Messaging;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

trait HandlesMessageHeadersTrait
{
    /**
     * Get all custom headers from the AMQPMessage
     *
     * @param AMQPMessage $message
     * @return array<string, mixed>
     */
    protected function getHeaders(AMQPMessage $message): array
    {
        if (!$message->has('application_headers')) {
            return [];
        }

        $table = $message->get('application_headers');
        
        if ($table instanceof AMQPTable) {
            return $table->getNativeData();
        }

        return [];
    }

    /**
     * Get a specific header from the AMQPMessage
     *
     * @param AMQPMessage $message
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getHeader(AMQPMessage $message, string $key, mixed $default = null): mixed
    {
        $headers = $this->getHeaders($message);
        return $headers[$key] ?? $default;
    }

    /**
     * Set a header on the AMQPMessage
     *
     * @param AMQPMessage $message
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function setHeader(AMQPMessage $message, string $key, mixed $value): void
    {
        $headers = $this->getHeaders($message);
        $headers[$key] = $value;
        
        $message->set('application_headers', new AMQPTable($headers));
    }
}
