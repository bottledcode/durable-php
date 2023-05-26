<?php

namespace Bottledcode\DurablePhp;

use Closure;
use Exception;
use Throwable;

/**
 * @template T
 *
 * Represents the result of an async task.
 */
class Task
{
	/**
	 * @param T $value
	 * @param bool $hasValue
	 * @param Closure|null $continuation
	 */
	private function __construct(
		private mixed $value,
		private bool $hasValue,
		private Closure|null $continuation = null
	) {
	}

	/**
	 * @param T $result
	 * @return Task<T>
	 */
	public static function withResult(mixed $result): Task
	{
		return new self($result, true);
	}

	public static function withContinuation(Closure $continuation): Task
	{
		return new self(null, false, $continuation);
	}

	/**
	 * @return T
	 * @throws Throwable
	 */
	public function await(): mixed
	{
		if ($this->hasValue) {
			return $this->value;
		}

		if (null === $this->continuation) {
			throw new Exception('Task has no value and no continuation');
		}

		$this->value = ($this->continuation)();
		$this->hasValue = true;
		return $this->value;
	}
}
