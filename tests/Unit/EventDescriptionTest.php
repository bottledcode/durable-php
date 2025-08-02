<?php

/*
 * Copyright ©2024 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\External;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Events\ReplyToInterface;
use Bottledcode\DurablePhp\Events\Shares\NeedsSource;
use Bottledcode\DurablePhp\Events\Shares\NeedsTarget;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use Bottledcode\DurablePhp\Events\TargetType;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithDelay;
use Bottledcode\DurablePhp\Events\WithLock;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Ramsey\Uuid\Uuid;

use function Bottledcode\DurablePhp\OrchestrationInstance;

// Mock classes for testing
class SimpleEvent extends Event
{
    public function __construct(string $eventId = '')
    {
        parent::__construct($eventId ?: Uuid::uuid7()->toString());
    }

    public function __toString(): string
    {
        return 'SimpleEvent()';
    }
}

#[NeedsTarget(Operation::Signal)]
class EventWithTargetAttribute extends Event
{
    public function __construct(string $eventId = '')
    {
        parent::__construct($eventId ?: Uuid::uuid7()->toString());
    }

    public function __toString(): string
    {
        return 'EventWithTargetAttribute()';
    }
}

#[NeedsSource(Operation::Call)]
class EventWithSourceAttribute extends Event
{
    public function __construct(string $eventId = '')
    {
        parent::__construct($eventId ?: Uuid::uuid7()->toString());
    }

    public function __toString(): string
    {
        return 'EventWithSourceAttribute()';
    }
}

class EventWithReplyTo extends Event implements ReplyToInterface
{
    public function __construct(string $eventId, private string $replyToId)
    {
        parent::__construct($eventId ?: Uuid::uuid7()->toString());
    }

    public function getReplyTo(): StateId
    {
        // In a real test, we'd need to return an actual StateId
        // For our test purposes, we'll mock this behavior
        return StateId::fromString($this->replyToId);
    }

    public function __toString(): string
    {
        return 'EventWithReplyTo()';
    }
}

class EventWithTarget extends Event implements StateTargetInterface
{
    public function __construct(
        string $eventId,
        private string $targetId,
    ) {
        parent::__construct($eventId ?: Uuid::uuid7()->toString());
    }

    public function getTarget(): StateId
    {
        // In a real test, we'd need to return an actual StateId
        // For our test purposes, we'll mock this behavior
        return StateId::fromString($this->targetId);
    }

    public function __toString(): string
    {
        return 'EventWithTarget()';
    }
}

class MockExternalEvent extends Event implements External
{
    public string $externalData = 'external data';

    public function __construct(string $eventId = '')
    {
        parent::__construct($eventId ?: Uuid::uuid7()->toString());
    }

    public function __toString(): string
    {
        return 'MockExternalEvent()';
    }
}

class MockWrapperEvent extends Event implements HasInnerEventInterface
{
    public function __construct(string $eventId = '', public Event $innerEvent = new SimpleEvent())
    {
        parent::__construct($eventId ?: Uuid::uuid7()->toString());
    }

    public function getInnerEvent(): Event
    {
        return $this->innerEvent;
    }

    public function __toString(): string
    {
        return 'MockWrapperEvent(' . $this->innerEvent . ')';
    }
}

// Tests for EventDescription constructor and describe method
test('EventDescription constructor with simple event', function (): void {
    $event = new SimpleEvent('test-id');
    $description = new EventDescription($event);

    expect($description->eventId)->toBe('test-id')
        ->and($description->innerEvent)->toBe($event)
        ->and($description->locks)->toBeFalse()
        ->and($description->isPoisoned)->toBeFalse()
        ->and($description->replyTo)->toBeNull()
        ->and($description->scheduledAt)->toBeNull()
        ->and($description->destination)->toBeNull()
        ->and($description->targetType)->toBe(TargetType::None)
        ->and($description->sourceOperations)->toBeEmpty()
        ->and($description->targetOperations)->toBeEmpty();
});

test('EventDescription constructor with event that has target attribute', function (): void {
    $event = new EventWithTargetAttribute('test-id');
    $description = new EventDescription($event);

    expect($description->eventId)->toBe('test-id')
        ->and($description->innerEvent)->toBe($event)
        ->and($description->targetOperations)->toHaveCount(1)
        ->and($description->targetOperations[0])->toBe(Operation::Signal);
});

test('EventDescription constructor with event that has source attribute', function (): void {
    $event = new EventWithSourceAttribute('test-id');
    $description = new EventDescription($event);

    expect($description->eventId)->toBe('test-id');
    expect($description->innerEvent)->toBe($event);
    expect($description->sourceOperations)->toHaveCount(1);
    expect($description->sourceOperations[0])->toBe(Operation::Call);
});

test('EventDescription constructor with event that implements ReplyToInterface', function (): void {
    $event = AwaitResult::forEvent(StateId::fromString('orchestration:instance:reply-to-id'), new SimpleEvent('test-id'));
    $description = new EventDescription($event);

    expect($description->eventId)->toBe('test-id');
    expect($description->replyTo)->not()->toBeNull();
    expect((string) $description->replyTo)->toBe('orchestration:instance:reply-to-id');
});

test('EventDescription constructor with event that implements StateTargetInterface', function (): void {
    $event = WithOrchestration::forInstance(StateId::fromString('activity:target-id'), new SimpleEvent('test-id'));
    $description = new EventDescription($event);

    expect($description->eventId)->toBe('test-id');
    expect($description->destination)->not()->toBeNull();
    expect((string) $description->destination)->toBe('activity:target-id');
    expect($description->targetType)->toBe(TargetType::Activity);
});

test('EventDescription constructor with WithDelay event', function (): void {
    $innerEvent = StartOrchestration::forInstance(OrchestrationInstance('instance', 'inner-id'));
    $fireAt = new DateTimeImmutable('2023-01-01 12:00:00');
    $event = WithDelay::forEvent($fireAt, $innerEvent);
    $description = new EventDescription($event);

    expect($description->destination)->toBe(StateId::fromString('orchestration:instance:inner-id'));
    expect($description->scheduledAt)->toBe($fireAt);
    expect($description->innerEvent)->toBe($innerEvent->getInnerEvent());
});

test('EventDescription constructor with WithLock event', function (): void {
    $innerEvent = new SimpleEvent('inner-id');
    $owner = StateId::fromString('orchestration:instance:owner-id');
    $target = StateId::fromString('entity:target-id');
    $event = WithLock::onEntity($owner, $innerEvent, $target);
    $description = new EventDescription($event);

    expect($description->eventId)->toBe('inner-id');
    expect($description->locks)->toBeTrue();
    expect($description->innerEvent)->toBe($innerEvent);
    expect($description->targetOperations)->toHaveCount(1);
    expect($description->targetOperations[0])->toBe(Operation::Lock);
});

test('EventDescription constructor with PoisonPill event', function (): void {
    $event = PoisonPill::digest();
    $description = new EventDescription($event);

    expect($description->isPoisoned)->toBeTrue();
});

test('EventDescription constructor with External event', function (): void {
    $event = new MockExternalEvent('test-id');
    $description = new EventDescription($event);

    expect($description->eventId)->toBe('test-id');
    expect($description->meta)->toBeArray();
    expect($description->meta)->toHaveKey('externalData');
    expect($description->meta['externalData'])->toBe('external data');
});

test('EventDescription constructor with nested events', function (): void {
    $innerEvent = new EventWithTargetAttribute('inner-id');
    $wrapperEvent = new MockWrapperEvent('wrapper-id', $innerEvent);
    $description = new EventDescription($wrapperEvent);

    expect($description->eventId)->toBe('wrapper-id');
    expect($description->innerEvent)->toBe($innerEvent);
    expect($description->targetOperations)->toHaveCount(1);
    expect($description->targetOperations[0])->toBe(Operation::Signal);
});

// Tests for serialization/deserialization methods
test('toStream method', function (): void {
    $event = new SimpleEvent('test-id');
    $description = new EventDescription($event);

    $stream = $description->toStream();
    $stream = json_decode($stream, true);
    $result = EventDescription::fromStream($stream['event']);

    // hack around serialization of timestamps
    $description = new EventDescription($event->with(timestamp: $result->event->timestamp));

    expect($result)->toEqual($description);
});

test('toJson method', function (): void {
    $event = new SimpleEvent('test-id');
    $description = new EventDescription($event);

    $json = $description->toJson();
    $result = EventDescription::fromJson($json);
    // hack around serialization of timestamps
    $description = new EventDescription($event->with(timestamp: $result->event->timestamp));
    expect($result)->toEqual($description);
});

// Edge cases
test('EventDescription handles multiple attributes of the same type', function (): void {
    #[NeedsTarget(Operation::Signal)]
    #[NeedsTarget(Operation::Call)]
    class EventWithMultipleAttributes extends Event
    {
        public function __construct(string $eventId = '')
        {
            parent::__construct($eventId ?: Uuid::uuid7()->toString());
        }

        public function __toString(): string
        {
            return 'EventWithMultipleAttributes()';
        }
    }

    $event = new EventWithMultipleAttributes('test-id');
    $description = new EventDescription($event);

    expect($description->targetOperations)->toHaveCount(2);
    expect($description->targetOperations)->toContain(Operation::Signal);
    expect($description->targetOperations)->toContain(Operation::Call);
});

test('EventDescription handles different target types', function (): void {
    $testCases = [
        ['isActivity' => true, 'expected' => TargetType::Activity],
        ['isOrchestration' => true, 'expected' => TargetType::Orchestration],
        ['isEntity' => true, 'expected' => TargetType::Entity],
    ];

    foreach ($testCases as $case) {
        $targetId = StateId::fromString(match ($case) {
            [...$case, 'isActivity' => true] => 'activity:id',
            [...$case, 'isOrchestration' => true] => 'orchestration:instance:id',
            [...$case, 'isEntity' => true] => 'entity:id',
        });
        $event = new WithOrchestration('test-id', $targetId, TaskFailed::forTask('123', 'test'));
        $description = new EventDescription($event);

        expect($description->targetType)->toBe($case['expected']);
    }
});
