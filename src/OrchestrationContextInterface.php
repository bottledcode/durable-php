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

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityLock;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Ramsey\Uuid\UuidInterface;

interface OrchestrationContextInterface
{
    /**
     * Call an activity function and return a future that the context can await on.
     *
     * @template T
     * @param string $name The name of the function to remotely invoke
     * @param array $args The arguments to pass to the function
     * @param RetryOptions|null $retryOptions How to retry on failure
     * @return DurableFuture<T>
     */
    public function callActivity(string $name, array $args = [], RetryOptions|null $retryOptions = null): DurableFuture;

    /**
     * Call an entity and get the response
     *
     * @template T
     * @param EntityId $entityId
     * @param string $operation
     * @param array $args
     * @return DurableFuture<T>
     */
    public function callEntity(EntityId $entityId, string $operation, array $args = []): DurableFuture;


    public function signalEntity(EntityId $entityId, string $operation, array $args = []): void;

    /**
     * Determines if an entity is locked. Returns true if the entity is locked.
     *
     * @param EntityId $entityId
     * @return bool
     */
    public function isLocked(EntityId $entityId): bool;

    /**
     * Determines if the current lock is owned by the current instance.
     *
     * @param EntityId $entityId
     * @return bool
     */
    public function isLockedOwned(EntityId $entityId): bool;

    /**
     * Attempts to lock an entity. Returns once the lock is acquired.
     *
     * @param EntityId ...$entityId
     * @return EntityLock
     */
    public function lockEntity(EntityId ...$entityId): EntityLock;

    public function callSubOrchestrator(
        string $name, array $args = [], string|null $instanceId = null, RetryOptions|null $retryOptions = null
    ): DurableFuture;

    public function continueAsNew(array $args = []): void;

    /**
     * Creates a durable timer that resolves at the specified time.
     *
     * @param \DateTimeImmutable $fireAt The time to resolve the future.
     * @return DurableFuture<true>
     */
    public function createTimer(\DateTimeImmutable $fireAt): DurableFuture;

    /**
     * The input to the orchestrator function.
     *
     * @return array<array-key, mixed>
     */
    public function getInput(): array;

    /**
     * Constructs a db-friendly GUID.
     *
     * @return UuidInterface
     */
    public function newGuid(): UuidInterface;

    /**
     * Set the custom status of the orchestration.
     *
     * @param string $customStatus The new status.
     * @return void
     */
    public function setCustomStatus(string $customStatus): void;

    /**
     * Waits for an external event to be raised. May resolve immediately if the event has already been raised.
     *
     * @template T
     * @param string $name
     * @return DurableFuture<T>
     */
    public function waitForExternalEvent(string $name): DurableFuture;

    /**
     * Gets the current time in a deterministic way. (always the time the execution started)
     *
     * @return \DateTimeImmutable
     */
    public function getCurrentTime(): \DateTimeImmutable;

    /**
     * Retrieve the current custom status or null if none has been set.
     *
     * @return string|null
     */
    public function getCustomStatus(): string|null;

    /**
     * Retrieve the current orchestration instance id.
     *
     * @return OrchestrationInstance
     */
    public function getCurrentId(): OrchestrationInstance;

    /**
     * Whether we're replaying an orchestration on this particular line of code.
     *
     * Warning: do not use this for i/o or other side effects.
     *
     * @return bool
     */
    public function isReplaying(): bool;

    /**
     * Retrieve a parent orchestration if there is one.
     *
     * @return OrchestrationInstance|null
     */
    public function getParentId(): OrchestrationInstance|null;

    /**
     * Whether the orchestration will restart as a new orchestration.
     *
     * @return bool
     */
    public function willContinueAsNew(): bool;

    /**
     * A helper method for creating a DateInterval.
     *
     * @param int|null $years
     * @param int|null $months
     * @param int|null $weeks
     * @param int|null $days
     * @param int|null $hours
     * @param int|null $minutes
     * @param int|null $seconds
     * @param int|null $microseconds
     * @return \DateInterval
     */
    public function createInterval(
        int $years = null, int $months = null, int $weeks = null, int $days = null, int $hours = null,
        int $minutes = null, int $seconds = null, int $microseconds = null
    ): \DateInterval;

    /**
     * Returns the first successful future to complete.
     *
     * @param DurableFuture ...$tasks
     * @return DurableFuture
     */
    public function waitAny(DurableFuture ...$tasks): DurableFuture;

    /**
     * Returns once all futures have completed.
     *
     * @param DurableFuture ...$tasks
     * @return DurableFuture<true>
     */
    public function waitAll(DurableFuture ...$tasks): DurableFuture;

    /**
     * Returns the result (or throws on failure) once a single future has completed.
     *
     * @param DurableFuture $task
     * @return mixed
     */
    public function waitOne(DurableFuture $task): mixed;

    /**
     * Creates a simple proxy for the given class
     *
     * @template T
     * @param class-string<T> $className
     * @return T
     */
    public function createEntityProxy(string $className, EntityId $entityId): object;
}
