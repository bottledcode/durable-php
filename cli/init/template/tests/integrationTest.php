<?php

use Bottledcode\DurablePhp\DurableClient;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;
use {{.Name}}\Entities\CountInterface;
use {{.Name}}\Orchestrations\Password;

require_once __DIR__ . '/../vendor/autoload.php';

$client = DurableClient::get();


$entity = new EntityId(CountInterface::class, random_int(0, 10000));

echo "Signaling an entity, which will start an orchestration, which we will wait for completion\n";
$start = microtime(true);
$client->signal($entity, fn(CountInterface $state) => $state->countTo(100));
$client->waitForCompletion(new OrchestrationInstance(\{{.Name}}\Orchestrations\Counter::class, $entity->id));
$time = number_format(microtime(true) - $start, 2);
echo "Cool! That took $time seconds\n";
echo "Here's the state:\n" . json_encode($client->getEntitySnapshot($entity, CountInterface::class), JSON_PRETTY_PRINT) . "\n";

echo "Directly start an orchestration and wait for its completion\n";
$start = microtime(true);
$orch = $client->startNew(\{{.Name}}\Orchestrations\Counter::class, ['count' => 100]);
$client->waitForCompletion($orch);
$time = number_format(microtime(true) - $start, 2);
echo "Neat! That took $time seconds\nHere's the status:\n";
$status = $client->getStatus($orch);
echo json_encode(Serializer::serialize($status), JSON_PRETTY_PRINT) . "\n";

echo "Now we will send a password: ";
$start = microtime(true);
$orch = $client->startNew(Password::class, ['password' => 'test']);
$client->raiseEvent($orch, 'password', ['password' => 'test']);
$client->waitForCompletion($orch);
$status = $client->getStatus($orch);
echo $status->output->toArray() === [true] ? 'success' : 'fail';
echo "\n";
