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
echo($generator->generateSchema());

$client = DurableClient::get();
$client->withAuth(str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']));

$decorator = function (array $typeConfig, TypeDefinitionNode $typeDefinitionNode): array {
    $name = $typeConfig['name'];

    switch($name) {
        case 'Query':
            $typeConfig['resolveField'] = function ($value, array $args, DurableClient $context, ResolveInfo $resolveInfo): mixed {
                switch($resolveInfo->fieldName) {
                    case 'orchestration':
                        $id = new OrchestrationInstance($args['id']['instance'], $args['id']['execution']);
                        if($args['waitForCompletion'] ?? false) {
                            return Serializer::serialize($context->waitForCompletion($id));
                        }

                        return Serializer::serialize($context->getStatus($id));
                    case 'entity':
                        $id = new EntityId($args['id']['name'], $args['id']['id']);
                        return Serializer::serialize($context->getEntitySnapshot($id));
                }

                return 'error';
            };
            break;
        case 'Mutation':
            $typeConfig['resolveField'] = function ($value, array $args, DurableClient $context, ResolveInfo $resolveInfo): mixed {
                switch($resolveInfo->fieldName) {
                    case 'StartOrchestration':
                        $id = $context->startNew($args['name'], $args['input'], $args['id'] ?? null);
                        return [
                            'instance' => $id->instanceId,
                            'execution' => $id->executionId,
                        ];
                    case 'RaiseOrchestrationEvent':
                        $id = new OrchestrationInstance($args['id']['instance'], $args['id']['execution']);
                        $arguments = array_map(static fn($x, $i) => ['key' => $i, ...$x], $args['arguments'], range(0, count($args['arguments']) - 1));
                        $arguments = array_column($arguments, 'value', 'key');
                        $context->raiseEvent($id, $args['signal'], $arguments);
                        return [];
                    case 'SendEntitySignal':
                        $id = new EntityId($args['id']['name'], $args['id']['id']);
                        $arguments = array_map(static fn($x, $i) => ['key' => $i, ...$x], $args['arguments'], range(0, count($args['arguments']) - 1));
                        $arguments = array_column($arguments, 'value', 'key');
                        $context->signalEntity($id, $args['signal'], $arguments);
                        return [];
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
$contents = file_get_contents(__DIR__ . '/generic-schema.graphql');
$schema = BuildSchema::build($contents, $decorator);

$config = ServerConfig::create()->setSchema($schema)->setContext($client)->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE);

$server = new StandardServer($config);
$server->handleRequest();
