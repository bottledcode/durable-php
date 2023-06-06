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

namespace Bottledcode\DurablePhp\Tests;

class StopWatch
{
	private int $startTime = 0;
	private float $mag = 0;

	public function __construct(public int $time = 0)
	{
	}

	public function start(): void
	{
		$this->startTime = hrtime(true);
		$this->mag = microtime(true);
	}

	public function stop(): void
	{
		$this->time = hrtime(true) - $this->startTime;
		$this->mag = microtime(true) - $this->mag;
	}

	public function getSeconds(): float
	{
		return $this->time / 1_000_000_000;
	}

	public function getTestSecs(): float
	{
		return $this->mag;
	}
}