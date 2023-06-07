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

namespace Bottledcode\DurablePhp\State;

use Crell\Serde\Serde;
use Crell\Serde\SerdeCommon;
use LogicException;

abstract class Serializer
{
	private static mixed $serializer = null;

	public static function serialize(mixed $value): array
	{
		if (is_object($value)) {
			return self::get()->serialize($value, 'array');
		}
		if (is_array($value)) {
			$result = [];
			foreach ($value as $k => $v) {
				$result[$k] = self::serialize($v);
			}
			return $result;
		}
		if (is_scalar($value) || $value === null) {
			return compact('value');
		}

		throw new LogicException('Cannot serialize value of type ' . gettype($value));
	}

	public static function get(): Serde
	{
		return self::$serializer ??= new SerdeCommon();
	}

	/**
	 * @template T
	 * @param array $value
	 * @param class-string $type
	 * @return T
	 */
	public static function deserialize(array $value, string $type): mixed
	{
		if (array_key_exists('value', $value) && count($value) === 1 && (is_scalar(
					$value['value']
				) || $value['value'] === null)) {
			return $value['value'];
		}

		if ($type === 'array') {
			return $value;
		}

		return self::get()->deserialize($value, 'array', $type);
	}

	public static function set(Serde $serializer): void
	{
		self::$serializer = $serializer;
	}
}
