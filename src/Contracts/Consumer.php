<?php

namespace Core\Messaging\Contracts;

interface Consumer
{
    /**
     * @param callable $callback
     * @param int $timeoutSeconds
     * @param bool $enableSignalHandling
     * @return mixed
     */
    public function consume(callable $callback, int $timeoutSeconds = 0, bool $enableSignalHandling = true);

    /**
     * @return mixed
     */
    public function close();
}