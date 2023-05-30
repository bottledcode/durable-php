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

use Ramsey\Uuid\UuidInterface;

readonly class StateId implements \Stringable
{
	public function __construct(public string $id)
	{
	}

	public static function fromState(StateInterface $state): self
	{
		return match ($state::class) {
			OrchestrationHistory::class => self::fromInstance($state->instance),
			ActivityHistory::class => self::fromActivityId($state->activityId),
		};
	}

	public static function fromInstance(OrchestrationInstance $instance): self
	{
		return new self("orchestration:{$instance}");
	}

	public static function fromActivityId(UuidInterface $activityId): self
	{
		return new self("activity:{$activityId}");
	}

	public function toActivityId(): UuidInterface
	{
		$parts = explode(':', $this->id, 3);
		return match ($parts) {
			['orchestration', $parts[1]] => throw new \Exception("Cannot convert orchestration state to activity id"),
			['activity', $parts[1]] => $parts[2],
		};
	}

	public function toOrchestrationInstance(): OrchestrationInstance
	{
		$parts = explode(':', $this->id, 3);
		return match ($parts) {
			['orchestration', $parts[1], $parts[2]] => new OrchestrationInstance($parts[1], $parts[2]),
			['activity', $parts[1]] => throw new \Exception("Cannot convert activity state to orchestration instance"),
		};
	}

	/**
	 * @return class-string
	 */
	public function getStateType(): string
	{
		$parts = explode(':', $this->id, 3);
		return match ($parts) {
			['orchestration', $parts[1], $parts[2]] => OrchestrationHistory::class,
			['activity', $parts[1]] => ActivityHistory::class,
		};
	}

	public function __toString()
	{
		return $this->id;
	}
}
