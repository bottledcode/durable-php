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

use Bottledcode\DurablePhp\DurableClient;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Serializer;
use GraphQL\Error\DebugFlag;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Server\ServerConfig;
use GraphQL\Server\StandardServer;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Utils\BuildSchema;

require_once __DIR__ . '/../../Glue/autoload.php';

header('Content-Type: text/plain');

$generator = new SchemaGenerator();
$schemaParts = $generator->generateSchema();

$schema = <<<SCHEMA
schema {
    query: Query
    mutation: Mutation
}

type Query {
    entitySnapshot(id: EntityId!): State
    orchestration(id: OrchestrationId!, waitForCompletion: Boolean): Status
    {$schemaParts['queries']}
}

type Mutation {
    SendEntitySignal(id: EntityId!, signal: String!, arguments: [Input!]!): Void
    RaiseOrchestrationEvent(id: OrchestrationId!, signal: String!, arguments: [Input!]!): Void
    StartOrchestration(name: String!, input: [Input!]!, id: String): Orchestration
    {$schemaParts['mutations']}
}

input EntityId {
    name: ID!
    id: ID!
}

input OrchestrationId {
    instance: ID!
    execution: ID!
}

type Orchestration {
    instance: ID!
    execution: ID!
}

type Status {
    createdAt: Date!
    customStatus: String!
    input: [OriginalInput!]!
    id: ID!
    lastUpdated: Date!
    output: State
    runtimeStatus: RuntimeStatus!
}

enum RuntimeStatus {
    Running
    Completed
    ContinuedAsNew
    Failed
    Canceled
    Terminated
    Pending
    Suspended
    Unknown
}

type OriginalInput {
    key: String
    value: Any!
}

input Input {
    key: String,
    value: Any!
}

{$schemaParts['types']}

{$schemaParts['scalars']}

SCHEMA;
if($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain');
    echo $schema;
    die();
}

$client = DurableClient::get();
$client->withAuth(str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']));

function getOrchestrationStatus(array $args, DurableClient $context): array
{
    $id = new OrchestrationInstance($args['id']['instance'], $args['id']['execution']);
    if($args['waitForCompletion'] ?? false) {
        $context->waitForCompletion($id);
    }

    return Serializer::serialize($context->getStatus($id));
}

function getEntitySnapshot(array $args, DurableClient $context): array
{
    $id = new EntityId($args['id']['name'], $args['id']['id']);
    return Serializer::serialize($context->getEntitySnapshot($id));
}

function startOrchestration(array $args, DurableClient $context): array
{
    $id = $context->startNew($args['name'], $args['input'], $args['id'] ?? null);
    return [
        'instance' => $id->instanceId,
        'execution' => $id->executionId,
    ];
}

function raiseEvent(array $args, DurableClient $context): array
{
    $id = new OrchestrationInstance($args['id']['instance'], $args['id']['execution']);
    $arguments = array_map(static fn($x, $i) => ['key' => $i, ...$x], $args['arguments'], range(0, count($args['arguments']) - 1));
    $arguments = array_column($arguments, 'value', 'key');
    $context->raiseEvent($id, $args['signal'], $arguments);
    return [];
}

function signal(array $args, DurableClient $context): array
{
    $id = new EntityId($args['id']['name'], $args['id']['id']);
    $arguments = array_map(static fn($x, $i) => ['key' => $i, ...$x], $args['arguments'], range(0, count($args['arguments']) - 1));
    $arguments = array_column($arguments, 'value', 'key');
    $context->signalEntity($id, $args['signal'], $arguments);
    return [];
}

$decorator = function (array $typeConfig, TypeDefinitionNode $typeDefinitionNode) use ($generator): array {
    $name = $typeConfig['name'];

    switch($name) {
        case 'Query':
            $queries = [];
            foreach($generator->handlers['queries'] as $handler) {
                $queries[$handler['op-name']] = $handler;
            }
            $typeConfig['resolveField'] = function ($value, array $args, DurableClient $context, ResolveInfo $resolveInfo) use ($queries): mixed {
                switch($resolveInfo->fieldName) {
                    case 'orchestration':
                        return getOrchestrationStatus($args, $context);
                        // no break
                    case 'entity':
                        return getEntitySnapshot($args, $context);
                    default:
                        if($handler = $queries[$resolveInfo->fieldName]) {
                            switch($handler['op']) {
                                case 'entity':
                                    return getEntitySnapshot(['id' => ['id' => $args['id'], 'name' => $resolveInfo->fieldName], ...$args], $context);
                            }
                        }
                }

                return 'error';
            };
            break;
        case 'Mutation':
            $queries = [];
            foreach($generator->handlers['mutations'] as $handler) {
                $queries[$handler['op-name']] = $handler;
            }
            $typeConfig['resolveField'] = function ($value, array $args, DurableClient $context, ResolveInfo $resolveInfo) use ($queries): mixed {
                switch($resolveInfo->fieldName) {
                    case 'StartOrchestration':
                        return startOrchestration($args, $context);
                        // no break
                    case 'RaiseOrchestrationEvent':
                        return raiseEvent($args, $context);
                    case 'SendEntitySignal':
                        return signal($args, $context);
                    default:
                        if($handler = $queries[$resolveInfo->fieldName]) {
                            switch($handler['op']) {
                                case 'StartOrchestration':
                                    return startOrchestration(['name' => $handler['name'], ...$args], $context);
                                case 'RaiseOrchestrationEvent':
                                    $originalArgs = $args;
                                    unset($args['execution']);
                                    return raiseEvent(['signal' => $handler['event'], 'id' => ['execution' => $originalArgs['execution'], 'instance' => $handler['name']], ...$args], $context);
                                case 'SendEntitySignal':
                                    $originalArgs = $args;
                                    unset($args['id']);
                                    return signal(['id' => ['name' => $handler['realName'], 'id' => $originalArgs['id']], 'signal' => $handler['method'], ...$args], $context);
                            }
                        }
                }

                return 'error';
            };
            break;
        case 'Orchestration':
            break;
    }

    return $typeConfig;
};

// todo: caching
$schema = BuildSchema::build($schema, $decorator);

$config = ServerConfig::create()->setSchema($schema)->setContext($client)->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE);

$server = new StandardServer($config);
$server->handleRequest();
