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
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'mq:server
                            {--timeout=0 : The number of seconds a child process can run}';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Listen to a queue.';

    /**
     * Create a new command instance.
     */
    public function __construct(
        protected readonly EventDispatcher $dispatcher,
        protected readonly Consumer        $consumer,
    )
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     * @return void
     * @throws Exception
     */
    public function handle()
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $handler = function (int $sig) {
                $this->info("Received signal {$sig}, shutting down...");
                $this->consumer->close();
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGQUIT, $handler);
        }

        $timeout = 0;
        try {
            $timeout = (int) ($this->option('timeout') ?? 0);
        } catch (\Throwable $e) {
            // option() not available without full Laravel container
        }
        
        if ($timeout > 0 && extension_loaded('pcntl')) {
            $processId = getmypid();
            $this->info("Time limit of {$timeout}s was set to process {$processId}.");
            
            pcntl_signal(SIGALRM, function () use ($processId, $timeout) {
                $this->info("Consumer process {$processId} stopped due to time limit of {$timeout}s exceeded.");
                $this->consumer->close();
                if (extension_loaded('posix')) {
                    posix_kill($processId, SIGKILL);
                }
                exit(1);
            });
            pcntl_alarm($timeout);
        }

        try {
            $this->consumer->consume(function (AMQPMessage $message) {
                $event = $this->mapMessageToEvent($message);
                $this->dispatcher->dispatch($event);
            }, 0, false);
        } catch (\Exception $e) {
            try {
                $this->error('Error: ' . $e->getMessage());
            } catch (\Throwable $e2) {
                // output not available
            }
            $this->consumer->close();
            throw $e;
        }
    }

    /**
     * @param AMQPMessage $message
     * @return DomainEvent
     */
    protected function mapMessageToEvent(AMQPMessage $message): DomainEvent
    {
        $payload = json_decode($message->getBody(), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload received, expected JSON object or array.');
        }
        return new DomainEvent($payload);
    }
}