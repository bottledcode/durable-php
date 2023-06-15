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

namespace Bottledcode\DurablePhp\Abstractions\Sources;

use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Status;
use Generator;
use Withinboredom\Time\Seconds;

interface Source
{
    public static function connect(Config $config, bool $asyncSupport): static;

    public function close(): void;

    public function getPastEvents(): Generator;

    public function receiveEvents(): Generator;

    public function cleanHouse(): void;

    public function storeEvent(Event $event, bool $local): string;

    public function workerStartup(): void;

    public function put(string $key, mixed $data, Seconds|null $ttl = null, ?int $etag = null): void;

    /**
     * @template T
     * @param string $key
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $key, string $class): mixed;

    public function ack(Event $event): void;

    public function watch(StateId $stateId, RuntimeStatus ...$expected): Status|null;
}
