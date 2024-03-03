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
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Transmutation\Router;
use Mockery\Container;
use Monolog\Level;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// we are in the context of a project
if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}

class Task
{
    use Router;

    private string $stream;

    public function __construct(private DurableLogger $logger) {}

    public function run(): void
    {
        $route = array_values(array_filter(explode('/', $_SERVER['REQUEST_URI'])));
        sleep(10);

        switch ($route[0] ?? null) {
            case 'event':
                $subject = $route[1] ?? null;
                if ($subject === null) {
                    $this->emitError(404, 'subject must be set');
                }
                [$stream, $type, $_] = explode('.', $subject);
                $this->stream = $stream;
                try {
                    $event = EventDescription::fromJson(base64_decode(file_get_contents('php://input')));
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

                        // initialize the container
                        $bootstrapFile = getenv('BOOTSTRAP_FILE');
                        if(file_exists($bootstrapFile)) {
                            $container = include $bootstrapFile;
                            $state->setContainer($container);
                        } else {
                            $container = new class () implements ContainerInterface {
                                private array $things = [];

                                #[\Override] public function get(string $id)
                                {
                                    if(empty($this->things[$id])) {
                                        return $this->things[$id] = new $id();
                                    }

                                    return $this->things[$id];
                                }

                                #[\Override] public function has(string $id): bool
                                {
                                    return isset($this->things[$id]);
                                }

                                public function set(string $id, $value): void
                                {
                                    $this->things[$id] = $value;
                                }
                            };
                            $state->setContainer($container);
                        }


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
                        try {
                            $this->commit($state, $stream);
                        } catch(\JsonException $e) {
                            $this->emitError(500, 'json encoding error when committing state', ['exception' => $e]);
                        }

                        exit();
                    case 'orchestrations':
                        $this->emitError(500, "ERROR: orchestrations not implemented yet\n");
                        // no break
                    case 'entities':
                        $this->emitError(500, "ERROR: entities not implemented yet\n");
                        // no break
                    default:
                        $this->emitError(404, "ERROR: unknown route type \"{$type}\"\n");
                }
            case 'activity':
            case 'entity':
            case 'orchestration':
                break;
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

    /**
     * @throws \JsonException
     */
    private function loadState(): AbstractHistory|null
    {
        $file = $_SERVER['HTTP_STATE_FILE'] ?? throw new \JsonException('Missing state file');
        $state = stream_get_contents($file = fopen($file, 'rb'));
        fclose($file);
        if(empty($state)) {
            return null;
        }
        $state = json_decode(base64_decode($state), true, 512, JSON_THROW_ON_ERROR);

        return Serializer::deserialize($state, StateInterface::class);
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
                $this->stream . "." . match($description->targetType) {
                    TargetType::Activity => 'activities',
                    TargetType::Entity => 'entities',
                    TargetType::Orchestration => 'orchestrations',
                    default => $this->emitError(500, 'unknown event type'),
                } . "." . $this->sanitizeId($description->destination),
                $description->scheduledAt?->format(DATE_ATOM) ?? '',
                base64_encode($description->toJson()),
            ];
            echo implode("~!~", $serialized);
            $ids[] = $parent->eventId;
        }

        return $ids;
    }

    private function sanitizeId(StateId $destination): string
    {
        return trim(base64_encode($destination->id), "=");
    }

    private function commit(AbstractHistory $state, string $stream): void
    {
        $serialized = Serializer::serialize($state);
        $serialized = base64_encode(json_encode($serialized, JSON_THROW_ON_ERROR));
        file_put_contents($_SERVER["HTTP_STATE_FILE"], $serialized);
    }
}

$logger = new DurableLogger(level: match (getenv('LOG_LEVEL') ?: 'INFO') {
    'DEBUG' => Level::Debug,
    'INFO' => Level::Info,
    'ERROR' => Level::Error,
});

error_reporting(E_ALL);
ini_set('display_errors', true);
ini_set('output_buffering', 0);

(new Task($logger))->run();
