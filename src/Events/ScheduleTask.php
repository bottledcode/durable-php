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

class ScheduleTask extends Event
{
	public function __construct(
		public string $eventId,
		public string $name,
		public string|null $version = null,
		public array|null $input = null,
	) {
		parent::__construct($eventId);
	}

	public static function fromOrchestrationContext(array $data): self
	{
		return new self('', $data['name'], input: $data['input']);
	}

	public static function forName(string $name, array $data): self
	{
		return new self('', $name, input: $data);
	}

	public function __toString(): string
	{
		return sprintf(
			'ScheduleTask(%s, %s, %s, %s)',
			$this->eventId,
			$this->name,
			$this->version,
			json_encode($this->input),
		);
	}
}
