<?php

namespace Tests\Unit;

use Core\Messaging\InternalConfig;
use Core\Messaging\Publisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

class PublisherPublishTest extends TestCase
{
    public function testPublish()
    {
        $config = new InternalConfig(
            host: 'localhost',
            port: 5672,
            login: 'guest',
            password: 'guest',
            vhost: '/',
            service_id: 'test-service',
            exchange_name: 'events',
            queue_prefix: 'evt',
            with_dlq: true,
            max_retries: 5,
            retry_delay_sec: 10,
            dlq_message_ttl_sec: 86400,
            connection_timeout: 120,
            heartbeat_timeout: 60,
        );

        $channelMock = $this->createMock(AMQPChannel::class);
        $channelMock->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->isInstanceOf(AMQPMessage::class),
                $this->equalTo('events')
            );
        $channelMock->expects($this->once())
            ->method('close');

        // Create partial mock
        $publisher = $this->getMockBuilder(Publisher::class)
            ->setConstructorArgs([$config])
            ->onlyMethods(['connect', 'setupTopology'])
            ->getMock();

        // Inject the channel mock via reflection on the base class Publisher
        $reflection = new \ReflectionClass(Publisher::class);
        $property = $reflection->getProperty('channel');
        $property->setAccessible(true);
        $property->setValue($publisher, $channelMock);

        $publisher->expects($this->once())->method('connect');
        $publisher->expects($this->once())->method('setupTopology');
        
        $publisher->publish('{"test":"data"}');
    }
}
