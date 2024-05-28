<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Bottledcode\DurablePhp\State\Attributes\Activity;
use Bottledcode\DurablePhp\State\Attributes\Entity;
use Bottledcode\DurablePhp\State\Attributes\Name;
use Bottledcode\DurablePhp\State\Attributes\Orchestration;
use Crell\Serde\Attributes\Field;
use Crell\Serde\Attributes\SequenceField;
use Crell\Serde\ValueType;
use DateTimeImmutable;
use LogicException;
use Override;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class AstVisitor extends NodeVisitorAbstract
{
    private const array BAD_FUNCS = [
        'rand',
        'mt_rand',
        'random_int',
        'random_bytes',
        'time',
        'microtime',
        'date',
        'gmdate',
        'uniqid',
        'com_create_guid',
        'sys_getloadavg',
        'gethostbyname',
        'gethostbyaddr',
        'dns_get_record',
        'checkdnsrr',
        'getmxrr',
        'getmypid',
        'memory_get_usage',
        'memory_get_peak_usage',
        'getrusage',
    ];
    public bool $isOrchestration = false;
    public bool $isActivity = false;
    public bool $isEntity = false;
    public bool $isEnum = false;
    public string $namespace = '\\';

    public array $waitsFor = [];

    /**
     * @var array<AstProperty>
     */
    #[SequenceField(arrayType: AstProperty::class)]
    public array $properties = [];
    /**
     * @var array<AstMethod>
     */
    #[SequenceField(arrayType: AstMethod::class)]
    public array $methods = [];
    /**
     * @var array<string>
     */
    #[SequenceField(arrayType: ValueType::String)]
    public array $extends = [];
    /**
     * @var array<string>
     */
    #[SequenceField(arrayType: ValueType::String)]
    public array $implements = [];
    /**
     * @var array<AstAttribute>
     */
    #[SequenceField(arrayType: AstAttribute::class)]
    public array $attributes = [];
    public string $name;
    /**
     * @var array<string>
     */
    #[Field(exclude: true)]
    private array $uses = [];
    #[Field(exclude: true)]
    private bool $inBody = false;

    #[Override]
    public function leaveNode(Node $node): void
    {
        switch (true) {
            case $node instanceof Node\Stmt\Function_:
            case $node instanceof Node\Stmt\ClassMethod:
                $this->inBody = false;
                break;
        }
    }

    #[Override]
    public function enterNode(Node $node): void
    {
        // some effort to check for non-deterministic calls
        if ($this->inBody) {
            if ($this->isOrchestration) {
                switch (true) {
                    case $node instanceof Node\Expr\FuncCall:
                        if (in_array($node->name->name, self::BAD_FUNCS, true)) {
                            $this->emitError(
                                $node,
                                'Usage of non-deterministic in main orchestration body, use activity instead: ',
                                ['non-deterministic function' => $node->name->name],
                            );
                        }
                        break;
                    case $node instanceof Node\Expr\MethodCall:
                        if ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                            return;
                        }

                        // todo: be able to mark calls as deterministic?
                        //$this->emitWarning($node, 'Potential non-deterministic call found');
                        break;
                    case $node instanceof Node\Expr\StaticCall:
                        break;
                    case $node instanceof Node\Expr\NullsafeMethodCall:
                        break;
                    case $node instanceof Node\Expr\New_:
                        // check explicitly for new dates!
                        if ($node->class instanceof Node\Name) {
                            if (in_array(
                                $node->class->name->name,
                                [DateTimeImmutable::class, DateTimeImmutable::class],
                                true,
                            )) {
                                if (empty($node->args) || ($node->args[0] instanceof Node\Scalar\String_ &&
                                        $node->args[0]->value === 'now')) {
                                    $this->emitError(
                                        $node,
                                        'Usage of non-deterministic date main orchestration body, use activity instead: ',
                                    );
                                }
                            }
                        }
                        break;
                }
            }

            return;
        }


        switch (true) {
            case $node instanceof Node\Stmt\Namespace_:
                $this->namespace = $node->name->name;
                break;
            case $node instanceof Node\Stmt\Use_:
                foreach ($node->uses as $use) {
                    $alias = $use->alias?->name ?? $this->getSimpleName($use->name->name);
                    $this->uses[$alias] = $use->name->name;
                }
                break;
            case $node instanceof Node\Stmt\Interface_:
                foreach ($node->extends as $extend) {
                    $this->implements[] = $this->deUse($extend->name->name);
                }
                $this->attributes = $this->extractAttributes(...$node->attrGroups);
                $this->name = $node->name->name;
                break;
            case $node instanceof Node\Stmt\Class_:
                foreach ($node->extends ?? [] as $extend) {
                    $this->extends[] = $this->deUse($extend instanceof Name ? $extend->name : $extend);
                }
                foreach ($node->implements as $implement) {
                    $this->implements[] = $this->deUse($implement->name);
                }
                $this->attributes = $this->extractAttributes(...$node->attrGroups);

                $this->determineKind();

                $this->name = $node->name->name;
                break;
            case $node instanceof Node\Stmt\ClassMethod:
                $this->inBody = true;

                $name = $node->name->name;

                if (in_array($name, ['__destruct', '__get', '__set', '__isset', '__unset'], true)) {
                    $node->stmts = [];
                    return;
                }

                if ($name === '__construct') {
                    foreach ($node->params as $param) {
                        if ($param->isPublic()) {
                            $attributes = $this->extractAttributes(...$param->attrGroups);
                            $type = AstType::fromArray($this->extractTypes($param->type));

                            $this->properties[] = new AstProperty($type, $param->var->name, $attributes);
                        }
                    }
                    return;
                }

                if ($name !== '__invoke' &&
                    ($node->isStatic() || $node->isAbstract() || $node->isPrivate() || $node->isProtected() ||
                        $node->isMagic())) {
                    $node->stmts = [];
                    return;
                }

                $args = [];
                foreach ($node->params as $param) {
                    $type = AstType::fromArray($this->extractTypes($param->type));

                    // todo: variadic

                    $args[] =
                        new AstProperty($type, $param->var->name, $this->extractAttributes(...$param->attrGroups));
                }

                $return = AstType::fromArray($this->extractTypes($node->returnType));
                $attributes = $this->extractAttributes(...$node->attrGroups);

                $this->methods[] = new AstMethod($name, $args, $return, $attributes);
                break;
            case $node instanceof Node\Stmt\Property:

                if (!$node->isPublic()) {
                    return;
                }

                if ($node->isStatic()) {
                    return;
                }

                $attributes = $this->extractAttributes(...$node->attrGroups);
                $type = AstType::fromArray($this->extractTypes($node->type));

                foreach ($node->props as $prop) {
                    $this->properties[] = new AstProperty($type, $prop->name->name, $attributes);
                }
                break;
            case $node instanceof Node\Stmt\Function_:
                $this->inBody = true;
                $attributes = $this->extractAttributes(...$node->attrGroups);

                $this->determineKind();

                // skip the body
                $node->stmts = [];
                $args = [];
                foreach ($node->params as $param) {
                    $args[] = AstType::fromArray($this->extractTypes($param->type));
                }

                $return = AstType::fromArray($this->extractTypes($node->returnType));
                $name = $node->name->name;
                $this->name = $name;
                $this->attributes = $attributes;
                $this->methods[] = new AstMethod('__invoke', $args, $return, []);
                break;
            case $node instanceof Node\Stmt\Enum_:
                $this->name = $node->name->name;
                $this->attributes = $this->extractAttributes(...$node->attrGroups);
                $this->isEnum = true;
                break;
            case $node instanceof Node\Stmt\EnumCase:
                $this->properties[] = new AstProperty(
                    new AstType(['string'], false),
                    $node->name,
                    $this->extractAttributes(...$node->attrGroups),
                );
                break;
        }
    }

    private function emitError(Node $node, string $warning, array $context = []): never
    {
        $message = "{$this->name}:{$node->getStartLine()} {$warning} " . json_encode($context);

        throw new LogicException($message);
    }

    private function getSimpleName(string $fullName): string
    {
        $parts = explode('\\', $fullName);
        return end($parts);
    }

    private function deUse(string $name): string
    {
        if (in_array($name, ['string', 'int', 'float', 'bool', 'array', 'void', 'never'], true)) {
            return $name;
        }

        return $this->uses[$name] ?? $this->namespace . '\\' . $name;
    }

    private function extractAttributes(Node\AttributeGroup ...$group): array
    {
        $attributes = [];
        foreach ($group as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $args = [];

                foreach ($attr->args as $idx => $arg) {
                    $args[$arg->name?->name ?? $idx] = $this->extractValue($arg);
                }

                $attributes[] = new AstAttribute($this->deUse($attr->name->name), $args);
            }
        }

        return $attributes;
    }

    private function extractValue(Node\Arg $arg): string|int|float
    {
        $value = $arg->value;
        return match (true) {
            $value instanceof Node\Scalar\String_ => $value->value,
            $value instanceof Node\Scalar\Int_ => $value->value,
            $value instanceof Node\Scalar\Float_ => $value->value,
            $value instanceof Node\Expr\ConstFetch => $value->name,
            default => $this->emitError($arg, 'attempted to parse impossible argument', ['type' => $value->getType()]),
        };
    }

    private function determineKind(): void
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name === Orchestration::class) {
                $this->isOrchestration = true;
            }
            if ($attribute->name === Activity::class) {
                $this->isActivity = true;
            }
            if ($attribute->name === Entity::class) {
                $this->isEntity = true;
            }
        }
    }

    private function extractTypes(
        null|Node|Node\ComplexType|Node\Identifier|Node\Name|string $node,
    ): array {
        $types = match (true) {
            is_string($node) => [$this->deUse($node)],
            $node instanceof Node\Name\FullyQualified => [$node->name],
            $node instanceof Node\Name => [$this->deUse($node->name)],
            $node instanceof Node\Identifier => [$node->name],
            $node instanceof Node\UnionType => array_merge(
                ...
                array_map(fn($node) => $this->extractTypes($node), $node->types),
            ),
            $node instanceof Node\IntersectionType => throw new LogicException('intersection types cannot be used'),
            $node instanceof Node\NullableType => ['null', ...$this->extractTypes($node->type)],
            $node instanceof Node => [$this->deUse($node->name)],
            default => ['mixed'],
        };

        return $types;
    }

    private function emitWarning(Node $node, string $warning, array $context = []): void
    {
        $message = "{$this->name}:{$node->getStartLine()} {$warning} " . json_encode($context);

        trigger_error($message, E_USER_WARNING);
    }
}
