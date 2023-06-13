<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use r\AmpConnection;
use r\Connection;
use r\ConnectionOptions;
use Revolt\EventLoop;

use function r\db;
use function r\row;
use function r\table;

require_once __DIR__ . '/../vendor/autoload.php';

class Monitor
{
    private Connection $connection;

    public function __construct(private Config $config)
    {
        $this->connection = new AmpConnection(new ConnectionOptions(
            $this->config->storageConfig->host,
            $this->config->storageConfig->port,
            user: $this->config->storageConfig->username ?? 'admin',
            password: $this->config->storageConfig->password ?? '',
            db: 'rethinkdb'
        ));

        EventLoop::repeat(5, $this->monitor(...));
    }

    public function monitor(): void
    {
        $cluster = $this->getClusterStats();
        $partitions = [];
        for ($i = 0; $i < $this->config->totalPartitions; $i++) {
            $partitions[$i] = $this->getPartitionStats($i);
        }
        $state = $this->getStateStats();

        $partitions = array_map(fn($partition) => sprintf(
            <<<STATS
Partition %d:
    reads: %d/s
    writes: %d/s
    queue len: %d
STATS,
            $partition['partition'],
            $partition['reads'],
            $partition['writes'],
            $partition['count'],
), $partitions);

        $partitions = implode("\n", $partitions);

        Logger::always(
            <<<STATS

Cluster:
    connections: %d/%d active
    q/s: %d
    w/s: %d
    r/s: %d
$partitions
State:
    reads: %d/s
    writes: %d/s
    activities: %d
    entities: %d
    orchestrations: %d
Server:
    memory: %d%%
    cpu load: %d
STATS,
            $cluster['query_engine']['clients_active'],
            $cluster['query_engine']['client_connections'],
            $cluster['query_engine']['queries_per_sec'],
            $cluster['query_engine']['written_docs_per_sec'],
            $cluster['query_engine']['read_docs_per_sec'],
            $state['reads'],
            $state['writes'],
            $state['count']['activities'],
            $state['count']['entities'],
            $state['count']['Orchestrations'],
            $this->getServerMemoryUsage(),
            $this->getServerCpuUsage()
        );

        // todo: detect stalled partitions
    }

    private function getServerMemoryUsage(): float|int
    {
        $free = shell_exec('/usr/bin/free');
        $free = trim($free);
        $free = explode("\n", $free);
        $mem = explode(" ", $free[1]);
        $mem = array_filter($mem);
        $mem = array_merge($mem);
        return $mem[2] / $mem[1] * 100;
    }

    private function getServerCpuUsage()
    {
        $load = sys_getloadavg();
        return $load[0];
    }

    private function getStateStats(): array
    {
        $stats = $this->getTableStats('state');
        $count = db($this->config->storageConfig->database)->table('state')->group('type')->count()->run($this->connection);

        $count = array_column($count['data'], 1, 0);

        return [
            'reads' => $stats['query_engine']['read_docs_per_sec'],
            'writes' => $stats['query_engine']['written_docs_per_sec'],
            'count' => [
                'activities' => $count[ActivityHistory::class] ?? 0,
                'entities' => $count[EntityHistory::class] ?? 0,
                'Orchestrations' => $count[OrchestrationHistory::class] ?? 0,
            ]
        ];
    }

    private function getPartitionStats(int $partition): array
    {
        $stats = $this->getTableStats('partition_' . $partition);
        $count = db($this->config->storageConfig->database)->table('partition_' . $partition)->count()->run($this->connection);

        if ($count * 4 > $this->config->storageConfig->changefeedBufferSize) {
            Logger::error('Partition %d is too big for buffer size, restart with --changefeed-buffer-size %d or some events WILL be lost', $partition, $count * 5);
        }

        return [
            'reads' => $stats['query_engine']['read_docs_per_sec'],
            'writes' => $stats['query_engine']['written_docs_per_sec'],
            'count' => $count,
            'partition' => $partition,
        ];
    }

    private function getClusterStats(): array
    {
        return table('stats')->get(['cluster'])->run($this->connection);
    }

    private function getTableStats(string $table): array
    {
        return table('stats')->get(['table', $this->getTableId($table)])->run($this->connection);
    }

    private function getTableId(string $table): string
    {
        return table('table_config')
            ->filter(
                row('db')->eq($this->config->storageConfig->database)->rAnd(row('name')->eq($table))
            )
            ->pluck('id')
            ->nth(0)
            ->run($this->connection)['id'];
    }
}
