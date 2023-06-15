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

namespace Bottledcode\DurablePhp\Abstractions\Sources;

use Amp\File\File;
use Bottledcode\DurablePhp\Abstractions\WAL\Index;
use Bottledcode\DurablePhp\Abstractions\WAL\Record;
use Bottledcode\DurablePhp\Abstractions\WAL\RecordType;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\State\Status;
use Generator;
use Ramsey\Uuid\Uuid;
use Withinboredom\Time\Seconds;

use function Amp\delay;
use function Amp\File\openFile;

class LocalWAL implements Source
{
    private File $file;
    private int $readPosition = 0;

    /**
     * @param Config $config
     * @param array<Index> $index
     * @param array<\Closure> $subscriptions
     */
    private function __construct(private Config $config, private array $index = [], private array $subscriptions = [])
    {
    }

    public static function connect(Config $config, bool $asyncSupport): static
    {
        // open the WAL file for appending
        $source = new self($config);
        $source->file = openFile($config->storageConfig->file, 'ab+');

        return $source;
    }

    public function close(): void
    {
        $this->file->close();
    }

    public function cleanHouse(): void
    {
        // todo: compact the file
    }

    public function storeEvent(Event $event, bool $local): string
    {
        $event->eventId = empty($event->eventId) ? Uuid::uuid7()->toString() : $event->eventId;
        $this->writeData($event->eventId, '__DELETE__');
        $this->writeData($event->eventId, json_encode(Serializer::serialize($event), JSON_THROW_ON_ERROR));
        return $event->eventId;
    }

    private function writeData(string $id, string $data): void
    {
        // todo: we should probably respect the page size here...
        $record = (string)new Record($id, $data, RecordType::Last);
        $this->file->write($record);
    }

    public function put(string $key, mixed $data, ?Seconds $ttl = null, ?int $etag = null): void
    {
        $this->writeData($key, '__DELETE__');
        $this->writeData($key, json_encode(Serializer::serialize($data), JSON_THROW_ON_ERROR));
    }

    public function get(string $key, string $class): mixed
    {
        $index = $this->index[$key] ?? null;
        return $index?->deserialize($class);
    }

    public function ack(Event $event): void
    {
        $id = $event->eventId;
        $record = (string)new Record($id, '__DELETE__', RecordType::Last);
        $this->file->write($record);
    }

    public function watch(StateId $stateId, RuntimeStatus ...$expected): Status|null
    {
        // catch up the local file
        while ($this->readRecord()) {
            // check if we have a status...
            if (isset($this->index[$stateId->id])) {
                $state = $this->index[$stateId->id]->deserialize(StateInterface::class);
                if($state === null) continue;
                var_dump($state->getStatus());
                if (in_array($state->getStatus()->runtimeStatus, $expected, true)) {
                    return $state->getStatus();
                }
            }
        }
        return null;
    }

    private function readRecord(bool $index = true, bool $oneShot = false): Record|null
    {
        $this->file->seek($this->readPosition);
        ['id' => $id, 'data' => $data, 'type' => $type] = unpack('Nid/Ndata/Ctype', $this->readBlob(9, $oneShot));
        $id = $this->readBlob($id);
        $data = $this->readBlob($data);
        $record = new Record($id, $data, RecordType::from($type));
        if ($index) {
            $this->index[$id] ??= new Index($id);
            $this->index[$id]->build($record, $this->readPosition);
            Logger::log('Appending %s to index', $record->id);
        }
        $this->readPosition = $this->file->tell();
        return $record;
    }

    private function readBlob(int $length, bool $oneShot = false): string
    {
        if ($length === 0) {
            return '';
        }
        $remaining = $length;
        $read = '';
        cont:
        if($remaining === 0) return $read;
        $data = $this->file->read(length: $remaining);
        $read .= $data;
        if (strlen($data) < $length) {
            $remaining -= strlen($data);
            if (!$oneShot) {
                goto cont;
            } else {
                throw new \RuntimeException('Unexpected end of file');
            }
        }
        return $read;
    }

    public function receiveEvents(bool $exit = false): Generator
    {
        while (true) {
            $record = $this->readRecord(true);
            if ($record === null && !$exit) {
                delay(0.1);
                continue;
            } elseif ($record === null && $exit) {
                break;
            }
            if ($record->type === RecordType::Last) {
                // we have a complete object and can deserialize it
                if (isset($this->subscriptions[$record->id])) {
                    foreach ($this->subscriptions[$record->id] as $callback) {
                        $callback($this->index[$record->id]->deserialize(Event::class));
                    }
                }

                $stateId = StateId::fromString($record->id);
                if ($stateId->isOrchestrationId() || $stateId->isEntityId() || $stateId->isActivityId()) {
                    continue;
                }

                // we can also yield the event
                if ($this->index[$record->id]->deserialize(Event::class) instanceof Event) {
                    yield $this->index[$record->id]->deserialize(Event::class);
                }
            }
        }
    }

    public function getPastEvents(): Generator
    {
        yield null;
    }

    public function workerStartup(): void
    {
        while (!$this->file->eof()) {
            try {
                $this->readRecord(oneShot: true);
            } catch (\RuntimeException) {
                break;
            }
        }
    }

    private function subscribe(string $key, \Closure $callback): void
    {
        $this->subscriptions[$key][] = $callback;
    }
}
