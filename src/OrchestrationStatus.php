<?php

namespace Bottledcode\DurablePhp;

enum OrchestrationStatus
{
    case Running;
    case Completed;
    case ContinuedAsNew;
    case Failed;
    case Canceled;
    case Terminated;
    case Pending;
    case Suspended;

    public function isTerminalState(): bool
    {
        return !$this->isRunningOrPending();
    }

    public function isRunningOrPending(): bool
    {
        return $this === self::Running || $this === self::Pending;
    }
}
