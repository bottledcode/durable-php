<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Bottledcode\DurablePhp\OrchestrationContext;
use Bottledcode\DurablePhp\OrchestrationContextInterface;
use Bottledcode\DurablePhp\State\Attributes\Activity;
use Bottledcode\DurablePhp\State\Attributes\Entity;
use Bottledcode\DurablePhp\State\Attributes\EntryPoint;
use Bottledcode\DurablePhp\State\Attributes\Name;
use Bottledcode\DurablePhp\State\Attributes\Orchestration;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Status;
use Crell\Serde\Attributes\DictionaryField;
use Crell\Serde\KeyType;
use LogicException;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Throwable;

class SchemaExtractor implements SchemaRendererInterface
{
    private AstVisitor $visitor;

    private array|null $depends = null;
    /**
     * @var array<Union>|null
     */
    #[DictionaryField(arrayType: Union::class, keyType: KeyType::String)]
    private array|null $unions = null;

    public function __construct(
        public readonly string|null $filename = null,
        public readonly string|null $contents = null,
        public bool $forInput = false,
    ) {
        $this->visitor = new AstVisitor();
    }

    public function isTopLevel(): bool
    {
        return $this->visitor->isEntity || $this->visitor->isActivity || $this->visitor->isOrchestration;
    }

    public function parse(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($this->contents ?? file_get_contents($this->filename));
        } catch (Throwable $error) {
            echo "Parse error: {$error->getMessage()}\n";
            throw $error;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);
    }

    public function getPhpType(): string
    {
        return $this->visitor->namespace . '\\' . $this->visitor->name;
    }

    public function dependsOn(): array
    {
        if ($this->depends !== null) {
            return $this->depends;
        }

        $types = [];
        foreach ($this->visitor->properties as $property) {
            foreach ($property->type->types as $type) {
                $types[] = $type;
            }
        }

        foreach ($this->visitor->methods as $method) {
            foreach ($method->returnType->types as $type) {
                $types[] = $type;
            }
            foreach ($method->arguments as $argument) {
                foreach ($argument->type->types as $type) {
                    $types[] = $type;
                }
            }
        }

        return $this->depends = array_values(array_unique($types));
    }

    /**
     * @return array<string, Union>
     */
    public function unions(): array
    {
        if ($this->unions !== null) {
            return $this->unions;
        }

        $unions = [];
        foreach ($this->visitor->properties as $property) {
            if (count($property->type->types) > 1) {
                $union = new Union($property->type->types);
                $unions[$union->name] ??= $union;
            }
        }

        foreach ($this->visitor->methods as $method) {
            foreach ($method->arguments as $argument) {
                if (count($argument->type->types) > 1) {
                    $union = new Union($argument->type->types);
                    $unions[$union->name] = $union;
                }
            }
            if (count($method->returnType->types) > 1) {
                $union = new Union($method->returnType->types);
                $unions[$union->name] = $union;
            }
        }

        return $this->unions = $unions;
    }

    public function implements(): array
    {
        return array_merge($this->visitor->implements, $this->visitor->extends);
    }

    public function renderType(TypeManager $typeManager): string
    {
        if ($this->forInput) {
            return '';
        }

        if ($this->visitor->isEnum) {
            $cases = array_map(static fn(AstProperty $x) => $x->name, $this->visitor->properties);
            $cases = implode("\n\t", $cases);

            return <<<GQL
enum {$this->getGraphQlType()} {
\t{$cases}
}
GQL;
        }

        if ($this->visitor->isOrchestration) {
            $entrypoint = $this->getEntryPoint();
            if ($entrypoint === null) {
                return '';
            }

            // make sure the return type is rendered
            $typeManager->lookupType($entrypoint->returnType->getUnionOrType());
            return '';
        }

        if ($this->visitor->isActivity) {
            return '';
        }

        /** @lang GraphQL */
        return <<<GQL
type {$this->getGraphQlName()} {
{$this->renderProps($typeManager)}
}
GQL;
    }

    public function getGraphQlType(bool $forInput = false, bool $nullable = true): string
    {
        return $this->getGraphQlName() . ($forInput ? 'Input' : '') . ($nullable ? '' : '!');
    }

    public function getGraphQlName(): string
    {
        foreach ($this->visitor->attributes as $attribute) {
            if ($attribute->name === Name::class) {
                return $attribute->parameters[0] ?? $attribute->parameters['name'] ??
                    throw new LogicException('Name attribute defined with no name given');
            }

            if (in_array($attribute->name, [Activity::class, Entity::class, Orchestration::class], true)) {
                return $attribute->parameters[0] ?? $attribute->parameters['name'] ?? $this->visitor->name;
            }
        }

        return $this->visitor->name;
    }

    public function getEntryPoint(): AstMethod|null
    {
        $entryPoint = null;
        foreach ($this->visitor->methods as $method) {
            foreach ($method->attributes as $attribute) {
                if ($attribute->name === EntryPoint::class) {
                    $entryPoint = $method;
                }
            }
            if ($method->name === '__invoke') {
                $entryPoint ??= $method;
            }
        }

        return $entryPoint;
    }

    private function renderProps(TypeManager $typeManager): string
    {
        $lines = [];
        foreach ($this->visitor->properties as $property) {
            $type = $property->type->getUnionOrType();
            $type = $typeManager->lookupType($type);

            $lines[] = "\t" . $property->name . ': ' . $type->getGraphQlType(nullable: $property->type->nullable);
        }

        return implode("\n", $lines);
    }

    public function renderInputType(TypeManager $typeManager): string
    {
        if (!$this->forInput) {
            return '';
        }

        if ($this->visitor->isOrchestration) {
            $entryPoint = $this->getEntryPoint();

            if ($entryPoint === null) {
                return '';
            }

            foreach ($entryPoint->arguments as $argument) {
                $typeManager->lookupType($argument->type->getUnionOrType());
            }
        }

        /** @lang GraphQL */
        return <<<GQL
input {$this->getGraphQlName()}Input {
{$this->renderProps($typeManager)}
}
GQL;
        // todo: mutation args
    }

    public function renderMutations(TypeManager $typeManager): array
    {
        if ($this->visitor->isOrchestration) {
            $entryPoint = $this->getEntryPoint();
            if ($entryPoint === null) {
                return [];
            }

            $args = [];

            foreach ($entryPoint->arguments as $argument) {
                if ($argument->type->getUnionOrType() === OrchestrationContext::class ||
                    $argument->type->getUnionOrType() === OrchestrationContextInterface::class) {
                    continue;
                }
                $type = $argument->type->getUnionOrTypeForInput();

                $args[] = $argument->name . ': ' .
                    $typeManager->lookupType($type)?->getGraphQlType(true, $argument->type->nullable);
            }

            $id = $typeManager->lookupType(OrchestrationInstance::class . 'Input')?->getGraphQlType(true, true);

            $args[] = 'id: ' . $id;

            return [
                $this->getGraphQlName() . ': ' . $this->getGraphQlType() . 'Orchestration' => [
                    'Start(' . implode(',', $args) . '): ' .
                    $typeManager->lookupType(Status::class)?->getGraphQlType(nullable: false),
                    "Signal(id: {$id}, name: String!, message: [Mixed]!): Void",
                ],
            ];
        }

        if ($this->visitor->isEntity) {
            $signals = [];

            foreach ($this->visitor->methods as $method) {
                $signal = "{$method->name}";
                $arguments = [];
                foreach ($method->arguments as $argument) {
                    $arguments[] = $argument->name . ': ' .
                        $typeManager->lookupType($argument->type->getUnionOrTypeForInput())?->getGraphQlType(
                            true,
                            $argument->type->nullable,
                        );
                }
                if ($arguments) {
                    $signal .= '(' . implode(',', $arguments) . ')';
                }

                $signal .= ': ' . $typeManager->lookupType('void')?->getGraphQlType(nullable: true);

                $signals [] = $signal;
            }

            return [
                $this->getGraphQlName() . '(id: ID!): ' . $this->getGraphQlType() . 'EntitySignal' => $signals,
            ];
        }

        return [];
    }

    public function renderQueries(TypeManager $typeManager): array
    {
        if ($this->visitor->isOrchestration) {
            $id = $typeManager->lookupType(OrchestrationInstance::class . 'Input')?->getGraphQlType(true, true);

            return [
                "{$this->getGraphQlName()}(id: {$id}): {$this->getGraphQlType()}Query" => [
                    'Status: ' . $typeManager->lookupType(Status::class)?->getGraphQlType(nullable: false),
                ],
            ];
        }

        if ($this->visitor->isEntity) {
            return [
                "{$this->getGraphQlName()}(id: ID!): {$this->visitor->namespace}\\{$this->visitor->name}" => [],
            ];
        }

        return [];
    }

    public function isHidden(): bool
    {
        foreach ($this->visitor->attributes as $attribute) {
            if (in_array($attribute->name, [Entity::class, Orchestration::class, Activity::class, Name::class], true)) {
                $value = $attribute->parameters[1] ?? $attribute->parameters['hidden'] ?? 'false';
                return $value === 'true';
            }
        }

        return false;
    }
}
