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

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\Serializer;

final readonly class SerializedArray
{
    private function __construct(public array $source, public array $types) {}

    public static function fromArray(array $array): SerializedArray
    {
        $source = array_map(static function (mixed $x) {
            if (is_object($x)) {
                return Serializer::serialize($x);
            }

            if (is_array($x)) {
                return Serializer::serialize(SerializedArray::fromArray($x));
            }

            return $x;
        }, $array);

        $types = array_map(static fn(mixed $x) => get_debug_type($x), $array);

        return new self($source, $types);
    }

    public function toArray(): array
    {
        return array_map(static function (mixed $source, string $type) {
            if($type === 'array') {
                return self::import($source)->toArray();
            }

            return is_array($source) ? Serializer::deserialize($source, $type) : $source;
        }, $this->source, $this->types);
    }

    public static function import(array $source): SerializedArray
    {
        return new self($source['source'], $source['types']);
    }
}