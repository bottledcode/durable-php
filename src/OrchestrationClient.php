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

namespace Bottledcode\DurablePhp;

use Amp\Cancellation;
use Amp\NullCancellation;
use Bottledcode\DurablePhp\Abstractions\EventQueueInterface;
use Bottledcode\DurablePhp\Abstractions\ProjectorInterface;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\ExecutionTerminated;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Status;
use LogicException;
use Ramsey\Uuid\Uuid;

use function Amp\async;

final class OrchestrationClient implements OrchestrationClientInterface
{
    public function __construct(private EventQueueInterface $queue, private ProjectorInterface $projector)
    {
        $this->projector->connect();
    }

    public function purge(OrchestrationInstance $instance): void
    {
        $this->projector->projectState(StateId::fromInstance($instance), null);
    }

    public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
    {
        $this->postEvent(
            WithOrchestration::forInstance(StateId::fromInstance($instance), new RaiseEvent('', $eventName, $eventData))
        );
    }

    private function postEvent(Event $event): void
    {
        $this->queue->fire($event);
    }

    public function startNew(string $name, array $args = [], string|null $id = null): OrchestrationInstance
    {
        $instance = $this->getInstanceFor($name);
        if ($id) {
            $instance = new OrchestrationInstance($instance->instanceId, $id);
        }

        $event = WithOrchestration::forInstance(
            StateId::fromInstance($instance),
            StartExecution::asParent($args, [])
        );
        $this->postEvent($event);
        return $instance;
    }

    private function getInstanceFor(string $name): OrchestrationInstance
    {
        return new OrchestrationInstance($name, Uuid::uuid7()->toString());
    }

    public function terminate(OrchestrationInstance $instance, string $reason): void
    {
        $this->postEvent(
            WithOrchestration::forInstance(StateId::fromInstance($instance), new ExecutionTerminated('', $reason))
        );
    }

    public function waitForCompletion(OrchestrationInstance $instance, Cancellation $timeout = null): void
    {
        async(function () use ($instance) {
            $this->projector->watch(
                StateId::fromInstance($instance),
                RuntimeStatus::Completed,
                RuntimeStatus::Canceled,
                RuntimeStatus::Failed,
                RuntimeStatus::Terminated,
            );
        })->await($timeout ?? new NullCancellation());
    }

    public function getStatus(OrchestrationInstance $instance): Status
    {
        return $this->projector->getState(StateId::fromInstance($instance))->status ?? new Status(
            new \DateTimeImmutable(),
            '',
            [],
            StateId::fromInstance($instance),
            new \DateTimeImmutable(),
            null,
            RuntimeStatus::Unknown
        );
    }

    public function listInstances(): \Generator
    {
        throw new LogicException('Not implemented');
    }

    public function restart(OrchestrationInstance $instance): void
    {
        throw new LogicException('Not implemented');
    }

    public function resume(OrchestrationInstance $instance, string $reason): void
    {
        throw new LogicException('Not implemented');
    }

    public function suspend(OrchestrationInstance $instance, string $reason): void
    {
        throw new LogicException('Not implemented');
    }
}
