<?php

declare(strict_types=1);

namespace Core\Messaging;

use Exception;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class ConnectionFactory
{
    /**
     * @throws Exception
     */
    public static function create(InternalConfig $config, string $identifier): AMQPStreamConnection
    {
        $amqp_config = new AMQPConnectionConfig();
        $amqp_config->setConnectionName(sprintf("%s@%s-%s-%s", $config->service_id, gethostname(), $identifier, date('Ymd-His')));

        return new AMQPStreamConnection(
            $config->host,
            $config->port,
            $config->login,
            $config->password,
            $config->vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            (float) $config->connection_timeout,
            (float) $config->connection_timeout,
            null,
            true,
            (int) $config->heartbeat_timeout
        );
    }
}