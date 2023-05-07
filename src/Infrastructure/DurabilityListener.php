<?php

namespace Bottledcode\DurablePhp\Infrastructure;

use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Infrastructure\Interfaces\DurabilityListenerInterface;

class DurabilityListener
{
	public static function register(Event $event, DurabilityListenerInterface $listener): void
	{
	}

	public function clear(): void {}
}
