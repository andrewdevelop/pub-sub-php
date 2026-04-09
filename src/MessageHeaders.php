<?php

declare(strict_types=1);

namespace Core\Messaging;

final class MessageHeaders
{
    public const SERVICE_ID = 'service_id';
    public const MESSAGE_ID = 'message_id';
    public const RETRY_COUNT = 'retry_count';
}
