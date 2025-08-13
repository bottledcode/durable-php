<?php

/*
 * Copyright ©2024 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\Status;
use Exception;
use Generator;
use Override;
use Ramsey\Uuid\Uuid;

use function Bottledcode\DurablePhp\Ext\emit_event;

final class LocalOrchestrationClient implements OrchestrationClientInterface
{
    private ?Provenance $userContext = null;

    public function __construct(
        private SpyProxy $spyProxy = new SpyProxy(),
    ) {}

    #[Override]
    public function listInstances(): Generator
    {
        throw new Exception('listInstances not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    #[Override]
    public function purge(OrchestrationInstance $instance): void
    {
        throw new Exception('purge not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    #[Override]
    public function getStatus(OrchestrationInstance $instance): Status
    {
        throw new Exception('getStatus not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    #[Override]
    public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
    {
        $event = WithOrchestration::forInstance(
            StateId::fromOrchestrationInstance($instance),
            RaiseEvent::forSignal($eventName, SerializedArray::fromArray($eventData)),
        );

        $eventDescription = new EventDescription($event);
        $userArray = $this->userContext ? Serializer::serialize($this->userContext) : null;

        emit_event($userArray, $eventDescription->toArray(), StateId::fromOrchestrationInstance($instance)->id);
    }

    #[Override]
    public function restart(OrchestrationInstance $instance): void
    {
        throw new Exception('restart not implemented in LocalOrchestrationClient');
    }

    #[Override]
    public function resume(OrchestrationInstance $instance, string $reason): void
    {
        throw new Exception('resume not implemented in LocalOrchestrationClient');
    }

    #[Override]
    public function startNew(string $name, array $args = [], ?string $id = null): OrchestrationInstance
    {
        $orchestrationId = OrchestrationId($name, $id ?? Uuid::uuid4()->toString());
        $stateId = StateId::fromOrchestrationId($orchestrationId);

        $event = WithOrchestration::forInstance(
            $stateId,
            StartOrchestration::fromArray(SerializedArray::fromArray($args)),
        );

        $eventDescription = new EventDescription($event);
        $userArray = $this->userContext ? Serializer::serialize($this->userContext) : null;

        $sequence = emit_event($userArray, $eventDescription->toArray(), $stateId->id);

        return $orchestrationId->toOrchestrationInstance();
    }

    #[Override]
    public function suspend(OrchestrationInstance $instance, string $reason): void
    {
        throw new Exception('suspend not implemented in LocalOrchestrationClient');
    }

    #[Override]
    public function terminate(OrchestrationInstance $instance, string $reason): void
    {
        throw new Exception('terminate not implemented in LocalOrchestrationClient');
    }

    #[Override]
    public function waitForCompletion(OrchestrationInstance $instance): void
    {
        throw new Exception('waitForCompletion not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    #[Override]
    public function withAuth(string $token): void
    {
        throw new Exception('withAuth not implemented for LocalOrchestrationClient - set user context directly using setUserContext');
    }

    public function setUserContext(?Provenance $userContext): void
    {
        $this->userContext = $userContext;
    }

    public function shareOrchestrationOwnership(OrchestrationInstance $id, string $with): void
    {
        throw new Exception('shareOrchestrationOwnership not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    public function grantOrchestrationAccessToUser(OrchestrationInstance $id, string $user, Operation $operation): void
    {
        throw new Exception('grantOrchestrationAccessToUser not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    public function grantOrchestrationAccessToRole(OrchestrationInstance $id, string $role, Operation $operation): void
    {
        throw new Exception('grantOrchestrationAccessToRole not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    public function revokeOrchestrationAccessToUser(OrchestrationInstance $id, string $user): void
    {
        throw new Exception('revokeOrchestrationAccessToUser not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }

    public function revokeOrchestrationAccessToRole(OrchestrationInstance $id, string $role): void
    {
        throw new Exception('revokeOrchestrationAccessToRole not supported in LocalOrchestrationClient - use RemoteOrchestrationClient for this operation');
    }
}
