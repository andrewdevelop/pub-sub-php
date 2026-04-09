<?php

declare(strict_types=1);

namespace Core\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

class Topology
{
    public static function setup(
        AMQPChannel $channel,
        string      $main_exchange,
        string      $main_queue,
        string      $retry_exchange,
        ?string     $retry_queue,
        ?string     $dead_letter_queue,
        int         $retry_delay_sec = 10,
        int         $dlq_message_ttl_sec = 0
    ): string
    {
        // Declare main fanout exchange
        $channel->exchange_declare(
            exchange: $main_exchange,
            type: 'fanout',
            durable: true,
            auto_delete: false,
        );
        $arguments = [];

        $with_dlq = !is_null($retry_queue) && !is_null($dead_letter_queue);

        if ($with_dlq) {
            // Declare Retry Exchange (Direct)
            $channel->exchange_declare($retry_exchange, 'direct', false, true, false);

            // Declare Retry Queue (TTL = Xs, DLX points back to main queue)
            $retry_args = new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $main_queue,
                'x-message-ttl' => $retry_delay_sec * 1000,
            ]);
            $channel->queue_declare($retry_queue, false, true, false, false, false, $retry_args);
            $channel->queue_bind(
                queue: $retry_queue,
                exchange: $retry_exchange,
                routing_key: $main_queue,
            );

            // Declare DLQ Queue
            $dlq_args_arr = [];
            if ($dlq_message_ttl_sec > 0) {
                $dlq_args_arr['x-message-ttl'] = $dlq_message_ttl_sec * 1000;
            }
            $dlq_args = empty($dlq_args_arr) ? [] : new AMQPTable($dlq_args_arr);
            
            // Queue declare with or without arguments depending on if they are empty
            if (empty($dlq_args_arr)) {
                $channel->queue_declare($dead_letter_queue, false, true, false, false, false);
            } else {
                $channel->queue_declare($dead_letter_queue, false, true, false, false, false, $dlq_args);
            }

            // Set DLX for Main Queue to route to Retry Exchange on reject
            $arguments['x-dead-letter-exchange'] = $retry_exchange;
            $arguments['x-dead-letter-routing-key'] = $main_queue;
        }

        // Declare main queue - handle existing queue with different DLX config
        // Only pass AMQPTable when there are arguments to avoid "AMQP-rabbit doesn't define data of type []" error
        if (!empty($arguments)) {
            $queueArgs = new AMQPTable($arguments);
            
            try {
                $channel->queue_declare(
                    queue: $main_queue,
                    durable: true,
                    auto_delete: false,
                    arguments: $queueArgs,
                );
            } catch (\Throwable $e) {
                // Check if it's a DLX mismatch error
                if (str_contains($e->getMessage(), 'inequivalent arg')) {
                    // Queue exists with different DLX config - delete and recreate
                    try {
                        $channel->queue_delete($main_queue);
                    } catch (\Throwable $deleteError) {
                        // Ignore delete errors, proceed with creation
                    }
                    
                    // Now create with correct arguments
                    $channel->queue_declare(
                        queue: $main_queue,
                        durable: true,
                        auto_delete: false,
                        arguments: $queueArgs,
                    );
                } else {
                    // Re-throw other exceptions
                    throw $e;
                }
            }
        } else {
            // No arguments - declare without the arguments parameter
            $channel->queue_declare(
                queue: $main_queue,
                durable: true,
                auto_delete: false
            );
        }
        
        $channel->queue_bind(
            queue: $main_queue,
            exchange: $main_exchange,
        );

        return $main_queue;
    }
}