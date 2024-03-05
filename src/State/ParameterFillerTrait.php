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

namespace Bottledcode\DurablePhp\State;

trait ParameterFillerTrait
{
    private function fillParameters(array $arguments, \ReflectionMethod|\ReflectionFunction $method): array
    {
        foreach($arguments as $name => &$entry) {
            if(!is_array($entry)) {
                continue;
            }
            if(is_numeric($name)) {
                $parameter = $method->getParameters()[$name];
                if($parameter->getType()?->isBuiltin()) {
                    continue;
                }
                $entry = Serializer::deserialize($entry, $parameter->getType());
            } else {
                foreach($method->getParameters() as $parameter) {
                    if ($parameter->getName() === $name) {
                        if($parameter->getType()?->isBuiltin() && $parameter->getType()?->getName() === 'array') {
                            // todo: deserialize arrays
                            break;
                        }
                        $entry = Serializer::deserialize($entry, $parameter->getType()?->getName());
                        break;
                    }
                }
            }
        }

        return $arguments;
    }
}
