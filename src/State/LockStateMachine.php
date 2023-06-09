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
use Bottledcode\DurablePhp\Events\LockParticipant;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithLock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Generator;

class LockStateMachine
{
    public function __construct(
        private StateId $myId,
        private LockStateEnum $state = LockStateEnum::ProcessEvents,
        private array $lockQueue = [],
    ) {
    }

    private function hasParticipants(Event $event): WithLock|null
    {
        while ($event instanceof HasInnerEventInterface) {
            if ($event instanceof WithLock) {
                foreach ($event->participants as $participant) {
                    if ($participant->participant->id === $this->myId->id) {
                        return $event;
                    }
                }
                return null;
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

    private function getOwner(WithLock $event): StateId
    {
        return $event->participants[0]->owner;
    }

    private function isNotification(Event $event): RaiseEvent|null
    {
        if ($event instanceof RaiseEvent && $event->eventName === '__lock_notification') {
            return $event;
        }

        return null;
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

    public function process(Event $event): Generator
    {
        $participants = $this->hasParticipants($event);
        $innerEvent = $this->getInnerEvent($event);
        $owner = null;
        $sender = $this->getReplyTo($event);
        if ($participants) {
            $owner = $this->getOwner($participants);
        }

        restart:
        switch ($this->state) {
            case LockStateEnum::ProcessEvents:
                if (!$participants) {
                    // there is nothing to lock, so we can just process the event
                    return;
                }
                // we have participants, so we need to decide whether to enqueue the event or process it
                // so, first, are we already participating in the lock?
                $queue = $this->lockQueue[$owner->id] ?? null;
                if ($queue === null) {
                    // we are not participating, so change state
                    $this->state = LockStateEnum::Participants;
                    goto restart;
                }
                // add it to the queue
                /** @noinspection SuspiciousAssignmentsInspection */
                $this->state = LockStateEnum::Enqueue;
                goto restart;
            case LockStateEnum::Enque:
                assert($owner !== null);
                // determine if it is a notification???

                // add it to the queue
                $this->lockQueue[$owner->id]['events'][] = $event;
                $this->state = LockStateEnum::ProcessEvents;
                yield PoisonPill::digest();
                break;
            case LockStateEnum::Participants:
                // create a lock queue for ourselves
                assert($participants !== null);
                $this->lockQueue[$owner->id] = [
                    'events' => [], 'participants' => array_map(
                        static fn(LockParticipant $participant) => [
                            'id' => $participant->participant->id, 'locked' => null
                        ],
                        $participants->participants
                    ),
                ];
                $this->lockQueue[$owner->id]['participants'] = array_combine(
                    array_column($this->lockQueue[$owner->id]['participants'], 'id'),
                    array_column($this->lockQueue[$owner->id]['participants'], 'locked')
                );
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
                    // yes, so track it as a received notification
                    $this->lockQueue[$owner->id]['participants'][$sender->getReplyTo()->id] = true;
                }

                $allReceived = true;
                // find other participants that we haven't received a notification for yet
                foreach ($this->lockQueue[$owner->id]['participants'] as $id => $status) {
                    // null === nothing done yet
                    // false === not yet received
                    // true === received
                    if ($status === false || $status === null) {
                        $allReceived = false;
                    }
                    if ($status === null) {
                        if ($id === $this->myId->id) {
                            $this->lockQueue[$owner->id]['participants'][$id] = true;
                            continue;
                        }

                        $this->lockQueue[$owner->id]['participants'][$id] = false;
                        yield AwaitResult::forEvent(
                            $this->myId,
                            WithEntity::forInstance(
                                StateId::fromString($id),
                                WithLock::onEntity(
                                    $owner,
                                    RaiseEvent::forLockNotification($owner->id),
                                    ...array_map(static fn(LockParticipant $p) => $p->participant, $participants->participants)
                                )
                            )
                        );
                    }
                }

                // have we received notifications from all participants?
                if ($allReceived) {
                    // we can now take a lock
                    $this->state = LockStateEnum::ProcessLocked;
                    // replay locked events
                    foreach ($this->lockQueue[$owner->id]['events'] as $lockedEvent) {
                        yield $lockedEvent;
                    }
                    // clear the queue
                    $this->lockQueue[$owner->id]['events'] = [];

                    // set the current lock
                    $this->lockQueue['current'] = $owner->id;
                    yield PoisonPill::digest();
                    break;
                }

                // we need to wait for the remainder to lock
                $this->state = LockStateEnum::ProcessEvents;
                yield PoisonPill::digest();
                break;
            case LockStateEnum::ProcessLocked:
                if ($participants === null || $owner->id !== $this->lockQueue['current']) {
                    // the event is not part of the lock, save it for later
                    $this->lockQueue[$owner?->id ?? '_']['events'][] = $event;
                }

                // if this is an unlock event, then we need to unlock
                if ($innerEvent instanceof RaiseEvent && $innerEvent->eventName === '__unlock') {
                    $this->lockQueue['current'] = null;
                    $this->state = LockStateEnum::ProcessEvents;
                    foreach ($this->lockQueue['_']['events'] as $lockedEvent) {
                        yield $lockedEvent;
                    }
                    unset($this->lockQueue[$owner->id]);
                    yield PoisonPill::digest();
                }

                return;
        }
    }
}
