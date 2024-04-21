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

class MetaParser
{
    public function __construct(public string $namespace, public array $uses, public array $methods, public array $implements, public array $attributes, public array $properties) {}

    public static function getSequenceType(array $attributes): string
    {
        foreach ($attributes as $attribute) {
            if ($attribute['name'] === 'SequenceField') {
                $type = $attribute['args'][0]['full_type'];

                return match ($type) {
                    'Crell\Serde\ValueTypeString' => 'string',
                    'Crell\Serde\ValueTypeFloat' => 'float',
                    'Crell\Serde\ValueTypeInt' => 'int',
                    'Crell\Serde\ValueTypeArray' => 'array',
                    default => $type,
                };
            }
        }

        return 'mixed';
    }

    public static function parseFile(string $contents): self
    {
        $tokens = token_get_all($contents);

        $mode = Mode::None;
        $namespace = '';
        $uses = [];
        $lastUse = '';
        $implements = [];
        $lastVisibility = '';
        $methods = [];
        $currentMethod = [];
        $currentArgument = [];
        $attributes = [];
        $properties = [];
        $currentProperty = [];
        $lastAttributes = [];

        foreach ($tokens as $token) {
            $currentToken = is_array($token) ? $token[0] : $token;

            $name = is_int($currentToken) ? token_name($currentToken) : $currentToken;

            //var_dump(['currentToken' => $name, 'currentProperty' => $currentProperty, 'properties' => $properties, 'mode' => $mode]);

            switch ($currentToken) {
                case T_NAMESPACE:
                    $mode = Mode::CapturingNamespace;
                    break;
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                case T_NAME_RELATIVE:
                    switch ($mode) {
                        case Mode::CapturingNamespace:
                            $namespace = $token[1];
                            break;
                        case Mode::CapturingUse:
                            $ns = explode('\\', $token[1]);
                            $uses[array_pop($ns)] = $token[1];
                            $lastUse = $token[1];
                            break;
                        case Mode::CapturingImplements:
                            $implements[] = $token[1];
                            break;
                    }
                    break;
                case '|':
                case '&':
                case '?':
                    switch ($mode) {
                        case Mode::CapturingProperty:
                        case Mode::MaybeProperty:
                            $currentProperty['type'] ??= '';
                            $currentProperty['type'] .= $token;
                            break;
                        case Mode::CapturingArguments:
                            $currentArgument['type'] .= $token;
                            $currentArgument['full_type'] .= $token;
                            break;
                        case Mode::CapturingReturn:
                            $currentMethod['return'] .= $token;
                            $currentMethod['full_return'] .= $token;
                            break;
                    }
                    break;
                case T_ARRAY:
                    switch ($mode) {
                        case Mode::CapturingArguments:
                            $currentArgument['type'] ??= '';
                            $currentArgument['type'] .= 'array';
                            $currentArgument['full_type'] ??= '';
                            $currentArgument['full_type'] .= 'array';
                            break;
                        case Mode::CapturingReturn:
                            $currentMethod['return'] ??= '';
                            $currentMethod['full_return'] ??= '';
                            $currentMethod['return'] .= 'array';
                            $currentMethod['full_return'] .= 'array';
                            break;
                        case Mode::CapturingProperty:
                        case Mode::MaybeProperty:
                            $currentProperty['type'] ??= '';
                            $currentProperty['full_type'] ??= '';
                            $currentProperty['type'] .= 'array';
                            $currentProperty['full_type'] .= 'array';
                            break;
                    }
                    break;
                case T_STRING:
                case T_CONSTANT_ENCAPSED_STRING:
                    switch ($mode) {
                        case Mode::CapturingUse:
                            $uses[$lastUse] = $token[1];
                            break;
                        case Mode::CapturingImplements:
                            $implements[] = $token[1];
                            break;
                        case Mode::CapturingFunction:
                            $currentMethod['name'] = $token[1];
                            $currentMethod['args'] = [];
                            $currentMethod['return'] = 'mixed';
                            $currentMethod['attributes'] = $lastAttributes;
                            $mode = Mode::CapturingArguments;
                            break;
                        case Mode::CapturingArguments:
                            $currentArgument['type'] ??= '';
                            $currentArgument['full_type'] ??= '';
                            $currentArgument['type'] .= $token[1];
                            if (! in_array($token[1], ['int', 'float', 'string', 'bool', 'array', 'object', 'resource', 'null'])) {
                                $currentArgument['full_type'] .= $uses[$token[1]] ?? $token[1];
                                if (! str_contains($currentArgument['full_type'], '\\')) {
                                    $currentArgument['full_type'] = $namespace . '\\' . $currentArgument['full_type'];
                                }
                            }
                            break;
                        case Mode::CapturingReturn:
                            $currentMethod['return'] ??= '';
                            $currentMethod['full_return'] ??= '';
                            $currentMethod['return'] .= $token[1];
                            if (! in_array($token[1], ['int', 'float', 'string', 'bool', 'array', 'object', 'resource', 'null'])) {
                                $currentMethod['full_return'] .= $uses[$token[1]] ?? $token[1];
                                if (! str_contains($currentMethod['full_return'], '\\')) {
                                    $currentMethod['full_return'] = $namespace . '\\' . $currentMethod['full_return'];
                                }
                            }
                            break;
                        case Mode::CapturingAttribute:
                            $currentMethod['name'] = $token[1];
                            $mode = Mode::CapturingArguments;
                            break;
                        case Mode::MaybeProperty:
                        case Mode::CapturingProperty:
                            $currentProperty['type'] ??= '';
                            $currentProperty['full_type'] ??= '';
                            $currentProperty['type'] .= $token[1];
                            if (! in_array($token[1], ['int', 'float', 'string', 'bool', 'array', 'object', 'resource', 'null'])) {
                                $currentProperty['full_type'] .= $uses[$token[1]] ?? $token[1];
                                if (! str_contains($currentProperty['full_type'], '\\')) {
                                    $currentProperty['full_type'] = $namespace . '\\' . $currentProperty['full_type'];
                                }
                            }
                            $mode = Mode::CapturingProperty;
                            break;
                    }
                    break;
                case T_VARIABLE:
                    switch ($mode) {
                        case Mode::CapturingArguments:
                            $currentArgument['name'] = $token[1];
                            break;
                        case Mode::MaybeProperty:
                            $currentProperty['type'] ??= 'mixed';
                            // no break
                        case Mode::CapturingProperty:
                            $currentProperty['name'] = $token[1];
                            $mode = Mode::None;
                            $properties[] = $currentProperty;
                            $currentProperty = [];
                            break;
                    }
                    break;
                case ',':
                case ')':
                    switch ($mode) {
                        case Mode::CapturingArguments:
                            if (! empty($currentArgument)) {
                                if (empty($currentArgument['type'])) {
                                    $currentArgument['type'] = 'mixed';
                                }
                                $currentMethod['args'][] = $currentArgument;
                                $currentArgument = [];
                            }
                            break;
                    }
                    break;
                case ':':
                    switch ($mode) {
                        case Mode::CapturingArguments:
                            $mode = Mode::CapturingReturn;
                            break;
                    }
                    break;
                case ']':
                case '{':
                    switch ($mode) {
                        case Mode::CapturingReturn:
                        case Mode::CapturingArguments:
                        case Mode::CapturingFunction:
                        case Mode::CapturingImplements:
                            $mode = Mode::None;
                            if (! empty($currentMethod)) {
                                if (($currentMethod['type'] ?? '') === 'attr') {
                                    $attributes[] = $currentMethod;
                                    $lastAttributes[] = $currentMethod;
                                } else {
                                    $currentMethod['attributes'] = $lastAttributes;
                                    $lastAttributes = [];
                                    $methods[] = $currentMethod;
                                }
                                $currentArgument = [];
                                $currentMethod = [];
                            }
                            break;
                    }
                    break;
                case T_USE:
                    $mode = Mode::CapturingUse;
                    break;
                case T_IMPLEMENTS:
                    $mode = Mode::CapturingImplements;
                    break;
                case T_PUBLIC:
                    $lastVisibility = 'public';
                    $currentProperty['attributes'] = $lastAttributes;
                    $lastAttributes = [];
                    $mode = Mode::MaybeProperty;
                    break;
                case T_PRIVATE:
                    $lastVisibility = 'private';
                    break;
                case T_PROTECTED:
                    $lastVisibility = 'protected';
                    break;
                case T_FUNCTION:
                    if ($lastVisibility === 'public') {
                        $mode = Mode::CapturingFunction;
                        $lastAttributes = $currentProperty['attributes'];
                        unset($currentProperty['attributes']);
                    }
                    break;
                case T_ATTRIBUTE:
                    if ($mode === Mode::None) {
                        $mode = Mode::CapturingAttribute;
                        $currentMethod = ['type' => 'attr'];
                    }
                    break;
            }
            if ($mode !== Mode::None && $token === ';') {
                $mode = Mode::None;
                if (! empty($currentMethod)) {
                    $methods[] = $currentMethod;
                    $currentMethod = [];
                }
            }
        }

        return new self($namespace, $uses, $methods, $implements, $attributes, $properties);
    }
}
