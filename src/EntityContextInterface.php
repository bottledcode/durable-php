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

use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Closure;
use Crell\Serde\Attributes\ClassNameTypeMap;
use DateTimeImmutable;
use DateTimeInterface;

#[ClassNameTypeMap('__entity_type')]
interface EntityContextInterface
{
    public static function current(): static;

    /**
     * Delete the current entity.
     */
    public function delete(): never;

    /**
     * Get the input to the current operation.
     *
     * @template T
     *
     * @return T
     */
    public function getInput(): mixed;

    /**
     * Get the current entity's state.
     *
     * @template T
     *
     * @return T
     */
    public function getState(): mixed;

    /**
     * Return the given value from the current operation.
     *
     * @template T
     *
     * @param  T  $value
     */
    public function return(mixed $value): never;

    /**
     * Set the current state of the entity.
     *
     * @template T
     *
     * @param  T  $value
     */
    public function setState(mixed $value): void;

    /**
     * Signal another entity.
     *
     * @template T
     *
     * @param  EntityId<T>  $entityId
     * @param  non-empty-string  $operation
     */
    public function signalEntity(
        EntityId $entityId,
        string $operation,
        array $input = [],
        ?DateTimeImmutable $scheduledTime = null,
    ): void;

    /**
     * Get the current entity id.
     */
    public function getId(): EntityId;

    /**
     * Get the current operation.
     */
    public function getOperation(): string;

    /**
     * Call the entity with a single signal
     *
     * @template T of EntityState
     *
     * @param  EntityId<T>  $entityId
     * @param  callable(T): void  $signal
     */
    public function signal(EntityId $entityId, callable $signal): void;

    /**
     * Retrieve a snapshot of the remote entity state
     *
     * @template T of EntityState
     *
     * @param  EntityId<T>  $entityId
     * @return EntityState<T>
     */
    public function getSnapshot(EntityId $entityId): EntityState;

    public function startNewOrchestration(string $orchestration, array $input = [], ?string $id = null): void;

    public function delayUntil(
        string $operation,
        array $args = [],
        DateTimeInterface $until = new DateTimeImmutable(),
    ): void;

    public function delay(Closure $self, DateTimeInterface $until = new DateTimeImmutable()): void;

    public function currentUserId(): string;

    public function shareOwnership(string $withUser): void;

    public function grantUser(string $withUser, Operation ...$operation): void;

    public function grantRole(string $withRole, Operation ...$operation): void;

    public function revokeUser(string $user): void;

    public function revokeRole(string $role): void;

    public function giveOwnership(string $withUser): void;
}
