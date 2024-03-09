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
use Amp\Http\Client\Request;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\Status;

use function Withinboredom\Time\Hours;
use function Withinboredom\Time\Seconds;

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
    public function listInstances(): \Generator
    {
        $req = new Request("$this->apiHost/orchestrations");
        $result = $this->client->request($req);
        $result = json_decode($result->getBody()->read(), true, 512, JSON_THROW_ON_ERROR);
        yield from $result;
    }

    #[\Override]
    public function purge(OrchestrationInstance $instance): void
    {
        throw new \LogicException('not implemented yet');
    }

    #[\Override]
    public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
    {
        $name = rawurlencode($instance->instanceId);
        $id = rawurlencode($instance->executionId);
        $signal = rawurlencode($eventName);
        $req = new Request("$this->apiHost/orchestration/$name/$id/$signal", 'PUT', json_encode(Serializer::serialize($eventData), JSON_THROW_ON_ERROR));
        $result = $this->client->request($req);
        if ($result->getStatus() >= 300) {
            throw new \Exception($result->getBody()->read());
        }
    }

    #[\Override]
    public function getStatus(OrchestrationInstance $instance): Status
    {
        $name = rawurlencode($instance->instanceId);
        $id = rawurlencode($instance->executionId);
        $req = new Request("$this->apiHost/orchestration/$name/$id");
        $result = $this->client->request($req);
        $result = json_decode($result->getBody()->read(), true, 512, JSON_THROW_ON_ERROR);

        return Serializer::deserialize($result, Status::class);
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
        $data = [
            'name' => $name,
            ...($id ? ['id' => $id] : []),
            'input' => $args,
        ];
        $data = json_encode(Serializer::serialize($data), JSON_THROW_ON_ERROR);
        $req = new Request("$this->apiHost/orchestrations", 'PUT', $data);
        $result = $this->client->request($req);
        if ($result->getStatus() >= 300) {
            throw new \Exception($result->getBody()->read());
        }

        return new OrchestrationInstance($name, $result->getHeader('X-Id'));
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
        $name = rawurlencode($instance->instanceId);
        $id = rawurlencode($instance->executionId);
        $req = new Request("$this->apiHost/orchestration/$name/$id?wait=60");
        $req->setInactivityTimeout(Hours(1)->inSeconds());
        $req->setTcpConnectTimeout(Seconds(1)->inSeconds());
        $req->setTransferTimeout(Hours(1)->inSeconds());
        $result = $this->client->request($req);
        $result = $result->getBody()->read();


    }
}
