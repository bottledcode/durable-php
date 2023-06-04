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

namespace Bottledcode\DurablePhp;

class HelloSequence
{
	public function __invoke(OrchestrationContextInterface $context): void
	{
		$name = $context->getInput()['name'];
		$context->setCustomStatus("Saying hello to {$name}!");
		$hello = $context->callActivity('strlen', [$name]);
		$wait = $context->createTimer($context->getCurrentTime()->add($context->createInterval(seconds: 5)));
		$winner = $context->waitAny($hello, $wait);

		if ($winner === $hello) {
			$context->setCustomStatus("hello {$name}!");
		} elseif ($winner === $wait) {
			$context->setCustomStatus('timeout!');
		}

		$context->getCurrentTime();

		$context->waitAll($context->waitForExternalEvent('event'));
		$context->setCustomStatus('all done');
	}
}
