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

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Bottledcode\DurablePhp\State\Attributes\Name;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use DateTimeImmutable;
use DateTimeInterface;
use DI\Definition\Helper\AutowireDefinitionHelper;
use DI\Definition\Helper\CreateDefinitionHelper;

use const T_STRING;

class SchemaGenerator
{
    public array $handlers = [];

    private string $bootstrap;

    private string $root;

    private array $scalars = [
        'Any',
        'Void',
        'State',
        'Date',
    ];

    private array $inputScalars = [
        'Any',
        'Void',
        'State',
        'Date',
    ];

    /**
     * @var array<MetaParser>
     */
    private array $types = [];

    private array $states = [];

    private array $searchedStates = [];

    public function __construct()
    {
        $this->bootstrap = $_SERVER['HTTP_DPHP_BOOTSTRAP'] ?: null;
    }

    public function generateSchema(string|null $rootDirectory = null): array
    {
        $projectRoot = $rootDirectory ?? $this->findComposerJson(__DIR__ . '/../../../..');
        $this->root = $projectRoot;
        $types = '';

        ['mutation' => $mutations, 'query' => $queries] = $this->findPhpFiles($projectRoot);

        foreach ($this->inputScalars as $idx => $scalarName) {
            if (is_numeric($idx)) {
                continue;
            }
            $toConstruct = $this->types[$scalarName] ?? null;
            if ($toConstruct === null) {
                continue;
            }
            unset($this->inputScalars[$idx]);

            [$moreTypes, $scalars] = $this->createProperties('input', $idx, $toConstruct);
            $this->inputScalars += $scalars;
            $types .= $moreTypes;
        }

        foreach ($this->scalars as $idx => $scalarName) {
            if (is_numeric($idx)) {
                continue;
            }
            $toConstruct = $this->types[$scalarName] ?? null;
            if ($toConstruct === null) {
                continue;
            }
            unset($this->scalars[$idx]);

            [$moreTypes, $scalars] = $this->createProperties('type', $idx, $toConstruct);
            $this->scalars += $scalars;
            $types .= $moreTypes;
        }

        $flipped = array_flip($this->searchedStates);
        foreach ($this->states as $realName => $properties) {
            if (empty(trim($properties))) {
                continue;
            }

            $rootName = $this->findRootName($realName, $flipped);
            if ($rootName === $realName) {
                // todo: warn?
                continue;
            }
            $name = $flipped[$rootName];
            $types .= <<<EOF

type {$name}Snapshot {
    {$properties}
}
EOF;

            $queries .= <<<EOF

{$name}(id: ID!): {$name}Snapshot
EOF;
            $this->handlers['queries'][] = ['op' => 'entity', 'op-name' => $name, 'realName' => $rootName];
        }
        $scalars = array_map(fn($x) => 'scalar ' . $x, array_unique($this->scalars + $this->inputScalars));
        $scalars = implode("\n", $scalars);

        return compact('queries', 'types', 'mutations', 'scalars');
    }

    public function findComposerJson(string $startDirectory): ?string
    {
        $dir = realpath($startDirectory);

        while ($dir !== '/' && $dir !== null) {
            $path = $dir . '/composer.json';

            if (file_exists($path)) {
                return dirname($path);
            }

            $dir = dirname($dir);
        }

        return null;
    }

    public function findPhpFiles($dir): array
    {
        $mutation = '';
        $query = '';

        $items = glob($dir . '/*');

        foreach ($items as $item) {
            if (is_dir($item) && !str_ends_with($item, 'vendor')) {
                $results = $this->findPhpFiles($item);
                $mutation .= $results['mutation'];
                $query .= $results['query'];
            } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                $results = $this->processPhpFile($item);
                $mutation .= $results['mutation'];
                $query .= $results['query'];
            }
        }

