<?php

namespace parallel {

	use parallel\Sync\Error\IllegalValue;

	final class Sync
	{
		/**
		 * @param int|float|string|null $value
		 * @throws IllegalValue
		 */
		public function __construct(int|null|float|string $value = null)
		{
		}

		public function get(): int|float|string|null
		{
		}

		/**
		 * @param int|float|string $value
		 * @return void
		 * @throws IllegalValue
		 */
		public function set(int|float|string $value): void
		{
		}

		public function wait(): void
		{
		}

		public function notify(bool $all = false): void
		{
		}

		public function __invoke(callable $critical): mixed
		{
		}
	}
}

namespace parallel\Sync\Error {

	use Exception;

	class IllegalValue extends Exception
	{
	}
}
