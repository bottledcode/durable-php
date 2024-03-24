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
    public function __construct(public string $namespace, public array $uses, public array $methods, public array $implements, public array $attributes) {}

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

        foreach ($tokens as $token) {
            $currentToken = is_array($token) ? $token[0] : $token;

            $name = is_int($currentToken) ? token_name($currentToken) : $currentToken;

            switch($currentToken) {
                case T_NAMESPACE:
                    $mode = Mode::CapturingNamespace;
                    break;
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                case T_NAME_RELATIVE:
                    switch($mode) {
                        case Mode::CapturingNamespace:
                            $namespace = $token[1];
                            break;
                        case Mode::CapturingUse:
                            $ns = explode('\\', $token[1]);
                            $uses[$token[1]] = array_pop($ns);
                            $lastUse = $token[1];
                            break;
                        case Mode::CapturingImplements:
                            $implements[] = $token[1];
                            break;
                    }
                    break;
                case T_STRING:
                case T_CONSTANT_ENCAPSED_STRING:
                    switch($mode) {
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
                            $mode = Mode::CapturingArguments;
                            break;
                        case Mode::CapturingArguments:
                            $currentArgument['type'] = $token[1];
                            break;
                        case Mode::CapturingReturn:
                            $currentMethod['return'] = $token[1];
                            break;
                        case Mode::CapturingAttribute:
                            $currentMethod['name'] = $token[1];
                            $mode = Mode::CapturingArguments;
                            break;
                    }
                    break;
                case T_VARIABLE:
                    switch($mode) {
                        case Mode::CapturingArguments:
                            $currentArgument['name'] = $token[1];
                            break;
                    }
                    break;
                case ',':
                case ')':
                    switch($mode) {
                        case Mode::CapturingArguments:
                            if(!empty($currentArgument)) {
                                $currentMethod['args'][] = $currentArgument;
                                $currentArgument = [];
                            }
                            break;
                    }
                    break;
                case ':':
                    switch($mode) {
                        case Mode::CapturingArguments:
                            $mode = Mode::CapturingReturn;
                            break;
                    }
                    break;
                case ']':
                case '{':
                    switch($mode) {
                        case Mode::CapturingReturn:
                        case Mode::CapturingArguments:
                        case Mode::CapturingFunction:
                        case Mode::CapturingImplements:
                            $mode = Mode::None;
                            if(!empty($currentMethod)) {
                                if(($currentMethod['type'] ?? '') === 'attr') {
                                    $attributes[] = $currentMethod;
                                } else {
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
                    break;
                case T_PRIVATE:
                    $lastVisibility = 'private';
                    break;
                case T_PROTECTED:
                    $lastVisibility = 'protected';
                    break;
                case T_FUNCTION:
                    if($lastVisibility === 'public') {
                        $mode = Mode::CapturingFunction;
                    }
                    break;
                case T_ATTRIBUTE:
                    if ($mode === Mode::None) {
                        $mode = Mode::CapturingAttribute;
                        $currentMethod = ['type' => 'attr'];
                    }
                    break;
            }
            if($mode !== Mode::None && $token === ';') {
                $mode = Mode::None;
                if(!empty($currentMethod)) {
                    $methods[] = $currentMethod;
                    $currentMethod = [];
                }
            }
        }

        return new self($namespace, $uses, $methods, $implements, $attributes);
    }
}