        return compact('mutation', 'query');
    }

    public function processPhpFile(string $file): array
    {
        $content = file_get_contents($file);

        if (str_contains($content, 'OrchestrationContext') || str_contains($content, 'OrchestrationContextInterface')) {
            return $this->defineOrchestration($file, $content);
        }

        return ['mutation' => $this->defineEntity($file, $content), 'query' => ''];
    }

    public function defineOrchestration(string $filename, string $contents): array
    {
        $parsed = GraphGenerator::parseFile($contents);
        $name = $parsed->name;

        $m = null;
        foreach ($parsed->methods as $method) {
            if ($method['name'] === '__invoke') {
                $m = $method;
            }
            foreach ($method['attributes'] as $attribute) {
                if ($attribute['name'] === 'EntryPoint') {
                    $m = $method;
                }
            }
        }

        if ($m === null) {
            return ['mutation' => '', 'query' => ''];
        }

        $arguments = [];
        foreach ($m['args'] as ['type' => $type, 'name' => $argName, 'full_type' => $fullType]) {
            if ($argName === '$context' ||
                in_array($type, ['OrchestrationContextInterface', 'OrchestrationContext'], true)) {
                $arguments[] = 'input: [Input!]!';
                break;
            }

            [$type, $scalar] = $this->extractScalars($type, true);

            if ($scalar) {
                $this->inputScalars[$type] = $fullType;
            }
            $argName = trim($argName, '$');

            $arguments[] = "{$argName}: {$type}";
        }
        if (empty($arguments)) {
            $arguments = '';
        } else {
            $arguments = '(' . implode(', ', $arguments) . ')';
        }

        $realName = $parsed->namespace . '\\' . $name;
        $name = ucfirst($name);

        $mutation = <<<GRAPHQL
    StartNew{$name}Orchestration{$arguments}: Orchestration

GRAPHQL;

        $query = <<<GRAPHQL

    {$name}Status(execution: ID!): Status!

GRAPHQL;

        $this->handlers['mutations'][] =
            ['op' => 'StartOrchestration', 'op-name' => "StartNew{$name}Orchestration", 'name' => $realName];
        $this->handlers['queries'][] =
            ['op' => 'OrchestrationStatus', 'op-name' => "{$name}Status", 'name' => $realName];

        $waitForExternalEventCalls = [];
        $tokens = token_get_all($contents);
        $waitForExternalEventFound = false;
        $nextStringIsArgument = false;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_STRING && $token[1] === 'waitForExternalEvent') {
                    $waitForExternalEventFound = true;
                } elseif ($waitForExternalEventFound && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $waitForExternalEventCalls[] = trim($token[1], '\'"');
                    $waitForExternalEventFound = false;
                }
            }
        }
        foreach (array_unique($waitForExternalEventCalls) as $event) {
            $originalEvent = $event;
            $event = str_replace(' ', '', ucwords($event));
            $mutation .= "    Send{$event}To{$name}Orchestration(execution: ID!, arguments: [Input!]!): Void\n";
            $this->handlers['mutations'][] = [
                'op' => 'RaiseOrchestrationEvent',
                'op-name' => "Send{$event}To{$name}Orchestration",
                'event' => $originalEvent,
                'name' => $realName,
            ];
        }

        return compact('mutation', 'query');
    }

    private function extractScalars(string $type, bool $input = false, $innerType = 'Any!'): array
    {
        $scalar = null;
        $nullable = false;

        if (str_contains($type, '|')) {
            if (mb_substr_count($type, '|') === 1 && (str_contains($type, '|null') || str_contains($type, 'null|'))) {
                $nullable = true;
                $type = str_replace(['|null', 'null|'], '', $type);
            } else {
                $type = 'mixed';
            }
        }

        if (str_contains($type, '?')) {
            $nullable = true;
            $type = str_replace('?', '', $type);
        }

        switch ($type) {
            case 'string':
            case 'int':
            case 'float':
                $type = ucfirst($type);
                break;
            case 'bool':
                $type = 'Boolean';
                break;
            case 'EntityId':
            case EntityId::class:
                $type = 'EntityId';
                break;
            case 'OrchestrationInstance':
            case OrchestrationInstance::class:
                $type = 'OrchestrationId';
                break;
            case DateTimeImmutable::class:
            case DateTimeImmutable::class:
            case DateTimeInterface::class:
                $type = 'Date';
                $scalar = 'Date';
                break;
            case 'mixed':
                $type = 'Any';
                $scalar = 'Any';
                break;
            case 'array':
                $type = "[{$innerType}]";
                break;
            case 'void':
                $type = 'Void';
                $nullable = true;
                break;
            default:
                $scalar = explode('\\', $type);
                $scalar = array_pop($scalar);
                $scalar = ucfirst($scalar);
                $type = $scalar . ($input ? 'Input' : '');
                break;
        }

        return [$nullable ? $type : "{$type}!", $scalar];
    }

    public function defineEntity(string $filename, string $contents): string
    {
        $parsed = GraphGenerator::parseFile($contents);

        $realName = $parsed->name;
        $realName = $parsed->namespace . '\\' . $realName;

        foreach ($parsed->attributes as $attribute) {
            if ($attribute['name'] === 'Name' || $attribute['name'] === Name::class) {
                $className = ucfirst($attribute['args'][0]);
                goto found;
            }
        }

        if (str_contains($contents, 'EntityState')) {
            $properties = [];
            foreach ($parsed->properties as ['type' => $type, 'name' => $name, 'full_type' => $fullType]) {
                [$type, $scalar] = $this->extractScalars($type);
                if ($scalar) {
                    $this->scalars[$type] = $fullType;
                }
                $name = trim($name, '$');
                $properties[] = "{$name}: {$type}";
            }

            $properties = implode("\n", $properties);
            $this->states[$realName] = $properties;

            return '';
        }

        // maybe define type
        $this->types[$realName] = $parsed;

        return '';

        found:

        $methods = [];

        foreach ($parsed->methods as $method) {
            $originalMethodName = $method['name'];
            $method['name'] = ucfirst($method['name']);

            $arguments = ['id: ID!'];
            $method['args'] = array_map(fn(array $args) => ['type' => 'mixed', ...$args], $method['args']);

            foreach ($method['args'] as ['type' => $type, 'name' => $name, 'full_type' => $fullType]) {
                [$type, $scalar] = $this->extractScalars($type, true);
                if ($scalar) {
                    $this->inputScalars[$type] = $fullType;
                }
                $name = trim($name, '$');

                $arguments[] = "{$name}: {$type}";
            }
            $arguments = implode(', ', $arguments);

            /*
            [$returnType, $scalar] = $this->extractScalars($method['return']);
            if($scalar) {
                $this->scalars[] = $scalar;
            }*/
            [$returnType, $scalar] = $this->extractScalars($method['return'], false);
            if ($scalar) {
                $this->scalars[$method['return']] = $method['full_return'];
            }

            $methods[] = "    Signal{$className}With{$method['name']}({$arguments}): {$returnType}";
            $this->handlers['mutations'][] = [
                'op' => 'SendEntitySignal',
                'op-name' => "Signal{$className}With{$method['name']}",
                'realName' => $realName,
                'method' => $originalMethodName,
            ];
            $this->searchedStates[$className] = $realName;
        }

        return implode("\n", $methods) . "\n";
    }

    private function createProperties(string $kind, string $name, MetaParser $parser): array
    {
        $innerTypes = '';
        $finalTypes = '';

        $showName = trim($name, '!');

        $finalTypes = "\n{$kind} {$showName} {\n";
        $newScalars = [];

        foreach ($parser->properties as $property) {
            $innerFullType = GraphGenerator::getSequenceType($property['attributes']);
            [$innerType, $scalar] = $this->extractScalars($innerFullType, $kind === 'input');
            if ($scalar) {
                $newScalars[$innerType] = $innerFullType;
            }

            [$type, $scalar] = $this->extractScalars($property['type'], $kind === 'input', $innerType);
            if ($scalar) {
                $newScalars[$type] = $property['full_type'] ?? $type;
            }
            $property['name'] = trim($property['name'], '$');
            $finalTypes .= "    {$property['name']}: {$type}\n";
        }
        $finalTypes .= "}{$innerTypes}";

        foreach ($newScalars as $name => $type) {
            if (is_numeric($name)) {
                continue;
            }
            $toConstruct = $this->types[$type] ?? null;
            if ($toConstruct === null) {
                continue;
            }
            unset($newScalars[$name]);

            [$moreTypes, $moreScalars] = $this->createProperties($kind, $name, $toConstruct);
            $finalTypes .= $moreTypes;
            $newScalars += $moreScalars;
        }

        return [$finalTypes, $newScalars];
    }

    protected function findRootName(string $parsedName, array $matches): string
    {
        $definitions = include $this->bootstrap;

        foreach ($definitions as $name => $class) {
            if ($class instanceof AutowireDefinitionHelper) {
                $class = $class->getDefinition('none')->getClassName();
            } elseif ($class instanceof CreateDefinitionHelper) {
                $class = $class->getDefinition('none')->getClassName();
            }
            if ($class === $parsedName && !in_array($name, $matches, true)) {
                // we have an alias
                return $this->findRootName($name, $matches);
            }
        }

        return $parsedName;
    }
}
