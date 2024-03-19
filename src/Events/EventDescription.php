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

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\Events\Shares\NeedsSource;
use Bottledcode\DurablePhp\Events\Shares\NeedsTarget;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\Serializer;
use DateTimeImmutable;

readonly class EventDescription
{
    public ?StateId $replyTo;

    public ?DateTimeImmutable $scheduledAt;

    public ?StateId $destination;

    public string $eventId;

    public int $priority;

    public bool $locks;

    public bool $isPoisoned;

    public TargetType $targetType;

    public Event $innerEvent;

    /**
     * @var array<Operation>
     */
    public array $sourceOperations;

    /**
     * @var array<Operation>
     */
    public array $targetOperations;

    public function __construct(public Event $event)
    {
        $this->describe($event);
    }

    private function describe(Event $event): void
    {
        $this->eventId = $event->eventId;

        $targetOps = [];
        $sourceOps = [];
        do {
            if ($event instanceof ReplyToInterface) {
                $this->replyTo = $event->getReplyTo();
            }
            if ($event instanceof WithDelay) {
                $this->scheduledAt = $event->fireAt;
            }
            if ($event instanceof StateTargetInterface) {
                $this->destination = $event->getTarget();
            }
            if ($event instanceof WithLock) {
                $this->locks = true;
            }
            if ($event instanceof PoisonPill) {
                $this->isPoisoned = true;
            }

            $reflection = new \ReflectionClass($event);
            foreach($reflection->getAttributes(NeedsTarget::class) as $target) {
                /** @var NeedsTarget $attr */
                $attr = $target->newInstance();
                $targetOps[] = $attr->operation;
            }

            foreach($reflection->getAttributes(NeedsSource::class) as $target) {
                /** @var NeedsTarget $attr */
                $attr = $target->newInstance();
                $sourceOps[] = $attr->operation;
            }

            if ($event instanceof HasInnerEventInterface) {
                $event = $event->getInnerEvent();
            }

        } while  ($event instanceof HasInnerEventInterface);

        $this->innerEvent = $event;
        $this->targetOperations = array_values(array_unique($targetOps));
        $this->sourceOperations = array_values(array_unique($sourceOps));

        $this->locks ??= false;
        $this->isPoisoned ??= false;
        $this->replyTo ??= null;
        $this->scheduledAt ??= null;
        $this->destination ??= null;

        $this->targetType = match (true) {
            $this->destination->isActivityId() => TargetType::Activity,
            $this->destination->isOrchestrationId() => TargetType::Orchestration,
            $this->destination->isEntityId() => TargetType::Entity,
            default => TargetType::None,
        };
    }

    public static function fromStream(string $data): self
    {
        $data = base64_decode($data);
        $data = function_exists('gzdecode') ? gzdecode($data) : $data;
        $data = function_exists('igbinary_unserialize') ? igbinary_unserialize($data) : unserialize($data);

        return new self($data);
    }

    /**
     * @throws \JsonException
     */
    public static function fromJson(string $json): EventDescription
    {
        return new EventDescription(Serializer::deserialize(json_decode($json, true, 512, JSON_THROW_ON_ERROR), Event::class));
    }

    public function toStream(): string
    {
        $serialized = function_exists('igbinary_serialize') ? igbinary_serialize($this->event) : serialize($this->event);
        $serialized = function_exists('gzencode') ? gzencode($serialized) : $serialized;

        $event = base64_encode($serialized);

        return json_encode([
            'destination' => $this->destination->id,
            'replyTo' => $this->replyTo?->id ?? '',
            'scheduleAt' => $this->scheduledAt?->format(DATE_ATOM) ?? gmdate(DATE_ATOM, time() - 30),
            'eventId' => $this->eventId,
            'eventType' => $this->innerEvent->eventType(),
            'targetType' => $this->targetType->name,
            'sourceOps' => implode(',', $this->sourceOperations),
            'targetOps' => implode(',', $this->targetOperations),
            'event' => $event,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode(Serializer::serialize($this->event), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
