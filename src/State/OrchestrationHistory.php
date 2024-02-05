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
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithLock;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\ExternalException;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\MonotonicClock;
use Bottledcode\DurablePhp\OrchestrationContext;
use Bottledcode\DurablePhp\Proxy\OrchestratorProxy;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\WorkerTask;
use Crell\Serde\Attributes\Field;
use Crell\Serde\Attributes\SequenceField;

class OrchestrationHistory extends AbstractHistory
{
    public \DateTimeImmutable $now;

    public string $name;

    public string $version;

    public array $tags;

    public OrchestrationInstance $instance;

    public OrchestrationInstance|null $parentInstance;

    public HistoricalStateTracker $historicalTaskResults;

    public array $history = [];

    public array $randoms = [];

    /**
     * @var array<StateId>
     */
    #[SequenceField(StateId::class)]
    public array $locks = [];
    private bool $debugHistory = false;
    #[Field(exclude: true)]
    private mixed $constructed = null;

    public function __construct(public readonly StateId $id)
    {
        $this->instance = $id->toOrchestrationInstance();
        $this->historicalTaskResults = new HistoricalStateTracker();
    }

    /**
     * This represents the beginning of the orchestration and is the first event
     * that is applied to the history. The next phase is to actually run the
     * orchestration now that we've set up the history.
     *
     * @param StartExecution $event
     * @return array
     */
    public function applyStartExecution(StartExecution $event, Event $original): \Generator
    {
        if ($this->isFinished()) {
            return;
        }

        //Logger::log("Applying StartExecution event to OrchestrationHistory");
        $this->now = $event->timestamp;
        $this->name = $this->id->toOrchestrationInstance()->instanceId;
        $this->version = $event->version;
        $this->tags = $event->tags;
        $this->parentInstance = $event->parentInstance ?? null;
        $this->history = [];
        $this->historicalTaskResults = new HistoricalStateTracker();
        $this->status = new Status($this->now, '', $event->input, $this->id, $this->now, [], RuntimeStatus::Pending);

        yield StartOrchestration::forInstance($this->instance);

        yield from $this->finalize($event);
    }

    private function finalize(Event $event): \Generator
    {
        $this->addEventToHistory($event);
        ($this->status?->with(lastUpdated: MonotonicClock::current()->now())) ?? $this->status = new Status(
            MonotonicClock::current()->now(),
            '',
            [],
            $this->id,
            MonotonicClock::current()->now(),
            [],
            RuntimeStatus::Unknown
        );

        yield null;
    }

    private function addEventToHistory(Event $event): void
    {
        $now = time();
        $cutoff = $now - 3600; // 1 hour
        $this->history[$event->eventId] = $this->debugHistory ? $event : $now;
        $this->history = array_filter($this->history, static fn(int|bool|Event $value) => is_int($value) ? $value > $cutoff : $value);
    }

    public function applyStartOrchestration(StartOrchestration $event, Event $original): \Generator
    {
        if ($this->isFinished()) {
            return;
        }

        $this->status = $this->status->with(runtimeStatus: RuntimeStatus::Running);

        // go ahead and finalize this event to the history and update the status
        // we won't be updating any more state
        yield from $this->finalize($event);
        yield from $this->construct();
    }

    private function construct(): \Generator
    {
        try {
            $class = new \ReflectionClass($this->instance->instanceId);
        } catch (\ReflectionException) {
            // we should handle this more gracefully...
        }

        $this->constructed = $this->container->get($this->instance->instanceId);
        $proxyGenerator = $this->container->get(OrchestratorProxy::class);
        $spyGenerator = $this->container->get(SpyProxy::class);
        try {
            $taskScheduler = null;
            yield static function (WorkerTask $task) use (&$taskScheduler) {
                $taskScheduler = $task;
            };
            $context = new OrchestrationContext($this->instance, $this, $taskScheduler, $proxyGenerator, $spyGenerator);
            try {
                $result = ($this->constructed)($context);
            } catch (Unwind) {
                // we don't need to do anything here, we just need to catch it
                // so that we don't throw an exception
                return;
            }

            $this->status = $this->status->with(
                runtimeStatus: RuntimeStatus::Completed,
                output: Serializer::serialize($result),
            );
            $completion = TaskCompleted::forId(StateId::fromInstance($this->instance), $result);
        } catch (\Throwable $e) {
            $this->status = $this->status->with(
                runtimeStatus: RuntimeStatus::Failed,
                output: Serializer::serialize(
                    ExternalException::fromException($e)
                ),
            );
            $completion = TaskFailed::forTask(
                StateId::fromInstance($this->instance),
                $e->getMessage(),
                $e->getTraceAsString(),
                $e::class
            );
        } finally {
            if (!$this->isRunning()) {
                // ok, we now need to release all of the locks that we have
                yield from $this->releaseAllLocks();
            }
        }

        $completion = WithOrchestration::forInstance(StateId::fromInstance($this->instance), $completion);

        if ($this->parentInstance ?? false) {
            $completion = AwaitResult::forEvent(StateId::fromInstance($this->parentInstance), $completion);
        } else {
            $completion = null;
        }

        yield $completion;
    }

    public function releaseAllLocks(): \Generator
    {
        foreach ($this->locks as $lock) {
            yield WithLock::onEntity(
                $this->id,
                WithEntity::forInstance($lock, RaiseEvent::forUnlock($this->id->id, null, null))
            );
        }
    }

    public function applyTaskCompleted(TaskCompleted $event, Event $original): \Generator
    {
        if ($this->isFinished()) {
            return;
        }

        yield from $this->finalize($event);

        $this->historicalTaskResults->receivedEvent($event);

        yield from $this->construct();
    }

    public function applyTaskFailed(TaskFailed $event, Event $original): \Generator
    {
        if ($this->isFinished()) {
            return;
        }

        yield from $this->finalize($event);

        $this->historicalTaskResults->receivedEvent($event);

        yield from $this->construct();
    }

    public function applyRaiseEvent(RaiseEvent $event, Event $original): \Generator
    {
        yield from $this->finalize($event);

        switch ($event->eventName) {
            case '__lock':
                // we have received confirmation of a lock being acquired
                if (($this->locks[$event->eventData['target']] ?? false) !== true) {
                    return;
                }
                $this->locks[$event->eventData['target']] = time();
                break;
            case '__unlock':
                // we have received confirmation of a lock being released
                unset($this->locks[$event->eventData['target']]);
                break;
            default:
                $this->historicalTaskResults->receivedEvent($event);
                break;
        }

        if ($this->isRunning()) {
            yield from $this->construct();
        }
    }

    public function applyExecutionTerminated(ExecutionTerminated $event, Event $original): \Generator
    {
        if ($this->isFinished()) {
            return;
        }

        $this->status = $this->status->with(runtimeStatus: RuntimeStatus::Terminated);

        yield from $this->finalize($event);
    }

    public function hasAppliedEvent(Event $event): bool
    {
        return array_key_exists($event->eventId, $this->history);
    }

    public function resetState(): void
    {
        $this->historicalTaskResults->resetState();
        $this->locks = [];
    }

    public function restartAsNew(array $args): void
    {
        $this->historicalTaskResults->restartAsNew();
        $this->status = $this->status->with(input: $args, runtimeStatus: RuntimeStatus::ContinuedAsNew);
        ++$this->version;
    }

    public function ackedEvent(Event $event): void
    {
        unset($this->history[$event->eventId]);
    }
}
