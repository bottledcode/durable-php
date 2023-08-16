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

class ClientProxy extends Generator
{
    protected function pureMethod(\ReflectionMethod $method): string
    {
        $name = $method->getName();
        $params = $method->getParameters();
        $params = array_map(
            function (\ReflectionParameter $param) {
                $type = $param->getType();
                if ($type !== null) {
                    $type = $this->getTypes($type);
                }

                return "$type \${$param->getName()}";
            },
            $params
        );
        $params = implode(', ', $params);
        $return = $method->getReturnType();
        $return = $return ? ": {$this->getTypes($return)}" : '';

        return <<<EOT
public function $name($params)$return {
    return \$this->source->$name(...func_get_args());
}
EOT;
    }

    protected function getName(\ReflectionClass $class): string
    {
        return "__ClientProxy_{$class->getShortName()}";
    }

    protected function impureSignal(\ReflectionMethod $method): string
    {
        return $this->impureCall($method);
    }

    protected function impureCall(\ReflectionMethod $method): string
    {
        $name = $method->getName();
        $params = $method->getParameters();
        $params = array_map(
            function (\ReflectionParameter $param) {
                $type = $param->getType();
                if ($type !== null) {
                    $type = $this->getTypes($type);
                }

                return "$type \${$param->getName()}";
            },
            $params
        );
        $params = implode(', ', $params);
        $return = $method->getReturnType();
        $return = $return ? ": {$this->getTypes($return)}" : '';

        return <<<EOT
public function $name($params)$return {
    throw new Bottledcode\DurablePhp\Proxy\ImpureException();
}
EOT;
    }

    protected function preamble(\ReflectionClass $class): string
    {
        return <<<EOT
public function __construct(private mixed \$source) {}
EOT;
    }
}
