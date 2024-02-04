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

namespace Bottledcode\DurablePhp\Abstractions;

use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\StateInterface;

interface ProjectorInterface
{
    /**
     * Connect via environment variables
     *
     * @return void
     */
    public function connect(): void;

    /**
     * Disconnect
     * @return void
     */
    public function disconnect(): void;

    public function projectState(StateId $key, StateInterface|null $history): void;

    public function getState(StateId $key): EntityHistory|OrchestrationHistory|ActivityHistory|null;

    public function chain(ProjectorInterface $next): void;

    public function watch(StateId $key, RuntimeStatus ...$for): void;
}
