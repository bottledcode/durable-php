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

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithActivity;
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Ramsey\Uuid\Uuid;

function activity(bool $fail)
{
    if ($fail) {
        throw new \Exception('test');
    }
}

it('only says it has executed once', function () {
    $history = new ActivityHistory(StateId::fromActivityId(Uuid::uuid7()), getConfig());
    expect($history->hasAppliedEvent(RaiseEvent::forOperation('test', [])))->toBeFalse()
        ->and($history->hasAppliedEvent(RaiseEvent::forOperation('test', [])))->toBeTrue();
});

it('fails on an exception', function () {
    $history = new ActivityHistory(StateId::fromActivityId(Uuid::uuid7()), getConfig());
    $event = AwaitResult::forEvent(
        StateId::fromEntityId(new EntityId('test', 'test')),
        WithActivity::forEvent(Uuid::uuid7(), ScheduleTask::forName(__NAMESPACE__ . '\activity', [true]))
    );
    $result = processEvent($event, $history->applyScheduleTask(...));
    expect($result)->toHaveCount(1)->and($result[0]->getInnerEvent())->toBeInstanceOf(TaskFailed::class);
});

it('succeeds on no exception', function () {
    $history = new ActivityHistory(StateId::fromActivityId(Uuid::uuid7()), getConfig());
    $event = AwaitResult::forEvent(
        StateId::fromEntityId(new EntityId('test', 'test')),
        WithActivity::forEvent(Uuid::uuid7(), ScheduleTask::forName(__NAMESPACE__ . '\activity', [false]))
    );
    $result = processEvent($event, $history->applyScheduleTask(...));
    expect($result)->toHaveCount(1)->and($result[0]->getInnerEvent())->toBeInstanceOf(TaskCompleted::class);
});
