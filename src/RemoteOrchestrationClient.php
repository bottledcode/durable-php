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
use Amp\Http\Client\SocketException;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\Status;
use Exception;
use Generator;
use JsonException;
use Override;
use RuntimeException;
use Withinboredom\Time\Unit;

use function Withinboredom\Time\Hours;
use function Withinboredom\Time\Seconds;

final class RemoteOrchestrationClient implements OrchestrationClientInterface
{
    private string $userToken = '';

    public function __construct(
        private string $apiHost = 'http://localhost:8080',
        private HttpClient $client = new HttpClient(),
        private SpyProxy $spyProxy = new SpyProxy(),
    ) {
        $this->apiHost = mb_rtrim($this->apiHost, '/');
    }

    #[Override]
    public function listInstances(): Generator
    {
        $req = new Request("{$this->apiHost}/orchestrations");
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        $result = json_decode($result->getBody()->buffer(), true, 512, JSON_THROW_ON_ERROR);
        yield from $result;
    }

    #[Override]
    public function purge(OrchestrationInstance $instance): void
    {
        $name = rawurlencode($instance->instanceId);
        $id = rawurlencode($instance->executionId);
        $req = new Request("{$this->apiHost}/orchestrations/{$name}/{$id}", 'DELETE');
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() !== 204) {
            throw new Exception('Cannot purge Orchestration');
        }
    }

    #[Override]
    public function getStatus(OrchestrationInstance $instance): Status
    {
        $name = rawurlencode($instance->instanceId);
        $id = rawurlencode($instance->executionId);
        $req = new Request("{$this->apiHost}/orchestration/{$name}/{$id}");
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        $body = '';
        while ($result->getBody()->isReadable()) {
            $body .= $result->getBody()->buffer();
        }
        try {
            $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to decode JSON: ' . $body, previous: $exception);
        }

        return Serializer::deserialize($result, Status::class);
    }

    #[Override]
    public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
    {
        $name = rawurlencode($instance->instanceId);
        $id = rawurlencode($instance->executionId);
        $signal = rawurlencode($eventName);
        $eventData = SerializedArray::fromArray($eventData);
        $req = new Request(
            "{$this->apiHost}/orchestration/{$name}/{$id}/{$signal}",
            'PUT',
            json_encode($eventData, JSON_THROW_ON_ERROR),
        );
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() >= 300) {
            throw new Exception($result->getBody()->buffer());
        }
    }

    #[Override]
    public function restart(OrchestrationInstance $instance): void
    {
        // TODO: Implement restart() method.
    }

    #[Override]
    public function resume(OrchestrationInstance $instance, string $reason): void
    {
        // TODO: Implement resume() method.
    }

    #[Override]
    public function startNew(string $name, array $args = [], ?string $id = null): OrchestrationInstance
    {
        $data = ['input' => SerializedArray::fromArray($args)];
        $data = json_encode($data, JSON_THROW_ON_ERROR);
        $name = rawurlencode($name);
        $id = $id ? '/' . rawurlencode($id) : '';
        $req = new Request("{$this->apiHost}/orchestration/{$name}{$id}", 'PUT', $data);
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() >= 300) {
            throw new Exception($result->getBody()->buffer());
        }

        return StateId::fromString($result->getHeader('X-Id'))->toOrchestrationInstance();
    }

    #[Override]
    public function suspend(OrchestrationInstance $instance, string $reason): void
    {
        // TODO: Implement suspend() method.
    }

    #[Override]
    public function terminate(OrchestrationInstance $instance, string $reason): void
    {
        // TODO: Implement terminate() method.
    }

    #[Override]
    public function waitForCompletion(OrchestrationInstance $instance): void
    {
        $name = rawurlencode($instance->instanceId);
        $id = rawurlencode($instance->executionId);
        $req = new Request("{$this->apiHost}/orchestration/{$name}/{$id}?wait=60");
        $req->setInactivityTimeout(Hours(1)->as(Unit::Seconds));
        $req->setTcpConnectTimeout(Seconds(30)->as(Unit::Seconds));
        $req->setTransferTimeout(Hours(1)->as(Unit::Seconds));
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $retries = 3;
        retry:
        try {
            $result = $this->client->request($req);
            $result->getBody()->buffer();
        } catch (SocketException $exception) {
            if ($retries-- > 0) {
                goto retry;
            }
            throw $exception;
        }
    }

    #[Override]
    public function withAuth(Provenance|string|null $token): void
    {
        $this->userToken = $token;
    }

    public function shareOrchestrationOwnership(OrchestrationInstance $id, string $with): void
    {
        $req = new Request("{$this->apiHost}/orchestration/{$id->instanceId}/{$id->executionId}/share/{$with}", 'PUT');
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() !== 200) {
            throw new Exception('Failed to share ownership');
        }
    }

    public function grantOrchestrationAccessToUser(OrchestrationInstance $id, string $user, Operation $operation): void
    {
        $req = new Request("{$this->apiHost}/orchestration/{$id->instanceId}/{$id->executionId}/grant/user/{$user}/{$operation->value}", 'PUT');
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() !== 200) {
            throw new Exception('Failed to grant access');
        }
    }

    public function grantOrchestrationAccessToRole(OrchestrationInstance $id, string $role, Operation $operation): void
    {
        $req = new Request("{$this->apiHost}/orchestration/{$id->instanceId}/{$id->executionId}/grant/role/{$role}/{$operation->value}", 'PUT');
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() !== 200) {
            throw new Exception('Failed to grant access');
        }
    }

    public function revokeOrchestrationAccessToUser(OrchestrationInstance $id, string $user): void
    {
        $req = new Request("{$this->apiHost}/orchestration/{$id->instanceId}/{$id->executionId}/grant/user/{$user}", 'DELETE');
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() !== 200) {
            throw new Exception('Failed to grant access');
        }
    }

    public function revokeOrchestrationAccessToRole(OrchestrationInstance $id, string $role): void
    {
        $req = new Request("{$this->apiHost}/orchestration/{$id->instanceId}/{$id->executionId}/grant/role/{$role}", 'DELETE');
        if ($this->userToken) {
            $req->setHeader('Authorization', 'Bearer ' . $this->userToken);
        }
        $result = $this->client->request($req);
        if ($result->getStatus() !== 200) {
            throw new Exception('Failed to grant access');
        }
    }
}
