<?php

/*
 * Copyright ©2023 Robert Landers
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

namespace Bottledcode\DurablePhp\Config;

use InvalidArgumentException;
use ReflectionClass;

readonly class Config
{
    public function __construct(
        public int $currentPartition,
        public RedisConfig|RethinkDbConfig|WALConfig $storageConfig,
        public int $totalPartitions = 1,
        public int $totalWorkers = 3,
        public string|null $bootstrapPath = null,
        public string $partitionKeyPrefix = 'partition_',
        public int $workerTimeoutSeconds = 10,
        public int $workerGracePeriodSeconds = 1,
        public int $maximumMemoryPerWorker = 32,
        public string|null $factory = null,
    ) {
    }

    public static function fromArgs(array $args): self
    {
        $arr = [];
        for ($i = 1, $iMax = count($args); $i < $iMax; $i += 2) {
            $key = $args[$i];
            $value = $args[$i + 1] ?? throw new InvalidArgumentException(
                'Missing value for argument ' . $key
            );
            $key = str_replace('--', '', $key);
            $key = explode('-', $key);
            $key = array_map(ucfirst(...), $key);
            $key = lcfirst(implode('', $key));
            $arr[$key] = $value;
        }

        $db = $arr['db'] ?? null;
        unset($arr['db']);

        $allowedKeys = self::getAllowedKeys(
            match ($db) {
                'redis' => RedisConfig::class,
                'rethinkdb' => RethinkDbConfig::class,
                'WAL' => WALConfig::class,
                default => throw new InvalidArgumentException(
                    'Missing value for argument db (rethinkdb or redis)'
                )
            }
        );

        $sub = [];
        foreach ($allowedKeys as $key) {
            $val = $arr[$key] ?? null;
            if ($val !== null) {
                $sub[$key] = $val;
            }
            unset($arr[$key]);
        }
        $arr['storageConfig'] = match ($db) {
            'redis' => new RedisConfig(...$sub),
            'rethinkdb' => new RethinkDbConfig(...$sub),
            'WAL' => new WALConfig(...$sub),
            default => throw new InvalidArgumentException(
                'Missing value for argument db (rethinkdb or redis)'
            )
        };

        return new self(...$arr);
    }

    public static function getAllowedKeys(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();
        $props = [];
        foreach ($parameters as $parameter) {
            $props[] = $parameter->getName();
        }
        return $props;
    }
}
