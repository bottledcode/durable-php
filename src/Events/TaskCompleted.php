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

use Bottledcode\DurablePhp\Events\Shares\NeedsSource;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\State\Serializer;
use Ramsey\Uuid\Uuid;

#[NeedsSource(Operation::Completion)]
class TaskCompleted extends Event
{
    public function __construct(string $eventId, public string $scheduledId, public string $type, public array|null $result = null)
    {
        parent::__construct($eventId);
    }

    public static function forId(string $scheduledId, mixed $result = null): self
    {
        return new self(Uuid::uuid7(), $scheduledId, get_debug_type($result), $result ? Serializer::serialize($result) : null);
    }

    public function getResult(): mixed
    {
        return $this->result ? Serializer::deserialize($this->result, $this->type) : null;
    }

    public function __toString(): string
    {
        return sprintf(
            'TaskCompleted(%s, %s, %s)',
            $this->eventId,
            $this->scheduledId,
            json_encode($this->result),
        );
    }
}
