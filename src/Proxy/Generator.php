<?php
/*
 * Copyright ©2023 Robert Landers
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

namespace Bottledcode\DurablePhp\Proxy;

use ReflectionUnionType;

abstract class Generator
{
    public function __construct(protected string|null $cacheDir = null)
    {
    }

    public function define(string $interface): string
    {
        $name = $this->getName(new \ReflectionClass($interface));
        $cacheFile = null;
        if ($this->cacheDir) {
            $cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . $name . '.php';
            if (file_exists($cacheFile)) {
                require_once $cacheFile;
                return $name;
            }
        }

        if (!class_exists($this->getName(new \ReflectionClass($interface)))) {
            eval($output = $this->generate($interface));
            if ($cacheFile) {
                file_put_contents($cacheFile, "<?php\n$output");
            }
        }

        return $name;
    }

    abstract protected function getName(\ReflectionClass $class): string;

    public function generate(string $interface): string
    {
        $reflection = new \ReflectionClass($interface);
        $methods = $reflection->getMethods();
        $namespace = $reflection->getNamespaceName();
        $className = $reflection->getShortName();
        $methods = array_map(
            function (\ReflectionMethod $method) {
                if ($method->getAttributes(Pure::class)) {
                    return $this->pureMethod($method);
                }

                if ($method->getAttributes(\JetBrains\PhpStorm\Pure::class)) {
                    return $this->pureMethod($method);
                }

                $return = $method->getReturnType();
                if (($return instanceof \ReflectionNamedType) && $return->getName() === 'void') {
                    return $this->impureSignal($method);
                }

                return $this->impureCall($method);
            },
            $methods
        );
        $methods = implode("\n", $methods);
        $namespace = $namespace ? "namespace $namespace;" : '';

        return <<<EOT
{$namespace}

class {$this->getName($reflection)} implements {$className} {
  {$this->preamble($reflection)}
  $methods
}
EOT;
    }

    abstract protected function pureMethod(\ReflectionMethod $method): string;

    abstract protected function impureSignal(\ReflectionMethod $method): string;

    abstract protected function impureCall(\ReflectionMethod $method): string;

    abstract protected function preamble(\ReflectionClass $class): string;

    protected function getTypes(\ReflectionNamedType|ReflectionUnionType|\ReflectionIntersectionType|null $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map($this->getTypes(...), $type->getTypes()));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map($this->getTypes(...), $type->getTypes()));
        }

        return '';
    }
}
