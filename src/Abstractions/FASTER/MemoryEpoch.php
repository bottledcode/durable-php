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

namespace Bottledcode\DurablePhp\Abstractions\FASTER;

use Amp\File\FileMutex;

use function Amp\delay;

class TableEntry
{
    public function __construct(
        public int $localCurrentEpoch, public int $threadId, public int $reentrant, public array $markers
    ) {
    }

    public static function deserialize(string $data): self
    {
        $arr = unpack('llocalCurrentEpoch/ilthreadId/ilreentrant/l*marker', $data);
        $markers = [];
        for ($i = 0; $i < 6; $i++) {
            if (!isset($arr['marker' . $i])) {
                break;
            }
            $markers[$arr['marker' . $i]] = $arr['marker' . ($i + 1)];
        }
        return new TableEntry($arr['localCurrentEpoch'], $arr['threadId'], $arr['reentrant'], $markers);
    }

    public function serialize(): string
    {
        $arr = array_map(static fn(int $key, int $value) => [$key, $value], array_keys($this->markers), $this->markers);
        $arr = array_merge(...$arr);

        return pack('liil*', $this->localCurrentEpoch, $this->threadId, $this->reentrant, ...$arr);
    }
}

class MemoryEpoch implements Epoch
{

    private const CACHE_LINE_SIZE = 64;
    private const INVALID_INDEX_ENTRY = 0;
    private const DRAIN_LIST_SIZE = 16;

    private \Shmop $table;
    private int $tableSize;

    private array $drainList = [];

    private int $safeToReclaimEpoch;

    private array $metadata = [];

    private TableEntry $localEntry;

    public function __construct(int $processorCount)
    {
        $this->tableSize = max(128, $processorCount * 2);
        $this->table = shmop_open(ftok(__FILE__, 'e'), 'c', 0644, $this->tableSize + 16);
    }

    public function bumpCurrentEpoch(\Closure|null $action = null): int
    {
        $nextEpoch = $this->currentEpoch(1);
        if (count($this->drainList) > 0) {
            $this->drain($nextEpoch);
        }
        if ($action !== null) {
            $priorEpoch = $nextEpoch - 1;
            foreach ($this->drainList as $idx => $toDrain) {
                $triggerEpoch = $toDrain['epoch'];
                if ($triggerEpoch < $this->safeToReclaimEpoch) {
                    unset($this->drainList[$idx]);
                    $toDrain['action']();
                }
            }
            $this->drainList[] = ['epoch' => $priorEpoch, 'action' => $action];
        }

        $this->protectAndDrain();


        return $nextEpoch;
    }

    private function currentEpoch(int|null $newEpoch = null): int
    {
        if ($newEpoch !== null) {
            $mutex = new FileMutex(__FILE__);
            $lock = $mutex->acquire();
        }
        $currentEpoch = unpack('LcurrentEpoch', shmop_read($this->table, 0, 4))['currentEpoch'];
        if ($newEpoch !== null) {
            $currentEpoch += $newEpoch;
            $data = pack('L', $currentEpoch);
            shmop_write($this->table, $data, 0);
            $lock->release();
        }
        return $currentEpoch;
    }

    public function acquire(): int
    {
        $this->metadata['threadEntryIndex'] ??= $this->reserveEntryForThread();

        return $this->metadata['threadEntryIndex'] + 1;
    }

    private function reserveEntryForThread()
    {
        if ($this->metadata['threadId'] === 0) {
            $this->metadata['threadId'] = getmypid();
        }

        return $this->reserveEntry();
    }

    private function reserveEntry(): int
    {
        while (true) {
            for ($i = 1; $i < $this->tableSize; $i++) {
                if ($this->casThreadWriteToTable($i, new TableEntry(0, $this->metadata['threadId'], 0, []))) {
                    return $i;
                }
            }

            delay(0);
        }
    }

