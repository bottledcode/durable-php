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

namespace Bottledcode\DurablePhp\State;

use Bottledcode\DurablePhp\DurableFuture;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\MonotonicClock;
use Closure;
use Crell\Serde\Attributes\DictionaryField;
use Crell\Serde\Attributes\Field;
use DateTimeImmutable;

class HistoricalStateTracker
{
    public function __construct(
        /**
         * @var \WeakMap<DurableFuture, Closure>
         */
        #[Field(exclude: true)]
        private \WeakMap|null $eventSlots = null,
        #[Field(exclude: true)]
        private int|null $readKey = null,
        #[Field(exclude: true)]
        private int $identityKey = 0,
        /**
         * @var ResultSet[]
         */
        #[DictionaryField(arrayType: ResultSet::class)]
        private array $results = [],
        /**
         * @var ReceivedSet[]
         */
        #[DictionaryField(arrayType: ReceivedSet::class)]
        private array $received = [],
        /**
         * @var string[]
         */
        private array $expecting = [],
        private int $writeKey = 0,
        /**
         * @var DateTimeImmutable[]
         */
        #[DictionaryField(arrayType: DateTimeImmutable::class)]
        private array $currentTime = [],
    ) {
    }

    /**
     * Begin tracking an event and it's future.
     *
     * @param string $identity
     * @param string $eventId
     * @return void
     */
    public function sentEvent(string $identity, string $eventId): void
    {
        $this->expecting[$identity] = $eventId;
    }

    /**
     * Get a unique, replayable identity.
     */
    public function getIdentity(): string
    {
        $this->identityKey ??= 0;
        return 'identity' . $this->identityKey++;
    }

    /**
     * Determine if we are expecting an identity.
     */
    public function hasSentIdentity(string $identity): bool
    {
        return array_key_exists($identity, $this->expecting);
    }

    public function trackFuture(Closure $matcher, DurableFuture $future): void
    {
        $this->getSlots()[$future] = $matcher;
    }

    private function getReadKey(): int
    {
        return $this->readKey ??= 0;
    }

    /**
     * @return \WeakMap<DurableFuture,Closure>
     */
    private function getSlots(): \WeakMap
    {
        return $this->eventSlots ??= new \WeakMap();
    }

    public function resetState(): void
    {
        $this->readKey = null;
        $this->eventSlots = null;
        $this->identityKey = 0;
    }

    /**
     * Assign a result to an awaiting slot.
     *
     * @param TaskCompleted|TaskFailed $event
     * @return void
     */
    public function receivedEvent(TaskCompleted|TaskFailed|RaiseEvent $event): void
    {
        // ok, we've received an event, so add it to the received list
        $received = new ReceivedSet($event);

        // see if we are expecting it?
        $identity = array_search($event->scheduledId ?? $event->eventId, $this->expecting, true);

        // we are expecting it, so annotate the received list
        $received->identity = $identity;

        // add it to the received list
        $this->received[] = $received;
    }

    /**
     * Match waiting futures to event slots.
     *
     * @param DurableFuture ...$futures
     * @return array<int, DurableFuture>
     * @throws \Exception
     */
    public function awaitingFutures(DurableFuture ...$futures): array
    {
        $this->writeFutures($futures);
        return $this->readFutures($futures);
    }

    /**
     * @param array<DurableFuture> $futures
     * @return void
     */
    private function writeFutures(array $futures): void
    {
        // now we hunt for unsolved futures
        foreach ($futures as $idx => $future) {
            // see if we have a match already
            if($this->results[$this->getReadKey()] ?? false and $this->results[$this->getReadKey()]->match[$idx] ?? false) {
                continue;
            }

            foreach ($this->received as $order => $received) {
                $callback = $this->getSlots()[$future];
                [$result, $found] = $callback($received->event, $received->identity);
                if ($found) {
                    $this->results[$this->getReadKey()] ??= new ResultSet();
                    $this->results[$this->getReadKey()]->match[$idx] = $result;
                    $this->results[$this->getReadKey()]->order[] = $idx;
                    // unset the received event and the future
                    unset($this->received[$order]);
                    break;
                }
            }
        }
        $this->received = array_values($this->received);
        ++$this->writeKey;
    }

    /**
     * @param array<DurableFuture> $futures
     * @return array
     */
    private function readFutures(array $futures): array
    {
        $completedInOrder = [];

        if (array_key_exists($this->readKey, $this->results)) {
            foreach($this->results[$this->readKey]->order as $idx) {
                /** @var DurableFuture $handler */
                $handler = $futures[$idx];
                $result = $this->results[$this->readKey]->match[$idx];
                switch (true) {
                    case $result instanceof TaskCompleted:
                        $handler->future->complete($result->result);
                        $completedInOrder[] = $handler;
                        break;
                    case $result instanceof TaskFailed:
                        $details = $result->reason . PHP_EOL . $result->details;
                        $exceptionType = $result->previous ?? \Exception::class;
                        $handler->future->error(new $exceptionType($details));
                        $completedInOrder[] = $handler;
                        break;
                    case $result instanceof RaiseEvent:
                        $handler->future->complete($result->eventData);
                        $completedInOrder[] = $handler;
                        break;
                }
            }
        }

        $this->readKey = $this->getReadKey() + 1;

        return $completedInOrder;
    }

    public function setCurrentTime(DateTimeImmutable $time): DateTimeImmutable
    {
        return $this->currentTime[$this->getReadKey()] ??= $time;
    }

    public function getCurrentTime(): DateTimeImmutable
    {
        return $this->currentTime[$this->getReadKey()] ??= MonotonicClock::current()->now();
    }

    public function isReading(): bool
    {
        return $this->writeKey >= $this->getReadKey();
    }
}
