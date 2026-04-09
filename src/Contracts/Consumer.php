<?php

namespace Core\Messaging\Contracts;

interface Consumer
{
    /**
     * @param callable $callback
     * @param int $timeoutSeconds
     * @return mixed
     */
    public function consume(callable $callback, int $timeoutSeconds = 3600);

    /**
     * @return mixed
     */
    public function close();
}