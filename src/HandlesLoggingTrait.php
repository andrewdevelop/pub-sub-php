<?php

declare(strict_types=1);

namespace Core\Messaging;

use Psr\Log\LoggerInterface;

/**
 * Trait for handling logging
 */
trait HandlesLoggingTrait
{
    protected LoggerInterface|null $logger = null;

    public function withLogger(LoggerInterface $logger): static
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @param string $message
     * @param string $level
     * @return void
     */
    protected function log(string $message, string $level = 'info'): void
    {
        if ($this->logger === null) {
            return;
        }

        // Get service_id from config if available (requires the using class to have $config property)
        $serviceId = 'unknown';
        if (property_exists($this, 'config') && isset($this->config)) {
            $serviceId = $this->config->service_id;
        }

        $context = [
            'source' => get_called_class(),
            'service_id' => $serviceId,
            'hostname' => gethostname(),
        ];
        $this->logger->log($level, $message, $context);
    }
}