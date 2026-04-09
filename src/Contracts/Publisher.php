<?php

namespace Core\Messaging\Contracts;

interface Publisher
{
    /**
     * @param string $message
     * @return void
     */
    public function publish(string $message): void;

    /**
     * @return void
     */
    public function close(): void;
}