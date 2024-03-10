<?php

namespace {{.Name}}\Activities;

/**
 * We specify an invokable class to allow autoloading to work.
 */
class AddOne {
	public function __invoke(int $number): int {
		return $number + 1;
	}
}