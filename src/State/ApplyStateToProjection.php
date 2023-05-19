<?php

namespace Bottledcode\DurablePhp\State;

use Bottledcode\DurablePhp\Events\CompleteExecution;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\OrchestrationContextRedis;

readonly class ApplyStateToProjection implements ApplyStateInterface
{
    private mixed $projection;
    private \Redis|\RedisCluster $redis;

    public function __construct(
        public Event $previousEvent,
        public OrchestrationHistory $history,
        public string $eventId
    ) {
    }

    public function __invoke(\Redis|\RedisCluster $redis): array
    {
        $this->redis = $redis;
        $context = new OrchestrationContextRedis($this->history->instance, $this->history);
        $this->loadProjection();
        $fiber = new \Fiber(function ($context, $args) {
            try {
                $result = ($this->projection)($context, ...igbinary_unserialize($args));
            } catch (\Throwable $x) {
                return $this->createProjectionResult(status: OrchestrationStatus::Failed, error: $x);
            }
            return $this->createProjectionResult(result: $result);
        });
        $awaiter = $fiber->start($context, $this->history->input);
        if ($awaiter === null) {
            $result = $fiber->getReturn();

            return [
                new CompleteExecution(
                    '',
                    $this->history->instance,
                    igbinary_serialize($result['result']),
                    $result['status'],
                    $result['error']
                )
            ];
        }

        return [];
    }

    private function loadProjection(): void
    {
        Logger::log("Loading projection for %s", $this->history->instance->instanceId);
        $rawProjection = $this->redis->get(
            "projection:{$this->history->instance->instanceId}:{$this->history->instance->executionId}"
        );
        if (empty($rawProjection)) {
            if (!class_exists($this->history->name)) {
                throw new \Exception("Orchestration {$this->history->name} not found");
            }
            Logger::log("Projection not found, creating new instance of %s", $this->history->name);
            // todo: DI
            $this->projection = new ($this->history->name)();
            return;
        }

        $this->projection = igbinary_unserialize($rawProjection);
    }

    public function createProjectionResult(
        mixed $result = null,
        OrchestrationStatus $status = OrchestrationStatus::Completed,
        \Throwable|null $error = null
    ): array {
        return compact('result', 'status', 'error');
    }
}
