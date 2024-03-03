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
use Basis\Nats\AmpClient;
use Basis\Nats\Configuration;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\PoisonPill;
use Bottledcode\DurablePhp\State\AbstractHistory;
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Transmutation\Router;
use Monolog\Level;
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

    private array $batch = [];

    public function __construct(private DurableLogger $logger, private AmpClient $nats) {}

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
                if ($stream !== getenv('NATS_STREAM')) {
                    $this->emitError(404, 'NATS_STREAM must match event stream');
                }
                try {
                    $event = EventDescription::fromJson(file_get_contents('php://input'));
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
                            ['version' => $expectedVersion, 'state' => $state] = $this->loadState($subject, $this->nats);
                        } catch (\JsonException $e) {
                            $this->emitError(400, 'json decoding error', ['exception' => $e]);
                        }

                        if ($state === null) {
                            $state = new ActivityHistory($activity, $this->logger);
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
                        $this->commit($state, $subject, $expectedVersion);

                        exit();
                    case 'orchestrations':
                        echo "ERROR: orchestrations not implemented yet\n";
                        http_response_code(500);
                        exit();
                    case 'entities':
                        echo "ERROR: entities not implemented yet\n";
                        http_response_code(500);
                        exit();
                    default:
                        echo "ERROR: unknown route type \"{$type}\"\n";
                        http_response_code(404);
                        exit();
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
        $logger = match (true) {
            $code >= 500 => $this->logger->critical(...),
            $code >= 400 => $this->logger->warning(...),
            default => $this->logger->info(...),
        };
        $logger($message, $context);
        http_response_code($code);
        exit();
    }

    private function loadState(string $subject, AmpClient $client): array
    {
        $bucket = $client->getApi()->getBucket($subject);
        $entry = $bucket->getEntry('state');
        if ($entry === null) {
            return [0, null];
        }
        $state = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);
        $version = $entry->revision;

        return ['version' => $version, 'state' => Serializer::deserialize($state, StateInterface::class)];
    }

    public function fire(Event ...$events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $this->logger->debug('Batching', ['event' => $event]);
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

    private function commit(AbstractHistory $state, string $subject, int $version): void
    {
        $bucket = $this->nats->getApi()->getBucket($subject);
        $sequence = $bucket->update('state', json_encode(Serializer::serialize($state), JSON_THROW_ON_ERROR), $version);
        $this->logger->alert('Pushed state', ['version' => $version, 'new seq' => $sequence]);
    }
}

$logger = new DurableLogger(level: match (getenv('LOG_LEVEL') ?: 'INFO') {
    'DEBUG' => Level::Debug,
    'INFO' => Level::Info,
    'ERROR' => Level::Error,
});

// configure a nats client
$nats = new AmpClient(new Configuration([
    'host' => explode(':', getenv('NATS_SERVER'))[0],
    'port' => explode(':', getenv('NATS_SERVER'))[1],
]));
$nats->setLogger($logger);
$nats->connect();
$nats->background(false);

(new Task($logger, $nats))->run();
