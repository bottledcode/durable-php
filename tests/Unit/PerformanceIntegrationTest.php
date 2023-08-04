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

namespace Bottledcode\DurablePhp\Tests\Unit;

use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\Tests\PerformanceTests\HelloCities\HelloSequence;

test('performance orchestration', function () {
    $orchestration = new HelloSequence();
    $instance = getOrchestration('test', fn(...$args) => $orchestration(...$args), [], $nextEvent);
    $result = processEvent($nextEvent, $instance->applyStartOrchestration(...));
    $instance->resetState();
    expect($result)->toHaveCount(5);
    $nextEvent = [];
    foreach ($result as $item) {
        $history = new ActivityHistory($item->innerEvent->target, getConfig());
        $nextEvent[] = processEvent($item, $history->applyScheduleTask(...));
    }
    $nextEvent = array_merge(...$nextEvent);
    expect($nextEvent)->toHaveCount(5);
    foreach ($nextEvent as $i => $item) {
        $result = processEvent($item, $instance->applyTaskCompleted(...));
        $instance->resetState();
        expect($result)->toHaveCount(0);
    }
    expect($instance)->toHaveStatus(RuntimeStatus::Completed);
});

test('snapshot', function () {
    $this->markTestSkipped('snapshot requires an update');
    $state = Serializer::deserialize(
        json_decode(file_get_contents(__DIR__ . '/snapshot.json'), true), OrchestrationHistory::class
    );
    $state->setConfig(getConfig());
    $finalEvent = json_decode(file_get_contents(__DIR__.'/final-event.json'), true);
    $finalEvent = Serializer::deserialize($finalEvent, Event::class);
    $result = processEvent($finalEvent, $state->applyTaskCompleted(...));
    expect($state)->toHaveStatus(RuntimeStatus::Completed);
});
