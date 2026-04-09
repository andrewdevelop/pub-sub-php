<?php

namespace Core\Messaging\Console;

use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\DomainEvent;
use Core\Messaging\Contracts\Consumer;
use Exception;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeMessages extends Command
{
    protected $signature = 'mq:server
                            {--timeout=3600 : The number of seconds a child process can run}';

    protected $description = 'Listen to a queue.';

    public function __construct(
        protected readonly EventDispatcher $dispatcher,
        protected readonly Consumer        $consumer,
    )
    {
        parent::__construct();
    }

    public function handle()
    {
        $timeout = 0;
        try {
            $timeout = (int) ($this->option('timeout') ?? 3600);
        } catch (\Throwable $e) {
            $timeout = 3600;
        }

        try {
            $this->consumer->consume(
                callback: function (AMQPMessage $message) {
                    $event = $this->mapMessageToEvent($message);
                    $this->dispatcher->dispatch($event);
                },
                timeoutSeconds: $timeout,
            );
        } catch (\Exception $e) {
            if ($this->output) {
                $this->error('Error: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    protected function mapMessageToEvent(AMQPMessage $message): DomainEvent
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload received, expected JSON object or array.');
        }
        return new DomainEvent($payload);
    }
}
