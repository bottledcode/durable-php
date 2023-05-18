<?php

namespace Bottledcode\DurablePhp\State;

use Bottledcode\DurablePhp\Events\CompleteExecution;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\OrchestrationContextRedis;

readonly class ApplyStateToProjection
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
            $result = ($this->projection)($context, ...igbinary_unserialize($args));
            return $this->createProjectionResult($result);
        });
        $awaiter = $fiber->start($context, $this->history->input);
        if ($awaiter === null) {
            $result = $fiber->getReturn();

            return match ($result['status']) {
                'completed' => [
                    new CompleteExecution(
                        '',
                        $this->history->instance,
                        igbinary_serialize($result['result']),
                        OrchestrationStatus::Completed
                    )
                ],
                default => [],
            };
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

    public function createProjectionResult($result): array
    {
        return [
            'status' => 'completed',
            'result' => $result,
        ];
    }
}
