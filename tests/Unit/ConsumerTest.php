<?php

namespace Tests\Unit;

use Core\Messaging\InternalConfig;
use Core\Messaging\Consumer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

class ConsumerTest extends TestCase
{
    private InternalConfig $config;

    protected function setUp(): void
    {
        $this->config = new InternalConfig(
            host: 'localhost',
            port: 5672,
            login: 'guest',
            password: 'guest',
            vhost: '/',
            service_id: 'test-service',
            exchange_name: 'events',
            queue_prefix: 'evt',
            with_dlq: true,
            max_retries: 2,
            retry_delay_sec: 10,
            dlq_message_ttl_sec: 86400,
            connection_timeout: 120,
            heartbeat_timeout: 60,
        );
    }

    private function createConsumerMock($methods = ['connect', 'setupTopology', 'reconnect'])
    {
        $consumer = $this->getMockBuilder(Consumer::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods($methods)
            ->getMock();

        return $consumer;
    }

    private function setChannel($consumer, $channel)
    {
        $reflection = new \ReflectionClass(Consumer::class);
        $property = $reflection->getProperty('channel');
        $property->setAccessible(true);
        $property->setValue($consumer, $channel);
    }

    public function testConsumeTimeoutExits()
    {
        $consumer = $this->createConsumerMock();
        $channelMock = $this->createMock(AMQPChannel::class);
        $this->setChannel($consumer, $channelMock);

        $consumer->expects($this->once())->method('connect');
        $consumer->expects($this->once())->method('setupTopology');
        
        $channelMock->expects($this->once())->method('basic_qos');
        $channelMock->expects($this->once())->method('basic_consume');
        $channelMock->method('is_consuming')->willReturn(true);
        $channelMock->method('wait')->willThrowException(new \PhpAmqpLib\Exception\AMQPTimeoutException("timeout"));

        $consumer->consume(function() {}, 1);
        
        // Exited gracefully
        $this->assertTrue(true);
    }

    public function testHandleMessageSuccess()
    {
        $consumer = $this->createConsumerMock();
        $channelMock = $this->createMock(AMQPChannel::class);
        $this->setChannel($consumer, $channelMock);

        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(false);
        $message->expects($this->once())->method('ack');

        $called = false;
        $callback = function($msg) use (&$called) {
            $called = true;
        };

        $reflection = new \ReflectionClass(Consumer::class);
        $method = $reflection->getMethod('handleMessage');
        $method->setAccessible(true);
        $method->invoke($consumer, $message, $callback);

        $this->assertTrue($called);
    }

    public function testHandleMessageLoopbackPrevention()
    {
        $consumer = $this->createConsumerMock();
        $channelMock = $this->createMock(AMQPChannel::class);
        $this->setChannel($consumer, $channelMock);

        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(true);
        $message->method('get')->with('application_headers')->willReturn(new AMQPTable(['service_id' => 'test-service']));
        $message->expects($this->once())->method('ack');

        $called = false;
        $callback = function($msg) use (&$called) {
            $called = true;
        };

        $reflection = new \ReflectionClass(Consumer::class);
        $method = $reflection->getMethod('handleMessage');
        $method->setAccessible(true);
        $method->invoke($consumer, $message, $callback);

        $this->assertFalse($called);
    }

    public function testHandleMessageFailureSendsToRetry()
    {
        $consumer = $this->createConsumerMock();
        $channelMock = $this->createMock(AMQPChannel::class);
        $this->setChannel($consumer, $channelMock);

        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(false);
        $message->method('getBody')->willReturn('{"test": "data"}');
        $message->method('get')->willReturnCallback(function($key) {
            return $key === 'content_type' ? 'application/json' : '123';
        });
        
        $message->expects($this->once())->method('ack');

        $channelMock->expects($this->once())
            ->method('basic_publish')
            ->with($this->callback(function(AMQPMessage $msg) {
                $headers = $msg->get('application_headers')->getNativeData();
                return $headers['retry_count'] === 1;
            }), 'events.retry', 'evt.test-service');

        $reflection = new \ReflectionClass(Consumer::class);
        $method = $reflection->getMethod('handleFailure');
        $method->setAccessible(true);
        $method->invoke($consumer, $message, new \RuntimeException("Test error"));
    }

    public function testHandleMessageFailureSendsToDlqAfterMaxRetries()
    {
        $consumer = $this->createConsumerMock();
        $channelMock = $this->createMock(AMQPChannel::class);
        $this->setChannel($consumer, $channelMock);

        $message = $this->createMock(AMQPMessage::class);
        $message->method('has')->with('application_headers')->willReturn(true);
        $message->method('get')->willReturnCallback(function($key) {
            if ($key === 'application_headers') return new AMQPTable(['retry_count' => 1]);
            return $key === 'content_type' ? 'application/json' : '123';
        });
        $message->method('getBody')->willReturn('{"test": "data"}');
        
        $message->expects($this->once())->method('ack');

        $channelMock->expects($this->once())
            ->method('basic_publish')
            ->with($this->callback(function(AMQPMessage $msg) {
                $headers = $msg->get('application_headers')->getNativeData();
                return $headers['retry_count'] === 2; // max_retries is 2
            }), '', 'evt.dlq.evt.test-service'); // Fix expected dlq queue name format!

        $reflection = new \ReflectionClass(Consumer::class);
        $method = $reflection->getMethod('handleFailure');
        $method->setAccessible(true);
        $method->invoke($consumer, $message, new \RuntimeException("Test error"));
    }
    
    public function testSignalHandler()
    {
        $consumer = new Consumer($this->config);
        
        $consumer->signalHandler(SIGTERM);
        
        $reflection = new \ReflectionClass(Consumer::class);
        $shouldQuit = $reflection->getProperty('shouldQuit');
        $shouldQuit->setAccessible(true);
        
        $this->assertTrue($shouldQuit->getValue($consumer));
        
        $consumer = new Consumer($this->config);
        $consumer->signalHandler(SIGHUP);
        
        $shouldRestart = $reflection->getProperty('shouldRestart');
        $shouldRestart->setAccessible(true);
        
        $this->assertTrue($shouldRestart->getValue($consumer));
    }
}
