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

use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\Tests\PerformanceTests\Bank\BankTransaction;
use Bottledcode\DurablePhp\Tests\StopWatch;

require_once __DIR__ . '/../../../vendor/autoload.php';

$config = \Bottledcode\DurablePhp\Config\Config::fromArgs($argv);
$client = new \Bottledcode\DurablePhp\OrchestrationClient($config, SourceFactory::fromConfig($config));

$watch = new StopWatch();
$watch->start();
$id = random_int(1, 1000000);
//$id = 1;
$instance = $client->startNew(BankTransaction::class, [$id]);
$instance2 = $client->startNew(BankTransaction::class, [$id]);
$client->waitForCompletion($instance);
$client->waitForCompletion($instance2);
$watch->stop();

var_dump($client->getStatus($instance));
var_dump($client->getStatus($instance2));

Logger::log("Completed in %s seconds", number_format($watch->getSeconds(), 2));
