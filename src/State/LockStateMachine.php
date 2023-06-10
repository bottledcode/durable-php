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

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\With;
use Bottledcode\DurablePhp\Events\WithLock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Generator;

class LockStateMachine
{
    public function __construct(
        private StateId $myId, private LockStateEnum $state = LockStateEnum::ProcessEvents,
        private array $lockQueue = [],
    ) {
    }

    public function process(Event $event): Generator
    {
        $lock = $this->getLock($event);
        $innerEvent = $this->getInnerEvent($event);
        $owner = null;
        $sender = $this->getReplyTo($event);
        if ($lock) {
            $owner = $this->getOwner($lock);
        }

        restart:
        switch ($this->state) {
            case LockStateEnum::ProcessEvents:
                if (!$lock) {
                    // there is nothing to lock, so we can just process the event
                    return;
                }
                // we have a lock, so we need to decide whether to enqueue the event or process it
                // so, first, are we already participating in the lock?
                $queue = $this->lockQueue[$owner->id] ?? null;
                if ($queue === null) {
                    // we are not participating, so change state
                    $this->state = empty($lock->participants) ? LockStateEnum::Enqueue : LockStateEnum::Participants;
                    goto restart;
                }
                // add it to the queue
                /** @noinspection SuspiciousAssignmentsInspection */
                $this->state = LockStateEnum::Enqueue;
                goto restart;
            case LockStateEnum::Participants:
                // create a lock queue for ourselves
                assert($lock !== null);
                $next = $this->getNextParticipant($lock);
                $prev = $this->getPreviousParticipant($lock);
                $this->lockQueue[$owner->id] = [
                    'events' => [], 'next' => $next,
                    'previous' => $prev ?? $owner, 'sent' => $next === null,
                    'received' => $next === null, 'lockId' => $lock->eventId,
                ];
                $this->state = LockStateEnum::Enqueue;
                goto restart;
            case LockStateEnum::Enqueue:
                assert($owner !== null);
                if (!$this->isNotification($innerEvent)) {
                    $this->lockQueue[$owner->id]['events'][] = $event;
                }
                $this->state = LockStateEnum::Notify;
                goto restart;
            case LockStateEnum::Notify:
                assert($sender !== null);
                // is the current event a notification?
                if ($raisedEvent = $this->isNotification($innerEvent)) {
                    if ($sender->origin->id === ($this->lockQueue[$owner->id]['next']->id ?? null)) {
                        // yes, so track it as a received notification
                        $this->lockQueue[$owner->id]['received'] = true;
                    }
                }

                if (!($this->lockQueue[$owner->id]['sent'] ?? null)) {
                    // we need to send a command to lock the entity
                    $next = $this->lockQueue[$owner->id]['next'] ?? null;
                    if ($next !== null) {
                        yield AwaitResult::forEvent(
                            $this->myId, With::id(
                            $next,
                            WithLock::onEntity($owner, RaiseEvent::forLockNotification($owner), ...$lock->participants)
                        )
                        );
                    }
                    $this->lockQueue[$owner->id]['sent'] = true;
                }

                // have we received a notification?
                if ($this->lockQueue[$owner->id]['received'] ?? null) {
                    // yes, so we can now take a lock
                    $this->state = LockStateEnum::ProcessLocked;
                    // replay locked events
                    foreach ($this->lockQueue[$owner->id]['events'] as $lockedEvent) {
                        yield $lockedEvent;
                    }
                    // clear the queue
                    $this->lockQueue[$owner->id]['events'] = [];
                    // set the lock
                    $this->lockQueue['current'] = $owner->id;

                    // tell the previous participant to lock
                    $prev = $this->lockQueue[$owner->id]['previous'] ?? null;
                    if ($prev && $prev->isOrchestrationId()) {
                        yield With::id($prev, TaskCompleted::forId($this->lockQueue[$owner->id]['lockId'], true));
                    } else {
                        yield AwaitResult::forEvent(
                            $this->myId, WithLock::onEntity(
                            $owner,
                            With::id($this->lockQueue[$owner->id]['previous'], RaiseEvent::forLockNotification($owner))
                        )
                        );
                    }

                    yield PoisonPill::digest();
                    break;
                }

                // we need to wait for the remainder to lock
                $this->state = LockStateEnum::ProcessEvents;
                yield PoisonPill::digest();
                break;
            case LockStateEnum::ProcessLocked:
                if ($lock === null || $owner->id !== $this->lockQueue['current']) {
                    $this->lockQueue['_']['events'][] = $event;
                    yield PoisonPill::digest();
                }

                // if this is an unlock event, then we need to unlock
                if ($innerEvent instanceof RaiseEvent && $innerEvent->eventName === '__unlock') {
                    $this->lockQueue['current'] = null;
                    $this->state = LockStateEnum::ProcessEvents;
                    foreach ($this->lockQueue['_']['events'] ?? [] as $lockedEvent) {
                        yield $lockedEvent;
                    }
                    unset($this->lockQueue[$owner->id]);
                    yield PoisonPill::digest();
                }

                return;
        }
    }

    private function getLock(Event $event): withLock|null
    {
        while ($event instanceof HasInnerEventInterface) {
            if ($event instanceof WithLock) {
                return $event;
            }
            $event = $event->getInnerEvent();
        }
        return null;
    }

    private function getInnerEvent(Event $event): Event
    {
        while ($event instanceof HasInnerEventInterface) {
            $event = $event->getInnerEvent();
        }
        return $event;
    }

    private function getReplyTo(Event $event): AwaitResult|null
    {
        while ($event instanceof HasInnerEventInterface) {
            if ($event instanceof AwaitResult) {
                return $event;
            }
            $event = $event->getInnerEvent();
        }
        return null;
    }

    private function getOwner(WithLock $event): StateId
    {
        return $event->owner;
    }

    private function getNextParticipant(WithLock $lock): StateId|null
    {
        $participants = $lock->participants;
        foreach ($participants as $idx => $participant) {
            if ($participant->id === $this->myId->id) {
                return $participants[$idx + 1] ?? null;
            }
        }
        return null;
    }

    private function getPreviousParticipant(WithLock $lock): StateId|null
    {
        $participants = $lock->participants;
        foreach ($participants as $idx => $participant) {
            if ($participant->id === $this->myId->id) {
                return $participants[$idx - 1] ?? null;
            }
        }
        return null;
    }

    private function isNotification(Event $event): RaiseEvent|null
    {
        if ($event instanceof RaiseEvent && $event->eventName === '__lock_notification') {
            return $event;
        }

        return null;
    }
}
