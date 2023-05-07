<?php

namespace Bottledcode\DurablePhp;

use olvlvl\ComposerAttributeCollector\Attributes;

require_once __DIR__ . '/../vendor/autoload.php';

if($argc < 2) {
	echo 'Usage: php Worker.php <src> <partitions>';
}

$base_dir = $argv[1];
chdir($base_dir);
$partitions = explode(':', $argv[2]);

// start the partition reader


// read the partition
// schedule workers
