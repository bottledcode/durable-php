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

use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\Status;
use Exception;
use Generator;
use LogicException;
use r\Connection;
use r\ConnectionOptions;
use r\Exceptions\RqlDriverError;
use r\Options\ChangesOptions;
use r\Options\Durability;
use r\Options\RunOptions;
use r\Options\TableCreateOptions;
use r\Options\TableInsertOptions;
use Withinboredom\Time\Seconds;

use function r\connect;
use function r\connectAsync;
use function r\dbCreate;
use function r\row;
use function r\table;
use function r\tableCreate;
use function r\uuid;

/**
 * Configures a rethinkdb source
 */
class RethinkDbSource implements Source
{
    use PartitionCalculator;


    private function __construct(
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly string $partitionTable,
        private readonly string $stateTable
    ) {
    }

    public static function connect(Config $config, bool $asyncSupport): static
    {
        if ($asyncSupport) {
            $conn = connectAsync(
                new ConnectionOptions(
                    $config->storageConfig->host,
                    $config->storageConfig->port,
                    $config->storageConfig->database,
                    ($config->storageConfig->username ?? 'admin'),
                    ($config->storageConfig->password ?? '')
                )
            );
        } else {
            $conn = connect(
                new ConnectionOptions(
                    $config->storageConfig->host,
                    $config->storageConfig->port,
                    $config->storageConfig->database,
                    ($config->storageConfig->username ?? 'admin'),
                    ($config->storageConfig->password ?? '')
                )
            );
        }

        try {
            try {
                dbCreate($config->storageConfig->database)->run($conn);
            } catch (Exception) {
                // database already exists
            }

            tableCreate('partition_' . $config->currentPartition, new TableCreateOptions(
                primaryKey: 'id',
                durability: Durability::Soft
            ))->run($conn);
            tableCreate('state', new TableCreateOptions(durability: Durability::Soft))->run($conn);
            tableCreate('locks')->run($conn);
            table('locks')->indexCreate('lock', [row('owner'), row('target')])->run($conn);
            table('locks')->indexWait('lock')->run($conn);
        } catch (Exception) {
            // table already exists
        }

        return new self($conn, $config, 'partition_' . $config->currentPartition, 'state');
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function getPastEvents(): Generator
    {
        yield;
    }


    public function receiveEvents(): Generator
    {
        $events = table($this->partitionTable)->changes(
            new ChangesOptions(
                include_initial: true,
                include_types: true,
                changefeed_queue_size: $this->config->storageConfig->changefeedBufferSize
            )
        )->filter(row('type')->ne('remove'))->run($this->connection, new RunOptions());

        foreach ($events as $event) {
            if ($event['type'] === 'remove') {
                continue;
            }

            if (empty($event['new_val'])) {
                Logger::error('Ignoring: %s', print_r($event, true));
                continue;
            }

            /*
             * @var Event $actualEvent
             */
            $actualEvent = Serializer::deserialize($event['new_val']['event'], $event['new_val']['type']);
            $actualEvent->eventId = $event['new_val']['id'];
            yield $actualEvent;
        }
    }


    public function cleanHouse(): void
    {
    }


    public function put(string $key, mixed $data, ?Seconds $ttl = null, ?int $etag = null): void
    {
        if($data === null) {
            table($this->stateTable)->get($key)->delete()->run($this->connection, new RunOptions());
            return;
        }

        $serialized = Serializer::serialize($data);
        if (function_exists('apcu_store')) {
            if ($etag && ($data['etag'] ?? false)) {
                $check = apcu_cas($key . '-etag', $etag, $data['etag']);
                if (!$check) {
                    throw new LogicException('Etag mismatch -- another process has updated the state');
                }
            }

            apcu_store($key, [$serialized, get_debug_type($data)], (int)($ttl?->inSeconds() ?? $this->config->workerTimeoutSeconds));
        }

        table($this->stateTable)->insert(
            [
                'id' => $key, 'data' => $serialized, 'etag' => $etag, 'ttl' => $ttl?->inSeconds(),
                'type' => get_debug_type($data),
            ],
            new TableInsertOptions(durability: Durability::Soft, conflict: 'update')
        )->run($this->connection, new RunOptions());
    }


    public function ack(Event $event): void
    {
        table($this->partitionTable)->get($event->eventId)->delete()->run(
            $this->connection,
            new RunOptions()
        );
    }


    /**
     * @template T
     * @param string $key
     * @param class-string<T> $class
     * @return   T|null
     * @throws   RqlDriverError
     */
    public function get(string $key, string $class): mixed
    {
        if (function_exists('apcu_fetch')) {
            [$state, $type] = apcu_fetch($key, $success);
            if ($success) {
                return Serializer::deserialize($state, $type);
            }
        }

        $result = table($this->stateTable)->get($key)->run($this->connection);

        if ($result) {
            return Serializer::deserialize($result['data'], $result['type']);
        }

        return null;
    }


    public function watch(StateId $stateId, RuntimeStatus ...$expected): Status|null
    {
        $cursor = table($this->stateTable)->get((string)$stateId)->changes(
            new ChangesOptions(include_initial: true)
        )->run(
            $this->connection
        );
        foreach ($cursor as $results) {
            $rawStatus = $results['new_val']['data']['status'] ?? null;
            if ($rawStatus === null) {
                continue;
            }

            $status = Serializer::deserialize($rawStatus, Status::class);
            if (in_array($status->runtimeStatus, $expected, true)) {
                return $status;
            }
        }

        return null;
    }

    public function storeEvent(Event $event, bool $local): string
    {
        $partition = 'partition_' . $this->calculateDestinationPartitionFor($event, $local);
        $results = table($partition)->insert(
            [
                'event' => Serializer::serialize($event), 'id' => $event->eventId ?: uuid(), 'type' => $event::class,
            ],
            new TableInsertOptions(return_changes: true)
        )->run($this->connection);
        return $results['changes'][0]['new_val']['id'] ?? $event->eventId;
    }

    public function workerStartup(): void
    {
        // TODO: Implement workerStartup() method.
    }
}
