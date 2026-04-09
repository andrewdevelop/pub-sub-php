<?php

namespace Tests\Unit;

use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\DomainEvent;
use Core\Messaging\Console\ConsumeMessages;
use Core\Messaging\Contracts\Consumer;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

class ConsumeMessagesTest extends TestCase
{
    public function testHandleDispatchesEvent()
    {
        $dispatcher = $this->createMock(EventDispatcher::class);
        $consumer = $this->createMock(Consumer::class);

        $command = new ConsumeMessages($dispatcher, $consumer);

        $consumer->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function($callback) {
                $msg = new AMQPMessage('{"name":"test.event","payload":{"test":"data"}}');
                $callback($msg);
            });

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function(DomainEvent $event) {
                return $event->name === 'test.event' && $event->payload->toArray() === ['test' => 'data'];
            }));

        $command->handle();
    }
    
    public function testHandleThrowsOnInvalidPayload()
    {
        $dispatcher = $this->createMock(EventDispatcher::class);
        $consumer = $this->createMock(Consumer::class);

        $command = new ConsumeMessages($dispatcher, $consumer);

        $consumer->expects($this->once())
            ->method('consume')
            ->willReturnCallback(function($callback) {
                $msg = new AMQPMessage('invalid json');
                $callback($msg);
            });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid payload received, expected JSON object or array.');

        $command->handle();
    }
}
