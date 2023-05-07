<?php

namespace parallel {

	use parallel\Runtime\Error\Closed;
	use parallel\Runtime\Error\IllegalFunction;
	use parallel\Runtime\Error\IllegalInstruction;
	use parallel\Runtime\Error\IllegalParameter;
	use parallel\Runtime\Error\IllegalReturn;

	return;

	final class Runtime
	{
		public function __construct(string|null $bootstrap = null)
		{
		}

		/**
		 * @param \Closure $task
		 * @param array $argv
		 * @return Future|null
		 * @throws Closed
		 * @throws IllegalFunction
		 * @throws IllegalInstruction
		 * @throws IllegalParameter
		 * @throws IllegalReturn
		 */
		public function run(\Closure $task, array $argv = []): Future|null
		{
		}

		/**
		 * @return void
		 * @throws Closed
		 */
		public function close(): void
		{
		}

		/**
		 * @return void
		 * @throws Closed
		 */
		public function kill(): void
		{
		}
	}
}

namespace parallel\Runtime {
	class Error extends \Exception {}
	class Bootstrap extends Error {}
}

namespace parallel\Runtime\Error {

	use parallel\Runtime\Error;

	class Closed extends Error {}

	class IllegalFunction extends Error {}

	class IllegalInstruction extends Error {}

	class IllegalParameter extends Error {}

	class IllegalReturn extends Error {}
}
