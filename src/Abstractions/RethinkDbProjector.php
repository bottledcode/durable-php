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

namespace Bottledcode\DurablePhp\Abstractions;

use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Exception;
use r\Connection;
use r\ConnectionOptions;
use r\Options\ChangesOptions;
use r\Options\Durability;
use r\Options\TableCreateOptions;
use r\Options\TableInsertOptions;

use function r\connectAsync;
use function r\dbCreate;
use function r\now;
use function r\row;
use function r\table;
use function r\tableCreate;

class RethinkDbProjector implements ProjectorInterface, Semaphore
{
    private Connection|null $conn = null;
    private ProjectorInterface|null $next = null;
    private string $instance;

    private array $semaphores = [];

    public function __construct()
    {
        $this->instance = random_int(PHP_INT_MIN + 1, PHP_INT_MAX - 1);
    }

    public function connect(): void
    {
        if ($this->conn) {
            return;
        }

        $database = getenv('RETHINKDB_DATABASE') ?: 'durablephp';

        $this->conn = connectAsync(
            new ConnectionOptions(
                getenv('RETHINKDB_HOST') ?: 'localhost',
                getenv('RETHINKDB_PORT') ?: 28015,
                $database,
                getenv('RETHINKDB_USER') ?: 'admin',
                getenv('RETHINKDB_PASSWORD') ?: '',
            )
        );

        try {
            try {
                dbCreate($database)->run($this->conn);
            } catch (Exception) {
                // database already exists
            }

            tableCreate('entities', new TableCreateOptions(durability: Durability::Soft))->run($this->conn);
            tableCreate('orchestrations', new TableCreateOptions(durability: Durability::Soft))->run($this->conn);
            tableCreate('activities', new TableCreateOptions(durability: Durability::Soft))->run($this->conn);
            tableCreate('locks')->run($this->conn);
            table('locks')->indexCreate('lock', [row('owner'), row('target')])->run($this->conn);
            table('locks')->indexWait('lock')->run($this->conn);
        } catch (Exception) {
            // table already exists
        }
    }

    public function disconnect(): void
    {
        $this->conn?->close();
        unset($this->conn);
    }

    public function projectState(StateId $key, StateInterface|null $history): void
    {
        $this->throwIfNotConnected();
        assert($this->conn !== null);

        $table = $this->getTable($key);

        $id = $key->id;

        if ($history === null) {
            table($table)->get($id)->delete()->run($this->conn);
            return;
        }

        $document = Serializer::serialize($history);

        table($table)->insert(['id' => $id, 'data' => $document, 'type' => get_debug_type($history)],
            new TableInsertOptions(durability: Durability::Soft, conflict: 'update'))->run($this->conn);

        $this->next?->projectState($key, $history);
    }

    private function throwIfNotConnected(): void
    {
            $this->conn ?? throw new \LogicException('Not connected to projector');
    }

    /**
     * @param StateId $key
     * @return string
     */
    public function getTable(StateId $key): string
    {
        $table = match ($key->getStateType()) {
            OrchestrationHistory::class => 'orchestrations',
            EntityHistory::class => 'entities',
            ActivityHistory::class => 'activities'
        };
        return $table;
    }

    public function getState(StateId $key): EntityHistory|OrchestrationHistory|ActivityHistory|null
    {
        $this->throwIfNotConnected();
        assert($this->conn !== null);

        $table = $this->getTable($key);

        $result = table($table)->get($key->id)->run($this->conn);

        if ($result) {
            return Serializer::deserialize($result['data'], $result['type']);
        }

        return $this->next?->getState($key);
    }

    public function wait(string $key, bool $block = false): bool
    {
        $this->throwIfNotConnected();
        assert($this->conn !== null);

        $me = gethostname() . ":" . (function_exists('posix_getpid') ? posix_getpid() : $this->instance);
        try {
            $result = table('locks')->insert(['id' => $key, 'owner' => $me, 'at' => now()],
                new TableInsertOptions(conflict: 'error'))->run($this->conn);
            if ($result['errors']) {
                throw new Exception('insert failed');
            }
            table('locks')->get($key)->update(['at' => now()])->run($this->conn);

            $this->semaphores[$key] = true;

            return true;
        } catch (\Throwable) {
            if ($block) {
                $cursor =
                    table('locks')->get($key)->changes(new ChangesOptions(include_initial: true))->run($this->conn);
                foreach ($cursor as $value) {
                    if ($value['new_val'] === null) {
                        return $this->wait($key, $block);
                    }
                }
            }

            if ($timeout = getenv('SEMAPHORE_TIMEOUT')) {
                $row = table('locks')->get($key)->run($this->conn);
                if ($timeout < (new \DateTimeImmutable()) - $row['at']) {
                    table('locks')->insert(['id' => $key, 'owner' => $me, 'at' => now()],
                        new TableInsertOptions(conflict: 'update'))->run($this->conn);
                }

                $this->semaphores[$key] = true;

                return true;
            }

            return false;
        }
    }

    public function chain(ProjectorInterface $next): void
    {
        $this->next = $next;
    }

    public function signalAll(): void
    {
        foreach ($this->semaphores as $key => $semaphore) {
            $this->signal($key);
        }
    }

    public function signal(string $key): void
    {
        $this->throwIfNotConnected();
        assert($this->conn !== null);

        table('locks')->get($key)->delete()->run($this->conn);
        unset($this->semaphores[$key]);
    }
}
