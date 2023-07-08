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
use Crell\Serde\Attributes\ClassNameTypeMap;

#[ClassNameTypeMap('__entity_type')]
interface EntityContextInterface
{
    public static function current(): static;

    /**
     * Delete the current entity.
     *
     * @return never
     */
    public function delete(): never;

    /**
     * Get the input to the current operation.
     *
     * @template T
     * @return T
     */
    public function getInput(): mixed;

    /**
     * Get the current entity's state.
     *
     * @template T
     * @return T
     */
    public function getState(): mixed;

    /**
     * Return the given value from the current operation.
     *
     * @template T
     * @param T $value
     * @return never
     */
    public function return(mixed $value): never;

    /**
     * Set the current state of the entity.
     *
     * @template T
     * @param T $value
     * @return void
     */
    public function setState(mixed $value): void;

    /**
     * Signal another entity.
     *
     * @template T
     * @param EntityId $entityId
     * @param string $operation
     * @param array<T> $input
     * @param \DateTimeImmutable|null $scheduledTime
     * @return void
     */
    public function signalEntity(
        EntityId $entityId,
        string $operation,
        array $input = [],
        \DateTimeImmutable|null $scheduledTime = null
    ): void;

    /**
     * Get the current entity id.
     *
     * @return EntityId
     */
    public function getId(): EntityId;

    /**
     * Get the current operation.
     *
     * @return string
     */
    public function getOperation(): string;

    public function startNewOrchestration(string $orchestration, array $input = [], string|null $id = null): void;

    public function delayUntil(
        string $operation,
        array $args = [],
        \DateTimeInterface $until = new \DateTimeImmutable()
    ): void;
}
