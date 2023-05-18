<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\OrchestrationStatus;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;
use Withinboredom\Time\ReadableConverterInterface;

final class OrchestrationClientRedis implements OrchestrationClientInterface
{
    public function __construct(private readonly \Redis|\RedisCluster $redis, private readonly Config $config)
    {
    }

    public function getStatus(OrchestrationInstance $instance): OrchestrationStatus
    {
        throw new \LogicException('Not implemented');
    }

    public function getStatusAll(): array
    {
        throw new \LogicException('Not implemented');
    }

    public function getStatusBy(
        ?Carbon $createdFrom = null,
        ?Carbon $createdTo = null,
        ?OrchestrationStatus ...$status
    ): array {
        throw new \LogicException('Not implemented');
    }

    public function purge(OrchestrationInstance $instance): void
    {
        throw new \LogicException('Not implemented');
    }

    public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
    {
        throw new \LogicException('Not implemented');
    }

    public function rewind(OrchestrationInstance $instance): void
    {
        throw new \LogicException('Not implemented');
    }

    public function startNew(string $name, array $args = []): OrchestrationInstance
    {
        $instance = $this->getInstanceFor($name);
        $event = new StartExecution(
            $instance,
            null,
            $name,
            '0',
            igbinary_serialize($args),
            [],
            Uuid::uuid7(),
            Carbon::now(),
            0,
            ''
        );
        $this->postEvent($event);
        return $instance;
    }

    private function getInstanceFor(string $name): OrchestrationInstance
    {
        return new OrchestrationInstance($name, Uuid::uuid7()->toString());
    }

    private function postEvent(Event $event): string
    {
        $id = $this->redis->xAdd($this->getPartitionFor($event->instance), '*', [
            'event' => igbinary_serialize($event)
        ]);
        $event->eventId = $id;
        return $id;
    }

    private function getPartitionFor(OrchestrationInstance $instance): string
    {
        return 'partition_' . (crc32($instance->instanceId) + crc32(
                    $instance->executionId
                )) % $this->config->totalPartitions;
    }

    public function terminate(OrchestrationInstance $instance, string $reason): void
    {
        throw new \LogicException('Not implemented');
    }

    public function waitForCompletion(OrchestrationInstance $instance, ReadableConverterInterface $timeout = null): void
    {
    }
}
