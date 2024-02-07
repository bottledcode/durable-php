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

namespace Bottledcode\DurablePhp;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Amp\TimeoutCancellation;
use Bottledcode\DurablePhp\Abstractions\BeanstalkEventSource;
use Bottledcode\DurablePhp\Abstractions\EventHandlerInterface;
use Bottledcode\DurablePhp\Abstractions\EventQueueInterface;
use Bottledcode\DurablePhp\Abstractions\QueueType;
use Pheanstalk\Values\JobId;
use Psr\Log\LoggerInterface;

class QueueTask implements Task
{
    private EventQueueInterface&EventHandlerInterface $queue;

    private LoggerInterface $logger;

    public function __construct(private string $host, private int $port, private string $namespace, private string $monitor, private int $maxEventsInFlight) {}

    #[\Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $this->logger = new DurableLogger(name: 'Q');

        $this->logger->debug('Connecting...', [$this->host, $this->port, $this->namespace, $this->monitor]);

        $this->queue = new BeanstalkEventSource($this->host, $this->port, $this->namespace);
        $monitor = $this->monitor;
        if (str_contains($monitor, 'activities')) {
            $this->logger->info('Subscribing to activity feed...');
            $this->queue->subscribe(QueueType::Activities);
        }

        if (str_contains($monitor, 'entities')) {
            $this->logger->info('Subscribing to entities feed...');
            $this->queue->subscribe(QueueType::Entities);
        }

        if (str_contains($monitor, 'orchestrations')) {
            $this->logger->info('Subscribing to orchestration feed...');
            $this->queue->subscribe(QueueType::Orchestrations);
        }

        $this->logger->debug('Starting event subscription');
        $eventsInFlight = 0;
        while (true) {
            if ($eventsInFlight > $this->maxEventsInFlight) {
                $timeout = 3;
            } else {
                $timeout = 0;
            }

            try {
                if ($eventsInFlight < $this->maxEventsInFlight) {
                    // when full, be fast, when empty, be slow
                    $event = $this->queue->getSingleEvent((int) ((1 - ($eventsInFlight / $this->maxEventsInFlight)) * 10));
                }

                if (! empty($event)) {
                    $this->logger->debug('Got event', ['event' => $event->getId()]);
                    $channel->send($event);
                    $eventsInFlight += 1;
                }

                try {
                    while (true) {
                        $delete = $channel->receive(new TimeoutCancellation($timeout, 'ack timeout'));
                        $this->queue->ack(new JobId($delete));
                        $eventsInFlight -= 1;
                    }
                } catch (\Throwable) {
                    $this->logger->debug('Timed out getting events to ack');
                }

                if (empty($event)) {
                    continue;
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Error occurred while getting event', ['exception' => $exception]);
                exit(1);
            }
        }
    }
}
