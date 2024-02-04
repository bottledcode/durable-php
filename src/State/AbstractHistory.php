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

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\ExecutionTerminated;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\ReplyToInterface;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Crell\Serde\Attributes\Field;
use Psr\Container\ContainerInterface;

abstract class AbstractHistory implements StateInterface, ApplyStateInterface
{
    public Status|null $status = null;

    #[Field(exclude: true)]
    protected ContainerInterface $container;

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function applyAwaitResult(AwaitResult $event, Event $original): \Generator
    {
        yield null;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function applyExecutionTerminated(ExecutionTerminated $event, Event $original): \Generator
    {
        yield null;
    }

    public function applyRaiseEvent(RaiseEvent $event, Event $original): \Generator
    {
        yield null;
    }

    public function applyScheduleTask(ScheduleTask $event, Event $original): \Generator
    {
        yield null;
    }

    public function applyStartExecution(StartExecution $event, Event $original): \Generator
    {
        yield null;
    }

    public function applyStartOrchestration(StartOrchestration $event, Event $original): \Generator
    {
        yield null;
    }

    public function applyTaskCompleted(TaskCompleted $event, Event $original): \Generator
    {
        yield null;
    }

    public function applyTaskFailed(TaskFailed $event, Event $original): \Generator
    {
        yield null;
    }

    protected function isFinished(): bool
    {
        return match ($this->status?->runtimeStatus ?? null) {
            RuntimeStatus::Terminated, RuntimeStatus::Canceled, RuntimeStatus::Failed, RuntimeStatus::Completed => true,
            default => false,
        };
    }

    protected function isRunning(): bool
    {
        return match ($this->status?->runtimeStatus ?? null) {
            RuntimeStatus::Running => true,
            default => false,
        };
    }

    /**
     * @param Event $event
     * @return array<StateId>
     */
    protected function getReplyTo(Event $event): array
    {
        $reply = [];
        while ($event instanceof HasInnerEventInterface) {
            if ($event instanceof ReplyToInterface) {
                $reply[] = $event->getReplyTo();
            }
            $event = $event->getInnerEvent();
        }
        return $reply;
    }
}
