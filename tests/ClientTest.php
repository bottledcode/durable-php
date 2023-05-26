<?php

require_once __DIR__ . '/../vendor/autoload.php';

$config = new \Bottledcode\DurablePhp\Config(0, 1);
$redis = \Bottledcode\DurablePhp\RedisReaderTask::connect($config);
$client = new \Bottledcode\DurablePhp\OrchestrationClientRedis($redis, $config);

$orchestrationInstance = $client->startNew(\Bottledcode\DurablePhp\HelloSequence::class, ['World']);
$client->waitForCompletion($orchestrationInstance, \Withinboredom\Time\Hours::from(1));
var_dump($client->getStatus($orchestrationInstance));
