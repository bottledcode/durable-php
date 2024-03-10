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
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\Serializer;

class RemoteEntityClient implements EntityClientInterface
{
    public function __construct(
        private string $apiHost = 'http://localhost:8080',
        private HttpClient $client = new HttpClient(),
        private SpyProxy $spyProxy = new SpyProxy(),
    ) {
        $this->apiHost = rtrim($this->apiHost, '/');
    }

    #[\Override]
    public function cleanEntityStorage(): void {}

    #[\Override]
    public function listEntities(): \Generator
    {
        $req = new Request($this->apiHost . '/entities');
        $result = $this->client->request($req);
        $result = json_decode($result->getBody()->read(), true, 512, JSON_THROW_ON_ERROR);
        yield from $result;
    }

    #[\Override]
    public function signal(EntityId|string $entityId, \Closure $signal): void
    {
        $interfaceReflector = new \ReflectionFunction($signal);
        $interfaceName = $interfaceReflector->getParameters()[0]->getType()?->getName();
        if(interface_exists($interfaceName) === false) {
            throw new \Exception("Interface $interfaceName does not exist");
        }
        $spy = $this->spyProxy->define($interfaceName);
        $operationName = '';
        $arguments = [];
        try {
            $class = new $spy($operationName, $arguments);
            $signal($class);
        } catch(\Throwable) {
            // spies always throw
        }
        $this->signalEntity(is_string($entityId) ? new EntityId($interfaceName, $entityId) : $entityId, $operationName, $arguments);
    }

    #[\Override]
    public function signalEntity(
        EntityId $entityId,
        string $operationName,
        array $input = [],
        ?\DateTimeImmutable $scheduledTime = null
    ): void {
        $name = rawurlencode($entityId->name);
        $id = rawurlencode($entityId->id);

        $input = [
            'signal' => $operationName,
            'input' => SerializedArray::fromArray($input)
        ];

        $req = new Request("{$this->apiHost}/entity/{$name}/{$id}   ", 'PUT', json_encode($input, JSON_THROW_ON_ERROR));
        if($scheduledTime) {
            $req->setHeader("At", $scheduledTime->format(DATE_ATOM));
        }
        $result = $this->client->request($req);
        if($result->getStatus() >= 300) {
            throw new \Exception("error calling " . $req->getUri()->getPath() . "\n" . $result->getBody()->read());
        }
    }

    #[\Override]
    public function getEntitySnapshot(EntityId $entityId): ?EntityState
    {
        $req = new Request($this->apiHost . '/entity/' . $entityId->name . '/' . $entityId->id);
        $result = $this->client->request($req);
        $result = json_decode($result->getBody()->read() ?: 'null', true, 512, JSON_THROW_ON_ERROR);

        if ($result === null) {
            return null;
        }

        return Serializer::deserialize($result, EntityState::class);
    }
}
