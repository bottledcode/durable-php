<?php

namespace Bottledcode\DurablePhp\Infrastructure\Interfaces;

use Bottledcode\DurablePhp\Events\Event;

interface DurabilityListenerInterface
{
	public function confirmDurable(Event $event);
}
