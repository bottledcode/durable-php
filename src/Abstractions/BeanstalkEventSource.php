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

namespace Bottledcode\DurablePhp\Abstractions;

use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use Bottledcode\DurablePhp\Events\WithDelay;
use Bottledcode\DurablePhp\Events\WithPriority;
use Bottledcode\DurablePhp\State\Serializer;
use Carbon\CarbonImmutable;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\TubeName;

class BeanstalkEventSource implements EventQueueInterface, EventHandlerInterface
{
    private Pheanstalk $beanstalkClient;

    public function __construct(private readonly string $host = 'localhost', private readonly string $port = '11300', private readonly string $namespace = 'dphp')
    {
        $this->reconnect();
    }

    public function reconnect(): void
    {
        $this->beanstalkClient = Pheanstalk::create($this->host, $this->port);
    }

    public function subscribe(QueueType $type): void
    {
        $this->beanstalkClient->watch($this->getTubeFor($type));
    }

    private function getTubeFor(QueueType $type): TubeName
    {
        static $tubes = [];

        return $tubes[$type->value] ??= new TubeName("{$this->namespace}_{$type->value}");
    }

    public function getSingleEvent(int $poll): Job|null
    {
        return $this->beanstalkClient->reserveWithTimeout($poll);
    }

    public function fire(Event $event): void
    {
        $priority = $event instanceof WithPriority ? $event->priority : 100;
        $this->beanstalkClient->useTube($this->getQueueForEvent($event));
        $this->beanstalkClient->put(json_encode(Serializer::serialize($event)), $priority, $this->getDelayForEvent($event), timeToRelease: 120);
    }

    private function getQueueForEvent(Event $event): TubeName
    {
        static $tubes = null;

        $tubes ??= [
            'entity' => new TubeName("{$this->namespace}_entities"),
            'activity' => new TubeName("{$this->namespace}_activities"),
            'orchestration' => new TubeName("{$this->namespace}_orchestrations"),
        ];

        while ($event instanceof HasInnerEventInterface) {
            if ($event instanceof StateTargetInterface) {
                $state = $event->getTarget();
                return $tubes[explode(':', $state->id)[0]];
            }

            $event = $event->getInnerEvent();
        }

        return $tubes['activity'];
    }

    private function getDelayForEvent(Event $event): int
    {
        while ($event instanceof HasInnerEventInterface) {
            if ($event instanceof WithDelay) {
                $now = new CarbonImmutable();
                return max(0, (new CarbonImmutable($event->fireAt))->diffInSeconds($now));
            }
            if ($event instanceof ScheduleTask) {
                $now = new CarbonImmutable();
                return max(0, (new CarbonImmutable($event->timestamp))->diffInSeconds($now));
            }
            $event = $event->getInnerEvent();
        }

        return 0;
    }

    public function ack(JobIdInterface $job): void
    {
        $this->beanstalkClient->delete($job);
    }

    public function deadLetter(JobIdInterface $job): void
    {
        $this->beanstalkClient->bury($job);
    }
}
