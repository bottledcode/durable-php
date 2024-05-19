<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Error;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

class GraphGenerator extends MetaParser
{
    public static function parseFile(string $contents): MetaParser
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($contents);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            throw $error;
        }

        $namespace = '';
        $name = '';
        $uses = [];
        $methods = [];
        $implements = [];
        $attributes = [];
        $properties = [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(
            new class ($namespace, $uses, $implements, $methods, $attributes, $name, $properties) extends
                NodeVisitorAbstract {
                public function __construct(
                    public string &$namespace,
                    public array &$uses,
                    public array &$implements,
                    public array &$methods,
                    public array &$attributes,
                    public string &$name,
                    public array &$properties,
                ) {}

                private function deUse(string $name): string
                {
                    return $this->uses[$name] ?? $name;
                }

                private function extractValue(Node\Arg $arg): string|int|float
                {
                    $value = $arg->value;
                    return match (true) {
                        $value instanceof Node\Scalar\String_ => $value->value,
                        $value instanceof Node\Scalar\Int_ => $value->value,
                        $value instanceof Node\Scalar\Float_ => $value->value,
                        $value instanceof Node\Expr\ConstFetch => $value->name,
                        default => throw new LogicException(
                            'attempted to parse impossible argument: ' . $value->getType(),
                        ),
                    };
                }

                private function extractAttributes(Node\AttributeGroup ...$group): array
                {
                    $attributes = [];
                    foreach ($group as $attrGroup) {
                        foreach ($attrGroup->attrs as $attr) {
                            $attributes[] = [
                                'type' => 'attr',
                                'name' => $this->deUse($attr->name->name),
                                'args' => array_map(fn(Node\Arg $arg) => $this->extractValue($arg), $attr->args),
                            ];
                        }
                    }

                    return $attributes;
                }

                private function extractTypes(
                    null|Node|Node\ComplexType|Node\Identifier|Node\Name|string $node,
                    bool $deuse = true,
                ): string {
                    $uses = $deuse ? $this->deUse(...) : static fn($x) => $x;
                    return match (true) {
                        is_string($node) => $uses($node),
                        $node instanceof Node\Name => $uses($node->name),
                        $node instanceof Node\Identifier => $uses($node->name),
                        $node instanceof Node\UnionType => implode(
                            '|',
                            array_map(fn($node) => $this->extractTypes($node), $node->types),
                        ),
                        $node instanceof Node\IntersectionType => implode(
                            '&',
                            array_map(fn($node) => $this->extractTypes($node), $node->types),
                        ),
                        $node instanceof Node\NullableType => 'null|' . $this->extractTypes($node->type),
                        $node instanceof Node => $uses($node->name),
                        default => 'mixed',
                    };
                }

                public function enterNode(Node $node): void
                {
                    switch (true) {
                        case $node instanceof Node\Stmt\Namespace_:
                            $this->namespace = $node->name->name;
                            break;
                        case $node instanceof Node\Stmt\Use_:
                            foreach ($node->uses as $use) {
                                $alias = $use->alias ?? $use->name->name;
                                $alias = explode('\\', $alias);
                                $alias = array_pop($alias);
                                $this->uses[$alias] = $use->name->name;
                            }
                            break;
                        case $node instanceof Node\Stmt\Interface_:
                            foreach ($node->extends as $extend) {
                                $this->implements[] = $this->deUse($extend->name);
                            }
                            $this->attributes = $this->extractAttributes(...$node->attrGroups);
                            $this->name = $node->name->name;
                            break;
                        case $node instanceof Node\Stmt\Class_:
                            foreach ($node->implements as $implement) {
                                $this->implements[] = $this->deUse($implement->name);
                            }
                            $this->attributes = $this->extractAttributes(...$node->attrGroups);
                            $this->name = $node->name->name;
                            break;
                        case $node instanceof Node\Stmt\ClassMethod:
                            // we do not want to parse the body
                            $node->stmts = [];

                            $args = [];
                            foreach ($node->params as $param) {
                                $arg = [
                                    'type' => $this->extractTypes($param->type, false),
                                    'full_type' => $this->extractTypes($param->type->name, true),
                                    'name' => $param->var->name,
                                ];
                                $args[] = $arg;
                            }

                            $method = [
                                'return' => $this->extractTypes($node->returnType, false),
                                'full_return' => $this->extractTypes($node->returnType, true),
                                'name' => $node->name->name,
                                'attributes' => $this->extractAttributes(...$node->attrGroups),
                                'args' => $args,
                            ];
                            $this->methods[] = $method;
                            break;
                        case $node instanceof Node\Stmt\Property:
                            $attributes = $this->extractAttributes(...$node->attrGroups);
                            foreach ($node->props as $prop) {
                                $this->properties[] = [
                                    'attributes' => $attributes,
                                    'type' => $this->extractTypes($node->type, false),
                                    'full_type' => $this->extractTypes($node->type, true),
                                    'name' => $prop->name->name,
                                ];
                            }
                            break;
                    }
                }
            },
        );

        $traverser->traverse($ast);

        return new self($namespace, $uses, $methods, $implements, $attributes, $properties, $name);
    }
}
