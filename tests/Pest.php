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

use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\State\AbstractHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\RuntimeStatus;

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toHaveStatus', function (\Bottledcode\DurablePhp\State\RuntimeStatus $status) {
    /** @var \Bottledcode\DurablePhp\State\Status $otherStatus */
    $otherStatus = $this->value->getStatus();

    return expect($otherStatus->runtimeStatus)->toBe($status, "Expected status {$status->name} but got {$otherStatus->runtimeStatus->name}");
});

expect()->extend('toHaveOutput', function (mixed $output) {
    return expect(getStatusOutput($this->value))->toBe($output);
});

expect()->intercept('toEqual', Event::class, function (Event $expected) {
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
    return $history->getStatus()->output['value'] ?? null;
}

function processEvent(\Bottledcode\DurablePhp\Events\Event $event, Closure $processor): array
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

    $eventDispatcher = new class ($fire) extends \Bottledcode\DurablePhp\WorkerTask {
        public function __construct(
            private Closure $fire
        ) {}

        public function fire(\Bottledcode\DurablePhp\Events\Event ...$events): array
        {
            return ($this->fire)($events);
        }
    };

    foreach ($processor($innerEvent, $event) as $nextEvent) {
        if ($nextEvent instanceof \Bottledcode\DurablePhp\Events\Event) {
            $nextEvent->eventId = $fakeId++;
            $events[] = $nextEvent;
            if ($nextEvent instanceof \Bottledcode\DurablePhp\Events\PoisonPill) {
                break;
            }
        }
        if ($nextEvent instanceof Closure) {
            $nextEvent($eventDispatcher, null, null);
        }
    }

    return $events;
}

class SimpleContainer implements Psr\Container\ContainerInterface
{
    public function __construct(private array $objects) {}

    #[\Override]
    public function get(string $id)
    {


        return $this->objects[$id];
    }

    #[\Override]
    public function has(string $id): bool
    {
        return isset($this->objects[$id]);
    }

    public function set(string $id, $value): void
    {
        $this->objects[$id] = $value;
    }
}

function getEntityHistory(?EntityState $withState = null): EntityHistory
{
    static $id = 0;
    $withState ??= new class () extends EntityState {};
    $entityId = new EntityId('test', $id++);
    $history = new EntityHistory(StateId::fromEntityId($entityId));
    $reflector = new \ReflectionClass($history);
    $reflector->getProperty('state')->setValue($history, $withState);
    $history->setContainer(new SimpleContainer(['test' => $withState]));

    return $history;
}

function getOrchestration(
    string $id,
    callable $orchestration,
    array $input,
    ?StartOrchestration &$nextEvent = null,
    ?Event $startupEvent = null
): OrchestrationHistory {
    static $instance = 0;
    $container = new SimpleContainer(
        [
            \Bottledcode\DurablePhp\Proxy\OrchestratorProxy::class => new \Bottledcode\DurablePhp\Proxy\OrchestratorProxy(),
            \Bottledcode\DurablePhp\Proxy\SpyProxy::class => new \Bottledcode\DurablePhp\Proxy\SpyProxy(),
            $instance => $orchestration,
        ]
    );
    $history = new OrchestrationHistory(StateId::fromInstance(new OrchestrationInstance($instance++, $id)));
    $history->setContainer($container);
    $startupEvent ??= StartExecution::asParent($input, []);
    $startupEvent = WithOrchestration::forInstance($history->id, $startupEvent);
    [$nextEvent] = processEvent($startupEvent, $history->applyStartExecution(...));
    expect($history)->toHaveStatus(RuntimeStatus::Pending);

    return $history;
}
