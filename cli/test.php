<?php

require_once __DIR__.'/../vendor/autoload.php';

use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\Serializer;

use function Bottledcode\DurablePhp\EntityId;
use function Bottledcode\DurablePhp\ext\emit_event;

$user = new Provenance('rob', ['admin']);
$user = Serializer::serialize($user);

$event = WithEntity::forInstance(
    StateId::fromEntityId(EntityId('test', 'test')),
    RaiseEvent::forTimer('ident')
);
$event = new EventDescription($event);

echo emit_event($user, $event->toArray(), StateId::fromEntityId(EntityId('test', 'test')));

// echo emit_event(['userId' => 'bob', 'roles' => ['admin']], [], 'activity:bullshit');
