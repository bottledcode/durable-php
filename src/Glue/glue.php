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
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\SerializedArray;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Task;
use JsonException;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/autoload.php';

class Glue
{
    public readonly ?string $bootstrap;
    public StateId $target;
    public $payloadHandle;
    public array $payload = [];
    private string $method;
    private $streamHandle;
    private array $queries = [];

    public function __construct(private DurableLogger $logger)
    {
        $this->target = StateId::fromString($_SERVER['STATE_ID']);
        $this->bootstrap = $_SERVER['HTTP_DPHP_BOOTSTRAP'] ?: null;
        $this->method = $_SERVER['HTTP_DPHP_FUNCTION'];

        if(!file_exists($_SERVER['HTTP_DPHP_PAYLOAD'])) {
            throw new \LogicException('Unable to load payload');
        }

        $payload = stream_get_contents($this->payloadHandle = fopen($_SERVER['HTTP_DPHP_PAYLOAD'], 'r+b'));
        try {
            $this->payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->payload = [];
        }
        $this->logger->debug("Got payload", ['raw' => $payload, 'parsed' => $this->payload]);

        $this->streamHandle = fopen('php://input', 'r+b');
    }

    public function __destruct()
    {
        fclose($this->payloadHandle);
    }

    public function process()
    {
        $this->{$this->method}();
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
        $task = new Task($this->logger, $this);
        $task->run();
    }

    private function entitySignal(): void
    {
        $input = SerializedArray::import($this->payload['input'])->toArray();

        $event = WithEntity::forInstance($this->target, RaiseEvent::forOperation($this->payload['signal'], $input));
        $this->outputEvent(new EventDescription($event));
    }

    public function outputEvent(EventDescription $event): void
    {
        echo 'EVENT~!~' . trim($event->toStream()) . "\n";
    }

    private function startOrchestration(): void
    {
        if(!$this->target->toOrchestrationInstance()->executionId) {
            $this->target = StateId::fromInstance(new OrchestrationInstance($this->target->toOrchestrationInstance()->instanceId, Uuid::uuid7()->toString()));
        }

        header('X-Id: ' . $this->target->id);
        $input = SerializedArray::import($this->payload['input'])->toArray();

        $event = WithOrchestration::forInstance($this->target, StartExecution::asParent($input, [], /* todo: scheduling */));
        $this->outputEvent(new EventDescription($event));
    }

    private function orchestrationSignal(): void
    {
        $input = SerializedArray::import($this->payload);
        $signal = $_SERVER['HTTP_SIGNAL'];
        $event = WithOrchestration::forInstance($this->target, RaiseEvent::forCustom($signal, $this->payload));
        $this->outputEvent(new EventDescription($event));
    }

    private function entityDecoder(): void
    {
        $state = file_get_contents($_SERVER['HTTP_ENTITY_STATE']);
        $state = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        $state = Serializer::deserialize($state, EntityHistory::class);
        fseek($this->payloadHandle, 0);
        ftruncate($this->payloadHandle, 0);
        if($state->getState() !== null) {
            $state = Serializer::serialize($state->getState(), ['API']);
        } else {
            fwrite($this->payloadHandle, 'null');
            return;
        }
        fwrite($this->payloadHandle, json_encode($state, JSON_THROW_ON_ERROR));
    }
}

(new Glue($logger))->process();

exit();
