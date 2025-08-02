<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\State\Ids\StateId;

class WithFrom extends Event implements HasInnerEventInterface
{
    public function __construct(string $eventId, public StateId $from, public readonly Event $innerEvent)
    {
        parent::__construct($eventId);
    }

    public static function forEvent(StateId $from, Event $innerEvent): Event
    {
        return new self($innerEvent->eventId, $from, $innerEvent);
    }

    public function __toString(): string
    {
        return sprintf('WithFrom(%s, %s)', $this->from, $this->innerEvent);
    }

    public function getInnerEvent(): Event
    {
        return $this->innerEvent;
    }
}
