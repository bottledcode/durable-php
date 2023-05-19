<?php

namespace Bottledcode\DurablePhp\State;

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\CompleteExecution;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\Task;
use Carbon\Carbon;
use Ramsey\Collection\DoubleEndedQueue;
use Ramsey\Collection\DoubleEndedQueueInterface;

class OrchestrationHistory
{
    public Carbon $now;

    public string $name;

    public string $version;

    public array $tags;

    public OrchestrationInstance $instance;

    public OrchestrationInstance|null $parentInstance;

    public Carbon $createdTime;

    public string $input;

    public DoubleEndedQueueInterface $historicalTaskResults;

    public array $awaiters = [];

    public bool $isCompleted = false;

    public function __construct()
    {
        $this->historicalTaskResults = new DoubleEndedQueue(Task::class);
    }

    public function applyStartExecution(StartExecution $event): array
    {
        Logger::log("Applying StartExecution event to OrchestrationHistory");
        $this->now = $event->timestamp;
        $this->createdTime = $event->timestamp;
        $this->name = $event->name;
        $this->version = $event->version;
        $this->tags = $event->tags;
        $this->instance = $event->instance;
        $this->parentInstance = $event->parentInstance;
        $this->input = $event->input;

        return [
            new ApplyStateToProjection($event, $this, $event->eventId),
        ];
    }

    public function applyCompleteExecution(CompleteExecution $event): array {
        Logger::log("Applying CompleteExecution event to OrchestrationHistory");
        foreach ($this->awaiters as $awaiter) {
            //todo
        }

        return [];
    }

    public function applyAwaitResult(AwaitResult $event): array {
        if($this->isCompleted) {
            return [

            ];
        }
    }
}
