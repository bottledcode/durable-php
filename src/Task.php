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

// we are in the context of this project (testing/developing)
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Glue\Glue;
use Bottledcode\DurablePhp\State\AbstractHistory;
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Transmutation\Router;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/Glue/autoload.php';

class Task
{
    use Router;

    private ContainerInterface $container;

    public function __construct(private readonly DurableLogger $logger, private readonly Glue $glue) {}

    public function run(): void
    {
        $stateId = $this->glue->target;

        $event = EventDescription::fromStream($_SERVER['EVENT']);
        $this->logger->debug("Invoking event $event->innerEvent");

        $state = $this->loadState();

        switch ($stateId->getStateType()) {
            case ActivityHistory::class:
                if (! $stateId->isActivityId()) {
                    $this->emitError(400, 'Invalid activity id');
                }
                $state ??= new ActivityHistory($stateId, $this->logger);
                break;
            case OrchestrationHistory::class:
                if (! $stateId->isOrchestrationId()) {
                    $this->emitError(400, 'Invalid orchestration id');
                }
                $state ??= new OrchestrationHistory($stateId, $this->logger);
                break;
            case EntityHistory::class:
                if (! $stateId->isEntityId()) {
                    $this->emitError(400, 'Invalid entity id');
                }
                $state ??= new EntityHistory($stateId, $this->logger);
                break;
            default:
                $this->emitError(404, "ERROR: unknown route type \"{$stateId->getStateType()}\"\n");
        }

        // initialize the container
        if (file_exists($this->glue->bootstrap)) {
            $this->container = include $this->glue->bootstrap;
        } else {
            $this->container = new class () implements ContainerInterface {
                private array $things = [];

                #[\Override]
                public function get(string $id)
                {
                    if (empty($this->things[$id])) {
                        return $this->things[$id] = new $id();
                    }

                    return $this->things[$id];
                }

                #[\Override]
                public function has(string $id): bool
                {
                    return isset($this->things[$id]);
                }

                public function set(string $id, $value): void
                {
                    $this->things[$id] = $value;
                }
            };
        }
        $state->setContainer($this->container);

        if ($state->hasAppliedEvent($event->event)) {
            $this->emitError(200, 'event already applied', ['event' => $event]);
        }

        try {
            foreach ($this->transmutate($event->innerEvent, $state, $event->event) as $eventOrCallable) {
                if ($eventOrCallable instanceof Event) {
                    if ($eventOrCallable instanceof PoisonPill) {
                        break;
                    }
                    $this->fire($eventOrCallable);
                } elseif ($eventOrCallable instanceof \Closure) {
                    $eventOrCallable($this);
                }
            }
        } catch (\Throwable $exception) {
            $this->emitError(
                500,
                'Failed to process',
                ['event' => $event, 'exception' => $exception]
            );
        }

        $state->resetState();
        $state->ackedEvent($event->event);
        try {
            $this->commit($state);
        } catch (\JsonException $e) {
            $this->emitError(500, 'json encoding error when committing state', ['exception' => $e]);
        }

        exit();
    }

    private function loadState(): ?AbstractHistory
    {
        if (! empty($this->payload)) {
            return Serializer::deserialize($this->payload, StateInterface::class);
        }

        return null;
    }

    private function emitError(int $code, string $message, array $context = []): never
    {
        http_response_code($code);
        $logger = match (true) {
            $code >= 500 => $this->logger->critical(...),
            $code >= 400 => $this->logger->warning(...),
            default => $this->logger->info(...),
        };
        $logger($message, $context);
        exit();
    }

    public function fire(Event ...$events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $this->glue->outputEvent(new EventDescription($event));
            $ids[] = $event->eventId;
        }

        return $ids;
    }

    private function commit(AbstractHistory $state): void
    {
        ftruncate($this->glue->payloadHandle, 0);
        fwrite($this->glue->payloadHandle, json_encode(Serializer::serialize($state), JSON_THROW_ON_ERROR));
    }

    public function getState(string $target): StateInterface
    {
        $id = StateId::fromString($target);

        $currentState = $this->glue->queryState($id);
        $currentState ??= new ($id->getStateType())($id, $this->logger);
        $currentState->logger = $this->logger;
        $currentState->setContainer($this->container);

        return $currentState;
    }
}
