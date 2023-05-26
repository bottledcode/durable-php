<?php

namespace Bottledcode\DurablePhp;

use InvalidArgumentException;

readonly class Config
{
	public function __construct(
		public int $currentPartition,
		public int $totalPartitions = 10,
		public string $redisHost = 'localhost',
		public int $redisPort = 6379,
		public int $totalWorkers = 10,
		public string $bootstrapPath = __DIR__ . '/../vendor/autoload.php',
		public string $partitionKeyPrefix = 'partition_',
	) {
	}

	public static function fromArgs(array $args): self
	{
		$arr = [];
		for ($i = 1, $iMax = count($args); $i < $iMax; $i++) {
			$key = match ($args[$i] ?? throw new InvalidArgumentException('Missing argument')) {
				'--current-partition', '-c' => 'currentPartition',
				'--total-partitions', '-t' => 'totalPartitions',
				'--redis-host', '-h' => 'redisHost',
				'--redis-port', '-p' => 'redisPort',
				'--total-workers', '-w' => 'totalWorkers',
				'--bootstrap-path', '-b' => 'bootstrapPath',
				default => throw new InvalidArgumentException('Unknown argument ' . $args[$i])
			};
			$arr[$key] = $args[++$i] ?? throw new InvalidArgumentException(
				'Missing value for argument ' . $args[$i - 1]
			);
		}

		return new self(...$arr);
	}
}
