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

namespace Bottledcode\DurablePhp\State;

use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\EntityContext;
use Bottledcode\DurablePhp\EntityContextInterface;
use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\With;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Events\WithPriority;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\MonotonicClock;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\Attributes\Operation;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Crell\Serde\Attributes\Field;
use Generator;
use ReflectionClass;
use ReflectionNamedType;

class EntityHistory extends AbstractHistory
{
    use ParameterFillerTrait;

    public EntityId $entityId;

    public string $name;
    public array $history = [];
    public string|null $lock;
    private bool $debugHistory = false;
    private EntityState|null $state = null;
    private LockStateMachine $lockQueue;

    public function __construct(public StateId $id, #[Field(exclude: true)] public DurableLogger|null $logger = null)
    {
        $this->entityId = $id->toEntityId();
    }

    public function hasAppliedEvent(Event $event): bool
    {
        return $this->history[$event->eventId] ?? false;
    }

    public function resetState(): void {}

    public function ackedEvent(Event $event): void
    {
        unset($this->history[$event->eventId]);
    }

    public function getState(): EntityState|null
    {
        return $this->state;
    }

    public function setState(mixed $state): void
    {
        $this->state = $state;
    }

    public function applyRaiseEvent(RaiseEvent $event, Event $original): Generator
    {
        yield from $this->participateInLock($original);

        $this->init();

        switch ($event->eventName) {
            case '__signal':
                $input = $event->eventData['input'];
                $operation = $event->eventData['operation'];
                yield from $this->execute($original, $operation, $input);
                if ($operation === 'delete') {
                    $this->delete();
                }
                break;
            case '__lock':
                // dequeue events currently in the lock queue
                foreach ($this->lockQueue[$this->lock]['events'] ?? [] as $nextEvent) {
                    yield $nextEvent;
                }
                // reply to the lock request
                $reply = $this->getReplyTo($original);
                foreach ($reply as $nextEvent) {
                    yield WithPriority::high(With::id(
                        $nextEvent,
                        RaiseEvent::forLock('locked', $event->eventData['owner'], $event->eventData['target'])
                    ));
                }
                break;
            case '__unlock':
                if ($this->lock === $event->eventData['owner']) {
                    // we can release the current lock
                    unset($this->lockQueue[$this->lock]);
                    $this->lock = null;
                    // dequeue any events waiting on the lock
                    foreach ($this->lockQueue['_']['events'] ?? [] as $nextEvent) {
                        yield $nextEvent;
                    }
                }
                break;
            default:
                break;
        }

        yield from $this->finalize($event);
    }

    private function participateInLock(Event $original): Generator
    {
        $this->init();

        yield from $this->lockQueue->process($original);
    }

    public function init(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->lockQueue ??= new LockStateMachine($this->id);
        $this->state ??= new class () extends EntityState {};

        $this->name = $this->id->toEntityId()->name;
        $now = MonotonicClock::current()->now();
        $this->status = new Status($now, '', [], $this->id, $now, [], RuntimeStatus::Running);

        $this->state = $this->container->get($this->name);
    }

    private function execute(Event $original, string $operation, array $input): Generator
    {
        $replyTo = $this->getReplyTo($original);

        $taskDispatcher = null;
        yield static function ($task) use (&$taskDispatcher) {
            $taskDispatcher = $task;
        };

        $context = new EntityContext(
            $this->id->toEntityId(),
            $operation,
            $input,
            $this->state,
            $this,
            $taskDispatcher,
            $replyTo,
            $original->eventId,
            $this->container->get(SpyProxy::class)
        );

        if (is_object($this->state)) {
            $reflector = new ReflectionClass($this->state);
            $properties = $reflector->getProperties();
            foreach ($properties as $property) {
                $type = $property->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === EntityContextInterface::class) {
                    $property->setValue($this->state, $context);
                }
            }
            try {
                $operationReflection = $reflector->getMethod($operation);
            } catch(\ReflectionException) {
                // search attributes for matching operation
                foreach($reflector->getMethods() as $method) {
                    foreach($method->getAttributes(Operation::class) as $attribute) {
                        /** @var Operation $attributeClass */
                        $attributeClass = $attribute->newInstance();

                        if($attributeClass->name === $operation) {
                            $operationReflection = $reflector->getMethod($attributeClass->name);
                            goto done;
                        }
                    }
                }
                $this->logger->critical('Unknown operation', ['operation' => $operation]);
                return;
            }
            done:
            $input = $this->fillParameters($input, $operationReflection);
            try {
                $result = $operationReflection->getClosure($this->state);
                $result = $result(...$input);
            } catch (Unwind) {
                return;
            }
        } elseif (is_callable($this->name)) {
            try {
                $result = ($this->name)($context);
            } catch (Unwind) {
                return;
            }
        }

        if ($replyTo) {
            foreach ($replyTo as $reply) {
                yield WithPriority::high(WithOrchestration::forInstance($reply, TaskCompleted::forId($original->eventId, $result ?? null)));
            }
        }
    }

    public function delete(): void
    {
        $this->state = null;
    }

    private function finalize(Event $event): Generator
    {
        $now = time();
        $cutoff = $now - 3600; // 1 hour
        $this->history[$event->eventId] = $this->debugHistory ? $event : $now;
        $this->history =
            array_filter(
                $this->history,
                static fn(int|bool|Event $value) => is_int($value) ? $value > $cutoff : $value
            );
        $this->status = $this->status->with(lastUpdated: MonotonicClock::current()->now());

        yield null;
    }

    public function applyTaskCompleted(TaskCompleted $event, Event $original): Generator
    {
        if ($this->queueIfLocked($original)) {
            return;
        }
        $this->init();

        yield from $this->finalize($event);
    }

    private function queueIfLocked(Event $original): bool
    {
        if ($this->isLocked($original)) {
            // queue the event
            $this->lockQueue['_']['events'][] = $original;
            return true;
        }
        return false;
    }

    private function isLocked(Event $original): bool
    {
        if (($this->lock ?? null) === null) {
            return false;
        }
        while ($original instanceof HasInnerEventInterface) {
            if (($original instanceof AwaitResult) && $original->origin->id === $this->lock) {
                return false;
            }
            $original = $original->getInnerEvent();
        }
        return true;
    }

    public function applyTaskFailed(TaskFailed $event, Event $original): Generator
    {
        if ($this->queueIfLocked($original)) {
            return;
        }
        $this->init();

        yield from $this->finalize($event);
    }

    public function applyAwaitResult(AwaitResult $event, Event $original): Generator
    {
        if ($this->queueIfLocked($original)) {
            return;
        }
        yield from $this->finalize($event);
    }

    #[\Override] public function setLogger(DurableLogger $logger): void
    {
        $this->logger = $logger;
    }
}
