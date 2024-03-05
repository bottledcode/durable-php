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
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\Events\TargetType;
use Bottledcode\DurablePhp\State\AbstractHistory;
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Transmutation\Router;
use JsonException;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/Glue/autoload.php';

class Task
{
    use Router;

    private string $stream;

    private $bodyStream;

    public function __construct(private DurableLogger $logger) {}

    /**
     * Query for state
     *
     * @throws JsonException
     */
    public function getState(StateId $id): StateInterface
    {
        echo implode('~!~', ['QUERY', match (true) {
            $id->isEntityId() => 'entities',
            $id->isOrchestrationId() => 'orchestrations',
            $id->isActivityId() => 'activities',
        }, $id->id]);
        $stateFile = $this->readData();
        $state = file_get_contents($stateFile);
        unlink($stateFile);
        $state = base64_decode($state);
        $state = json_decode($state, true, 512, JSON_THROW_ON_ERROR);

        return Serializer::deserialize($state, StateInterface::class);
    }

    private function readData(): string
    {
        $data = '';
        while (! str_ends_with($data, "\n\n")) {
            $data .= fread($this->bodyStream, 8096);
        }

        return $data;
    }

    public function run(): void
    {
        $route = array_values(array_filter(explode('/', $_SERVER['REQUEST_URI'])));

        switch ($route[0] ?? null) {
            case 'event':
                $subject = $route[1] ?? null;
                if ($subject === null) {
                    $this->emitError(404, 'subject must be set');
                }
                [$stream, $type, $_] = explode('.', $subject);
                $this->stream = $stream;
                try {
                    $this->bodyStream = fopen('php://input', 'rb');
                    $event = $this->readEvent();
                } catch (\JsonException $e) {
                    $this->emitError(400, 'json decoding error', ['exception' => $e]);
                }

                switch ($type) {
                    case 'activities':
                        $activity = $event->destination;
                        if (! $activity->isActivityId()) {
                            $this->emitError(400, 'Invalid activity id');
                        }

                        try {
                            /**
                             * @var AbstractHistory $state
                             * @var int $expectedVersion
                             */
                            $state = $this->loadState();
                        } catch (\JsonException $e) {
                            $this->emitError(400, 'json decoding error', ['exception' => $e]);
                        }

                        if ($state === null) {
                            $state = new ActivityHistory($activity, $this->logger);
                        }
                        break;
                    case 'orchestrations':
                        $orchestration = $event->destination;
                        if (! $orchestration->isOrchestrationId()) {
                            $this->emitError(400, 'Invalid orchestration id');
                        }

                        try {
                            $state = $this->loadState();
                        } catch (\JsonException $e) {
                            $this->emitError(400, 'json decoding error', ['exception' => $e]);
                        }

                        if ($state === null) {
                            $state = new OrchestrationHistory($orchestration, $this->logger);
                        }

                        break;
                    case 'entities':
                        $entity = $event->destination;
                        if (! $entity->isEntityId()) {
                            $this->emitError(400, 'Invalid entity id');
                        }

                        try {
                            $state = $this->loadState();
                        } catch (JsonException $e) {
                            $this->emitError(400, 'json decoding error', ['exception' => $e]);
                        }

                        if ($state === null) {
                            $state = new EntityHistory($entity, $this->logger);
                        }
                        break;
                    default:
                        $this->emitError(404, "ERROR: unknown route type \"{$type}\"\n");
                }

                // initialize the container
                $bootstrapFile = getenv('BOOTSTRAP_FILE');
                if (file_exists($bootstrapFile)) {
                    $container = include $bootstrapFile;
                } else {
                    $container = new class () implements ContainerInterface {
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
                $state->setContainer($container);

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
                    $this->commit($state, $stream);
                } catch (\JsonException $e) {
                    $this->emitError(500, 'json encoding error when committing state', ['exception' => $e]);
                }

                exit();
        }

        http_response_code(404);
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

    private function readEvent(): EventDescription
    {
        return EventDescription::fromStream($this->readData());
    }

    /**
     * @throws \JsonException
     */
    private function loadState(): ?AbstractHistory
    {
        $file = $_SERVER['HTTP_STATE_FILE'] ?? throw new \JsonException('Missing state file');
        $state = stream_get_contents($file = fopen($file, 'rb'));
        fclose($file);
        if (empty($state)) {
            return null;
        }
        $state = json_decode(base64_decode($state), true, 512, JSON_THROW_ON_ERROR);

        $state = Serializer::deserialize($state, StateInterface::class);
        $state->setLogger($this->logger);

        return $state;
    }

    public function fire(Event ...$events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $this->logger->info('Batching', ['event' => $event]);
            $parent = $event;
            if (empty($event->eventId)) {
                $id = Uuid::uuid7();
                while ($event instanceof HasInnerEventInterface) {
                    $event->eventId = $id;
                    $event = $event->getInnerEvent();
                }
            }
            $description = new EventDescription($event);
            $serialized = [
                'EVENT',
                $this->stream . '.' . match ($description->targetType) {
                    TargetType::Activity => 'activities',
                    TargetType::Entity => 'entities',
                    TargetType::Orchestration => 'orchestrations',
                    default => $this->emitError(500, 'unknown event type'),
                } . '.' . $this->sanitizeId($description->destination),
                $description->scheduledAt?->format(DATE_ATOM) ?? '',
                $description->toStream(),
            ];
            echo implode('~!~', $serialized);
            $ids[] = $parent->eventId;
        }

        return $ids;
    }

    private function sanitizeId(StateId $destination): string
    {
        $id = $destination->id;
        $id = explode(':', $id);
        $id = [$id[1], $id[2] ?? ''];
        $id = str_replace(['-', '\\', '.'], '_', $id);
        return rtrim(implode('.', $id), '.');
    }

    private function commit(AbstractHistory $state, string $stream): void
    {
        $serialized = Serializer::serialize($state);
        $serialized = base64_encode(json_encode($serialized, JSON_THROW_ON_ERROR));
        file_put_contents($_SERVER['HTTP_STATE_FILE'], $serialized);
    }
}

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('output_buffering', 0);

(new Task($logger))->run();
