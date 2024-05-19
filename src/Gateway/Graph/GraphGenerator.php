<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Error;
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

                private function extractAttributes(Node\AttributeGroup ...$group): array
                {
                    $attributes = [];
                    foreach ($group as $attrGroup) {
                        foreach ($attrGroup->attrs as $attr) {
                            $attributes[] = [
                                'type' => 'attr',
                                'name' => $this->deUse($attr->name->name),
                                'args' => array_map(fn(Node\Arg $arg) => $arg->name->name, $attr->args),
                            ];
                        }
                    }

                    return $attributes;
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
                        case $node instanceof Node\Stmt\Class_:
                            foreach ($node->implements as $implement) {
                                $this->implements[] = $this->deUse($implement->name);
                            }
                            $this->attributes = $this->extractAttributes(...$node->attrGroups);
                            break;
                        case $node instanceof Node\Stmt\ClassMethod:
                            // we do not want to parse the body
                            $node->stmts = [];

                            $args = [];
                            foreach ($node->params as $param) {
                                $arg = [
                                    'type' => $param->type->name,
                                    'full_type' => $this->deUse($param->type->name),
                                    'name' => $param->var->name,
                                ];
                                $args[] = $arg;
                            }

                            $method = [
                                'return' => $this->deUse($node->returnType->name ?? 'mixed'),
                                'full_return' => $node->returnType->name ?? 'mixed',
                                'name' => $node->name->name,
                                'attributes' => $this->extractAttributes(...$node->attrGroups),
                                'args' => $args,
                            ];
                            $this->methods[] = $method;
                            break;
                        case $node instanceof Node\Stmt\Property:
                            $type = $node->type->name ?? 'mixed';
                            $attributes = $this->extractAttributes(...$node->attrGroups);
                            foreach ($node->props as $prop) {
                                $this->properties[] = [
                                    'attributes' => $attributes,
                                    'type' => $type,
                                    'full_type' => $this->deUse($type),
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
