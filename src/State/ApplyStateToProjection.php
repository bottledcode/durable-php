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

use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Events\CompleteExecution;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\OrchestrationContext;
use Exception;
use Throwable;

use function Amp\async;

readonly class ApplyStateToProjection implements ApplyStateInterface
{
	private mixed $projection;
	private Source $source;

	public function __construct(
		public Event $previousEvent,
		public OrchestrationHistory $history,
		public string $eventId
	) {
	}

	public function __invoke(Source $source): array
	{
		$this->source = $source;
		$context = new OrchestrationContext($this->history->instance, $this->history);
		$this->loadProjection();
		$future = async(function () use ($context) {
			try {
				$result = ($this->projection)($context, ...igbinary_unserialize($this->history->input));
			} catch (Throwable $x) {
				return $this->createProjectionResult(status: OrchestrationStatus::Failed, error: $x);
			}
			return $this->createProjectionResult(result: $result);
		});

		$result = $future->await();
		return [
			new CompleteExecution(
				'', $this->history->instance, igbinary_serialize($result['result']), $result['status'], $result['error']
			)
		];
	}

	private function loadProjection(): void
	{
		Logger::log("Loading projection for %s", $this->history->instance->instanceId);
		$rawProjection = $this->source->get(
			"projection:{$this->history->instance->instanceId}:{$this->history->instance->executionId}"
		);
		if (empty($rawProjection)) {
			if (!class_exists($this->history->name)) {
				throw new Exception("Orchestration {$this->history->name} not found");
			}
			Logger::log("Projection not found, creating new instance of %s", $this->history->name);
			// todo: DI
			$this->projection = new ($this->history->name)();
			return;
		}

		$this->projection = igbinary_unserialize($rawProjection);
	}

	public function createProjectionResult(
		mixed $result = null,
		OrchestrationStatus $status = OrchestrationStatus::Completed,
		Throwable|null $error = null
	): array {
		return compact('result', 'status', 'error');
	}
}
