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

namespace Bottledcode\DurablePhp\Tests\PerformanceTests;

use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\EntityClient;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\Tests\Common\LauncherEntity;
use Bottledcode\DurablePhp\Tests\PerformanceTests\HelloCities\HelloSequence;
use Bottledcode\DurablePhp\Tests\StopWatch;

require_once __DIR__ . '/../../vendor/autoload.php';

$config = \Bottledcode\DurablePhp\Config\Config::fromArgs($argv);
$client = new \Bottledcode\DurablePhp\OrchestrationClient($config, SourceFactory::fromConfig($config));
$actors = new EntityClient($config, SourceFactory::fromConfig($config));

$watch = new StopWatch();
$watch->start();
$numberToLaunch = getenv('ACTIVITY_COUNT') ?: 5000 / 200;
$numberLaunchers = 200;
for ($i = 0; $i < $numberLaunchers; $i++) {
    $actors->signalEntity(
        new EntityId(LauncherEntity::class, $i),
        'launch',
        ['orchestration' => HelloSequence::class, 'number' => $numberToLaunch, 'offset' => $i * $numberToLaunch]
    );
}

for ($i = 0; $i < $numberLaunchers * $numberToLaunch; $i++) {
    Logger::always("Waiting for %d", $i);
    $client->waitForCompletion(new OrchestrationInstance(HelloSequence::class, $i));
}

$watch->stop();

Logger::always("Completed in %s seconds", number_format($watch->getSeconds(), 2));
