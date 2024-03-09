<?php

/*
 * Copyright ©2024 Robert Landers
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

use Bottledcode\DurablePhp\DurableClient;
use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\Tests\Common\LauncherEntity;
use Bottledcode\DurablePhp\Tests\PerformanceTests\HelloCities\HelloSequence;
use Bottledcode\DurablePhp\Tests\StopWatch;

use function Amp\async;
use function Amp\Future\await;

require_once __DIR__ . '/../../vendor/autoload.php';

$client = DurableClient::get();
$logger = new DurableLogger();

$watch = new StopWatch();
$watch->start();
$numberToLaunch = getenv('ACTIVITY_COUNT') ?: 1000 / 200;
$numberLaunchers = 200;
for ($i = 0; $i < $numberLaunchers; $i++) {
    $client->signalEntity(
        new EntityId(LauncherEntity::class, $i),
        'launch',
        ['orchestration' => HelloSequence::class, 'number' => $numberToLaunch, 'offset' => $i * $numberToLaunch]
    );
}

$ids = array_keys(array_fill(0, $numberToLaunch * $numberLaunchers, true));
$ids = array_chunk($ids, 100);

foreach($ids as $num => $chunk) {
    $getters = array_map(static fn($id) => async(fn() => $client->waitForCompletion(new OrchestrationInstance(HelloSequence::class, $id))), $chunk);
    $logger->alert(sprintf('Waiting for chunk %d of %d', $num, count($ids)));
    await($getters);
}

$watch->stop();

$logger->alert(sprintf("Completed in %s seconds", number_format($watch->getSeconds(), 3)));
