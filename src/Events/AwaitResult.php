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

class AwaitResult extends Event implements HasInnerEventInterface, ReplyToInterface
{
    public function __construct(string $eventId, public StateId $origin, public Event $innerEvent)
    {
        parent::__construct($eventId);
    }

    public static function forEvent(StateId $replyTo, Event $innerEvent): Event
    {
        return new AwaitResult('', $replyTo, $innerEvent);
    }

    public function getInnerEvent(): Event
    {
        $this->innerEvent->eventId = $this->eventId;
        return $this->innerEvent;
    }

    public function getReplyTo(): StateId
    {
        return $this->origin;
    }

    public function __toString(): string
    {
        return sprintf('AwaitResult(%s, %s)', $this->innerEvent, $this->origin);
    }
}