    private function casThreadWriteToTable(
        int $index, TableEntry $entry
    ): bool {
        $mutex = new FileMutex(__FILE__);
        $lock = $mutex->acquire();
        $currentData = $this->readTable($index);
        if ($currentData->threadId !== $entry->threadId) {
            $lock->release();
            return false;
        }
        $this->writeToTable($index, $entry);
        $lock->release();
        return true;
    }

    private function readTable(int $index): TableEntry
    {
        $data = shmop_read($this->table, self::CACHE_LINE_SIZE * $index, self::CACHE_LINE_SIZE);
        return TableEntry::deserialize($data);
    }

    /**
     * Release the current thread's entry in the epoch table.
     * @return void
     */
    public function release(): void
    {
        $entry = $this->metadata['threadEntryIndex'] ?? throw new \RuntimeException('No entry reserved for thread');

        $this->writeToTable($entry, new TableEntry(0, 0, 0, []));
        $this->metadata = [];
    }

    private function writeToTable(int $index, TableEntry $entry): void
    {
        shmop_write($this->table, $entry->serialize(), self::CACHE_LINE_SIZE * $index);
    }

    private function drain(int $nextEpoch): void
    {
        $this->computeNewSafeToReclaimEpoch($nextEpoch);

        foreach ($this->drainList as $idx => $toDrain) {
            $triggerEpoch = $toDrain['epoch'];
            if ($triggerEpoch < $this->safeToReclaimEpoch) {
                unset($this->drainList[$idx]);
                $toDrain['action']();
            }
        }
    }

    private function computeNewSafeToReclaimEpoch(int $currentEpoch): int
    {
        $oldestOngoingCall = $currentEpoch;

        for ($index = 1; $index <= $this->tableSize; $index++) {
            $entryEpoch = $this->readTable($index)->localCurrentEpoch;
            if (0 !== $entryEpoch) {
                $oldestOngoingCall = min($oldestOngoingCall, $entryEpoch);
            }
        }

        $this->safeToReclaimEpoch = $oldestOngoingCall - 1;
        return $this->safeToReclaimEpoch;
    }

    public function protectAndDrain(): void
    {
        $entry = $this->metadata['threadEntryIndex'] ?? throw new \RuntimeException('No entry reserved for thread');
        $localEpoch = $this->currentEpoch();
        $this->localEntry->localCurrentEpoch = $localEpoch;
        $this->writeToTable($entry, $this->localEntry);

        $this->drain($localEpoch);
    }

    public function startup(): void
    {
        $this->safeToReclaimEpoch = 0;
        $this->currentEpoch(0);
        for ($i = 1; $i < $this->tableSize; $i++) {
            $this->writeToTable($i, new TableEntry(0, 0, 0, []));
        }
    }

    public function shutdown(): void
    {
        shmop_delete($this->table);
    }

    public function mark(int $markerIndex, int $version): void
    {
        if ($markerIndex >= 6) {
            throw new \OutOfBoundsException('Marker index out of bounds');
        }
        $this->localEntry->markers[$markerIndex] = $version;
    }

    public function thisInstanceProtected(): bool
    {
        // TODO: Implement thisInstanceProtected() method.
    }

    public function suspend(): void
    {
        $this->release();
        if (count($this->drainList) > 0) {
            $this->suspendDrain();
        }
    }

    private function suspendDrain(): void
    {
        for ($i = 0; $i < $this->tableSize; $i++) {
            $entry = $this->readTable($i);
            if ($entry->threadId === 0) {
                continue;
            }
            $this->resume();
            $this->release();
        }
    }

    public function resume(): void
    {
        $this->acquire();
        $this->protectAndDrain();
    }

    public function checkIsComplete(int $markerId, int $version): bool
    {
        if ($markerId >= 6) {
            throw new \OutOfBoundsException('Marker index out of bounds');
        }

        for ($i = 1; $i < $this->tableSize; $i++) {
            $entry = $this->readTable($i);
            if ($entry->threadId === 0) {
                continue;
            }
            if ($entry->markers[$markerId] !== $version) {
                return false;
            }
        }

        return true;
    }
}
