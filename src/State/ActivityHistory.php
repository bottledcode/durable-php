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
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Events\WithPriority;
use Bottledcode\DurablePhp\Exceptions\ExternalException;
use Bottledcode\DurablePhp\MonotonicClock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Crell\Serde\Attributes\Field;

class ActivityHistory extends AbstractHistory
{
    use ParameterFillerTrait;
    use EntrypointLocatorTrait;

    public string $activityId;

    public function __construct(private StateId $id, #[Field(exclude: true)] public DurableLogger|null $logger = null)
    {
        $this->activityId = $id->toActivityId();
    }

    public function hasAppliedEvent(Event $event): bool
    {
        return false;
    }

    public function applyScheduleTask(ScheduleTask $event, Event $original): \Generator
    {
        $task = $event->name;
        $replyTo = $this->getReplyTo($original);

        if ($this->isFinished()) {
            if ($this->status->runtimeStatus === RuntimeStatus::Completed) {
                foreach ($replyTo as $id) {
                    yield WithOrchestration::forInstance($id, TaskCompleted::forId($original->eventId, Serializer::deserialize($this->status->output[0], $this->status->output[1])));
                }
            }
            if ($this->status->runtimeStatus === RuntimeStatus::Failed) {
                $exception = Serializer::deserialize($this->status->output, ExternalException::class);
                foreach ($replyTo as $id) {
                    yield WithOrchestration::forInstance($id, TaskFailed::forTask($original->eventId, $exception->message, $exception->trace, $exception->type));
                }
            }

            return;
        }

        try {
            if(!is_object($task)) {
                $task = $this->container->get($task);
                $reflection = new \ReflectionClass($task);
                $entrypoint = $this->locateEntrypoint($reflection) ?? throw new \RuntimeException("Unable to locate entrypoint for $event->name");
                $arguments = $this->fillParameters($event->input, $entrypoint);
            } else {
                $arguments = $this->fillParameters($event->input, new \ReflectionFunction($task));
            }

            $result = $task(...$arguments);
            $now = MonotonicClock::current()->now();
            $this->status = new Status(
                $now,
                '',
                $event->input,
                $this->id,
                $now,
                [Serializer::serialize($result), get_debug_type($result)],
                RuntimeStatus::Completed
            );
            foreach ($replyTo as $id) {
                yield WithPriority::high(WithOrchestration::forInstance(
                    $id,
                    TaskCompleted::forId($original->eventId, $result)
                ));
            }
        } catch (\Throwable $e) {
            $now = MonotonicClock::current()->now();
            $this->status = new Status(
                $now,
                '',
                $event->input,
                $this->id,
                $now,
                Serializer::serialize(ExternalException::fromException($e)),
                RuntimeStatus::Failed
            );
            foreach ($replyTo as $id) {
                yield WithOrchestration::forInstance(
                    $id,
                    TaskFailed::forTask(
                        $original->eventId,
                        $e->getMessage(),
                        $e->getTraceAsString(),
                        $e::class
                    )
                );
            }
        }
    }

    public function resetState(): void {}

    public function ackedEvent(Event $event): void {}
}
