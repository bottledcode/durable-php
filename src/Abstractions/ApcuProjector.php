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
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\State\Status;

class ApcuProjector implements ProjectorInterface
{
    private ProjectorInterface|null $next = null;

    public function connect(): void {}

    public function disconnect(): void {}

    public function projectState(StateId $key, StateInterface|null $history): void
    {
        if (function_exists('apcu_store')) {
            apcu_store($key->id, [Serializer::serialize($history), get_debug_type($history)], getenv('APCU_TTL') ?: 60);
        }

        $this->next?->projectState($key, $history);
    }

    public function getState(StateId $key): EntityHistory|OrchestrationHistory|ActivityHistory|null
    {
        if (function_exists('apcu_fetch')) {
            [$state, $type] = apcu_fetch($key->id, $success);
            if ($success) {
                return Serializer::deserialize($state, $type);
            }
        }

        return $this->next?->getState($key);
    }

    public function chain(ProjectorInterface $next): void
    {
        $this->next = $next;
    }

    public function watch(StateId $key, RuntimeStatus ...$for): Status|null
    {
        return $this->next?->watch($key, ...$for);
    }
}
