<?php

namespace Tests\Unit;

use Core\Messaging\HandlesLoggingTrait;
use Core\Messaging\HandlesConnectionTrait;
use Core\Messaging\InternalConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TraitDummy
{
    use HandlesLoggingTrait;
    use HandlesConnectionTrait;
    
    public function __construct(public InternalConfig $config) {}
}

class TraitsTest extends TestCase
{
    public function testLoggingTrait()
    {
        $config = InternalConfig::fromArray([]);
        $dummy = new TraitDummy($config);
        $logger = new NullLogger();
        
        $dummy->withLogger($logger);
        
        $reflection = new \ReflectionClass($dummy);
        $property = $reflection->getProperty('logger');
        $property->setAccessible(true);
        
        $this->assertSame($logger, $property->getValue($dummy));
    }
    
    public function testConnectionTraitClose()
    {
        $config = InternalConfig::fromArray([]);
        $dummy = new TraitDummy($config);
        
        $channelMock = $this->createMock(AMQPChannel::class);
        $channelMock->expects($this->once())->method('close');
        
        $connMock = $this->createMock(AMQPStreamConnection::class);
        $connMock->expects($this->once())->method('close');
        
        $reflection = new \ReflectionClass($dummy);
        
        $prop = $reflection->getProperty('channel');
        $prop->setAccessible(true);
        $prop->setValue($dummy, $channelMock);
        
        $prop2 = $reflection->getProperty('connection');
        $prop2->setAccessible(true);
        $prop2->setValue($dummy, $connMock);
        
        $dummy->close();
        
        $this->assertNull($prop->getValue($dummy));
        $this->assertNull($prop2->getValue($dummy));
        $this->assertNull($dummy->getChannel());
        $this->assertNull($dummy->getConnection());
    }

    public function testConnectionTraitCloseWithExceptionsIsGraceful()
    {
        $config = InternalConfig::fromArray([]);
        $dummy = new TraitDummy($config);
        
        $channelMock = $this->createMock(AMQPChannel::class);
        $channelMock->method('close')->willThrowException(new \Exception("channel error"));
        
        $connMock = $this->createMock(AMQPStreamConnection::class);
        $connMock->method('close')->willThrowException(new \Exception("conn error"));
        
        $reflection = new \ReflectionClass($dummy);
        
        $prop = $reflection->getProperty('channel');
        $prop->setAccessible(true);
        $prop->setValue($dummy, $channelMock);
        
        $prop2 = $reflection->getProperty('connection');
        $prop2->setAccessible(true);
        $prop2->setValue($dummy, $connMock);
        
        $dummy->close();
        
        // Should not throw
        $this->assertNull($dummy->getChannel());
    }
}
