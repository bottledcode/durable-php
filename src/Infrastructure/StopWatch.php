<?php

namespace Bottledcode\DurablePhp\Infrastructure;

use Withinboredom\Time\Seconds;

class StopWatch
{
	private float $start = 0;
	private float|null $end = null;

	private bool $running = false;

	private bool $stopped = false;

	public function __construct()
	{
	}

	public function start(): void
	{
		$this->start = microtime(true);
	}

	public function stop(): Seconds
	{
		$this->end = microtime(true);
		$this->running = false;
		$this->stopped = true;
		return $this->elapsedTime();
	}

	public function elapsedTime(): Seconds|null
	{
		if ($this->running || $this->stopped) {
			return new Seconds(($this->end ?? microtime(true)) - $this->start);
		}

		return null;
	}
}
