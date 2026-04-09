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
            host: $config->host,
            port: $config->port,
            user: $config->login,
            password: $config->password,
            vhost: $config->vhost,
            insist: false,
            login_method: 'AMQPLAIN',
            login_response: null,
            locale: 'en_US',
            connection_timeout: $config->connection_timeout,
            read_write_timeout: $config->connection_timeout,
            context: null,
            keepalive: true,
            heartbeat:  $config->heartbeat_timeout,
            config: $amqp_config
        );
    }
}