<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Bottledcode\DurablePhp\SerializedArray;
use Crell\Serde\Attributes\Field;
use DateTimeImmutable;
use Ramsey\Uuid\Guid\Guid;

class TypeManager
{
    #[Field(exclude: true)]
    private bool $rendering = false;

    /**
     * @param array<SchemaRendererInterface> $knownTypes
     */
    public function __construct(public array $knownTypes = [], #[Field(exclude: true)] private array $referenced = [])
    {
        $this->addType('int', new NoopRenderer('Int'), true);
        $this->addType('float', new NoopRenderer('Float'), true);
        $this->addType('string', new NoopRenderer('String'), true);
        $this->addType('bool', new NoopRenderer('Boolean'), true);
        $this->addType(Guid::class, new NoopRenderer('ID'), true);
        $this->addType(DateTimeImmutable::class, new ScalarRenderer('DateTime'), true);
        $this->addType('void', new ScalarRenderer('Void', alwaysNullable: true));
        $this->addType('mixed', new ScalarRenderer('Mixed', alwaysNullable: true));

        $this->discoverType(__DIR__ . '/../../State/OrchestrationInstance.php');
        $this->discoverType(__DIR__ . '/../../State/EntityId.php');
        $this->discoverType(__DIR__ . '/../../State/Status.php');
        $this->discoverType(__DIR__ . '/../../State/RuntimeStatus.php');
        $this->discoverType(__DIR__ . '/../../State/Ids/StateId.php');
        $this->addType(SerializedArray::class, new NoopRenderer('[Mixed]'));
    }

    public function addType(string $type, SchemaRendererInterface $renderer, bool $sameInput = false): void
    {
        if ($renderer->isHidden()) {
            // will be added as a scalar if referenced
            return;
        }

        $this->knownTypes[$type] = $renderer;
        if ($sameInput) {
            $this->knownTypes[$type . 'Input'] = $renderer;
        }
    }

    public function discoverType(string $filename): void
    {
        $schema = new SchemaExtractor($filename, forInput: false);
        $schema->parse();
        $input = clone $schema;
        $input->forInput = true;
        $this->addType($schema->getPhpType(), $schema);
        $this->addType($schema->getPhpType() . 'Input', $input);
    }

    public function findMissingTypes(): void
    {
        foreach ($this->knownTypes as $type => $renderer) {
            if ($renderer instanceof SchemaExtractor) {
                $dependencies = $renderer->dependsOn();
                foreach ($dependencies as $dependency) {
                    $type = explode('\\', $dependency);
                    $this->knownTypes[$dependency] ??= new ScalarRenderer(end($type));
                }
            }
        }
    }

    public function renderTypes(): string
    {
        $types = [];
        $inputTypes = [];
        $queries = [];
        $mutations = [];

        $this->rendering = true;

        reset($this->knownTypes);

        do {
            $currentType = key($this->knownTypes);
            $renderer = current($this->knownTypes);

            if ($renderer instanceof SchemaExtractor && $renderer->isTopLevel()) {
                $this->referenced[$currentType] = true;
            }

            $types[$currentType] = $renderer->renderType($this);
            $inputTypes[$currentType] = $renderer->renderInputType($this);
            $queries[$currentType] = $renderer->renderQueries($this);
            $mutations[$currentType] = $renderer->renderMutations($this);
        } while (next($this->knownTypes) !== false);

        $this->rendering = false;

        $final = [];
        foreach ([$types, $inputTypes] as $rendered) {
            foreach ($rendered as $type => $part) {
                if ($this->referenced[$type] ?? false) {
                    $final[] = $part;
                }
            }
        }
        if ($mutations) {
            $mutationTypes = [];
            foreach ($mutations as $type => $part) {
                if (($this->referenced[$type] ?? false) && !empty($part)) {
                    $mutationTypes = array_merge_recursive($part, $mutationTypes);
                }
            }

            $tip = '';
            foreach ($mutationTypes as $type => $part) {
                $lines = implode("\n\t", $part);
                $typeType = explode(':', $type);
                $typeType = mb_trim(end($typeType));
                $final[] = <<<QQL
                    type {$typeType} {
                    \t{$lines}
                    }
                    QQL;
                $tip .= "\t{$type}\n";
            }
            $final[] = <<<QGL
                type Mutation {
                {$tip}
                }
                QGL;
        }

        if ($queries) {
            $queryTypes = [];
            foreach ($queries as $type => $part) {
                if (($this->referenced[$type] ?? false) && !empty($part)) {
                    $queryTypes = array_merge_recursive($part, $queryTypes);
                }
            }

            $tip = '';
            foreach ($queryTypes as $type => $part) {
                $lines = implode("\n\t", $part);
                $typeType = explode(':', $type);
                $typeType = mb_trim(end($typeType));
                if ($this->knownTypes[$typeType] ?? false) {
                    $type =
                        str_replace($typeType, $this->lookupType($typeType)?->getGraphQlType(nullable: true), $type);
                } else {
                    $final[] = <<<GQL
                        type {$typeType} {
                        \t{$lines}
                        }
                        GQL;
                }
                $tip .= "\t{$type}\n";
            }
            $final[] = <<<GQL
                type Query {
                {$tip}
                }
                GQL;
        }

        return mb_trim(implode("\n", array_filter($final))) . "\n";
    }

    public function lookupType(string|Union $type): SchemaRendererInterface|null
    {
        if ($type instanceof Union) {
            $typed = $this->lookupType($type->name);
            if ($typed === null) {
                $this->addType($type->name, $typed = new UnionRenderer($type->name, $type->types));
            }

            return $typed;
        }

        if ($this->rendering) {
            $this->referenced[$type] = true;
        }

        return $this->knownTypes[$type] ?? null;
    }
}
