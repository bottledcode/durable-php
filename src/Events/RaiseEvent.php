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

namespace Bottledcode\DurablePhp\Events;

class RaiseEvent extends Event
{
	public function __construct(string $eventId, public string $eventName, public array $eventData)
	{
		parent::__construct($eventId);
	}

	public static function forOperation(string $operation, array $input): static
	{
		return new static('', '__signal', ['input' => $input, 'operation' => $operation]);
	}

	public static function forLock(string $name): static
	{
		return new static('', '__lock', ['name' => $name]);
	}

	public static function forUnlock(string $name): static
	{
		return new static('', '__unlock', ['name' => $name]);
	}

	public static function forTimer(string $identity): static
	{
		return new static('', $identity, []);
	}

	public function __toString(): string
	{
		return sprintf('RaiseEvent(%s, %s, %s)', $this->eventId, $this->eventName, json_encode($this->eventData));
	}
}
