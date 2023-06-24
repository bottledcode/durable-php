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

use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Config\MemoryConfig;

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toHaveStatus', function (\Bottledcode\DurablePhp\State\RuntimeStatus $status) {
    /** @var \Bottledcode\DurablePhp\State\Status $otherStatus */
    $otherStatus = $this->value->getStatus();
    return expect($otherStatus->runtimeStatus)->toBe($status, "Expected status {$status->name} but got {$otherStatus->runtimeStatus->name}");
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

function getStatusOutput(\Bottledcode\DurablePhp\State\AbstractHistory $history): mixed {
    return $history->getStatus()->output['value'] ?? null;
}

function getConfig(): Config
{
    return new Config(
        currentPartition: 0, storageConfig: new MemoryConfig()
    );
}

function processEvent(\Bottledcode\DurablePhp\Events\Event $event, Closure $processor): array
{
    $events = [];
    $innerEvent = $event;
    while ($innerEvent instanceof \Bottledcode\DurablePhp\Events\HasInnerEventInterface) {
        $innerEvent = $innerEvent->getInnerEvent();
    }

    $fire = function (array $fired) use (&$events) {
        static $id = 0;
        $ids = [];
        foreach ($fired as $toFire) {
            $ids[] = $toFire->eventId = $id++;
            $events[] = $toFire;
        }

        return $ids;
    };

    $eventDispatcher = new class($fire) extends \Bottledcode\DurablePhp\EventDispatcherTask {
        public function __construct(
            private Closure $fire
        ) {
        }

        public function fire(\Bottledcode\DurablePhp\Events\Event ...$events): array
        {
            return ($this->fire)($events);
        }
    };

    foreach ($processor($innerEvent, $event) as $nextEvent) {
        if ($nextEvent instanceof \Bottledcode\DurablePhp\Events\Event) {
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

function simpleFactory(string $key, Closure|null $store = null): object
{
    static $factory = [];

    if ($store) {
        $factory[$key] = $store;
    }

    return new class($key, $factory[$key] ?? null) {
        public function __invoke(...$params)
        {
            return ($this->store)(...$params);
        }

        public function __construct(
            private string $key,
            private Closure|null $store
        ) {
        }
    };
}
