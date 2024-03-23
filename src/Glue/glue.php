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
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateAll;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateForAuth;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateForRole;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateForUser;
use Bottledcode\DurablePhp\State\Attributes\TimeToLive;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\State\StateInterface;
use Bottledcode\DurablePhp\Task;
use DI\Container;
use DI\Definition\AutowireDefinition;
use DI\Definition\Definition;
use DI\Definition\FactoryDefinition;
use DI\Definition\Helper\AutowireDefinitionHelper;
use DI\Definition\Helper\CreateDefinitionHelper;
use DI\Definition\InstanceDefinition;
use DI\Definition\ObjectDefinition;
use JsonException;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/autoload.php';

class Glue
{
    public readonly ?string $bootstrap;

    public StateId $target;

    public $payloadHandle;

    public array $payload = [];

    public ?Provenance $provenance;

    private string $method;

    private $streamHandle;

    private array $queries = [];

    public function __construct(private DurableLogger $logger)
    {
        $this->target = StateId::fromString($_SERVER['STATE_ID']);
        $this->bootstrap = $_SERVER['HTTP_DPHP_BOOTSTRAP'] ?: null;
        $this->method = $_SERVER['HTTP_DPHP_FUNCTION'];
        try {
            $provenance = json_decode($_SERVER['HTTP_DPHP_PROVENANCE'] ?? 'null', true, 32, JSON_THROW_ON_ERROR);
            if (! $provenance || $provenance === ['userId' => '', 'roles' => null]) {
                $this->provenance = null;
            } else {
                $provenance['roles'] ??= [];
                $this->provenance = Serializer::deserialize($provenance, Provenance::class);
            }
        } catch (JsonException $e) {
            $this->logger->alert('Failed to capture provenance', ['provenance' => $_SERVER['HTTP_DPHP_PROVENANCE'] ?? null]);
            $this->provenance = null;
        }

        if (! file_exists($_SERVER['HTTP_DPHP_PAYLOAD'])) {
            throw new \LogicException('Unable to load payload');
        }

        $payload = stream_get_contents($this->payloadHandle = fopen($_SERVER['HTTP_DPHP_PAYLOAD'], 'r+b'));
        try {
            $this->payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->payload = [];
        }
        $this->logger->debug('Got payload', ['raw' => $payload, 'parsed' => $this->payload]);

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

    public function bootstrap(): Container
    {
        $builder = new \DI\ContainerBuilder();
        if ($this->bootstrap) {
            $builder->addDefinitions(include $this->bootstrap);
        }

        return $builder->build();
    }

    private function entitySignal(): void
    {
        $input = SerializedArray::import($this->payload['input'])->toArray();

        $event = WithEntity::forInstance($this->target, RaiseEvent::forOperation($this->payload['signal'], $input));
        $this->outputEvent(new EventDescription($event));
    }

    public function outputEvent(EventDescription $event): void
    {
        // determine access level

        echo 'EVENT~!~' . trim($event->toStream()) . "\n";
    }

    private function startOrchestration(): void
    {
        if (! $this->target->toOrchestrationInstance()->executionId) {
            $this->target = StateId::fromInstance(new OrchestrationInstance($this->target->toOrchestrationInstance()->instanceId, Uuid::uuid7()->toString()));
        }

        header('X-Id: ' . $this->target->id);
        $input = SerializedArray::import($this->payload['input'])->toArray();

        $event = WithOrchestration::forInstance($this->target, StartExecution::asParent($input, []/* todo: scheduling */));
        $this->outputEvent(new EventDescription($event));

        $actualId = $this->target->toOrchestrationInstance();

        $this->writePayload(json_encode([
            'id' => $this->target->id,
            'execution' => $actualId->executionId,
            'instance' => $actualId->instanceId,
        ], JSON_THROW_ON_ERROR));
    }

    private function writePayload(string $payload): void
    {
        fseek($this->payloadHandle, 0);
        ftruncate($this->payloadHandle, 0);
        fwrite($this->payloadHandle, $payload);
    }

    private function orchestrationSignal(): void
    {
        $input = SerializedArray::import($this->payload);
        $signal = $_SERVER['HTTP_SIGNAL'];
        $event = WithOrchestration::forInstance($this->target, RaiseEvent::forCustom($signal, $this->payload));
        $this->outputEvent(new EventDescription($event));
        $this->writePayload('');
    }

    private function entityDecoder(): void
    {
        $state = file_get_contents($_SERVER['HTTP_ENTITY_STATE']);
        if (empty($state)) {
            fwrite($this->payloadHandle, 'null');

            return;
        }
        $state = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        $state = Serializer::deserialize($state, EntityHistory::class);
        if ($state->getState() !== null) {
            $state = Serializer::serialize($state->getState(), ['API']);
        } else {
            $this->writePayload('null');

            return;
        }
        $this->writePayload(json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function getPermissions(): void
    {
        $permissions = [
            'mode' => 'explicit',
            'users' => [],
            'roles' => [],
            'limits' => [
                'user' => -1,
                'role' => -1,
                'global' => -1,
            ],
            'ttl' => 0,
        ];
        $class = null;
        // todo: assert sanity in including this
        if ($this->bootstrap) {
            $definitions = include $this->bootstrap;
        } else {
            $definitions = [];
        }
        switch($this->target->getStateType()) {
            case ActivityHistory::class:
                $permissions['users'] = [$this->provenance?->userId];
                break;
            case EntityHistory::class:
                $entity = $this->target->toEntityId();
                $class = $definitions[$entity->name] ?? $entity->name;
                if($class instanceof AutowireDefinitionHelper) {
                    $class = $class->getDefinition('none')->getClassName();
                } elseif ($class instanceof CreateDefinitionHelper) {
                    $class = $class->getDefinition('none')->getClassName();
                }
                break;
            case OrchestrationHistory::class:
                $instance = $this->target->toOrchestrationInstance();
                $class = $definitions[$instance->instanceId] ?? $instance->instanceId;
                if($class instanceof AutowireDefinitionHelper) {
                    $class = $class->getDefinition('none')->getClassName();
                } elseif ($class instanceof CreateDefinitionHelper) {
                    $class = $class->getDefinition('none')->getClassName();
                }
        }

        if ($class !== null) {
            foreach ($class->getAttributes() as $attribute) {
                switch (true) {
                    case $attribute->getName() === AllowCreateForAuth::class:
                        $permissions['mode'] = 'auth';
                        /** @var AllowCreateForAuth $attribute */
                        $attribute = $attribute->newInstance();
                        $permissions['limits']['user'] = $attribute->userLimit;
                        $permissions['limits']['role'] = $attribute->roleLimit;
                        $permissions['limits']['global'] = $attribute->globalLimit;
                        break;
                    case $attribute->getName() === AllowCreateAll::class:
                        $permissions['mode'] = 'anon';
                        break;
                    case $attribute->getName() === AllowCreateForRole::class:
                        /** @var AllowCreateForRole $attribute */
                        $attribute = $attribute->newInstance();
                        $permissions['roles'][] = $attribute->role;
                        break;
                    case $attribute->getName() === AllowCreateForUser::class:
                        /** @var AllowCreateForUser $attribute */
                        $attribute = $attribute->newInstance();
                        $permissions['users'][] = $attribute->user;
                        break;
                    case $attribute->getName() === TimeToLive::class:
                        /** @var TimeToLive $attribute */
                        $attribute = $attribute->newInstance();
                        $permissions['ttl'] = $attribute->timeToLive()->inNanoseconds();
                        break;
                }
            }
        }

        $permissions = json_encode($permissions, JSON_THROW_ON_ERROR);
        header("Permissions: $permissions");
    }

    private function getFromDefinition(Definition $definition): \ReflectionClass|\ReflectionFunction|null
    {
        if ($definition instanceof AutowireDefinition || $definition instanceof ObjectDefinition) {
            return new \ReflectionClass($definition->getClassName());
        } elseif ($definition instanceof InstanceDefinition) {
            return new \ReflectionClass($definition->getInstance());
        } elseif ($definition instanceof FactoryDefinition) {
            return new \ReflectionFunction($definition->getCallable());
        }

        return null;
    }
}

header('Content-type: application/dphp');

(new Glue($logger))->process();

exit();
