<?php
/*
 * Copyright ©2024 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp\Events;

use Ramsey\Uuid\Uuid;

class WithPriority extends Event implements HasInnerEventInterface
{
    private function __construct(public string $eventId, public int $priority, private Event $innerEvent)
    {
        parent::__construct($this->eventId ?? Uuid::uuid7()->toString());
    }

    public static function low(Event $event): self
    {
        return new self($event->eventId, 500, $event);
    }

    public static function normal(Event $event): self
    {
        return new self($event->eventId, 100, $event);
    }

    public static function high(Event $event): self
    {
        return new self($event->eventId, 50, $event);
    }

    public static function system(Event $event): self
    {
        return new self($event->eventId, 0, $event);
    }

    public function __toString(): string
    {
        return sprintf("WithPriority(%d, %s)", $this->priority, $this->innerEvent);
    }

    #[\Override] public function getInnerEvent(): Event
    {
        return $this->innerEvent;
    }
}
