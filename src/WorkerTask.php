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

use Ahc\Cli\Output\Writer;
use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Bottledcode\DurablePhp\Abstractions\ProjectorInterface;
use Bottledcode\DurablePhp\Abstractions\Semaphore;
use Bottledcode\DurablePhp\Config\ProviderTrait;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use Bottledcode\DurablePhp\State\ApplyStateInterface;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Transmutation\Router;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

class WorkerTask implements Task
{
    use ProviderTrait;
    use Router;

    private ContainerInterface $container;

    private ProjectorInterface $projector;

    private Semaphore $semaphore;

    private Writer $writer;

    private array $batch = [];

    public function __construct(private string $bootstrap, private Event $event, private array $providers)
    {
    }

    public function run(Channel $channel, Cancellation $cancellation): array
    {
        $this->writer = new Writer();
        $this->configureProviders($this->providers);
        $this->container = include $this->bootstrap;

        $states = [];
        $originalEvent = $this->event;
        while ($this->event instanceof HasInnerEventInterface) {
            if ($this->event instanceof StateTargetInterface) {
                $states[] = $this->getState($this->event->getTarget(), $channel);
            }

            $this->event = $this->event->getInnerEvent();
        }

        foreach ($states as $state) {
            if ($state->hasAppliedEvent($this->event)) {
                $this->writer->warn("Already applied $this->event");
                goto finalize;
            }

            try {
                foreach ($this->transmutate($this->event, $state, $originalEvent) as $eventOrCallable) {
                    if ($eventOrCallable instanceof Event) {
                        if ($eventOrCallable instanceof PoisonPill) {
                            break;
                        }
                        $this->fire($eventOrCallable);
                    } elseif ($eventOrCallable instanceof \Closure) {
                        $eventOrCallable($this, $this->projector);
                    }
                }
            } catch (\Throwable $exception) {
                echo "Failed to process $originalEvent: {$exception->getMessage()}\n{$exception->getTraceAsString()}";
                throw $exception;
            }

            finalize:

            $state->resetState();
            $this->updateState($state);

            $state->ackedEvent($originalEvent);
        }

        $this->semaphore->signalAll();

        return $this->batch;
    }

    public function getState(string $target): ApplyStateInterface&StateInterface
    {
        $this->writer->comment("Taking lock for $target", true);
        $result = $this->semaphore->wait($target, true);
        if (!$result) {
            throw new \LogicException('unable to get lock on state, manual intervention may be required');
        }

        $id = new StateId($target);

        $currentState = $this->projector->getState($id);
        $currentState ??= new ($id->getStateType())($id);
        $currentState->setContainer($this->container);
        return $currentState;
    }

    public function fire(Event $event): void
    {
        $this->writer->comment("Batching: $event", true);
        if (empty($event->eventId)) {
            $event->eventId = Uuid::uuid7();
        }
        $this->batch[] = $event;
    }

    private function updateState(ApplyStateInterface&StateInterface $state): void
    {
        $this->writer->comment('projecting state', true);
        $this->projector->projectState($id = StateId::fromState($state), $state);
        $this->semaphore->signal($id->id);
    }
}
