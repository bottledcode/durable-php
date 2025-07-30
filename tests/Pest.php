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

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\Proxy\OrchestratorProxy;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\AbstractHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Status;
use Bottledcode\DurablePhp\Task;
use DI\Container;

use function Bottledcode\DurablePhp\EntityId;
use function Bottledcode\DurablePhp\OrchestrationInstance;

$_SERVER['SERVER_PROTOCOL'] = 'DPHP/1.0';

expect()->extend('toBeOne', fn() => $this->toBe(1));

expect()->extend('toHaveStatus', function (RuntimeStatus $status) {
    /** @var Status $otherStatus */
    $otherStatus = $this->value->getStatus();

    return expect($otherStatus->runtimeStatus)->toBe(
        $status,
        "Expected status {$status->name} but got {$otherStatus->runtimeStatus->name}",
    );
});

expect()->extend('toHaveOutput', fn(mixed $output) => expect(getStatusOutput($this->value))->toBe($output));

expect()->intercept('toEqual', Event::class, function (Event $expected): void {
    $now = new DateTimeImmutable();
    while ($expected instanceof HasInnerEventInterface) {
        $expected->eventId = 'same';
        $expected->timestamp = $now;
        $expected = $expected->getInnerEvent();
    }
    while ($this->value instanceof HasInnerEventInterface) {
        $this->value->eventId = 'same';
        $this->value->timestamp = $now;
        $this->value = $this->value->getInnerEvent();
    }
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function getStatusOutput(AbstractHistory $history): mixed
{
    $array = $history->getStatus()->output ?? null;

    return $array?->toArray()[0];
}

function processEvent(Event $event, Closure $processor): array
{
    static $fakeId = 100;
    $events = [];
    $innerEvent = $event;
    while ($innerEvent instanceof HasInnerEventInterface) {
        $innerEvent = $innerEvent->getInnerEvent();
    }

    $fire = function (array $fired) use (&$events, &$fakeId) {
        $ids = [];
        foreach ($fired as $toFire) {
            $ids[] = $toFire->eventId = $fakeId++;
            $events[] = $toFire;
        }

        return $ids;
    };

    $eventDispatcher = new class ($fire) extends Task {
        public function __construct(
            private Closure $fire,
        ) {}

        public function fire(Event ...$events): array
        {
            return ($this->fire)($events);
        }
    };

    try {
        foreach ($processor($innerEvent, $event) as $nextEvent) {
            if ($nextEvent instanceof Event) {
                $nextEvent->eventId = $fakeId++;
                $events[] = $nextEvent;
                if ($nextEvent instanceof PoisonPill) {
                    break;
                }
            }
            if ($nextEvent instanceof Closure) {
                $nextEvent($eventDispatcher, null, null);
            }
        }
    } catch (Unwind) {
    }

    return $events;
}

function getEntityHistory(?EntityState $withState = null): EntityHistory
{
    static $id = 0;
    $withState ??= new class extends EntityState {};
    $entityId = EntityId('test', $id++);
    $history = new EntityHistory(StateId::fromEntityId($entityId), new DurableLogger(), new Provenance('', []));
    $reflector = new ReflectionClass($history);
    $reflector->getProperty('state')->setValue($history, $withState);
    $history->setContainer(new Container(['test' => $withState, SpyProxy::class => new SpyProxy()]));

    return $history;
}

function getOrchestration(
    string $id,
    callable|object $orchestration,
    array $input,
    ?StartOrchestration &$nextEvent = null,
    ?Event $startupEvent = null,
): OrchestrationHistory {
    $instance = base64_encode(random_bytes(5));

    if (is_callable($orchestration)) {
        $orchestration = static fn() => $orchestration;
    }

    $container = new Container(
        [
            OrchestratorProxy::class => new OrchestratorProxy(),
            SpyProxy::class => new SpyProxy(),
            $instance => $orchestration,
        ],
    );
    $history = new OrchestrationHistory(
        StateId::fromInstance(OrchestrationInstance($instance, $id)),
        new DurableLogger(),
        new Provenance('', []),
    );
    $history->setContainer($container);
    $startupEvent ??= StartExecution::asParent($input, []);
    $startupEvent = WithOrchestration::forInstance($history->id, $startupEvent);
    [$nextEvent] = processEvent($startupEvent, $history->applyStartExecution(...));
    expect($history)->toHaveStatus(RuntimeStatus::Pending);

    return $history;
}
