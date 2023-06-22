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

namespace Bottledcode\DurablePhp;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use Bottledcode\DurablePhp\State\ApplyStateInterface;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Transmutation\Router;
use Ramsey\Uuid\Uuid;

class EventDispatcherTask implements \Amp\Parallel\Worker\Task
{
    use Router;

    public Source $source;

    private array $batch = [];

    public function __construct(
        private readonly Config $config,
        private Event $event,
        private readonly MonotonicClock $clock
    ) {
    }

    public function runOnce(): void
    {
        $this->source = SourceFactory::fromConfig($this->config);
        $this->source->workerStartup();
        $originalEvent = $this->event;

        $states = [];
        while ($this->event instanceof HasInnerEventInterface) {
            if ($this->event instanceof StateTargetInterface) {
                $states[] = $this->getState($this->event->getTarget());
            }

            $this->event = $this->event->getInnerEvent();
        }

        foreach ($states as $state) {
            if ($state->hasAppliedEvent($this->event)) {
                $this->source->ack($this->event);
                return;
            }

            foreach ($this->transmutate($this->event, $state, $originalEvent) as $eventOrCallable) {
                if ($eventOrCallable instanceof Event) {
                    if ($eventOrCallable instanceof PoisonPill) {
                        break;
                    }
                    $this->fire($eventOrCallable);
                } elseif ($eventOrCallable instanceof \Closure) {
                    $eventOrCallable($this, $this->source, $this->clock);
                }
            }

            $this->updateState($state);
        }

        foreach ($this->batch as $f) {
            $this->source->storeEvent($f);
        }

        $this->source->ack($originalEvent);
        foreach ($states as $state) {
            $state->ackedEvent($originalEvent);
        }
        //Logger::log('EventDispatcherTask acked: %s', $originalEvent);

        foreach ($states as $state) {
            //$state->onComplete($this->source);
        }

        $this->source->close();
    }

    public function getState(StateId $instance): ApplyStateInterface&StateInterface
    {
        $rawState = $this->source->get($instance, $instance->getStateType());
        if (empty($rawState)) {
            $type = $instance->getStateType();
            $rawState = new $type($instance, $this->config);
        } else {
            $rawState->setConfig($this->config);
        }

        return $rawState;
    }

    public function fire(Event ...$events): array
    {
        $ids = [];
        foreach ($events as $event) {
            //Logger::log('EventDispatcherTask fired: %s', $event);
            $ids[] = $event->eventId = empty($event->eventId) ? Uuid::uuid7()->toString() : $event->eventId;
            $this->batch[] = $event;
        }

        return $ids;
    }

    public function updateState(StateInterface $state): void
    {
        $state->resetState();
        $this->source->put(StateId::fromState($state), $state);
    }

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        //$mem = memory_get_usage(true) / 1024 / 1024;
        //if ($mem > $this->config->maximumMemoryPerWorker) {
        //    die();
        //}

        $returnEvent = $this->event;
        $this->source = SourceFactory::fromConfig($this->config);
        $this->source->workerStartup();
        $originalEvent = $this->event;

        //Logger::log("EventDispatcher received event: %s", $this->event);

        /**
         * @var StateInterface&ApplyStateInterface[] $states
         */
        $states = [];
        while ($this->event instanceof HasInnerEventInterface) {
            if ($this->event instanceof StateTargetInterface) {
                $states[] = $this->getState($this->event->getTarget());
            }

            $this->event = $this->event->getInnerEvent();
        }

        foreach ($states as $state) {
            if ($state->hasAppliedEvent($this->event)) {
                $this->source->ack($this->event);
                return $originalEvent;
            }

            foreach ($this->transmutate($this->event, $state, $originalEvent) as $eventOrCallable) {
                if ($eventOrCallable instanceof Event) {
                    if ($eventOrCallable instanceof PoisonPill) {
                        break;
                    }
                    $this->fire($eventOrCallable);
                    Logger::log('EventDispatcherTask fired: %s', $eventOrCallable);
                } elseif ($eventOrCallable instanceof \Closure) {
                    $eventOrCallable($this, $this->source, $this->clock);
                }
            }

            $this->updateState($state);
        }

        foreach ($this->batch as $f) {
            $this->source->storeEvent($f, false);
        }

        $this->source->ack($originalEvent);
        foreach ($states as $state) {
            $state->ackedEvent($originalEvent);
            $state->onComplete($this->source);
        }

        try {
            $this->source->close();
        } catch (\Throwable) {
        }

        Logger::log('Source closed');
/*
        try {
            if ($this->hasExtendedState($states)) {
                $timeout = new TimeoutCancellation($this->config->workerTimeoutSeconds);
                $this->event = $channel->receive($timeout);
                unset($timeout);
                $originalEvent = $this->event;
                while ($this->event instanceof HasInnerEventInterface) {
                    $this->event = $this->event->getInnerEvent();
                }
                Logger::log('EventDispatcherTask received[channel]: %s', $originalEvent);
                goto gotNewEvent;
            }
        } catch (CancelledException) {
            Logger::error('EventDispatcherTask is cancelled');
        } catch (\Throwable $e) {
            Logger::error('EventDispatcherTask failed: %s', $e::class);
        }

        foreach ($states as $state) {
            $state->onComplete($this->source);
        }
*/
        return $returnEvent;
    }

    private function hasExtendedState(array $states): bool
    {
        return ($states[0] ?? null) instanceof OrchestrationHistory || ($states[0] ?? null) instanceof EntityHistory;
    }
}
