<?php

namespace Bottledcode\DurablePhp\Tests\PerformanceTests\src\Benchmarks\Bank;

use Bottledcode\DurablePhp\Attributes\Entity;

#[Entity]
class Account implements AccountInterface
{
	private int $value = 0;

	public function __construct()
	{
	}

	public function add(int $amount): void
	{
		$this->value += $amount;
	}

	public function reset(): void
	{
		$this->value = 0;
	}

	public function get(): int
	{
		return $this->value;
	}

	public function delete(): void
	{
		// TODO: Implement delete() method.
	}
}
