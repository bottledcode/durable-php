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

namespace Bottledcode\DurablePhp\State\Nats;

use Amp\Sync\Channel;
use Basis\Nats\AmpClient;
use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\ExecutionTerminated;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\Status;
use Psr\Container\ContainerInterface;

abstract class AbstractHistory implements ApplyStateInterface
{
    public ?Status $status = null;

    public function __construct(
        protected StateId $id,
        protected AmpClient $history,
        protected Channel $channel,
        protected ContainerInterface $container
    ) {}

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function applyAwaitResult(AwaitResult $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function applyExecutionTerminated(ExecutionTerminated $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }

    public function applyRaiseEvent(RaiseEvent $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }

    public function applyScheduleTask(ScheduleTask $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }

    public function applyStartExecution(StartExecution $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }

    public function applyStartOrchestration(StartOrchestration $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }

    public function applyTaskCompleted(TaskCompleted $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }

    public function applyTaskFailed(TaskFailed $event, EventDescription $original): void
    {
        $this->channel->send(null);
    }
}
