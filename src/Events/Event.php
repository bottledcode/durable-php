<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\Infrastructure\DurabilityListener;
use Bottledcode\DurablePhp\Serialization\DataContract;
use Bottledcode\DurablePhp\Serialization\IgnoreDataMember;

#[DataContract]
class Event
{
	#[IgnoreDataMember]
	public EventId $id;

	#[IgnoreDataMember]
	public DurabilityListener $listeners;

	public function safeToRetryFailedToSend(): bool
	{
		return !($this instanceof ClientRequestEvent);
	}
}
