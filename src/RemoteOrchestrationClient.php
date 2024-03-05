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

use Amp\Http\Client\HttpClient;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Status;

final class RemoteOrchestrationClient implements OrchestrationClientInterface
{
    public function __construct(
        private string $apiHost = 'http://localhost:8080',
        private HttpClient $client = new HttpClient(),
        private SpyProxy $spyProxy = new SpyProxy(),
    ) {
        $this->apiHost = rtrim($this->apiHost, '/');
    }

    #[\Override]
    public function getStatus(OrchestrationInstance $instance): Status {}

    #[\Override]
    public function listInstances(): \Generator
    {
        // TODO: Implement listInstances() method.
    }

    #[\Override]
    public function purge(OrchestrationInstance $instance): void
    {
        // TODO: Implement purge() method.
    }

    #[\Override]
    public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
    {
        // TODO: Implement raiseEvent() method.
    }

    #[\Override]
    public function restart(OrchestrationInstance $instance): void
    {
        // TODO: Implement restart() method.
    }

    #[\Override]
    public function resume(OrchestrationInstance $instance, string $reason): void
    {
        // TODO: Implement resume() method.
    }

    #[\Override]
    public function startNew(string $name, array $args = [], ?string $id = null): OrchestrationInstance
    {
        // TODO: Implement startNew() method.
    }

    #[\Override]
    public function suspend(OrchestrationInstance $instance, string $reason): void
    {
        // TODO: Implement suspend() method.
    }

    #[\Override]
    public function terminate(OrchestrationInstance $instance, string $reason): void
    {
        // TODO: Implement terminate() method.
    }

    #[\Override]
    public function waitForCompletion(OrchestrationInstance $instance): void
    {
        // TODO: Implement waitForCompletion() method.
    }
}
