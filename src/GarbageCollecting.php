<?php

namespace Bottledcode\DurablePhp;

trait GarbageCollecting
{
	private int $timesCollectedGarbage = 0;

	protected function collectGarbage(): bool
	{
		if ($this->timesCollectedGarbage++ % 100 === 0) {
			gc_collect_cycles();
			return true;
		}

		return false;
	}
}
