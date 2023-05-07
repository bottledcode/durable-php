<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Infrastructure\TaskHubParameters;
use Bottledcode\DurablePhp\Serialization\ChannelSerializer;
use parallel\Channel;
use parallel\Events;
use parallel\Runtime;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/attributes.php';

if ($argc < 2) {
	echo 'Usage: php Worker.php <src> <partitions>';
}

$base_dir = $argv[1];
chdir($base_dir);
[$partition, $totalPartitions] = explode(':', $argv[2]);

$channel = Channel::make('partitionReader', Channel::Infinite);
$parameters = new TaskHubParameters('test', Uuid::uuid7(), new \DateTimeImmutable(), 'test', $totalPartitions);

// start the partition reader
$partitionReaderThread = new Runtime(__DIR__ . '/../vendor/autoload.php');
$partitionReaderThread->run(function (Channel $channel, $parameters, $partition) {
	$partitionReader = new PartitionReader();
	$partitionReader->connect();
	$partitionReader->readPartition($channel, ChannelSerializer::unserialize($parameters), $partition);
}, [$channel, ChannelSerializer::serialize($parameters), $partition]);

$event = new Events();
$event->addChannel($channel);
$event->setBlocking(true);

foreach($event as $e) {
	var_dump($e);
	$input = new Events\Input();
	$input->add('test', 'testv');
	$event->setInput($input);
	$event->addChannel($channel);
}

// read the partition
// schedule workers
