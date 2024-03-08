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

namespace Bottledcode\DurablePhp\Glue;

use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Task;
use JsonException;

require_once __DIR__ . '/autoload.php';

class Glue
{
    public readonly ?string $bootstrap;

    private string $method;

    private array $payload = [];

    private $payloadHandle;

    private $streamHandle;

    private StateId $target;

    private array $queries = [];

    public function __construct(private DurableLogger $logger)
    {
        $this->target = StateId::fromString($_SERVER['HTTP_STATE_ID']);
        $this->bootstrap = $_SERVER['HTTP_DPHP_BOOTSTRAP'] ?: null;
        $this->method = $_SERVER['HTTP_DPHP_FUNCTION'];

        $payload = stream_get_contents($this->payloadHandle = fopen($_SERVER['HTTP_DPHP_PAYLOAD'], 'rb'));
        try {
            $this->payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->payload = [];
        }

        $this->streamHandle = fopen('php://input', 'rb');
    }

    public function __destruct()
    {
        fclose($this->payloadHandle);
    }

    public function process()
    {
        $this->{$this->method}();
    }

    public function entitySignal(): void
    {
        $event = WithEntity::forInstance($this->target, RaiseEvent::forOperation($this->payload['signal'], $this->payload['input']));
        $this->outputEvent(new EventDescription($event));
    }

    public function outputEvent(EventDescription $event): void
    {
        echo 'EVENT~!~' . $event->toStream();
    }

    public function queryState(StateId $id): ?StateInterface
    {
        $this->queries[] = true;
        echo implode('~!~', ['QUERY', $id->id, $qid = count($this->queries)]);

        while (true) {
            $result = fgets($this->streamHandle);
            if (str_starts_with($result, "$qid://")) {
                $file = explode('//', $result)[1];

                return $this->readStateFile($file);
            }
        }
    }

    private function readStateFile($resource): ?StateInterface
    {
        $payload = stream_get_contents($resource);
        try {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return Serializer::deserialize($payload, StateInterface::class);
    }

    public function processMsg(): void
    {
        $task = new Task();
        $task->run();
    }
}

(new Glue($logger))->process();

exit();
