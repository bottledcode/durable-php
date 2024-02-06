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

    private static array $globals = [];
    private ContainerInterface $container;
    private ProjectorInterface $projector;
    private Semaphore $semaphore;

    private DurableLogger $logger;

    private array $batch = [];

    public function __construct(private string $bootstrap, private Event $event, private array $providers, private string $semaphoreProvider) {}

    public function run(Channel $channel, Cancellation $cancellation): array
    {
        gc_enable();
        gc_collect_cycles();
        $this->logger = new DurableLogger();
        $this->logger->info("Running worker", ['event' => $this->event]);
        if(empty(self::$globals)) {
            $this->configureProviders($this->providers, $this->semaphoreProvider);
            self::$globals = [$this->projector, $this->semaphore];
        } else {
            [$this->projector, $this->semaphore] = self::$globals;
        }
        $this->container = include $this->bootstrap;

        $states = [];
        $originalEvent = $this->event;
        while ($this->event instanceof HasInnerEventInterface) {
            if ($this->event instanceof StateTargetInterface) {
                $states[] = $this->getState($this->event->getTarget());
            }

            $this->event = $this->event->getInnerEvent();
        }

        foreach ($states as $state) {
            if ($state->hasAppliedEvent($this->event)) {
                $this->logger->warning("Already applied", ['event' => $this->event]);
                goto finalize;
            }

            $this->logger->debug("Transmutating with event", ['event' => $this->event]);

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
                $this->logger->critical(
                    "Failed to process",
                    ['event' => $originalEvent, 'exception' => $exception]
                );
                throw $exception;
            }

            finalize:

            $this->logger->debug('Finalizing state');

            $state->resetState();
            $this->updateState($state);

            $state->ackedEvent($originalEvent);
        }

        $this->semaphore->signalAll();

        //$this->semaphore->close();
        //$this->projector->close();

        return $this->batch;
    }

    public function getState(string $target): ApplyStateInterface&StateInterface
    {
        $this->logger->debug("Taking lock", ['target' => $target]);
        $result = $this->semaphore->wait($target, true);
        if (!$result) {
            throw new \LogicException('unable to get lock on state, manual intervention may be required');
        }

        $id = new StateId($target);

        $currentState = $this->projector->getState($id);
        $currentState ??= new ($id->getStateType())($id, $this->logger);
        $currentState->logger = $this->logger;
        $currentState->setContainer($this->container);
        return $currentState;
    }

    public function fire(Event ...$events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $this->logger->debug("Batching", ['event' => $event]);
            $parent = $event;
            if (empty($event->eventId)) {
                $id = Uuid::uuid7();
                while ($event instanceof HasInnerEventInterface) {
                    $event->eventId = $id;
                    $event = $event->getInnerEvent();
                }
            }
            $this->batch[] = $parent;
            $ids[] = $parent->eventId;
        }

        return $ids;
    }

    private function updateState(ApplyStateInterface&StateInterface $state): void
    {
        $this->logger->debug('projecting state');
        $this->projector->projectState($id = StateId::fromState($state), $state);
        $this->semaphore->signal($id->id);
    }
}
