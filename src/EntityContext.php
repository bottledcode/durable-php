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

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\WithDelay;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Crell\Serde\Attributes\ClassSettings;
use Ramsey\Uuid\Uuid;

#[ClassSettings(includeFieldsByDefault: false)]
class EntityContext implements EntityContextInterface
{
    private static EntityContextInterface|null $current = null;

    public function __construct(
        private readonly EntityId $id, private readonly string $operation, private readonly mixed $input,
        private mixed $state, private readonly EntityHistory $history,
        private readonly EventDispatcherTask $eventDispatcher, private readonly array $caller,
        private readonly string $requestingId,
        private readonly SpyProxy $spyProxy,
    ) {
        self::$current = $this;
    }

    public static function current(): static
    {
        return self::$current ?? throw new \RuntimeException('Not in an entity context');
    }

    public function delete(): never
    {
        $this->history->delete();
        throw new Unwind('delete');
    }

    public function getInput(): mixed
    {
        return $this->input;
    }

    public function getState(): mixed
    {
        return $this->state;
    }

    public function return(mixed $value): never
    {
        foreach ($this->caller as $caller) {
            $this->eventDispatcher->fire(
                WithOrchestration::forInstance($caller, TaskCompleted::forId($this->requestingId, $value))
            );
        }
        throw new Unwind('return');
    }

    public function setState(mixed $value): void
    {
        $this->history->setState($value);
        $this->state = $value;
    }

    public function signalEntity(
        EntityId $entityId, string $operation, array $input = [], ?\DateTimeImmutable $scheduledTime = null
    ): void {
        $event = WithEntity::forInstance(
            StateId::fromEntityId($entityId), RaiseEvent::forOperation($operation, $input)
        );
        if ($scheduledTime) {
            $event = WithDelay::forEvent($scheduledTime, $event);
        }
        $this->eventDispatcher->fire($event);
    }

    public function getId(): EntityId
    {
        return $this->id;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function startNewOrchestration(string $orchestration, array $input = [], string|null $id = null): void
    {
        if ($id === null) {
            $id = Uuid::uuid7()->toString();
        }

        $instance = StateId::fromInstance(new OrchestrationInstance($orchestration, $id));
        $this->eventDispatcher->fire(
            WithOrchestration::forInstance(
                $instance, StartExecution::asParent($input, [])
            )
        );
    }

    public function delayUntil(string $operation, array $args = [], \DateTimeInterface $until = new \DateTimeImmutable()
    ): void {
        $this->eventDispatcher->fire(
            WithDelay::forEvent(
                $until,
                WithEntity::forInstance(StateId::fromEntityId($this->id), RaiseEvent::forOperation($operation, $args))
            )
        );
    }

    public function delay(\Closure $self, \DateTimeInterface $until = new \DateTimeImmutable()): void {
        $classReflector = new \ReflectionClass($this->history->getState());
        $interfaces = $classReflector->getInterfaceNames();
        if(count($interfaces) > 1) {
            throw new \Exception('Cannot delay an entity with more than one interface');
        }

        $fnReflector = new \ReflectionFunction($self);
        if($fnReflector->getNumberOfParameters() > 0) {
            throw new \Exception('Cannot delay a function with parameters');
        }

        // create the spy proxy
        $spy = $this->spyProxy->define($interfaces[0]);
        $class = new $spy($operationName, $arguments);
        $self->bindTo($class);

        try {
            $self();
        } catch(\Throwable) {}

        $this->delayUntil($operationName, $arguments, $until);
    }
}
