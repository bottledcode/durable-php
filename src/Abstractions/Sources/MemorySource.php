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

namespace Bottledcode\DurablePhp\Abstractions\Sources;

use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Status;
use Generator;
use Withinboredom\Time\Seconds;

class MemorySource implements Source
{
    private array $events = [];
    private array $state = [];

    public static function connect(Config $config, bool $asyncSupport): static
    {
        return new self();
    }

    public function addEvents(Event ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    public function close(): void
    {
    }

    public function getPastEvents(): Generator
    {
        yield null;
    }

    public function receiveEvents(): Generator
    {
        yield from $this->events;
    }

    public function cleanHouse(): void
    {
    }

    public function storeEvent(Event $event, bool $local): string
    {
        $event->eventId = count($this->events);
        $this->events[] = $event;
        return $event->eventId;
    }

    public function workerStartup(): void
    {
    }

    public function put(string $key, mixed $data, ?Seconds $ttl = null, ?int $etag = null): void
    {
        $this->state[$key] = $data;
    }

    public function get(string $key, string $class): mixed
    {
        return $this->state[$key];
    }

    public function ack(Event $event): void
    {
        $this->events = array_filter($this->events, fn(Event $e) => $e->eventId !== $event->eventId);
    }

    public function watch(StateId $stateId, RuntimeStatus ...$expected): Status|null
    {
        throw new \LogicException('not implemented');
    }
}
