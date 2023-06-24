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
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\OrchestrationContextInterface;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\RuntimeStatus;

function getOrchestration(
    string $id,
    callable $orchestration,
    array $input,
    StartOrchestration|null &$nextEvent = null,
    Event|null $startupEvent = null
): OrchestrationHistory {
    static $instance = 0;
    simpleFactory($instance, $orchestration);
    $history = new OrchestrationHistory(
        StateId::fromInstance(new OrchestrationInstance($instance++, $id)),
        getConfig()->with(factory: 'simpleFactory')
    );
    $startupEvent ??= StartExecution::asParent($input, []);
    $startupEvent = WithOrchestration::forInstance($history->id, $startupEvent);
    [$nextEvent] = processEvent($startupEvent, $history->applyStartExecution(...));
    expect($history)->toHaveStatus(RuntimeStatus::Pending);
    return $history;
}

it('can be started', function () {
    $instance = getOrchestration('test', fn() => true, [], $nextEvent);
    $result = processEvent($nextEvent, $instance->applyStartOrchestration(...));
    expect($result)->toBeEmpty();
    expect($instance)->toHaveStatus(RuntimeStatus::Completed);
});

it('returns a result to the parent', function () {
    $instance = getOrchestration('test', fn() => true, [], $nextEvent,
        StartExecution::asChild(new OrchestrationInstance('parent', 'parent'), [], []));
    $result = processEvent($nextEvent, $instance->applyStartOrchestration(...));
    expect($result)->toHaveCount(1);
    expect($instance)->toHaveStatus(RuntimeStatus::Completed);
});

it('properly delays when using timers', function () {
    $instance = getOrchestration('test', function (OrchestrationContextInterface $context) {
        $start = $context->getCurrentTime();
        $interval = $context->createInterval(hours: 1);
        $timeout = $context->createTimer($start->add($interval));
        $context->waitOne($timeout);
        return true;
    }, [], $nextEvent);
    $timer = processEvent($nextEvent, $instance->applyStartOrchestration(...));
    expect($timer)->toHaveCount(1);
    $instance->resetState();
    $result = processEvent($timer[0], $instance->applyRaiseEvent(...));
    expect($result)->toBeEmpty()
        ->and($instance)->toHaveStatus(RuntimeStatus::Completed)
        ->and(getStatusOutput($instance))->toBeTrue();
});

it('can wait for a signal after starting', function () {
    $instance = getOrchestration('test', function (OrchestrationContextInterface $context) {
        $waiter = [];
        for ($i = 0; $i < 3; $i++) {
            $waiter[] = $context->waitForExternalEvent('test');
        }
        $context->waitAll(...$waiter);
        return true;
    }, [], $nextEvent);
    $result = processEvent($nextEvent, $instance->applyStartOrchestration(...));
    $instance->resetState();
    expect($result)->toBeEmpty()
        ->and($instance)->toHaveStatus(RuntimeStatus::Running);
    $result = processEvent(
        WithOrchestration::forInstance($instance->id, new RaiseEvent('', 'test', [])), $instance->applyRaiseEvent(...)
    );
    $instance->resetState();
    expect($result)->toBeEmpty()
        ->and($instance)->toHaveStatus(RuntimeStatus::Running);
    $result = processEvent(
        WithOrchestration::forInstance($instance->id, new RaiseEvent('', 'test', [])), $instance->applyRaiseEvent(...)
    );
    $instance->resetState();
    expect($result)->toBeEmpty()
        ->and($instance)->toHaveStatus(RuntimeStatus::Running);
    $result = processEvent(
        WithOrchestration::forInstance($instance->id, new RaiseEvent('', 'test', [])), $instance->applyRaiseEvent(...)
    );
    $instance->resetState();
    expect($result)->toBeEmpty()
        ->and($instance)->toHaveStatus(RuntimeStatus::Completed)
        ->and(getStatusOutput($instance))->toBeTrue();
});
