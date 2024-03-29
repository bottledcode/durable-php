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

use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;

interface EntityClientInterface
{
    /**
     * Removes empty entities and releases orphaned locks
     *
     * @return void
     */
    public function cleanEntityStorage(): void;

    /**
     * Get a list of entities
     *
     * @return \Generator<EntityId>
     */
    public function listEntities(/* todo */): \Generator;

    /**
     * Signal an entity either now, or at some point in the future
     *
     * @param EntityId $entityId
     * @param string $operationName
     * @param array $input
     * @param \DateTimeImmutable|null $scheduledTime
     * @return void
     */
    public function signalEntity(
        EntityId $entityId,
        string $operationName,
        array $input = [],
        \DateTimeImmutable $scheduledTime = null
    ): void;

    /**
     * Signals an entity using a closure
     *
     * @template T
     * @param EntityId<T>|class-string<T> $entityId The id of the entity to signal
     * @param \Closure<T> $signal
     * @return void
     */
    public function signal(EntityId|string $entityId, \Closure $signal): void;

    /**
     * @template T
     * @param EntityId<T> $entityId
     * @return EntityState<T>|null
     */
    public function getEntitySnapshot(EntityId $entityId): EntityState|null;
}
