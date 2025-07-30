<?php

use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;

use function Bottledcode\DurablePhp\EntityId;
use function Bottledcode\DurablePhp\OrchestrationInstance;

it('can serialize an entity id', function (): void {
    $record = EntityId('name', 'id');
    $result = Serializer::serialize($record);
    $result = Serializer::deserialize($result, EntityId::class);
    expect($result)->toBe($record);
});

it('can serialize an orchestration id', function (): void {
    $record = OrchestrationInstance('name', 'id');
    $result = Serializer::serialize($record);
    $result = Serializer::deserialize($result, OrchestrationInstance::class);
    expect($result)->toBe($record);
});

it('can serialize an event', function (): void {
    $entity = EntityId('name', 'id');
    $event = WithEntity::forInstance(StateId::fromEntityId($entity), RaiseEvent::forOperation('get', ['test' => 'test']));
    $result = Serializer::serialize($event);
    $result = Serializer::deserialize($result, WithEntity::class);
    expect($result->target)->toBe($event->target);
});
