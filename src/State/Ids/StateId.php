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

namespace Bottledcode\DurablePhp\State\Ids;

use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\StateInterface;
use Crell\Serde\Attributes\ClassNameTypeMap;
use Exception;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

#[ClassNameTypeMap('__type')]
readonly class StateId implements \Stringable
{
    public function __construct(public string $id)
    {
    }

    public static function fromState(StateInterface $state): self
    {
        return match ($state::class) {
            OrchestrationHistory::class => self::fromInstance($state->instance),
            ActivityHistory::class => self::fromActivityId($state->activityId),
            EntityHistory::class => self::fromEntityId($state->entityId),
        };
    }

    public static function fromInstance(OrchestrationInstance $instance): self
    {
        return new self("orchestration:{$instance}");
    }

    public static function fromActivityId(UuidInterface|string $activityId): self
    {
        return new self("activity:{$activityId}");
    }

    public static function fromEntityId(EntityId $entityId): self
    {
        return new self("entity:{$entityId}");
    }

    public function toActivityId(): string
    {
        $parts = explode(':', $this->id, 3);
        return match ($parts) {
            ['orchestration', $parts[1]] => throw new Exception("Cannot convert orchestration state to activity id"),
            ['activity', $parts[1]] => Uuid::fromString($parts[1])->toString(),
            ['entity', $parts[1], $parts[2]] => throw new Exception("Cannot convert entity state to activity id"),
        };
    }

    public static function fromString(string $id): self
    {
        return new self($id);
    }

    public function toOrchestrationInstance(): OrchestrationInstance
    {
        $parts = explode(':', $this->id, 3);
        return match ($parts) {
            ['activity', $parts[1]] => throw new Exception("Cannot convert activity state to orchestration instance"),
            ['orchestration', $parts[1], $parts[2]] => new OrchestrationInstance($parts[1], $parts[2]),
            ['entity', $parts[1], $parts[2]] => throw new Exception(
                "Cannot convert entity state to orchestration instance"
            ),
        };
    }

    public function toEntityId(): EntityId
    {
        $parts = explode(':', $this->id, 3);
        return match ($parts) {
            ['activity', $parts[1]] => throw new Exception("Cannot convert activity state to entity id"),
            ['orchestration', $parts[1], $parts[2]] => throw new Exception(
                "Cannot convert orchestration state to entity id"
            ),
            ['entity', $parts[1], $parts[2]] => new EntityId($parts[1], $parts[2]),
        };
    }

    public function isActivityId(): bool
    {
        return str_starts_with($this->id, 'activity:');
    }

    /**
     * @return class-string
     */
    public function getStateType(): string
    {
        $parts = explode(':', $this->id, 3);
        return match ($parts) {
            ['activity', $parts[1]] => ActivityHistory::class,
            ['orchestration', $parts[1], $parts[2]] => OrchestrationHistory::class,
            ['entity', $parts[1], $parts[2]] => EntityHistory::class,
        };
    }

    public function getPartitionKey(int $totalPartitions): int|null
    {
        return match ($this->isPartitioned()) {
            true => crc32($this->id) % $totalPartitions,
            false => null,
        };
    }

    public function isPartitioned(): bool
    {
        return $this->isEntityId() || $this->isOrchestrationId();
    }

    public function isEntityId(): bool
    {
        return str_starts_with($this->id, 'entity:');
    }

    public function isOrchestrationId(): bool
    {
        return str_starts_with($this->id, 'orchestration:');
    }

    public function __invoke(string|StateId|OrchestrationInstance|EntityId|UuidInterface $id): self
    {
        if (is_string($id)) {
            return new self($id);
        }
        if ($id instanceof self) {
            return $id;
        }
        if ($id instanceof OrchestrationInstance) {
            return self::fromInstance($id);
        }
        if ($id instanceof EntityId) {
            return self::fromEntityId($id);
        }
        if ($id instanceof UuidInterface) {
            return self::fromActivityId($id);
        }

        throw new \RuntimeException("Cannot convert {$id} to StateId");
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
