<?php

/*
 * Copyright ©2023 Robert Landers
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

use Bottledcode\DurablePhp\State\Ids\StateId;

class WithLock extends Event implements HasInnerEventInterface
{
    public function __construct(
        string $eventId,
        public StateId $owner,
        public StateId $target,
        public Event $innerEvent
    ) {
        parent::__construct($eventId);
    }

    public static function onEntity(StateId $owner, StateId $target, Event $innerEvent): self
    {
        return new self('', $owner, $target, $innerEvent);
    }

    public function __toString(): string
    {
        return sprintf('WithLock(%s, %s, %s)', $this->owner, $this->target, $this->innerEvent);
    }

    public function getInnerEvent(): Event
    {
        $this->innerEvent->eventId = $this->eventId;
        return $this->innerEvent;
    }
}
