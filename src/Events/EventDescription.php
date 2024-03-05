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

    public function __construct(public Event $event)
    {
        $this->describe($event);
    }

    private function describe(Event $event): void
    {
        $this->eventId = $event->eventId;

        while ($event instanceof HasInnerEventInterface) {
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

            $event = $event->getInnerEvent();
        }

        $this->innerEvent = $event;

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
        return self::fromJson(gzuncompress(base64_decode($data)));
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
        return base64_encode(gzcompress($this->toJson()));
    }

    /**
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode(Serializer::serialize($this->event), JSON_THROW_ON_ERROR);
    }
}
