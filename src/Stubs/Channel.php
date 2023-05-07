<?php

namespace parallel {

	use parallel\Channel\Error\Closed;
	use parallel\Channel\Error\Existence;
	use parallel\Channel\Error\IllegalValue;

	return;

	final class Channel
	{
		const Infinite = -1;

		/**
		 * Make an anonymous channel
		 * @param int $capacity
		 */
		public function __construct(int $capacity = self::Infinite)
		{
		}

		/**
		 * @param string $name
		 * @param int $capacity
		 * @return Channel
		 * @throws Existence
		 */
		public static function make(string $name, int $capacity = 0): Channel
		{
		}

		/**
		 * @param string $name
		 * @return Channel
		 * @throws Existence
		 */
		public static function open(string $name): Channel
		{
		}

		/**
		 * @return mixed
		 * @throws Closed
		 */
		public function recv(): mixed
		{
		}

		/**
		 * @param mixed $value
		 * @return void
		 * @throws IllegalValue
		 * @throws Closed
		 */
		public function send(mixed $value): void
		{
		}

		/**
		 * @return void
		 * @throws Closed
		 */
		public function close(): void
		{
		}
	}
}

namespace parallel\Channel\Error {

	class Existence extends \Exception
	{
	}

	class Closed extends \Exception
	{
	}

	class IllegalValue extends \Exception {}
}
