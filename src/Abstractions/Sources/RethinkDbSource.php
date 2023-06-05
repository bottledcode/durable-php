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
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationStatus;
use Crell\Serde\Serde;
use Crell\Serde\SerdeCommon;
use Exception;
use Generator;
use r\Connection;
use r\ConnectionOptions;
use r\Options\ChangesOptions;
use r\Options\Durability;
use r\Options\RunOptions;
use r\Options\TableInsertOptions;
use Withinboredom\Time\Seconds;

use function r\connectAsync;
use function r\dbCreate;
use function r\table;
use function r\tableCreate;
use function r\uuid;

class RethinkDbSource implements Source
{
	use PartitionCalculator;

	private readonly Serde $serde;

	private function __construct(
		private Connection $connection,
		private Config $config,
		private string $partitionTable,
		private string $stateTable
	) {
		$this->serde = new SerdeCommon();
	}

	public static function connect(Config $config): static
	{
		$conn = connectAsync(
			new ConnectionOptions(
				$config->storageConfig->host,
				$config->storageConfig->port,
				$config->storageConfig->database,
				$config->storageConfig->username ?? 'admin',
				$config->storageConfig->password ?? ''
			)
		);

		try {
			try {
				dbCreate($config->storageConfig->database)->run($conn);
			} catch (Exception) {
				// database already exists
			}
			tableCreate('partition_' . $config->currentPartition)->run($conn);
			table('partition_' . $config->currentPartition)->indexCreate('timestamp')->run($conn);
			tableCreate('state')->run($conn);
		} catch (Exception) {
			// table already exists
		}

		return new self($conn, $config, 'partition_' . $config->currentPartition, 'state');
	}

	public function getPastEvents(): Generator
	{
		return;
		$events = table($this->partitionTable)->run($this->connection);

		foreach ($events as ['event' => $event, 'id' => $id, 'type' => $type]) {
			/**
			 * @var Event $actualEvent
			 */
			$actualEvent = $this->serde->deserialize($event, 'array', $type);
			$actualEvent->eventId = $id;
			yield $actualEvent;
		}
	}

	public function receiveEvents(): Generator
	{
		$events = table($this->partitionTable)->changes(
			new ChangesOptions(
				include_initial: true, include_types: true, squash: true
			)
		)->run($this->connection);

		foreach ($events as $event) {
			if ($event['type'] === 'remove') {
				continue;
			}

			if (empty($event['new_val'])) {
				continue;
			}

			/**
			 * @var Event $actualEvent
			 */
			$actualEvent = $this->serde->deserialize(
				$event['new_val']['event'],
				'array',
				$event['new_val']['type'],
			);
			$actualEvent->eventId = $event['new_val']['id'];
			yield $actualEvent;
		}
	}

	public function cleanHouse(): void
	{
		// no-op
	}

	public function storeEvent(Event $event, bool $local): string
	{
		$partition = 'partition_' . $this->calculateDestinationPartitionFor($event, $local);
		$results = table($partition)->insert(
			['event' => $this->serde->serialize($event, 'array'), 'id' => uuid(), 'type' => $event::class],
			new TableInsertOptions(return_changes: true)
		)->run($this->connection);
		return $results['changes'][0]['new_val']['id'];
	}

	public function put(string $key, mixed $data, ?Seconds $ttl = null, ?string $etag = null): void
	{
		table($this->stateTable)->insert(
			[
				'id' => $key,
				'data' => $this->serde->serialize($data, 'array'),
				'etag' => $etag,
				'ttl' => $ttl?->inSeconds(),
				'type' => $data::class,
			],
			new TableInsertOptions(conflict: 'update', durability: Durability::Soft)
		)->run($this->connection, new RunOptions());
	}

	public function ack(Event $event): void
	{
		table($this->partitionTable)->get($event->eventId)->delete()->run(
			$this->connection,
			new RunOptions(noreply: true)
		);
	}

	/**
	 * @template T
	 * @param string $key
	 * @param class-string<T> $class
	 * @return T|null
	 * @throws \r\Exceptions\RqlDriverError
	 */
	public function get(string $key, string $class): mixed
	{
		$result = table($this->stateTable)->get($key)->run($this->connection);

		if ($result) {
			return $this->serde->deserialize($result['data'], 'array', $result['type']);
		}

		return null;
	}

	public function watch(StateId $stateId, OrchestrationStatus ...$expected): OrchestrationStatus|null
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
			$status = $this->serde->deserialize($rawStatus, 'array', OrchestrationStatus::class);
			if (in_array($status, $expected, true)) {
				return $status;
			}
		}

		return null;
	}
}
