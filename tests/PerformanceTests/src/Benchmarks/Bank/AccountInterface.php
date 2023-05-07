<?php

namespace Bottledcode\DurablePhp\Tests\PerformanceTests\src\Benchmarks\Bank;

interface AccountInterface
{
	public function add(int $amount): void;

	public function reset(): void;

	public function get(): int;

	public function delete(): void;
}
