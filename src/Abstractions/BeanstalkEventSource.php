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
use Bottledcode\DurablePhp\State\Serializer;
use Carbon\CarbonImmutable;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\TubeName;

class BeanstalkEventSource implements EventQueueInterface, EventHandlerInterface
{
    private Pheanstalk $beanstalkClient;

    public function __construct(private string $host, private string $port, private string $namespace)
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

    public function getSingleEvent(): Job|null
    {
        return $this->beanstalkClient->reserveWithTimeout(0);
    }

    public function fire(Event $event): void
    {
        $this->beanstalkClient->useTube($this->getQueueForEvent($event));
        $this->beanstalkClient->put(json_encode(Serializer::serialize($event)), 100, $this->getDelayForEvent($event));
    }

    private function getQueueForEvent(Event $event): TubeName
    {
        $tubes = [
            'entity' => new TubeName('entities'),
            'activity' => new TubeName('activities'),
            'orchestration' => new TubeName('orchestrations'),
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
        }

        return 0;
    }

    public function ack(JobIdInterface $job): void
    {
        $this->beanstalkClient->delete($job);
    }
}
