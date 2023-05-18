<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\TaskMessage;
use Ramsey\Uuid\UuidInterface;

class ActivityTransfer
{
    public bool $isReplaying = false;

    public string $eventId;

    public function __construct(public \DateTimeImmutable $timestamp, public array $activities)
    {
    }

    public static function activity(TaskMessage $taskMessage, UuidInterface|null $senderId): array
    {
        return [$taskMessage, $senderId];
    }
}
