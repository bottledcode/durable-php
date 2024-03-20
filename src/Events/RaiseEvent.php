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

use Bottledcode\DurablePhp\Events\Shares\NeedsTarget;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Ramsey\Uuid\Uuid;

#[NeedsTarget(Operation::Signal)]
class RaiseEvent extends Event
{
    public function __construct(string $eventId, public string $eventName, public array $eventData)
    {
        parent::__construct($eventId);
    }

    public static function forOperation(string $operation, array $input): static
    {
        return new static(Uuid::uuid7(), '__signal', ['input' => $input, 'operation' => $operation]);
    }

    public static function forCustom(string $name, array $eventData): static
    {
        return new static(Uuid::uuid7(), ltrim($name, '_'), $eventData);
    }

    public static function forLock(string $owner): static
    {
        return new static(Uuid::uuid7(), '__lock', ['owner' => $owner]);
    }

    public static function forLockNotification(string $owner): static
    {
        return new static(Uuid::uuid7(), '__lock_notification', ['owner' => $owner]);
    }

    public static function forUnlock(string $name, ?string $owner, ?string $target): static
    {
        return new static(Uuid::uuid7(), '__unlock', ['name' => $name, 'owner' => $owner, 'target' => $target]);
    }

    public static function forTimer(string $identity): static
    {
        return new static(Uuid::uuid7(), $identity, []);
    }

    public function __toString(): string
    {
        return sprintf('RaiseEvent(%s, %s, %s)', $this->eventId, $this->eventName, json_encode($this->eventData));
    }
}
